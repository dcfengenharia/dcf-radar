<?php

namespace Tests\Feature;

use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\HealthCheckEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fluxo completo da Fase 2B.2B: XML → MsProjectImporter::analisar() →
 * PlanoImportacao → HealthCheckEngine::avaliar() (motor PADRÃO, mesmo
 * caminho que a produção usa via app(HealthCheckEngine::class)) →
 * findings LOGIC-005/008/009/010. Valida o pipeline real, não só fixtures
 * construídas à mão em memória (ver HealthCheckLogicaTest.php).
 */
class HealthCheckLogicaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ImportadorCronograma $importer;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->importer = app(ImportadorCronograma::class);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    private function findingsDe(array $findings, string $regraId): array
    {
        return array_values(array_filter($findings, fn ($f) => $f->regraId === $regraId));
    }

    public function test_fluxo_completo_gera_todos_os_findings_logic_esperados(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b2b_logica.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $ids = array_unique(array_map(fn ($f) => $f->regraId, $resultado->findings));

        $this->assertContains('LOGIC-005', $ids);
        $this->assertContains('LOGIC-008', $ids);
        $this->assertContains('LOGIC-009', $ids);
        $this->assertContains('LOGIC-010', $ids);
    }

    public function test_logic005_identifica_os_tres_casos(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b2b_logica.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $findings = $this->findingsDe($resultado->findings, 'LOGIC-005');
        $this->assertCount(3, $findings);

        $severidades = array_map(fn ($f) => $f->severidade->value, $findings);
        sort($severidades);
        $this->assertSame(['informativo', 'medio', 'medio'], $severidades);
    }

    public function test_logic008_identifica_conflito_de_datas_reais(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b2b_logica.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $finding = $this->findingsDe($resultado->findings, 'LOGIC-008')[0];
        $this->assertSame('40', $finding->atividades[0]['predecessora']['uid']);
        $this->assertSame('41', $finding->atividades[0]['sucessora']['uid']);
    }

    public function test_logic009_identifica_vinculo_com_pacote(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b2b_logica.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $finding = $this->findingsDe($resultado->findings, 'LOGIC-009')[0];
        $this->assertSame('1', $finding->atividades[0]['predecessora']['uid']);
        $this->assertSame('50', $finding->atividades[0]['sucessora']['uid']);
        $this->assertSame('predecessora_e_resumo', $finding->atividades[0]['direcao']);
    }

    public function test_logic010_identifica_predecessora_inativa(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b2b_logica.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $finding = $this->findingsDe($resultado->findings, 'LOGIC-010')[0];
        $this->assertSame('61', $finding->atividades[0]['sucessora']['uid']);
        $this->assertSame('60', $finding->atividades[0]['predecessoras_inativas'][0]['uid']);
    }

    public function test_regras_struct_da_fase_2b1_continuam_funcionando_junto(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b2b_logica.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $ids = array_unique(array_map(fn ($f) => $f->regraId, $resultado->findings));

        // Não afirma quais disparam neste XML específico, só que o motor
        // continua produzindo findings STRUCT-* normalmente junto dos LOGIC-*.
        $existeAlgumStruct = count(array_filter($ids, fn ($id) => str_starts_with($id, 'STRUCT-'))) > 0;
        $this->assertTrue($existeAlgumStruct);
    }
}
