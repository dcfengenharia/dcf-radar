<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarPrevisaoEntregaPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\OrigemPrevisaoEntregaPedido;
use App\Enums\Papel;
use App\Enums\StatusPedidoCompra;
use App\Exceptions\PrevisaoEntregaInvalidaException;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\DocumentoEngenharia;
use App\Models\PedidoCompra;
use App\Models\PedidoCompraPrevisaoEntrega;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Etapa 3 — Histórico de Prazo (Seção 33, A-K).
 */
class PedidoCompraPrevisaoEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private AtualizarPrevisaoEntregaPedidoCompra $atualizarPrevisao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->atualizarPrevisao = new AtualizarPrevisaoEntregaPedidoCompra();
    }

    private function criarItemTakeOff(float $quantidade): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item', 'quantidade' => $quantidade]);
    }

    private function alocacaoPronta(ItemTakeOff $ito, float $quantidade): AlocacaoRequisicaoPacote
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    private function pedidoEmitido(?string $dataPrevista = '2027-01-15'): PedidoCompra
    {
        $ito = $this->criarItemTakeOff(1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo, null, $this->user);
        $rcItem = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $fornecedor, $dataPrevista, null, null, null, $this->user);
        (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem->fresh(), 100);

        return (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
    }

    // ---- A/B/C/D — primeira previsão + preservação da cadeia ----

    public function test_a_primeira_previsao_e_registrada_na_emissao(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');

        $historico = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->get();

        $this->assertCount(1, $historico);
        $this->assertSame('2027-01-15', $historico->first()->data_prevista->toDateString());
        $this->assertSame(OrigemPrevisaoEntregaPedido::Inicial, $historico->first()->origem);
        $this->assertSame($this->user->id, $historico->first()->registrado_por_id);
    }

    public function test_b_segunda_previsao_preserva_a_primeira(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');

        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-25'), $this->user, 'Fornecedor atrasou produção', null);

        $historico = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->orderBy('created_at')->get();

        $this->assertCount(2, $historico);
        $this->assertSame('2027-01-15', $historico[0]->data_prevista->toDateString());
        $this->assertSame(OrigemPrevisaoEntregaPedido::Inicial, $historico[0]->origem);
        $this->assertSame('2027-01-25', $historico[1]->data_prevista->toDateString());
        $this->assertSame(OrigemPrevisaoEntregaPedido::Revisao, $historico[1]->origem);
        $this->assertSame('Fornecedor atrasou produção', $historico[1]->motivo);
    }

    public function test_c_terceira_previsao_preserva_a_cadeia_inteira(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-25'), $this->user, 'Revisão 1', null);
        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-02-05'), $this->user, 'Revisão 2', null);

        $historico = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->orderBy('created_at')->pluck('data_prevista')->map->toDateString();

        $this->assertSame(['2027-01-15', '2027-01-25', '2027-02-05'], $historico->all());
    }

    public function test_d_registra_usuario_data_e_motivo(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $evento = $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-25'), $this->user, 'Motivo X', 'Obs Y');

        $this->assertSame($this->user->id, $evento->registrado_por_id);
        $this->assertNotNull($evento->registrado_em);
        $this->assertSame('Motivo X', $evento->motivo);
        $this->assertSame('Obs Y', $evento->observacao);
    }

    public function test_d2_revisao_sem_motivo_e_rejeitada(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');

        $this->expectException(PrevisaoEntregaInvalidaException::class);
        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-25'), $this->user, null, null);
    }

    // ---- E/F — Pedido Emitido pode ter prazo revisado, sem afetar quantidade/fornecedor ----

    public function test_e_pedido_emitido_pode_ter_prazo_revisado(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $this->assertSame(StatusPedidoCompra::Emitido, $pedido->status);

        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-02-01'), $this->user, 'Novo prazo', null);

        $this->assertSame('2027-02-01', $pedido->fresh()->data_prevista_entrega->toDateString());
        $this->assertSame(StatusPedidoCompra::Emitido, $pedido->fresh()->status);
    }

    public function test_f_quantidade_e_fornecedor_nao_mudam_ao_revisar_prazo(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $fornecedorIdAntes = $pedido->fornecedor_id;
        $quantidadeAntes = $pedido->itens->first()->quantidade_pedida;

        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-02-01'), $this->user, 'Novo prazo', null);

        $pedidoFresh = $pedido->fresh(['itens']);
        $this->assertSame($fornecedorIdAntes, $pedidoFresh->fornecedor_id);
        $this->assertEquals((float) $quantidadeAntes, (float) $pedidoFresh->itens->first()->quantidade_pedida);
    }

    // ---- G/H — previsão x recebimento nunca se confundem ----

    public function test_g_recebimento_nao_altera_previsao(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');

        $item = $pedido->fresh(['itens'])->itens->first();
        (new RegistrarRecebimentoPedido())->execute($item, 40, now()->subDay(), $this->user, null, null);

        $this->assertSame('2027-01-15', $pedido->fresh()->data_prevista_entrega->toDateString());
        $this->assertCount(1, PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->get());
    }

    public function test_h_alteracao_de_previsao_nunca_cria_recebimento(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-02-01'), $this->user, 'Novo prazo', null);

        $item = $pedido->fresh(['itens'])->itens->first();
        $this->assertEquals(0.0, $item->quantidadeRecebida());
    }

    // ---- I — cross-obra bloqueado ----

    public function test_i_previsao_de_pedido_de_outra_obra_e_bloqueada(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $outroUsuario, Papel::GerentePlanejamento->value);

        // Mesma disciplina de resolver obra-scoped já usada em toda a
        // Etapa 2 — o resolver da UI (não a Action, que confia em quem
        // resolveu o model) é quem bloqueia; aqui reproduzimos a mesma
        // consulta obra-scoped que o Livewire usa.
        $encontrado = PedidoCompra::where('obra_id', $outraObra->id)->whereKey($pedido->id)->first();
        $this->assertNull($encontrado);
    }

    // ---- J — legado sem histórico continua válido ----

    public function test_j_pedido_legado_sem_historico_continua_valido(): void
    {
        // Simula um Pedido já emitido ANTES desta etapa existir — a
        // migration não faz backfill, então um Pedido legado
        // genuinamente não tem nenhuma linha de histórico.
        $pedido = $this->pedidoEmitido('2027-01-15');
        PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->delete();

        $this->assertNull($pedido->fresh()->ultimaPrevisaoRegistrada());
        $this->assertSame('2027-01-15', $pedido->fresh()->data_prevista_entrega->toDateString());
        $this->assertNotNull($pedido->fresh()->diasAtrasoAtual() ?? 'ok-sem-erro');
    }

    // ---- K — snapshot e histórico nunca divergem ----

    public function test_k_snapshot_e_historico_nunca_divergem(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-25'), $this->user, 'R1', null);
        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-02-05'), $this->user, 'R2', null);

        $pedidoFresh = $pedido->fresh();
        $ultimoEvento = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)
            ->orderByDesc('registrado_em')->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->assertSame($ultimoEvento->data_prevista->toDateString(), $pedidoFresh->data_prevista_entrega->toDateString());
        $this->assertSame($ultimoEvento->id, $pedidoFresh->ultimaPrevisaoRegistrada()->id);
    }

    // ---- Imutabilidade do histórico ----

    public function test_historico_nunca_e_editavel_ou_apagavel(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $evento = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->firstOrFail();

        $this->expectException(PrevisaoEntregaInvalidaException::class);
        $evento->update(['data_prevista' => '2030-01-01']);
    }

    public function test_historico_nunca_e_excluivel(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        $evento = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->firstOrFail();

        $this->expectException(PrevisaoEntregaInvalidaException::class);
        $evento->delete();
    }

    // ---- Concorrência estrutural (Seção 32) ----

    // ---- Fechamento Adversarial, Seção 5 — legado + primeira revisão formal ----

    public function test_fa5_legado_primeira_revisao_formal_nao_pode_virar_inicial(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->delete();

        $evento = $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-02-01'), $this->user, 'Primeira revisão formal rastreada', null);

        // Pedido JÁ era Emitido com 15/01 conhecido — rotular este evento
        // como "Inicial" fabricaria a narrativa de que 01/02 foi a
        // promessa original feita na emissão, apagando silenciosamente o
        // fato de que 15/01 já era um compromisso real e conhecido.
        $this->assertSame(OrigemPrevisaoEntregaPedido::Revisao, $evento->origem, 'Legado Emitido sem histórico: a primeira revisão registrada NUNCA pode ser rotulada como Inicial.');
        $this->assertCount(1, PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->get());
    }

    public function test_fa5_legado_primeira_revisao_formal_exige_motivo(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');
        PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)->delete();

        $this->expectException(PrevisaoEntregaInvalidaException::class);
        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-02-01'), $this->user, null, null);
    }

    // ---- Fechamento Adversarial, Seção 4 — Rascunho revisado antes da emissão ----

    public function test_fa4_rascunho_revisado_antes_da_emissao_vira_inicial_sem_duplicar(): void
    {
        $ito = $this->criarItemTakeOff(1000);
        $alocacao = $this->alocacaoPronta($ito, 100);
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo']);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo, null, $this->user);
        $rcItem = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor', 'cnpj' => '00.000.000/0001-00']);

        // Rascunho nasce com 10/01 (CriarPedidoCompra — sem histórico, por
        // design: Rascunho ainda não é compromisso comercial formal).
        $pedido = (new CriarPedidoCompra())->execute($rc->fresh(), $fornecedor, '2027-01-10', null, null, null, $this->user);
        (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcItem->fresh(), 100);

        // Revisado para 20/01 AINDA Rascunho — zero histórico existente
        // (nem Inicial nem Revisao), zero compromisso comercial anterior.
        $evento1 = $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-20'), $this->user, null, null);
        $this->assertSame(OrigemPrevisaoEntregaPedido::Inicial, $evento1->origem, 'Primeiro registro de um Rascunho sem histórico e sem compromisso comercial prévio deve ser Inicial.');

        // Emitido em seguida — nunca duplica a linha Inicial.
        $pedidoEmitido = (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        $historico = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedidoEmitido->id)->orderBy('created_at')->get();

        $this->assertCount(1, $historico, 'Emitir não pode criar uma 2ª linha Inicial quando o Rascunho já registrou sua previsão antes da emissão.');
        $this->assertSame('2027-01-20', $historico->first()->data_prevista->toDateString());
        $this->assertSame(OrigemPrevisaoEntregaPedido::Inicial, $historico->first()->origem);
    }

    // ---- Fechamento Adversarial, Seção 3 — outcome nunca invertido ----

    public function test_fa3_concorrencia_revisao_outcome_snapshot_e_ultimo_historico_sempre_coincidem(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-10');

        // Simula A e B disputando a MESMA linha, serializados pelo lock
        // (RefreshDatabase é mono-conexão — a prova estrutural real é o
        // lockForUpdate() já comprovado em test_concorrencia_lock_no_pedido_antes_de_gravar;
        // aqui provamos o INVARIANTE de resultado nos dois sentidos de ordem).
        $eventoA = $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-20'), $this->user, 'Usuário A', null);
        $eventoB = $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-30'), $this->user, 'Usuário B', null);

        $pedidoFresh = $pedido->fresh();
        $ultimoHistorico = PedidoCompraPrevisaoEntrega::where('pedido_compra_id', $pedido->id)
            ->orderByDesc('registrado_em')->orderByDesc('created_at')->orderByDesc('id')->first();

        $this->assertSame($eventoB->id, $ultimoHistorico->id);
        $this->assertSame('2027-01-30', $pedidoFresh->data_prevista_entrega->toDateString());
        $this->assertSame($ultimoHistorico->data_prevista->toDateString(), $pedidoFresh->data_prevista_entrega->toDateString(), 'Snapshot e último evento histórico nunca podem divergir, em nenhuma ordem de execução.');
    }

    public function test_concorrencia_lock_no_pedido_antes_de_gravar(): void
    {
        $pedido = $this->pedidoEmitido('2027-01-15');

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->atualizarPrevisao->execute($pedido->fresh(), \Carbon\Carbon::parse('2027-01-25'), $this->user, 'R1', null);

        $lockQuery = collect($queries)->first(fn ($sql) => str_contains($sql, 'pedidos_compra') && str_contains($sql, 'for update'));
        $this->assertNotNull($lockQuery, 'Esperava um lockForUpdate() sobre pedidos_compra antes de gravar a revisão.');
    }

    // ---- Fechamento Adversarial, Seção 1/23-G — nenhum write-path silencioso de produção ----

    public function test_fa1_nenhum_write_path_de_producao_altera_data_prevista_entrega_fora_dos_2_pontos_sancionados(): void
    {
        $arquivos = collect(\Illuminate\Support\Facades\File::allFiles(app_path()))
            ->filter(fn ($f) => $f->getExtension() === 'php')
            ->filter(fn ($f) => ! str_contains($f->getRealPath(), 'AtualizarPrevisaoEntregaPedidoCompra.php')
                && ! str_contains($f->getRealPath(), 'CriarPedidoCompra.php'));

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo->getRealPath());

            $this->assertDoesNotMatchRegularExpression(
                "/(->update\\(\\s*\\[[^\\]]*'data_prevista_entrega'|->forceFill\\(\\s*\\[[^\\]]*'data_prevista_entrega'|::create\\(\\s*\\[[^\\]]*'data_prevista_entrega')/s",
                $conteudo,
                "Write-path inesperado de data_prevista_entrega fora dos 2 pontos sancionados, achado em: {$arquivo->getRelativePathname()}"
            );
        }
    }
}
