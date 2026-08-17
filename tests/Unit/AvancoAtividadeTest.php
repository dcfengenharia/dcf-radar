<?php

namespace Tests\Unit;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\Tenant;
use App\Models\Work;
use App\Services\AvancoAtividade;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 17, A.8 — testa App\Services\AvancoAtividade isolado, sem Livewire.
 * Fonte canônica única do "% Realizado" reaproveitada por atividades()
 * (tabela) e modalCurvaAtividade() (popup) — ver LookaheadTest.php pros
 * cenários de paridade tabela×popup (testes L, M, N, O, P).
 */
class AvancoAtividadeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function importacao(TipoCronogramaImportacao $tipo): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => $tipo->value,
            'importado_em' => now(),
        ]);
    }

    private function periodo(CronogramaImportacao $importacao, Atividade $atividade, SerieAvanco $serie, GranularidadePeriodo $gran, float $horas): void
    {
        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => $gran->value,
            'serie' => $serie->value,
            'periodo_inicio' => now()->subMonth()->startOfMonth(),
            'horas' => $horas,
        ]);
    }

    public function test_percentual_do_mapa_calcula_realizado_sobre_previsto(): void
    {
        TenantContext::actingAs($this->tenant, function () {
            $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $baseline = $this->importacao(TipoCronogramaImportacao::Baseline);
            $avanco = $this->importacao(TipoCronogramaImportacao::Avanco);
            $this->periodo($baseline, $atividade, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, 100);
            $this->periodo($avanco, $atividade, SerieAvanco::Realizado, GranularidadePeriodo::Mensal, 25);

            $servico = new AvancoAtividade();
            $previstos = $servico->hhPrevistoEmLote([$atividade->id], $baseline->id);
            $realizados = $servico->hhRealizadoEmLote([$atividade->id], $avanco->id);

            $this->assertEquals(25.0, $servico->percentualDoMapa($atividade->id, $previstos, $realizados));
        });
    }

    public function test_percentual_do_mapa_e_null_sem_baseline_previsto(): void
    {
        TenantContext::actingAs($this->tenant, function () {
            $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $avanco = $this->importacao(TipoCronogramaImportacao::Avanco);
            $this->periodo($avanco, $atividade, SerieAvanco::Realizado, GranularidadePeriodo::Mensal, 25);

            $servico = new AvancoAtividade();
            $previstos = $servico->hhPrevistoEmLote([$atividade->id], null); // sem baseline
            $realizados = $servico->hhRealizadoEmLote([$atividade->id], $avanco->id);

            $this->assertNull($servico->percentualDoMapa($atividade->id, $previstos, $realizados));
        });
    }

    public function test_percentual_do_mapa_e_null_sem_nenhum_registro_realizado(): void
    {
        TenantContext::actingAs($this->tenant, function () {
            $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $baseline = $this->importacao(TipoCronogramaImportacao::Baseline);
            $avanco = $this->importacao(TipoCronogramaImportacao::Avanco);
            $this->periodo($baseline, $atividade, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, 100);
            // Nenhum AvancoPeriodo Realizado criado.

            $servico = new AvancoAtividade();
            $previstos = $servico->hhPrevistoEmLote([$atividade->id], $baseline->id);
            $realizados = $servico->hhRealizadoEmLote([$atividade->id], $avanco->id);

            $this->assertNull($servico->percentualDoMapa($atividade->id, $previstos, $realizados));
        });
    }

    public function test_percentual_do_mapa_e_zero_quando_registro_existe_somando_zero(): void
    {
        TenantContext::actingAs($this->tenant, function () {
            $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $baseline = $this->importacao(TipoCronogramaImportacao::Baseline);
            $avanco = $this->importacao(TipoCronogramaImportacao::Avanco);
            $this->periodo($baseline, $atividade, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, 100);
            $this->periodo($avanco, $atividade, SerieAvanco::Realizado, GranularidadePeriodo::Mensal, 0);

            $servico = new AvancoAtividade();
            $previstos = $servico->hhPrevistoEmLote([$atividade->id], $baseline->id);
            $realizados = $servico->hhRealizadoEmLote([$atividade->id], $avanco->id);

            $this->assertSame(0.0, $servico->percentualDoMapa($atividade->id, $previstos, $realizados));
        });
    }

    public function test_percentual_do_mapa_e_null_quando_previsto_soma_zero(): void
    {
        TenantContext::actingAs($this->tenant, function () {
            $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $baseline = $this->importacao(TipoCronogramaImportacao::Baseline);
            $avanco = $this->importacao(TipoCronogramaImportacao::Avanco);
            $this->periodo($baseline, $atividade, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, 0);
            $this->periodo($avanco, $atividade, SerieAvanco::Realizado, GranularidadePeriodo::Mensal, 10);

            $servico = new AvancoAtividade();
            $previstos = $servico->hhPrevistoEmLote([$atividade->id], $baseline->id);
            $realizados = $servico->hhRealizadoEmLote([$atividade->id], $avanco->id);

            $this->assertNull($servico->percentualDoMapa($atividade->id, $previstos, $realizados));
        });
    }

    public function test_ignora_registros_de_granularidade_semanal_nunca_soma_junto_com_mensal(): void
    {
        TenantContext::actingAs($this->tenant, function () {
            $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $baseline = $this->importacao(TipoCronogramaImportacao::Baseline);
            $avanco = $this->importacao(TipoCronogramaImportacao::Avanco);
            $this->periodo($baseline, $atividade, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, 100);
            $this->periodo($avanco, $atividade, SerieAvanco::Realizado, GranularidadePeriodo::Mensal, 25);
            // Registros Semanais "concorrentes" com valores DIFERENTES — se
            // a query somasse as duas granularidades juntas, o resultado
            // estaria errado (dupla contagem).
            $this->periodo($baseline, $atividade, SerieAvanco::Previsto, GranularidadePeriodo::Semanal, 999);
            $this->periodo($avanco, $atividade, SerieAvanco::Realizado, GranularidadePeriodo::Semanal, 999);

            $servico = new AvancoAtividade();
            $previstos = $servico->hhPrevistoEmLote([$atividade->id], $baseline->id);
            $realizados = $servico->hhRealizadoEmLote([$atividade->id], $avanco->id);

            $this->assertEquals(25.0, $servico->percentualDoMapa($atividade->id, $previstos, $realizados));
        });
    }

    public function test_lote_isola_atividades_diferentes_sem_vazamento(): void
    {
        TenantContext::actingAs($this->tenant, function () {
            $atividadeA = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $atividadeB = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
            $baseline = $this->importacao(TipoCronogramaImportacao::Baseline);
            $avanco = $this->importacao(TipoCronogramaImportacao::Avanco);
            $this->periodo($baseline, $atividadeA, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, 100);
            $this->periodo($baseline, $atividadeB, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, 100);
            $this->periodo($avanco, $atividadeA, SerieAvanco::Realizado, GranularidadePeriodo::Mensal, 10);
            $this->periodo($avanco, $atividadeB, SerieAvanco::Realizado, GranularidadePeriodo::Mensal, 90);

            $servico = new AvancoAtividade();
            $previstos = $servico->hhPrevistoEmLote([$atividadeA->id, $atividadeB->id], $baseline->id);
            $realizados = $servico->hhRealizadoEmLote([$atividadeA->id, $atividadeB->id], $avanco->id);

            $this->assertEquals(10.0, $servico->percentualDoMapa($atividadeA->id, $previstos, $realizados));
            $this->assertEquals(90.0, $servico->percentualDoMapa($atividadeB->id, $previstos, $realizados));
        });
    }

    public function test_sem_importacao_retorna_mapas_vazios_sem_erro(): void
    {
        $servico = new AvancoAtividade();

        $this->assertTrue($servico->hhPrevistoEmLote(['qualquer-id'], null)->isEmpty());
        $this->assertTrue($servico->hhRealizadoEmLote(['qualquer-id'], null)->isEmpty());
    }
}
