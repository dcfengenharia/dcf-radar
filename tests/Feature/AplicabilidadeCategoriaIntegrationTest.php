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
 * Ciclo 10 — confirma que HealthCheckEngine::catalogoRegras() (a fonte de
 * categoria pras 12 regras estruturais, que não têm categoria() no próprio
 * contrato — ver docblock do método) NUNCA diverge da categoria real usada
 * por cada regra dentro do seu próprio avaliar(). Roda o motor PADRÃO contra
 * fixtures já provadas (Fase 2B) que disparam STRUCT/LOGIC/SLACK de verdade
 * — se a constante CATEGORIA_REGRAS_ESTRUTURAIS do Engine algum dia
 * divergir do valor hardcoded dentro de uma regra, este teste quebra.
 */
class AplicabilidadeCategoriaIntegrationTest extends TestCase
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

    private function assertCatalogoBateComFindingsReais(string $fixture): void
    {
        $plano = $this->importer->analisar($this->fixture($fixture), $this->obra);
        $resultado = app(HealthCheckEngine::class)->avaliar($plano);
        $catalogo = app(HealthCheckEngine::class)->catalogoRegras();

        $this->assertNotEmpty($resultado->findings, "Fixture {$fixture} precisa disparar ao menos 1 finding pra este teste fazer sentido.");

        foreach ($resultado->findings as $finding) {
            $this->assertArrayHasKey($finding->regraId, $catalogo, "regra {$finding->regraId} ausente do catálogo do Engine.");
            $this->assertSame(
                $finding->categoria,
                $catalogo[$finding->regraId]['categoria'],
                "categoria de {$finding->regraId} no catalogoRegras() diverge da categoria real usada no finding."
            );
        }
    }

    public function test_catalogo_bate_com_findings_estruturais_reais(): void
    {
        $this->assertCatalogoBateComFindingsReais('cronograma_fase2b1_estrutural.xml');
    }

    public function test_catalogo_bate_com_findings_de_logica_reais(): void
    {
        $this->assertCatalogoBateComFindingsReais('cronograma_fase2b2b_logica.xml');
    }

    public function test_catalogo_bate_com_findings_de_slack_reais(): void
    {
        $this->assertCatalogoBateComFindingsReais('cronograma_fase2b3_slack.xml');
    }
}
