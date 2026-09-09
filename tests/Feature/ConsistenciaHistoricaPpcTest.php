<?php

namespace Tests\Feature;

use App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanal;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 24 (revisado) — Semana N comprometida e não concluída até
 * `semana_fim`; a atividade só é concluída fisicamente numa importação
 * posterior (Semana N+1). NENHUMA das telas que representam "cumprida/
 * não cumprida" para a Semana N pode divergir entre si — PPC canônico
 * (`⚡relatorios-restricoes.blade.php`), Minhas Programações
 * (`ProgramacaoSemanal::aderencia()`) e o card de PPC do próprio Plano
 * Semanal (`⚡plano-semanal.blade.php::ppc()`, quando o usuário navega até
 * a semana já fechada) precisam continuar concordando sobre o MESMO
 * compromisso histórico.
 */
class ConsistenciaHistoricaPpcTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    private function componentePpc()
    {
        return Livewire::test('pages::radar.relatorios-restricoes', ['obra' => $this->obra]);
    }

    private function componentePlanoSemanal(string $semanaInicio)
    {
        return Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
            ->set('semanaInicio', $semanaInicio);
    }

    public function test_conclusao_fisica_na_semana_seguinte_mantem_semana_anterior_nao_cumprida_em_todas_as_telas(): void
    {
        $semana36Inicio = Carbon::now()->subWeeks(4)->startOfWeek();
        $semana36Fim = $semana36Inicio->copy()->endOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
        ]);

        (new RegistrarComprometimentoSemanal())->execute(
            $this->obra, $semana36Inicio->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );

        $header36 = ProgramacaoSemanal::ativaPara($this->obra, $semana36Inicio->toDateString());

        // Atividade só é concluída DEPOIS do fim da Semana 36 (fato físico
        // real cai na semana seguinte) — nunca deveria "cumprir" a Semana 36.
        DB::table('atividades')->where('id', $atividade->id)->update([
            'status' => 'concluido',
            'concluido_em' => $semana36Fim->copy()->addDays(3),
        ]);

        // A) PPC canônico da Semana 36 continua NÃO CUMPRIDA.
        $ppc = $this->componentePpc()
            ->set('filtroDataInicio', $semana36Inicio->copy()->subWeek()->toDateString())
            ->set('filtroDataFim', $semana36Inicio->copy()->addWeek()->toDateString())
            ->instance()->ppcPorSemana;
        $this->assertCount(1, $ppc);
        $this->assertEquals(0.0, $ppc[0]['ppc_percentual'], 'A) PPC da Semana 36 deve continuar 0% (não cumprida).');

        // B) Minhas Programações (ProgramacaoSemanal::aderencia()) concorda.
        $this->assertEquals(0.0, $header36->fresh()->aderencia(), 'B) aderencia() da Semana 36 deve concordar com o PPC: não cumprida.');

        // C) Card de PPC do próprio Plano Semanal, ao navegar até a Semana 36, concorda.
        $ppcPlanoSemanal = $this->componentePlanoSemanal($semana36Inicio->toDateString())->instance()->ppc;
        $this->assertEquals(0, $ppcPlanoSemanal['percentual'], 'C) Badge do Plano Semanal também precisa mostrar não cumprida pra Semana 36.');
    }

    public function test_actual_finish_dentro_da_semana_importado_tardiamente_cumpre_retroativamente_em_todas_as_telas(): void
    {
        $semana36Inicio = Carbon::now()->subWeeks(4)->startOfWeek();
        $semana36Fim = $semana36Inicio->copy()->endOfWeek();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
        ]);

        (new RegistrarComprometimentoSemanal())->execute(
            $this->obra, $semana36Inicio->toDateString(), collect([$atividade]), OrigemProgramacaoSemanalItem::Manual
        );

        $header36 = ProgramacaoSemanal::ativaPara($this->obra, $semana36Inicio->toDateString());

        // ActualFinish CAI dentro da janela da Semana 36 — mesmo que o
        // sistema só tenha recebido essa informação depois, o fato físico
        // sempre foi verdade: a Semana 36 deve ser cumprida retroativamente.
        DB::table('atividades')->where('id', $atividade->id)->update([
            'status' => 'concluido',
            'concluido_em' => $semana36Fim->copy()->subDay(),
        ]);

        $ppc = $this->componentePpc()
            ->set('filtroDataInicio', $semana36Inicio->copy()->subWeek()->toDateString())
            ->set('filtroDataFim', $semana36Inicio->copy()->addWeek()->toDateString())
            ->instance()->ppcPorSemana;
        $this->assertEquals(100.0, $ppc[0]['ppc_percentual'], 'PPC deve reconhecer o fato físico real, mesmo importado tardiamente.');

        $this->assertEquals(100.0, $header36->fresh()->aderencia(), 'aderencia() concorda com o PPC.');

        $ppcPlanoSemanal = $this->componentePlanoSemanal($semana36Inicio->toDateString())->instance()->ppc;
        $this->assertEquals(100, $ppcPlanoSemanal['percentual'], 'Badge do Plano Semanal também concorda.');
    }
}
