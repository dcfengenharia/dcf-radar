<?php

namespace Tests\Feature;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PacoteTrabalhoRollupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function criarAtividadeComHh(?string $pacoteId, float $horas): Atividade
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_trabalho_id' => $pacoteId,
        ]);

        AvancoPeriodo::create([
            'tenant_id' => $this->tenant->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'atividade_id' => $atividade->id,
            'granularidade' => GranularidadePeriodo::Mensal->value,
            'serie' => SerieAvanco::Previsto->value,
            'periodo_inicio' => now()->startOfMonth(),
            'horas' => $horas,
        ]);

        return $atividade;
    }

    private function criarPacote(?string $parentId = null): PacoteTrabalho
    {
        return PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'parent_id' => $parentId,
        ]);
    }

    public function test_total_hh_baseline_soma_atividades_diretas(): void
    {
        $pacote = $this->criarPacote();
        $this->criarAtividadeComHh($pacote->id, 100.0);
        $this->criarAtividadeComHh($pacote->id, 50.0);

        $this->assertEquals(150.0, $pacote->totalHhBaseline($this->importacao->id));
    }

    public function test_total_hh_baseline_inclui_descendentes_aninhados(): void
    {
        $raiz = $this->criarPacote();
        $nivel1 = $this->criarPacote($raiz->id);
        $nivel2 = $this->criarPacote($nivel1->id);
        $nivel3 = $this->criarPacote($nivel2->id);

        $this->criarAtividadeComHh($raiz->id, 10.0);
        $this->criarAtividadeComHh($nivel1->id, 20.0);
        $this->criarAtividadeComHh($nivel2->id, 30.0);
        $this->criarAtividadeComHh($nivel3->id, 40.0);

        $this->assertEquals(100.0, $raiz->totalHhBaseline($this->importacao->id));
        $this->assertEquals(90.0, $nivel1->totalHhBaseline($this->importacao->id));
        $this->assertEquals(40.0, $nivel3->totalHhBaseline($this->importacao->id));
    }

    public function test_descendant_ids_nao_inclui_irmaos_nem_pai(): void
    {
        $raiz = $this->criarPacote();
        $filhoA = $this->criarPacote($raiz->id);
        $filhoB = $this->criarPacote($raiz->id);
        $neto = $this->criarPacote($filhoA->id);

        $descendentes = $filhoA->descendantIds();

        $this->assertContains($neto->id, $descendentes);
        $this->assertNotContains($filhoB->id, $descendentes);
        $this->assertNotContains($raiz->id, $descendentes);
    }

    public function test_total_hh_baseline_usa_importacao_mais_recente_quando_nao_informada(): void
    {
        $pacote = $this->criarPacote();
        $this->criarAtividadeComHh($pacote->id, 77.0);

        $this->assertEquals(77.0, $pacote->totalHhBaseline());
    }
}
