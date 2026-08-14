<?php

namespace Tests\Feature;

use App\Models\Atividade;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\DigestProntidao;
use App\Support\CentralProntidao\StatusOperacionalProntidao;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 16, Etapa A.2 — núcleo de detecção/consolidação do Digest de
 * Prontidão. Cobre exclusivamente `App\Services\DigestProntidao`
 * (consumindo `CentralProntidaoQuery` via dado real, nunca
 * `AtividadeProntidaoView` montada à mão) — nenhum destinatário, canal,
 * Notification, Command ou persistência entra aqui (fica pra A.3/A.4).
 */
class DigestProntidaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DigestProntidao $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->service = app(DigestProntidao::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function criarAtividade(array $overrides = [], ?Work $obra = null): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function tornarNaoPronta(Atividade $atividade): void
    {
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
        ]);
    }

    private function tornarAtencao(Atividade $atividade): void
    {
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'bloqueante' => false,
        ]);
    }

    // =========================================================================
    // 1) Obra sem atividades problemáticas
    // =========================================================================

    public function test_obra_sem_atividades_problematicas_nao_tem_pendencias(): void
    {
        $this->criarAtividade(['inicio_planejado' => now()->addDays(3)]); // pronta, sem restrição/checklist
        $this->criarAtividade(['inicio_planejado' => now()->addDays(3), 'concluido_em' => now()]); // concluída

        $resumo = $this->service->consolidar($this->obra);

        $this->assertFalse($resumo->temPendencias);
        $this->assertSame(0, $resumo->totalExigeAtencao);
        $this->assertSame(0, $resumo->totalNaoPronta);
        $this->assertSame(0, $resumo->totalAtencao);
        $this->assertSame(2, $resumo->totalAtividadesUniverso);
        $this->assertSame([], $resumo->atividadesProblematicas);
    }

    // =========================================================================
    // 2) Não Pronta
    // =========================================================================

    public function test_atividade_nao_pronta_dentro_do_horizonte_entra_no_digest(): void
    {
        $at = $this->criarAtividade(['inicio_planejado' => now()->addDays(5)]);
        $this->tornarNaoPronta($at);

        $resumo = $this->service->consolidar($this->obra);

        $this->assertTrue($resumo->temPendencias);
        $this->assertSame(1, $resumo->totalNaoPronta);
        $this->assertSame(0, $resumo->totalAtencao);
        $this->assertSame(1, $resumo->totalExigeAtencao);
        $this->assertCount(1, $resumo->atividadesProblematicas);
        $this->assertSame($at->id, $resumo->atividadesProblematicas[0]->atividadeId);
        $this->assertSame(StatusOperacionalProntidao::NaoPronta, $resumo->atividadesProblematicas[0]->statusOperacional);
    }

    // =========================================================================
    // 3) Atenção
    // =========================================================================

    public function test_atividade_atencao_dentro_do_horizonte_entra_no_digest(): void
    {
        $at = $this->criarAtividade(['inicio_planejado' => now()->addDays(5)]);
        $this->tornarAtencao($at);

        $resumo = $this->service->consolidar($this->obra);

        $this->assertTrue($resumo->temPendencias);
        $this->assertSame(0, $resumo->totalNaoPronta);
        $this->assertSame(1, $resumo->totalAtencao);
        $this->assertSame(1, $resumo->totalExigeAtencao);
        $this->assertCount(1, $resumo->atividadesProblematicas);
        $this->assertSame(StatusOperacionalProntidao::Atencao, $resumo->atividadesProblematicas[0]->statusOperacional);
    }

    // =========================================================================
    // 4) Mistura de status
    // =========================================================================

    public function test_mistura_de_status_conta_apenas_nao_pronta_e_atencao(): void
    {
        $pronta = $this->criarAtividade(['inicio_planejado' => now()->addDays(3)]);
        $concluida = $this->criarAtividade(['inicio_planejado' => now()->addDays(3), 'concluido_em' => now()]);
        $atencao = $this->criarAtividade(['inicio_planejado' => now()->addDays(3)]);
        $this->tornarAtencao($atencao);
        $naoPronta = $this->criarAtividade(['inicio_planejado' => now()->addDays(3)]);
        $this->tornarNaoPronta($naoPronta);

        $resumo = $this->service->consolidar($this->obra);

        $this->assertSame(4, $resumo->totalAtividadesUniverso);
        $this->assertSame(1, $resumo->totalNaoPronta);
        $this->assertSame(1, $resumo->totalAtencao);
        $this->assertSame(2, $resumo->totalExigeAtencao);
        $this->assertCount(2, $resumo->atividadesProblematicas);

        $idsProblematicos = collect($resumo->atividadesProblematicas)->pluck('atividadeId')->all();
        $this->assertContains($naoPronta->id, $idsProblematicos);
        $this->assertContains($atencao->id, $idsProblematicos);
        $this->assertNotContains($pronta->id, $idsProblematicos);
        $this->assertNotContains($concluida->id, $idsProblematicos);
    }

    // =========================================================================
    // 5) Horizonte — determinístico via Carbon::setTestNow()
    // =========================================================================

    public function test_horizonte_inclui_hoje_e_limite_de_30_dias_exclui_o_dia_seguinte(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $hoje = $this->criarAtividade(['inicio_planejado' => Carbon::parse('2026-01-01 00:00:00')]);
        $this->tornarNaoPronta($hoje);

        $maisUmDia = $this->criarAtividade(['inicio_planejado' => Carbon::parse('2026-01-02 00:00:00')]);
        $this->tornarNaoPronta($maisUmDia);

        $vinteENoveDias = $this->criarAtividade(['inicio_planejado' => Carbon::parse('2026-01-30 00:00:00')]);
        $this->tornarNaoPronta($vinteENoveDias);

        $limiteExato30Dias = $this->criarAtividade(['inicio_planejado' => Carbon::parse('2026-01-31 00:00:00')]);
        $this->tornarNaoPronta($limiteExato30Dias);

        $foraDoHorizonte = $this->criarAtividade(['inicio_planejado' => Carbon::parse('2026-02-01 00:00:00')]);
        $this->tornarNaoPronta($foraDoHorizonte);

        $resumo = $this->service->consolidar($this->obra);

        $this->assertSame(30, $resumo->horizonteDias);
        $this->assertTrue($resumo->horizonteAte->equalTo(Carbon::parse('2026-01-31 00:00:00')));

        $idsProblematicos = collect($resumo->atividadesProblematicas)->pluck('atividadeId')->all();
        $this->assertContains($hoje->id, $idsProblematicos);
        $this->assertContains($maisUmDia->id, $idsProblematicos);
        $this->assertContains($vinteENoveDias->id, $idsProblematicos);
        $this->assertContains($limiteExato30Dias->id, $idsProblematicos, 'Limite exato dos 30 dias deve entrar (Central usa <=, nunca <)');
        $this->assertNotContains($foraDoHorizonte->id, $idsProblematicos, 'Um dia além do horizonte não deve entrar');
        $this->assertCount(4, $resumo->atividadesProblematicas);

        Carbon::setTestNow();
    }

    public function test_horizonte_inclui_atividade_atrasada_sem_limite_inferior(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $atrasada = $this->criarAtividade(['inicio_planejado' => Carbon::parse('2025-11-01 00:00:00')]);
        $this->tornarNaoPronta($atrasada);

        $resumo = $this->service->consolidar($this->obra);

        $idsProblematicos = collect($resumo->atividadesProblematicas)->pluck('atividadeId')->all();
        $this->assertContains($atrasada->id, $idsProblematicos, 'Atividade atrasada (sem limite inferior de data) deve continuar entrando, mesma semântica da Central');

        Carbon::setTestNow();
    }

    // =========================================================================
    // 6) Isolamento de obra
    // =========================================================================

    public function test_atividade_de_outra_obra_do_mesmo_tenant_nao_entra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atOutraObra = $this->criarAtividade(['inicio_planejado' => now()->addDays(3)], $outraObra);
        $this->tornarNaoPronta($atOutraObra);

        $resumo = $this->service->consolidar($this->obra);

        $this->assertFalse($resumo->temPendencias);
        $this->assertSame(0, $resumo->totalAtividadesUniverso);
        $this->assertSame($this->obra->id, $resumo->obraId);
    }

    // =========================================================================
    // 7) Isolamento de tenant
    // =========================================================================

    public function test_atividade_de_outro_tenant_nao_entra(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $atOutroTenant = Atividade::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'fora_do_cronograma' => false,
            'inicio_planejado' => now()->addDays(3),
        ]);
        Restricao::factory()->create([
            'tenant_id' => $outroTenant->id,
            'atividade_id' => $atOutroTenant->id,
            'bloqueante' => true,
        ]);

        $resumo = $this->service->consolidar($this->obra);

        $this->assertFalse($resumo->temPendencias);
        $this->assertSame(0, $resumo->totalAtividadesUniverso);
    }

    // =========================================================================
    // 8) Zero escrita
    // =========================================================================

    public function test_consolidar_nao_altera_nenhum_registro(): void
    {
        $at = $this->criarAtividade(['inicio_planejado' => now()->addDays(3)]);
        $this->tornarNaoPronta($at);
        $this->criarItemProntidao();

        $antesAtividade = $at->fresh()->updated_at;
        $antesContagens = [
            'atividades' => Atividade::count(),
            'restricoes' => Restricao::count(),
            'itens_prontidao' => ItemProntidao::count(),
        ];

        $this->service->consolidar($this->obra);
        $this->service->consolidar($this->obra); // rodar 2x — ainda assim zero escrita

        $this->assertSame($antesAtividade->toDateTimeString(), $at->fresh()->updated_at->toDateTimeString());
        $this->assertSame($antesContagens, [
            'atividades' => Atividade::count(),
            'restricoes' => Restricao::count(),
            'itens_prontidao' => ItemProntidao::count(),
        ]);
    }

    private function criarItemProntidao(?Work $obra = null): ItemProntidao
    {
        return ItemProntidao::create([
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Projeto executivo',
            'ordem' => 0,
        ]);
    }

    // =========================================================================
    // 9) Queries / N+1
    // =========================================================================

    private function contarQueries(int $quantidadeAtividades): int
    {
        for ($i = 0; $i < $quantidadeAtividades; $i++) {
            $at = $this->criarAtividade(['external_uid' => "digest-n1-{$i}", 'inicio_planejado' => now()->addDays(3)]);
            $this->tornarNaoPronta($at);
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->service->consolidar($this->obra);

        return $queryCount;
    }

    public function test_quantidade_de_queries_nao_escala_proporcionalmente_ao_numero_de_atividades(): void
    {
        $queriesCom5 = $this->contarQueries(5);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $queriesCom20 = $this->contarQueries(20);

        $this->assertLessThan($queriesCom5 * 2, $queriesCom20, 'A quantidade de queries não deve crescer proporcionalmente ao número de atividades');
    }

    // =========================================================================
    // 10) Consumo da fonte real (CentralProntidaoQuery), nunca DTO manual
    // =========================================================================

    public function test_resumo_reflete_exatamente_o_que_a_central_prontidao_query_calcularia(): void
    {
        $at1 = $this->criarAtividade(['inicio_planejado' => now()->addDays(2)]);
        $this->tornarNaoPronta($at1);
        $at2 = $this->criarAtividade(['inicio_planejado' => now()->addDays(4)]);
        $this->tornarAtencao($at2);
        $at3 = $this->criarAtividade(['inicio_planejado' => now()->addDays(6)]); // pronta

        $query = new \App\Support\CentralProntidao\CentralProntidaoQuery();
        $horizonteAte = now()->addDays(DigestProntidao::HORIZONTE_DIAS);
        $viewsEsperadas = $query->paraObra($this->obra, $horizonteAte);

        $resumo = $this->service->consolidar($this->obra);

        $this->assertSame($viewsEsperadas->count(), $resumo->totalAtividadesUniverso);

        $naoProntaEsperada = $viewsEsperadas->filter(fn ($v) => $v->statusOperacional === StatusOperacionalProntidao::NaoPronta)->count();
        $atencaoEsperada = $viewsEsperadas->filter(fn ($v) => $v->statusOperacional === StatusOperacionalProntidao::Atencao)->count();

        $this->assertSame($naoProntaEsperada, $resumo->totalNaoPronta);
        $this->assertSame($atencaoEsperada, $resumo->totalAtencao);
    }

    // =========================================================================
    // Campos básicos do resultado
    // =========================================================================

    public function test_resumo_carrega_obra_e_horizonte_corretos(): void
    {
        $resumo = $this->service->consolidar($this->obra);

        $this->assertSame($this->obra->id, $resumo->obraId);
        $this->assertSame($this->obra->name, $resumo->obraNome);
        $this->assertSame(30, $resumo->horizonteDias);
        $this->assertInstanceOf(Carbon::class, $resumo->horizonteAte);
        $this->assertInstanceOf(Carbon::class, $resumo->geradoEm);
    }
}
