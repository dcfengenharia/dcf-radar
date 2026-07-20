<?php

namespace Tests\Feature;

use App\Models\DocumentoEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Services\CurvaEmissoesEngenharia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurvaEmissoesEngenhariaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);
    }

    public function test_obra_sem_documentos_retorna_series_vazias(): void
    {
        $resultado = (new CurvaEmissoesEngenharia())->calcular($this->obra);

        $this->assertSame(0, $resultado['total']);
        $this->assertSame([], $resultado['mensal']['labels']);
        $this->assertSame([], $resultado['mesesDisponiveis']);
    }

    public function test_mensal_acumula_previsto_e_realizado_corretamente(): void
    {
        // 4 documentos: 2 previstos em julho, 2 em agosto; só 1 emitido (julho).
        $doc1 = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D1', 'descricao' => 'D1', 'data_planejada' => '2026-07-05']);
        $doc1->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R0', 'descricao' => 'Emissão', 'data_emissao' => '2026-07-10']);

        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D2', 'descricao' => 'D2', 'data_planejada' => '2026-07-20']);
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D3', 'descricao' => 'D3', 'data_planejada' => '2026-08-01']);
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D4', 'descricao' => 'D4', 'data_planejada' => '2026-08-15']);

        $resultado = (new CurvaEmissoesEngenharia())->calcular($this->obra);

        $this->assertSame(4, $resultado['total']);
        $mensal = $resultado['mensal'];

        $this->assertSame(['2026-07-01', '2026-08-01'], $mensal['labels']);
        $this->assertSame([2, 2], $mensal['qtdPrevisto']);
        $this->assertSame([1, 0], $mensal['qtdRealizado']);
        // acumulado previsto: 2/4=50%, depois 4/4=100%
        $this->assertSame([50.0, 100.0], $mensal['pctPrevistoAcumulado']);
        // acumulado realizado: 1/4=25%, permanece 25% (nenhuma emissão em agosto)
        $this->assertSame([25.0, 25.0], $mensal['pctRealizadoAcumulado']);
    }

    public function test_meses_disponiveis_reflete_semanas_com_dado(): void
    {
        // Dias em pleno meio do mês, longe de virada de semana/mês, pra
        // não depender de onde a semana ISO começa (evita falso-negativo).
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D1', 'descricao' => 'D1', 'data_planejada' => '2026-07-15']);
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D2', 'descricao' => 'D2', 'data_planejada' => '2026-09-15']);

        $resultado = (new CurvaEmissoesEngenharia())->calcular($this->obra);

        $this->assertSame(['2026-07', '2026-09'], $resultado['mesesDisponiveis']);
    }

    public function test_documento_sem_data_planejada_nem_emissao_nao_entra_nas_series(): void
    {
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D1', 'descricao' => 'D1']);
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'D2', 'descricao' => 'D2', 'data_planejada' => '2026-07-05']);

        $resultado = (new CurvaEmissoesEngenharia())->calcular($this->obra);

        // total conta os 2 documentos (denominador do %), mas só D2 aparece na serie
        $this->assertSame(2, $resultado['total']);
        $this->assertSame(['2026-07-01'], $resultado['mensal']['labels']);
        $this->assertSame([1], $resultado['mensal']['qtdPrevisto']);
        $this->assertSame([50.0], $resultado['mensal']['pctPrevistoAcumulado']);
    }
}
