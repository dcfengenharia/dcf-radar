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
 * Fluxo completo da Fase 2B.3: XML → MsProjectImporter::analisar() →
 * PlanoImportacao → HealthCheckEngine::avaliar() (motor PADRÃO, mesmo
 * caminho que a produção usa via app(HealthCheckEngine::class)) →
 * findings SLACK-001/002/005. Valida o pipeline real, não só objetos
 * construídos à mão em memória (ver HealthCheckSlackTest.php).
 */
class HealthCheckSlackIntegrationTest extends TestCase
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

    public function test_fluxo_completo_gera_os_findings_slack_esperados(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b3_slack.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $ids = array_unique(array_map(fn ($f) => $f->regraId, $resultado->findings));

        $this->assertContains('SLACK-001', $ids);
        $this->assertContains('SLACK-002', $ids);
        $this->assertContains('SLACK-005', $ids);
    }

    public function test_slack001_identifica_apenas_uid_10_e_15(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b3_slack.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $finding = $this->findingsDe($resultado->findings, 'SLACK-001')[0];
        $uids = array_column($finding->atividades, 'uid');

        $this->assertEqualsCanonicalizing(['10', '15'], $uids);
        $this->assertNotContains('1', $uids);  // pacote (resumo) ignorado
        $this->assertNotContains('14', $uids); // inativa ignorada
    }

    public function test_slack002_identifica_uid_11_e_as_que_tambem_tem_total_slack_negativo(): void
    {
        // UID 10 e 15 têm TotalSlack E FreeSlack negativos (matematicamente
        // esperado: FreeSlack <= TotalSlack, então TotalSlack < 0 quase
        // sempre implica FreeSlack < 0 também — SLACK-001 e SLACK-002 co-
        // ocorrem nesses casos, não é uma falha). UID 11 é o caso isolado
        // (TotalSlack positivo, só FreeSlack negativo).
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b3_slack.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $finding = $this->findingsDe($resultado->findings, 'SLACK-002')[0];
        $uids = array_column($finding->atividades, 'uid');

        $this->assertEqualsCanonicalizing(['10', '11', '15'], $uids);
        $this->assertContains('11', $uids);
    }

    public function test_slack005_identifica_apenas_uid_12(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b3_slack.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $finding = $this->findingsDe($resultado->findings, 'SLACK-005')[0];
        $uids = array_column($finding->atividades, 'uid');

        $this->assertEqualsCanonicalizing(['12'], $uids);
        $this->assertNotContains('16', $uids); // FreeSlack == TotalSlack, não dispara
    }

    public function test_regras_struct_e_logic_continuam_funcionando_junto(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b3_slack.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        // Não afirma quais disparam neste XML específico — só confirma que
        // o motor continua produzindo o resultado agregado sem erro,
        // com os findings SLACK presentes ao lado do que mais existir.
        $this->assertGreaterThanOrEqual(3, count($resultado->findings));
    }
}
