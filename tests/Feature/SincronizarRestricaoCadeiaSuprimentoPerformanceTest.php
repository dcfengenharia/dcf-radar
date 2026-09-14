<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaPedidoCompra;
use App\Actions\Suprimentos\AtualizarDistribuicaoParcelaRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fechamento Adversarial Final do Motor Definitivo de Risco de
 * Suprimentos V1 — Seção 19/20 do pedido de fechamento: audita
 * especificamente se `SincronizarRestricaoCadeiaSuprimento::
 * sincronizarPacote()` (que agora chama `DescricaoRestricaoSuprimento::
 * paraPacoteEAtividade()` → `EstadoAtendimentoNecessidadeMaterialQuery::
 * porAtividade()` por atividade em risco) introduziu N+1 por NECESSIDADE.
 * Nunca otimiza sem prova (Seção 20) — só mede.
 */
class SincronizarRestricaoCadeiaSuprimentoPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;
    private Material $material;
    private ItemSuprimento $pacote;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-02-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
        $this->material = Material::create([
            'codigo' => 'MAT-PERF', 'descricao' => 'Cabo', 'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarAtividade(): Atividade
    {
        return Atividade::factory()->create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'inicio_planejado' => '2027-01-15',
        ]);
    }

    private function criarItemTakeOff(): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'Documento']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Cabo',
            'unidade_medida_id' => $this->unidade->id, 'quantidade' => 1000, 'material_id' => $this->material->id,
        ]);
    }

    /**
     * Monta UM Pacote com Pedido atrasado, nunca recebido, vinculado à(s)
     * Atividade(s) informada(s) — o único gatilho comercial precisa
     * existir 1x só; as necessidades "Operacional" (baratas, sem
     * RC/Pedido próprio) se tornam "relevantes" pra este Pacote via
     * `orWhereIn('material_id', ...)` em `necessidadesRelevantesParaPacote()`
     * (mesmo Material do item alocado) — sem precisar de N cadeias
     * RC/Pedido completas pra medir o efeito do NÚMERO de necessidades.
     */
    private function pacoteAtrasadoParaAtividades(array $atividades): ItemSuprimento
    {
        $ito = $this->criarItemTakeOff();
        $necessidadeBase = $this->criarNecessidadeTakeOff($atividades[0], $ito, 100);

        $rp = (new CriarRequisicaoPlanejamento())->execute($this->obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $ito->id, 100);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Perf ' . uniqid()]);
        $pacote->atividades()->sync(collect($atividades)->pluck('id')->all());
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, 100);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);

        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        $item = (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, 100);
        (new AtualizarDistribuicaoParcelaRequisicaoCompra())->adicionarParcela($item, $necessidadeBase, 100, $this->user);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $this->obra->id, 'nome' => 'Fornecedor', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), 100);
        (new AtualizarDistribuicaoParcelaPedidoCompra())->adicionarParcela($pedidoItem->fresh(), $necessidadeBase, 100, $this->user);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return $pacote->fresh(['atividades']);
    }

    private function criarNecessidadeTakeOff(Atividade $atividade, ItemTakeOff $ito, float $quantidade): AtividadeNecessidadeMaterial
    {
        return (new AtualizarNecessidadeMaterialAtividade())->criarTakeOff($atividade, $ito, $quantidade, $this->user);
    }

    /**
     * `AtividadeNecessidadeMaterial` tem unique(atividade,Material) — uma
     * atividade só pode ter 1 necessidade por Material. Como
     * `EstadoAtendimentoNecessidadeMaterialQuery::porAtividade()` processa
     * TODAS as necessidades de uma atividade em lote (nunca filtradas por
     * "relevância" a um Pacote específico — isso só decide quais delas
     * ganham FRASE, `porAtividade()` continua computando a decomposição
     * de TODAS), um Material NOVO e barato por necessidade (sem nenhuma
     * cadeia RC/Pedido) já é suficiente pra medir o efeito do NÚMERO de
     * necessidades no custo de `porAtividade()` — nunca precisa que elas
     * sejam "relevantes" ao Pacote sob teste.
     */
    private function criarNecessidadesOperacionaisBaratas(Atividade $atividade, int $quantidade): void
    {
        for ($i = 0; $i < $quantidade; $i++) {
            $materialAvulso = Material::create([
                'codigo' => 'MAT-FILLER-' . uniqid(), 'descricao' => 'Material avulso', 'unidade_medida_id' => $this->unidade->id,
                'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
            ]);
            (new AtualizarNecessidadeMaterialAtividade())->criarOperacional($atividade, $materialAvulso, 1, "Necessidade {$i}", $this->user);
        }
    }

    private function medirQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $total = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        return $total;
    }

    // =========================================================================
    // 1 atividade / 1 pacote / 10 necessidades VS 100 necessidades — o
    // número de queries não pode crescer linearmente por necessidade.
    // =========================================================================
    public function test_query_count_nao_cresce_linearmente_com_numero_de_necessidades(): void
    {
        $atividade10 = $this->criarAtividade();
        $this->criarNecessidadesOperacionaisBaratas($atividade10, 10);
        $pacote10 = $this->pacoteAtrasadoParaAtividades([$atividade10]);
        // 1ª chamada (fora da medição) — cria a Restrição inicial, evita
        // que o custo de INSERT/create() vs UPDATE distorça a comparação.
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote10->fresh(['atividades']), $this->user->id);

        $queries10 = $this->medirQueries(function () use ($pacote10) {
            SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote10->fresh(['atividades']), $this->user->id);
        });

        $atividade100 = $this->criarAtividade();
        $this->criarNecessidadesOperacionaisBaratas($atividade100, 100);
        $pacote100 = $this->pacoteAtrasadoParaAtividades([$atividade100]);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote100->fresh(['atividades']), $this->user->id);

        $queries100 = $this->medirQueries(function () use ($pacote100) {
            SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote100->fresh(['atividades']), $this->user->id);
        });

        // Tolerância generosa (+10 queries) — nunca crescimento
        // proporcional a 10x o número de necessidades (que produziria uma
        // diferença de centenas de queries, não umas poucas).
        $this->assertLessThanOrEqual(
            $queries10 + 10,
            $queries100,
            "Esperado custo praticamente constante por necessidade; 10 necessidades={$queries10} queries, 100 necessidades={$queries100} queries."
        );
    }

    // =========================================================================
    // 10 atividades × necessidades no MESMO Pacote — crescimento é
    // esperado por ATIVIDADE (cada uma precisa de sua própria avaliação/
    // Restrição), mas nunca EXPLOSIVO (nunca multiplicado pelo número de
    // necessidades de cada atividade).
    // =========================================================================
    public function test_query_count_com_multiplas_atividades_nunca_e_explosivo(): void
    {
        $atividade1 = $this->criarAtividade();
        $this->criarNecessidadesOperacionaisBaratas($atividade1, 10);
        $pacote1Atividade = $this->pacoteAtrasadoParaAtividades([$atividade1]);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote1Atividade->fresh(['atividades']), $this->user->id);
        $queries1Atividade = $this->medirQueries(function () use ($pacote1Atividade) {
            SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote1Atividade->fresh(['atividades']), $this->user->id);
        });

        $atividades10 = [];
        for ($i = 0; $i < 10; $i++) {
            $atividades10[] = $at = $this->criarAtividade();
            $this->criarNecessidadesOperacionaisBaratas($at, 10);
        }
        $pacote10Atividades = $this->pacoteAtrasadoParaAtividades($atividades10);
        SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote10Atividades->fresh(['atividades']), $this->user->id);
        $queries10Atividades = $this->medirQueries(function () use ($pacote10Atividades) {
            SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote10Atividades->fresh(['atividades']), $this->user->id);
        });

        // Crescimento LINEAR no número de Atividades é esperado e aceitável
        // (1 avaliação de cobertura + 1 lookup/gravação de Restrição por
        // Atividade, sempre foi assim) — o que NUNCA pode acontecer é
        // crescimento multiplicado pelo produto (atividades × necessidades).
        // Teto: 10x o custo por atividade + folga.
        $this->assertLessThanOrEqual(
            ($queries1Atividade * 10) + 20,
            $queries10Atividades,
            "Esperado crescimento linear por Atividade, nunca explosivo; 1 atividade={$queries1Atividade} queries, 10 atividades={$queries10Atividades} queries."
        );
    }
}
