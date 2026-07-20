<?php

namespace Tests\Feature;

use App\Models\Feriado;
use App\Models\Tenant;
use App\Models\Work;
use App\Support\DiasUteisCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiasUteisCalculatorTest extends TestCase
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

    public function test_somar_pula_fim_de_semana(): void
    {
        // Sexta-feira 2026-07-10 + 1 dia útil deve cair na segunda 2026-07-13,
        // pulando sábado e domingo.
        $calc = DiasUteisCalculator::paraObra($this->obra);

        $resultado = $calc->somar(Carbon::parse('2026-07-10'), 1);

        $this->assertTrue($resultado->isSameDay(Carbon::parse('2026-07-13')));
    }

    public function test_subtrair_pula_fim_de_semana(): void
    {
        // Segunda-feira 2026-07-13 - 1 dia útil deve cair na sexta 2026-07-10.
        $calc = DiasUteisCalculator::paraObra($this->obra);

        $resultado = $calc->subtrair(Carbon::parse('2026-07-13'), 1);

        $this->assertTrue($resultado->isSameDay(Carbon::parse('2026-07-10')));
    }

    public function test_subtrair_pula_feriado_configurado(): void
    {
        // Terça 2026-07-14 é feriado; subtrair 1 dia útil de quarta 2026-07-15
        // deve pular direto pra segunda 2026-07-13 (pula feriado + fim de semana anterior não se aplica aqui).
        Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data' => '2026-07-14',
            'descricao' => 'Feriado de teste',
        ]);

        $calc = DiasUteisCalculator::paraObra($this->obra);

        $resultado = $calc->subtrair(Carbon::parse('2026-07-15'), 1);

        $this->assertTrue($resultado->isSameDay(Carbon::parse('2026-07-13')));
    }

    public function test_feriado_caindo_num_fim_de_semana_nao_afeta_contagem(): void
    {
        // Sábado 2026-07-11 já não conta mesmo sem feriado; marcar feriado
        // nele não deve mudar o resultado de somar 1 dia útil a partir de sexta.
        Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data' => '2026-07-11',
            'descricao' => 'Feriado em cima de sábado',
        ]);

        $calc = DiasUteisCalculator::paraObra($this->obra);

        $resultado = $calc->somar(Carbon::parse('2026-07-10'), 1);

        $this->assertTrue($resultado->isSameDay(Carbon::parse('2026-07-13')));
    }

    public function test_prazo_zero_retorna_a_mesma_data(): void
    {
        $calc = DiasUteisCalculator::paraObra($this->obra);
        $data = Carbon::parse('2026-07-13');

        $this->assertTrue($calc->somar($data, 0)->isSameDay($data));
        $this->assertTrue($calc->subtrair($data, 0)->isSameDay($data));
    }

    public function test_e_dia_util_reflete_fim_de_semana_e_feriado(): void
    {
        Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'data' => '2026-07-14',
            'descricao' => 'Feriado de teste',
        ]);

        $calc = DiasUteisCalculator::paraObra($this->obra);

        $this->assertFalse($calc->eDiaUtil(Carbon::parse('2026-07-11'))); // sábado
        $this->assertFalse($calc->eDiaUtil(Carbon::parse('2026-07-14'))); // feriado
        $this->assertTrue($calc->eDiaUtil(Carbon::parse('2026-07-13'))); // segunda comum
    }

    public function test_feriados_sao_isolados_por_obra(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        Feriado::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $outraObra->id,
            'data' => '2026-07-14',
            'descricao' => 'Feriado de outra obra',
        ]);

        $calc = DiasUteisCalculator::paraObra($this->obra);

        $this->assertTrue($calc->eDiaUtil(Carbon::parse('2026-07-14')));
    }
}
