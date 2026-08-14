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
 * Fluxo completo da Fase 2B.1: XML → MsProjectImporter::analisar() →
 * PlanoImportacao → HealthCheckEngine::avaliar() (motor PADRÃO, com as 24
 * regras da Fase 1 + as 5 regras estruturais juntas — mesmo caminho que a
 * produção usa via app(HealthCheckEngine::class)) → findings. Valida que
 * os dados estruturais capturados na Fase 2A alimentam corretamente as
 * regras STRUCT-001..005 num cenário realista, não só via fixtures
 * construídas à mão em memória (ver HealthCheckEstruturalTest.php).
 */
class HealthCheckEstruturalIntegrationTest extends TestCase
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

    private function achar(array $findings, string $regraId)
    {
        foreach ($findings as $f) {
            if ($f->regraId === $regraId) {
                return $f;
            }
        }
        return null;
    }

    public function test_fluxo_completo_gera_todos_os_findings_estruturais_esperados(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $ids = array_map(fn ($f) => $f->regraId, $resultado->findings);

        $this->assertContains('STRUCT-001', $ids);
        $this->assertContains('STRUCT-002', $ids);
        $this->assertContains('STRUCT-003', $ids);
        $this->assertContains('STRUCT-004', $ids);
        $this->assertContains('STRUCT-005', $ids);
    }

    public function test_struct001_identifica_inicio_das_duas_redes(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $uids = array_column($this->achar($resultado->findings, 'STRUCT-001')->atividades, 'uid');
        $this->assertEqualsCanonicalizing(['100', '200'], $uids);
    }

    public function test_struct002_identifica_fim_das_duas_redes(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $uids = array_column($this->achar($resultado->findings, 'STRUCT-002')->atividades, 'uid');
        $this->assertEqualsCanonicalizing(['102', '202'], $uids);
    }

    public function test_struct003_identifica_isolada_marco_e_sucessora_de_inativa(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $atividades = $this->achar($resultado->findings, 'STRUCT-003')->atividades;
        $uids = array_column($atividades, 'uid');
        $this->assertEqualsCanonicalizing(['300', '500', '601'], $uids);

        $marco = collect($atividades)->firstWhere('uid', '500');
        $this->assertSame('Marco', $marco['tipo']);
    }

    public function test_struct004_identifica_as_redes_desconectadas(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $findingsStruct004 = array_values(array_filter($resultado->findings, fn ($f) => $f->regraId === 'STRUCT-004'));

        // 3 componentes qualificados (tamanho >= 2): Rede A (3), Rede B (3), par do ciclo (2).
        $this->assertCount(3, $findingsStruct004);

        $tamanhos = array_map(fn ($f) => $f->atividades[0]['quantidade_atividades'], $findingsStruct004);
        sort($tamanhos);
        $this->assertSame([2, 3, 3], $tamanhos);
    }

    public function test_struct005_identifica_o_ciclo(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        $finding = $this->achar($resultado->findings, 'STRUCT-005');
        $this->assertSame(\App\Enums\HealthCheckSeveridade::Critico, $finding->severidade);
        $uids = array_column($finding->atividades[0]['atividades'], 'uid');
        $this->assertEqualsCanonicalizing(['400', '401'], $uids);
    }

    public function test_atividade_inativa_nunca_aparece_em_nenhum_finding_estrutural(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        foreach ($resultado->findings as $finding) {
            if (!str_starts_with($finding->regraId, 'STRUCT-')) {
                continue;
            }
            $bruto = json_encode($finding->atividades);
            $this->assertStringNotContainsString('"600"', $bruto);
        }
    }

    public function test_tarefas_resumo_nunca_aparecem_em_nenhum_finding_estrutural(): void
    {
        $plano = $this->importer->analisar($this->fixture('cronograma_fase2b1_estrutural.xml'), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);

        foreach ($resultado->findings as $finding) {
            if (!str_starts_with($finding->regraId, 'STRUCT-')) {
                continue;
            }
            $bruto = json_encode($finding->atividades);
            $this->assertStringNotContainsString('"1"', $bruto);
        }
    }
}
