<?php

namespace Tests\Feature;

use App\Actions\Estoque\RegistrarEntradaEstoque;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
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
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\SeveridadeSituacao;
use App\Enums\StatusAtividade;
use App\Enums\StatusSituacaoOcorrencia;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\SituacaoOcorrencia;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\Gestao\SincronizarSituacoesGerenciais;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.3 — cobertura A-Q da Seção 28 do pedido, mais
 * cenários de segurança/graceful-degradation do deep-link (Seção
 * 13/24). `App\Models\DocumentoEngenharia` (situação `DocumentoBloqueante`)
 * é o veículo principal — é o tipo mais barato de construir e tem 2
 * níveis de severidade reais (Atenção/Alta) suficientes pra testar
 * escalada sem precisar da cadeia completa de Suprimentos; o teste Q
 * (legado 19.7) precisa especificamente de `PedidoAtrasado`, então
 * reaproveita a MESMA cadeia de fixtures já usada em
 * `SituacoesGerenciaisQueryTest.php`.
 */
class SincronizarSituacoesGerenciaisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // =========================================================
    // Helpers — DocumentoBloqueante (veículo principal)
    // =========================================================

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::create(array_merge([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Atividade '.uniqid(),
            'codigo_cronograma' => 'A'.uniqid(),
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => Carbon::today()->addDays(20),
            'data_termino' => Carbon::today()->addDays(25),
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    /** @return array{0: DocumentoEngenharia, 1: \App\Models\DocumentoEngenhariaRevisao} */
    private function criarDocumentoBloqueante(Atividade $atividade, ?Work $obra = null): array
    {
        $obra ??= $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'DOC-'.uniqid(), 'descricao' => 'Doc']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $doc->atividades()->sync([$atividade->id]);

        return [$doc, $rev];
    }

    private function liberar($rev): void
    {
        $rev->historicoLiberacoes()->create([
            'liberada_para_construcao' => true,
            'alterado_por' => $this->user->id,
            'ocorrido_em' => now(),
            'observacao' => 'ok',
        ]);
    }

    private function chaveDocumentoBloqueante(DocumentoEngenharia $doc, Atividade $atividade): string
    {
        return "documento_bloqueante:{$doc->id}:{$atividade->id}";
    }

    // =========================================================
    // Helpers — cadeia de Suprimentos (só pro teste Q, legado 19.7)
    // =========================================================

    private function criarMaterial(): Material
    {
        $unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M'.uniqid(), 'nome' => 'Metro']);

        return Material::create([
            'codigo' => 'MAT-'.uniqid(), 'descricao' => 'Material', 'unidade_medida_id' => $unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
    }

    private function criarLocal(): LocalEstoque
    {
        return LocalEstoque::create(['obra_id' => $this->obra->id, 'nome' => 'Local '.uniqid(), 'tipo' => TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
    }

    private function criarItemTakeOffOrfao(?Material $material): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D'.uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM'.uniqid()]);

        return ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A'.uniqid(), 'descricao' => 'Item', 'quantidade' => 1000, 'material_id' => $material?->id]);
    }

    private function criarPacoteVinculado(?Atividade $atividade = null): ItemSuprimento
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote '.uniqid()]);
        if ($atividade) {
            $pacote->atividades()->sync([$atividade->id]);
        }

        return $pacote;
    }

    private function requisitarEAlocar(ItemTakeOff $item, float $quantidade, ItemSuprimento $pacote): \App\Models\AlocacaoRequisicaoPacote
    {
        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, $quantidade);
    }

    private function comprarAte(\App\Models\AlocacaoRequisicaoPacote $alocacao, float $quantidade, string $dataPrevista = '2026-12-05'): \App\Models\PedidoCompraItem
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo '.uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor '.uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevista, null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $pedidoItem->fresh();
    }

    private function receber(\App\Models\PedidoCompraItem $pedidoItem, float $quantidade, LocalEstoque $local): void
    {
        $recebimento = (new RegistrarRecebimentoPedido())->execute($pedidoItem, $quantidade, Carbon::today(), $this->user);
        (new RegistrarEntradaEstoque())->execute($recebimento, $local, $quantidade, Carbon::today(), $this->user);
    }

    // =========================================================
    // A — Primeira detecção
    // =========================================================

    public function test_a_primeira_deteccao_cria_1_ocorrencia_e_1_comunicacao_por_destinatario(): void
    {
        $atividade = $this->criarAtividade();
        [$doc] = $this->criarDocumentoBloqueante($atividade);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencias = SituacaoOcorrencia::query()->get();
        $this->assertCount(1, $ocorrencias);
        $ocorrencia = $ocorrencias->first();
        $this->assertSame(TipoSituacaoGerencial::DocumentoBloqueante, $ocorrencia->tipo);
        $this->assertSame(StatusSituacaoOcorrencia::Ativa, $ocorrencia->status);
        $this->assertSame(1, $ocorrencia->episodio);
        $this->assertSame($this->chaveDocumentoBloqueante($doc, $atividade), $ocorrencia->chave_logica);
        $this->assertNotNull($ocorrencia->primeira_deteccao_em);
        $this->assertNotNull($ocorrencia->ultima_deteccao_em);
        $this->assertNull($ocorrencia->resolvida_em);

        $notificacoes = $this->user->notifications()->get();
        $this->assertCount(1, $notificacoes);
        $this->assertSame($ocorrencia->id, $notificacoes->first()->data['ocorrencia_id']);
        $this->assertSame('primeira_deteccao', $notificacoes->first()->data['motivo']);
        $this->assertSame($this->obra->id, $notificacoes->first()->data['obra_id']);
    }

    // =========================================================
    // B — Repetição idêntica (alta frequência, 50 syncs)
    // =========================================================

    public function test_b_cinquenta_syncs_identicos_nao_duplicam(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);

        for ($i = 0; $i < 50; $i++) {
            SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        }

        $this->assertSame(1, SituacaoOcorrencia::count());
        $this->assertSame(1, $this->user->notifications()->count());
    }

    // =========================================================
    // C — Leitura só altera estado do usuário
    // =========================================================

    public function test_c_marcar_lida_nao_altera_ocorrencia(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencia = SituacaoOcorrencia::firstOrFail();
        $notificacao = $this->user->notifications()->firstOrFail();
        $notificacao->markAsRead();

        $this->assertNotNull($notificacao->fresh()->read_at);
        $ocorrenciaDepois = $ocorrencia->fresh();
        $this->assertSame(StatusSituacaoOcorrencia::Ativa, $ocorrenciaDepois->status);
        $this->assertNull($ocorrenciaDepois->resolvida_em);
    }

    // =========================================================
    // D — Resolução (nunca gera comunicação nova)
    // =========================================================

    public function test_d_fato_deixa_de_existir_ocorrencia_resolvida_sem_nova_comunicacao(): void
    {
        $atividade = $this->criarAtividade();
        [, $rev] = $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->liberar($rev);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencia = SituacaoOcorrencia::firstOrFail();
        $this->assertSame(StatusSituacaoOcorrencia::Resolvida, $ocorrencia->status);
        $this->assertNotNull($ocorrencia->resolvida_em);
        $this->assertSame(1, $this->user->notifications()->count());
    }

    // =========================================================
    // E — Reabertura
    // =========================================================

    public function test_e_fato_retorna_gera_novo_episodio_e_nova_comunicacao(): void
    {
        $atividade = $this->criarAtividade();
        [$doc, $rev] = $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->liberar($rev);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $chave = $this->chaveDocumentoBloqueante($doc, $atividade);
        $this->assertSame(1, SituacaoOcorrencia::count());

        // nova revisão, ainda não liberada — MESMA chave lógica.
        $doc->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, SituacaoOcorrencia::count()); // nunca uma segunda linha
        $ocorrencia = SituacaoOcorrencia::where('chave_logica', $chave)->firstOrFail();
        $this->assertSame(StatusSituacaoOcorrencia::Ativa, $ocorrencia->status);
        $this->assertSame(2, $ocorrencia->episodio);
        $this->assertNull($ocorrencia->resolvida_em);

        $notificacoes = $this->user->notifications()->get();
        $this->assertCount(2, $notificacoes);
        $reabertura = $notificacoes->firstWhere(fn ($n) => $n->data['motivo'] === 'reabertura');
        $this->assertNotNull($reabertura);
        $this->assertStringContainsString('(reaberta)', $reabertura->data['titulo']);
    }

    // =========================================================
    // F — Escalada de severidade
    // =========================================================

    public function test_f_escalada_de_severidade_gera_nova_comunicacao_sem_novo_episodio(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(20)]); // Atenção
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencia = SituacaoOcorrencia::firstOrFail();
        $this->assertSame(SeveridadeSituacao::Atencao, $ocorrencia->severidade_atual);
        $this->assertSame(1, $this->user->notifications()->count());

        $atividade->update(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencia->refresh();
        $this->assertSame(1, SituacaoOcorrencia::count());
        $this->assertSame(1, $ocorrencia->episodio); // nunca um novo episódio
        $this->assertSame(SeveridadeSituacao::Alta, $ocorrencia->severidade_atual);

        // `created_at` tem precisão de segundo (mesma classe de
        // fragilidade já documentada no projeto) — as 2 comunicações
        // deste teste nascem no mesmo segundo real; identificar a de
        // escalada pelo `motivo`, nunca por ordem/`created_at`.
        $notificacoes = $this->user->notifications()->get();
        $this->assertCount(2, $notificacoes);
        $escalada = $notificacoes->firstWhere(fn ($n) => $n->data['motivo'] === 'escalada');
        $this->assertNotNull($escalada);
        $this->assertStringContainsString('agravada', $escalada->data['titulo']);
    }

    // =========================================================
    // G — Queda de severidade não gera spam
    // =========================================================

    public function test_g_queda_e_reescalada_ao_mesmo_peso_nao_geram_nova_comunicacao(): void
    {
        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(3)]); // Alta
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, $this->user->notifications()->count());

        $atividade->update(['inicio_planejado' => Carbon::today()->addDays(20)]); // desescala pra Atenção
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $ocorrencia = SituacaoOcorrencia::firstOrFail();
        $this->assertSame(SeveridadeSituacao::Atencao, $ocorrencia->severidade_atual); // exibição sempre atualizada
        $this->assertSame(1, $this->user->notifications()->count()); // zero spam na desescalada

        // reescala pro MESMO peso já comunicado neste episódio (Alta) —
        // decisão documentada: nunca renotifica o mesmo nível 2x.
        $atividade->update(['inicio_planejado' => Carbon::today()->addDays(3)]);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, $this->user->notifications()->count());
    }

    // =========================================================
    // H — Múltiplos usuários (leitura de um nunca marca o outro)
    // =========================================================

    public function test_h_leitura_de_um_usuario_nao_afeta_outro(): void
    {
        $user2 = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user2, Papel::GerentePlanejamento->value);

        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame(1, $user2->notifications()->count());

        $this->user->notifications()->firstOrFail()->markAsRead();

        $this->assertNotNull($this->user->notifications()->firstOrFail()->fresh()->read_at);
        $this->assertNull($user2->notifications()->firstOrFail()->fresh()->read_at);
    }

    // =========================================================
    // I — Usuário ganha permissão durante situação ativa
    // =========================================================

    public function test_i_usuario_ganha_acesso_passa_a_receber_ocorrencia_ativa(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $novoUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->assertSame(0, $novoUsuario->notifications()->count()); // ainda sem vínculo

        $this->vincularObra($this->obra, $novoUsuario, Papel::GerentePlanejamento->value);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $novoUsuario->notifications()->count());
        $this->assertSame(1, $this->user->notifications()->count()); // original não duplica
    }

    // =========================================================
    // J — Usuário perde permissão
    // =========================================================

    public function test_j_usuario_perde_acesso_nao_recebe_comunicacao_futura(): void
    {
        $user2 = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user2, Papel::GerentePlanejamento->value);

        $atividade = $this->criarAtividade(['inicio_planejado' => Carbon::today()->addDays(20)]);
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, $user2->notifications()->count());

        DB::table('obra_user')->where('user_id', $user2->id)->where('work_id', $this->obra->id)->delete();

        $atividade->update(['inicio_planejado' => Carbon::today()->addDays(3)]); // escalada
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        $this->assertSame(1, $user2->notifications()->count()); // histórico intacto, nada novo
        $this->assertSame(2, $this->user->notifications()->count()); // quem ficou recebe normalmente
    }

    // =========================================================
    // K — Multi-obra
    // =========================================================

    public function test_k_isolamento_absoluto_entre_obras(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);

        $atividadeA = $this->criarAtividade([], $this->obra);
        $this->criarDocumentoBloqueante($atividadeA, $this->obra);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        SincronizarSituacoesGerenciais::sincronizarObra($obraB);

        $this->assertSame(1, SituacaoOcorrencia::count());
        $this->assertSame($this->obra->id, SituacaoOcorrencia::firstOrFail()->obra_id);

        $notificacoes = $this->user->notifications()->get();
        $this->assertCount(1, $notificacoes);
        $this->assertSame($this->obra->id, $notificacoes->first()->data['obra_id']);
    }

    // =========================================================
    // L — Tenant
    // =========================================================

    public function test_l_isolamento_absoluto_entre_tenants(): void
    {
        // Work/User/Atividade/DocumentoEngenharia usam BelongsToTenant —
        // criados FORA de actingAs, ficariam carimbados com o tenant
        // ATUALMENTE autenticado ($this->tenant), nunca com $outroTenant
        // (mesmo `tenant_id` explícito no array é ignorado pelo trait).
        // Tudo que pertence ao outro tenant nasce dentro do closure.
        $outroTenant = Tenant::factory()->create();
        $outraObra = null;
        $outroUsuario = null;

        TenantContext::actingAs($outroTenant, function () use ($outroTenant, &$outraObra, &$outroUsuario) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $outroUsuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->vincularObra($outraObra, $outroUsuario, Papel::GerentePlanejamento->value);
            $atividade = Atividade::create([
                'obra_id' => $outraObra->id, 'nome' => 'X', 'codigo_cronograma' => 'X'.uniqid(),
                'status' => StatusAtividade::Planejado->value,
                'inicio_planejado' => Carbon::today()->addDays(3), 'data_termino' => Carbon::today()->addDays(5),
                'fora_do_cronograma' => false,
            ]);
            $doc = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'DOC-OUTRO', 'descricao' => 'Doc']);
            $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
            $doc->atividades()->sync([$atividade->id]);

            SincronizarSituacoesGerenciais::sincronizarObra($outraObra);
        });

        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);

        // Nosso tenant (autenticado como $this->user) nunca enxerga a
        // ocorrência do outro tenant, mesmo com 2 linhas na tabela.
        $this->assertSame(2, DB::table('situacao_ocorrencias')->count());
        $this->assertSame(1, SituacaoOcorrencia::count());
        $this->assertSame($this->tenant->id, SituacaoOcorrencia::firstOrFail()->tenant_id);

        // Nosso usuário recebe só a comunicação do PRÓPRIO tenant; o
        // usuário do outro tenant recebe só a dele — nunca cruzado.
        $this->assertSame(1, $this->user->notifications()->count());
        $this->assertSame(1, $outroUsuario->notifications()->count());
        $this->assertSame($outraObra->id, $outroUsuario->notifications()->firstOrFail()->data['obra_id']);
    }

    // =========================================================
    // M — Deep-link real
    // =========================================================

    public function test_m_deep_link_redireciona_para_rota_correta_e_marca_como_lida(): void
    {
        $atividade = $this->criarAtividade();
        [$doc] = $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $notificacao = $this->user->notifications()->firstOrFail();

        $response = $this->actingAs($this->user)->get(route('notificacoes.abrir', ['notification' => $notificacao->id]));

        $response->assertRedirect(route('engenharia.pacotes', ['documento' => $doc->id]));
        $this->assertNotNull($notificacao->fresh()->read_at);
    }

    public function test_m_deep_link_de_outro_usuario_nunca_e_acessivel(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $notificacao = $this->user->notifications()->firstOrFail();

        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $response = $this->actingAs($outroUsuario)->get(route('notificacoes.abrir', ['notification' => $notificacao->id]));

        $response->assertRedirect(route('notificacoes.index'));
        $this->assertNull($notificacao->fresh()->read_at);
    }

    public function test_m_deep_link_sem_acesso_a_obra_no_momento_do_clique_falha_com_graca(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $notificacao = $this->user->notifications()->firstOrFail();

        DB::table('obra_user')->where('user_id', $this->user->id)->where('work_id', $this->obra->id)->delete();

        $response = $this->actingAs($this->user)->get(route('notificacoes.abrir', ['notification' => $notificacao->id]));

        $response->assertRedirect(route('notificacoes.index'));
    }

    // =========================================================
    // N — Entidade removida: navegação graceful
    // =========================================================

    public function test_n_entidade_removida_navegacao_graceful_e_historico_legivel(): void
    {
        $atividade = $this->criarAtividade();
        [$doc] = $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $notificacao = $this->user->notifications()->firstOrFail();
        $mensagemOriginal = $notificacao->data['mensagem'];

        $doc->delete(); // soft delete

        $response = $this->actingAs($this->user)->get(route('notificacoes.abrir', ['notification' => $notificacao->id]));

        // A rota-alvo nunca faz model-binding do documento (query string
        // pura) — nunca crasha mesmo com a entidade removida.
        $response->assertRedirect(route('engenharia.pacotes', ['documento' => $doc->id]));
        $this->assertSame($mensagemOriginal, $notificacao->fresh()->data['mensagem']);
    }

    // =========================================================
    // O — Concorrência
    // =========================================================

    public function test_o_duas_sincronizacoes_sequenciais_nao_duplicam(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);

        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra); // "segundo worker"

        $this->assertSame(1, SituacaoOcorrencia::count());
        $this->assertSame(1, $this->user->notifications()->count());
    }

    public function test_o2_unique_constraint_bloqueia_insercao_duplicada_no_banco(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $ocorrencia = SituacaoOcorrencia::firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('situacao_ocorrencias')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'tipo' => $ocorrencia->tipo->value,
            'chave_logica' => $ocorrencia->chave_logica, // MESMA chave
            'status' => 'ativa',
            'episodio' => 1,
            'severidade_atual' => 'atencao',
            'severidade_peso_comunicado' => 1,
            'entidade_tipo' => 'DocumentoEngenharia',
            'entidade_id' => 'x',
            'descricao_atual' => 'x',
            'primeira_deteccao_em' => now(),
            'ultima_deteccao_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =========================================================
    // P — Execução parcial/falha nunca resolve erroneamente
    // =========================================================

    public function test_p_falha_na_derivacao_nunca_resolve_ocorrencias_desta_obra(): void
    {
        $atividade = $this->criarAtividade();
        $this->criarDocumentoBloqueante($atividade);
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertSame(1, SituacaoOcorrencia::where('status', 'ativa')->count());

        $sincronizadorComFalha = new class extends SincronizarSituacoesGerenciais
        {
            protected static function derivarSituacoes(Work $obra): Collection
            {
                throw new \RuntimeException('falha simulada de derivação — Seção 7');
            }
        };

        $sincronizadorComFalha::sincronizarObra($this->obra);

        // Nada foi resolvido — mesmo a "ausência total" que
        // resolverAusentes() interpretaria como "tudo desapareceu" se
        // tivesse chegado a rodar.
        $this->assertSame(1, SituacaoOcorrencia::where('status', 'ativa')->count());
        $this->assertSame(0, SituacaoOcorrencia::where('status', 'resolvida')->count());
    }

    // =========================================================
    // Q — Legado 19.7 nunca duplica comunicação
    // =========================================================

    public function test_q_pedido_atrasado_nao_produz_comunicacao_duplicada_pelos_dois_sistemas(): void
    {
        $material = $this->criarMaterial();
        $atividade = $this->criarAtividade();
        $pacote = $this->criarPacoteVinculado($atividade);
        $item = $this->criarItemTakeOffOrfao($material);
        $alocacao = $this->requisitarEAlocar($item, 100, $pacote);
        $pedidoItem = $this->comprarAte($alocacao, 100, '2026-12-05');

        Carbon::setTestNow(Carbon::parse('2026-12-20')); // agora está atrasado

        $pedido = $pedidoItem->pedidoCompra->fresh();

        // Confirma que este é EXATAMENTE o mesmo fato que já dispara o
        // legado 19.7 (`AlertaCadeiaSuprimento::dispararPedidoAtrasado()`
        // só notifica quando `diasAtrasoAtual() !== null`) — provado via
        // a condição de domínio real, não invocando o pipeline de
        // notificação do legado (que fixa conexão 'redis' incondicional,
        // mesmo achado corrigido na notificação NOVA desta etapa — chamar
        // o legado de verdade fora de fila reproduziria só esse problema
        // de infraestrutura, sem nada a ver com o que este teste precisa
        // provar).
        $this->assertNotNull($pedido->diasAtrasoAtual());

        // O NOVO motor, sobre o MESMO fato, rastreia a ocorrência (útil
        // pro futuro Cockpit)...
        SincronizarSituacoesGerenciais::sincronizarObra($this->obra);
        $this->assertTrue(SituacaoOcorrencia::where('tipo', TipoSituacaoGerencial::PedidoAtrasado->value)->exists());

        // ...mas NUNCA gera uma comunicação REAL pra este tipo — o
        // usuário não vê o sino duplicado pelos dois sistemas. (A
        // fixture da cadeia formal de Suprimentos também pode disparar
        // OUTRAS situações legítimas — ex.: MaterialSemDestinacao, que
        // nunca é suprimida — por isso a asserção filtra por tipo, nunca
        // pela contagem total.)
        $temComunicacaoDePedidoAtrasado = $this->user->notifications()
            ->get()
            ->contains(fn ($n) => ($n->data['tipo'] ?? null) === TipoSituacaoGerencial::PedidoAtrasado->value);
        $this->assertFalse($temComunicacaoDePedidoAtrasado);
    }
}
