<?php

namespace Tests\Feature\Auditoria;

use App\Enums\Papel;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanal;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Tempo\RelogioNegocio;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A2, Seção 4 (Timezone) — regressão dos pontos de
 * "vencida"/"semana atual" corrigidos pra usar RelogioNegocio (fuso de
 * negócio, America/Sao_Paulo) em vez de now()/Carbon::today()/isPast() no
 * fuso padrão da app (UTC). Todo cenário pina o relógio de teste na janela
 * onde UTC e Brasil discordam sobre "que dia é hoje" (21h00-23h59, horário
 * de Brasília) — a única janela em que o bug antigo se manifestava.
 */
class TimezoneNegocioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarRestricao(?string $prazoLimite, bool $aberta = true): Restricao
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ]);

        return Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'prazo_limite' => $prazoLimite,
            'status' => $aberta ? StatusRestricao::Aberta->value : StatusRestricao::Resolvida->value,
        ]);
    }

    /**
     * 2026-12-16 01:00:00 UTC = 2026-12-15 22:00:00 America/Sao_Paulo —
     * ainda dia 15 no Brasil, já dia 16 em UTC.
     */
    private function pinarNaJanelaDeVirada(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 16, 1, 0, 0, 'UTC'));
    }

    public function test_restricao_com_prazo_hoje_no_brasil_nao_esta_vencida_na_janela_de_virada_utc(): void
    {
        $this->pinarNaJanelaDeVirada();

        $r = $this->criarRestricao('2026-12-15');

        // Bug antigo: prazo_limite->isPast() já seria true aqui, porque
        // meia-noite UTC do dia 16 já passou — mas no Brasil ainda é 15,
        // o prazo NÃO deveria estar vencido ainda.
        $this->assertFalse($r->estaVencida());
    }

    public function test_restricao_com_prazo_ontem_continua_vencida(): void
    {
        $this->pinarNaJanelaDeVirada();

        $r = $this->criarRestricao('2026-12-14');

        $this->assertTrue($r->estaVencida());
    }

    public function test_restricao_sem_prazo_nunca_esta_vencida(): void
    {
        $this->pinarNaJanelaDeVirada();

        $r = $this->criarRestricao(null);

        $this->assertFalse($r->estaVencida());
    }

    public function test_restricao_vence_de_verdade_apos_meia_noite_brasileira(): void
    {
        $r = $this->criarRestricao('2026-12-15');

        // Ainda 15/12 no Brasil (22h) — não vencida.
        $this->pinarNaJanelaDeVirada();
        $this->assertFalse($r->estaVencida());

        // 00:00:01 do dia 16, horário de Brasília (03:00:01 UTC) — agora
        // sim, o dia 15 (Brasil) terminou de verdade.
        Carbon::setTestNow(Carbon::create(2026, 12, 16, 3, 0, 1, 'UTC'));
        $this->assertTrue($r->fresh()->estaVencida());
    }

    public function test_atividade_termino_esta_vencido_usa_calendario_brasileiro(): void
    {
        $this->pinarNaJanelaDeVirada();

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'data_termino' => '2026-12-15',
        ]);

        $this->assertFalse($atividade->terminoEstaVencido());
    }

    public function test_work_prazo_baseline_esta_vencido_usa_calendario_brasileiro(): void
    {
        $this->pinarNaJanelaDeVirada();

        $obra = Work::factory()->create([
            'tenant_id' => $this->tenant->id,
            'end_date_baseline' => '2026-12-15',
        ]);

        $this->assertFalse($obra->prazoBaselineEstaVencido());
    }

    public function test_plano_semanal_default_para_semana_correta_na_noite_de_domingo(): void
    {
        // 2026-12-14 é uma segunda-feira; 2026-12-20 é o domingo daquela
        // mesma semana. 2026-12-21 01:00:00 UTC = 2026-12-20 22:00:00
        // America/Sao_Paulo — ainda domingo (2026-12-14 é a semana
        // corrente) no Brasil, já segunda-feira seguinte em UTC.
        Carbon::setTestNow(Carbon::create(2026, 12, 21, 1, 0, 0, 'UTC'));

        $componente = Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra]);

        // Bug antigo: Carbon::now()->startOfWeek() (UTC) já teria virado
        // pra 2026-12-21 (a semana SEGUINTE) — a tela abriria mostrando a
        // semana errada por padrão durante toda a noite de domingo.
        $this->assertSame('2026-12-14', $componente->get('semanaInicio'));
    }

    public function test_relogio_negocio_inicio_da_semana_atual_nao_vira_cedo_demais(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 12, 21, 1, 0, 0, 'UTC'));

        $this->assertSame('2026-12-14', RelogioNegocio::inicioDaSemanaAtual()->toDateString());
        // Documenta o comportamento antigo (ainda usado deliberadamente
        // noutros pontos que normalizam uma data EXPLÍCITA, nunca "agora")
        // pra deixar claro que o bug é real e mensurável.
        $this->assertSame('2026-12-21', Carbon::now()->startOfWeek()->toDateString());
    }

    /**
     * Auditoria Pré-Produção A2.2, Seção 10 — achado real e PERMANENTE (não
     * só de horário-limite): `⚡plano-semanal.blade.php::
     * estaVisualizandoSemanaPassada()` comparava `Carbon::parse($semanaInicio)`
     * (string 'Y-m-d', hidratada à meia-noite no fuso PADRÃO da app, UTC)
     * via `.lt()` (instante absoluto) contra `RelogioNegocio::
     * inicioDaSemanaAtual()` (meia-noite America/Sao_Paulo = 03:00 UTC) —
     * uma diferença de ~3h que fazia a SEMANA ATUAL ser classificada como
     * "passada" (e uma Programação já existente pra ela, `congelada`)
     * SEMPRE, em qualquer hora do dia, contrariando o próprio contrato
     * documentado na classe ("a semana ATUAL nunca é tratada como
     * congelada"). Corrigido comparando só DATA DE CALENDÁRIO
     * (`$this->semanaInicio < RelogioNegocio::inicioDaSemanaAtual()
     * ->toDateString()`).
     *
     * Esta matriz prova, nos 4 instantes explicitamente pedidos (domingo
     * 20:59/21:01/23:59 BRT e segunda 00:01 BRT — a fronteira onde UTC e o
     * calendário brasileiro discordam sobre "que dia é hoje"), que:
     * (1) `RelogioNegocio::inicioDaSemanaAtual()` nunca vira a semana cedo
     * demais por causa do UTC; (2) uma Programação já existente pra semana
     * de referência (2026-12-14) só passa a ser tratada como `congelada`
     * quando a semana de fato virou passado no calendário brasileiro —
     * nunca antes disso, mesmo no minuto imediatamente anterior à virada.
     *
     * 2026-12-14 (segunda) a 2026-12-20 (domingo) é a semana de referência;
     * 2026-12-21 (segunda seguinte) é quando ela genuinamente vira passado.
     *
     * @dataProvider fronteiraSemanaBrasilProvider
     */
    public function test_semana_atual_nao_vira_congelada_precocemente_na_fronteira_utc_brasil(
        string $instanteBrt,
        string $inicioSemanaEsperado,
        bool $deveEstarCongelada,
    ): void {
        Carbon::setTestNow(Carbon::parse($instanteBrt, 'America/Sao_Paulo'));

        $this->assertSame(
            $inicioSemanaEsperado,
            RelogioNegocio::inicioDaSemanaAtual()->toDateString(),
            "Instante BRT '{$instanteBrt}': RelogioNegocio::inicioDaSemanaAtual() deveria resolver pra {$inicioSemanaEsperado}.",
        );

        ProgramacaoSemanal::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'semana_inicio' => '2026-12-14',
            'semana_fim' => '2026-12-20',
            'congelada_em' => Carbon::now(),
            'criado_por' => $this->user->id,
        ]);

        $componente = Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra])
            ->set('semanaInicio', '2026-12-14');

        $this->assertSame(
            $deveEstarCongelada,
            $componente->instance()->semanaEstaCongelada,
            "Instante BRT '{$instanteBrt}': semanaEstaCongelada deveria ser ".var_export($deveEstarCongelada, true).'.',
        );
    }

    public static function fronteiraSemanaBrasilProvider(): array
    {
        return [
            'domingo 20:59 BRT — ainda dentro da semana corrente, antes da virada UTC' => [
                '2026-12-20 20:59:00', '2026-12-14', false,
            ],
            'domingo 21:01 BRT — UTC já virou o dia, mas ainda é domingo no Brasil' => [
                '2026-12-20 21:01:00', '2026-12-14', false,
            ],
            'domingo 23:59 BRT — último minuto da semana corrente no Brasil' => [
                '2026-12-20 23:59:00', '2026-12-14', false,
            ],
            'segunda 00:01 BRT — a semana vira de verdade' => [
                '2026-12-21 00:01:00', '2026-12-21', true,
            ],
        ];
    }
}
