<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\CurvaAvanco;
use App\Imports\Contracts\ImportadorCronograma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurvaAvancoComLinhaBaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User   $user;
    private Work   $obra;

    private string $xmlV1;
    private string $xmlV2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra   = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);

        $this->xmlV1 = base_path('tests/Fixtures/cronograma_sample.xml');
        $this->xmlV2 = base_path('tests/Fixtures/cronograma_v2.xml');
    }

    private function importar(string $xmlPath): \App\Models\CronogramaImportacao
    {
        // "Ambos" — este teste exercita Previsto/Realizado da MESMA
        // importação (comportamento pré-separação Linha de Base x Avanço),
        // não a separação em si.
        $importer = app(\App\Imports\Contracts\ImportadorCronograma::class);
        $plano    = $importer->analisar($xmlPath, $this->obra, TipoCronogramaImportacao::Ambos);
        return $importer->aplicar($plano, $this->obra, $this->user->id, basename($xmlPath), TipoCronogramaImportacao::Ambos);
    }

    private function calcular(
        SerieAvanco $serie,
        GranularidadePeriodo $gran,
        ?string $linhaBaseId = null
    ): array {
        return app(CurvaAvanco::class)->calcular(
            $this->obra,
            $serie,
            $gran,
            null,
            $linhaBaseId,
        );
    }

    public function test_previsto_com_linha_base_usa_import_especifico(): void
    {
        // Importa v1 (Baseline Work = 40HH + 24HH = 64HH)
        $importV1 = $this->importar($this->xmlV1);

        // Cria linha de base apontando para v1
        $lb = LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'BL0 – Contrato',
            'cronograma_importacao_id' => $importV1->id,
        ]);

        // Importa v2 (Baseline Work = 50HH, Ativ2 removida → total=50HH)
        $this->importar($this->xmlV2);

        // Calcula Previsto SEM baseline → deve usar v2 (50HH)
        $semLb = $this->calcular(SerieAvanco::Previsto, GranularidadePeriodo::Mensal);
        $totalSemLb = array_sum(array_column($semLb, 'horas'));
        $this->assertEqualsWithDelta(50.0, $totalSemLb, 0.01, 'Sem LB deve usar importação mais recente (v2 = 50HH)');

        // Calcula Previsto COM baseline → deve usar v1 (64HH)
        $comLb = $this->calcular(SerieAvanco::Previsto, GranularidadePeriodo::Mensal, $lb->id);
        $totalComLb = array_sum(array_column($comLb, 'horas'));
        $this->assertEqualsWithDelta(64.0, $totalComLb, 0.01, 'Com LB deve usar importação v1 (64HH)');
    }

    public function test_previsto_sem_linha_base_usa_import_mais_recente(): void
    {
        $this->importar($this->xmlV1);
        $this->importar($this->xmlV2);

        $periodos = $this->calcular(SerieAvanco::Previsto, GranularidadePeriodo::Mensal);
        $total    = array_sum(array_column($periodos, 'horas'));

        // v2 tem apenas Ativ1 com baseline=50HH
        $this->assertEqualsWithDelta(50.0, $total, 0.01, 'Sem LB deve usar última importação (v2)');
    }

    public function test_realizado_ignora_linha_base(): void
    {
        // Importa v1 (Realizado = 16HH + 8HH = 24HH)
        $importV1 = $this->importar($this->xmlV1);

        $lb = LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'BL0',
            'cronograma_importacao_id' => $importV1->id,
        ]);

        // Importa v2 (Realizado = apenas Ativ1 = 16HH)
        $this->importar($this->xmlV2);

        // Realizado com linhaBaseId informado → ignora LB, usa última importação (v2 = 16HH)
        $periodos = $this->calcular(SerieAvanco::Realizado, GranularidadePeriodo::Mensal, $lb->id);
        $total    = array_sum(array_column($periodos, 'horas'));

        $this->assertEqualsWithDelta(16.0, $total, 0.01, 'Realizado ignora LB e usa última importação');
    }
}
