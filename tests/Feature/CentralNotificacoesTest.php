<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\SeveridadeSituacao;
use App\Enums\TipoSituacaoGerencial;
use App\Models\SituacaoOcorrencia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\SituacaoGerencialNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.3 — Central de Notificações in-app (Seção 10: sino
 * no shell, badge, lista recente, página completa, filtros, lida/não
 * lida) e as garantias de escopo por obra/segurança (Seção 12/23/24/29).
 * Nunca reimplementa a lógica de `SincronizarSituacoesGerenciais`
 * (coberta em `SincronizarSituacoesGerenciaisTest.php`) — usa
 * `App\Notifications\SituacaoGerencialNotification` diretamente com
 * payloads controlados, o mesmo mecanismo, só sem passar pela derivação
 * completa de `SituacoesGerenciaisQuery`.
 */
class CentralNotificacoesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private Work $obraB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-01'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->vincularObra($this->obraB, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Envia diretamente pra um usuário, mesma proteção de broadcast já
     * documentada em `SincronizarSituacoesGerenciais::
     * enviarComIdempotencia()` — este teste nunca passa pela derivação
     * completa, só quer controlar o payload persistido.
     */
    private function enviar(User $user, array $overrides = []): void
    {
        $payload = array_merge([
            'titulo' => 'Situação de teste',
            'mensagem' => 'Mensagem de teste',
            'icone' => 'bx-error-circle',
            'cor' => 'warning',
            'tipo' => TipoSituacaoGerencial::MaterialCritico->value,
            'severidade' => SeveridadeSituacao::Atencao->value,
            'obra_id' => $this->obra->id,
            'obra_nome' => $this->obra->name,
            'ocorrencia_id' => null,
            'motivo' => 'primeira_deteccao',
            'episodio' => 1,
            'entidade_tipo' => 'Atividade',
            'entidade_id' => 'x',
            'contexto' => [],
            'deep_link' => ['rota' => 'notificacoes.index', 'parametros' => []],
        ], $overrides);

        try {
            $user->notify(new SituacaoGerencialNotification($payload));
        } catch (\Throwable $e) {
            // canal broadcast inalcançável no ambiente de teste — mesmo
            // achado documentado em SincronizarSituacoesGerenciais; o
            // canal database (avaliado antes) já persistiu normalmente.
        }
    }

    // =========================================================
    // Sino/dropdown — badge + lista recente
    // =========================================================

    public function test_sino_mostra_badge_de_nao_lidas_e_lista_recente(): void
    {
        $this->enviar($this->user);
        $this->enviar($this->user);

        $componente = Livewire::test('notificacoes-dropdown');
        $this->assertSame(2, $componente->instance()->naoLidas);
        $this->assertCount(2, $componente->instance()->notificacoes);
    }

    public function test_sino_marcar_lida_atualiza_badge(): void
    {
        $this->enviar($this->user);
        $componente = Livewire::test('notificacoes-dropdown');
        $id = $componente->instance()->notificacoes->first()->id;

        $componente->call('marcarLida', $id);

        $this->assertSame(0, $componente->instance()->naoLidas);
    }

    // =========================================================
    // Central completa — filtros, lida/não lida, estado
    // =========================================================

    public function test_central_lista_e_filtra_por_lida_nao_lida(): void
    {
        $this->enviar($this->user);
        $notificacaoLida = $this->user->notifications()->first();
        $notificacaoLida->markAsRead();
        $this->enviar($this->user);

        $componente = Livewire::test('pages::notificacoes.index');
        $this->assertCount(2, $componente->instance()->notificacoes);

        $componente->set('filtro', 'nao_lidas');
        $this->assertCount(1, $componente->instance()->notificacoes);

        $componente->set('filtro', 'lidas');
        $this->assertCount(1, $componente->instance()->notificacoes);
    }

    public function test_central_filtra_por_obra_tipo_e_severidade(): void
    {
        $this->enviar($this->user, ['obra_id' => $this->obra->id, 'tipo' => TipoSituacaoGerencial::MaterialCritico->value, 'severidade' => SeveridadeSituacao::Alta->value]);
        $this->enviar($this->user, ['obra_id' => $this->obraB->id, 'tipo' => TipoSituacaoGerencial::PedidoAtrasado->value, 'severidade' => SeveridadeSituacao::Critica->value]);

        $componente = Livewire::test('pages::notificacoes.index');
        $this->assertCount(2, $componente->instance()->notificacoes);

        $componente->set('obraFiltro', $this->obraB->id);
        $this->assertCount(1, $componente->instance()->notificacoes);
        $componente->set('obraFiltro', '');

        $componente->set('tipoFiltro', TipoSituacaoGerencial::PedidoAtrasado->value);
        $this->assertCount(1, $componente->instance()->notificacoes);
        $componente->set('tipoFiltro', '');

        $componente->set('severidadeFiltro', SeveridadeSituacao::Critica->value);
        $this->assertCount(1, $componente->instance()->notificacoes);
    }

    public function test_central_mostra_estado_da_ocorrencia_ativa_e_resolvida(): void
    {
        $ocorrenciaAtiva = SituacaoOcorrencia::create([
            'obra_id' => $this->obra->id, 'tipo' => TipoSituacaoGerencial::MaterialCritico->value,
            'chave_logica' => 'x1', 'status' => 'ativa', 'episodio' => 1,
            'severidade_atual' => 'atencao', 'severidade_peso_comunicado' => 1,
            'entidade_tipo' => 'Atividade', 'entidade_id' => 'a1', 'descricao_atual' => 'd',
            'primeira_deteccao_em' => now(), 'ultima_deteccao_em' => now(),
        ]);
        $ocorrenciaResolvida = SituacaoOcorrencia::create([
            'obra_id' => $this->obra->id, 'tipo' => TipoSituacaoGerencial::MaterialCritico->value,
            'chave_logica' => 'x2', 'status' => 'resolvida', 'episodio' => 1,
            'severidade_atual' => 'atencao', 'severidade_peso_comunicado' => 1,
            'entidade_tipo' => 'Atividade', 'entidade_id' => 'a2', 'descricao_atual' => 'd',
            'primeira_deteccao_em' => now(), 'ultima_deteccao_em' => now(), 'resolvida_em' => now(),
        ]);

        $this->enviar($this->user, ['ocorrencia_id' => $ocorrenciaAtiva->id]);
        $this->enviar($this->user, ['ocorrencia_id' => $ocorrenciaResolvida->id]);

        $componente = Livewire::test('pages::notificacoes.index');
        $estados = $componente->instance()->estadosPorOcorrencia;

        $this->assertSame('ativa', $estados->get($ocorrenciaAtiva->id)->value);
        $this->assertSame('resolvida', $estados->get($ocorrenciaResolvida->id)->value);
    }

    public function test_central_marcar_todas_lidas_afeta_so_o_usuario_atual(): void
    {
        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $outroUsuario, Papel::GerentePlanejamento->value);
        $this->enviar($outroUsuario);
        $this->enviar($this->user);
        $this->enviar($this->user);

        Livewire::test('pages::notificacoes.index')->call('marcarTodasLidas');

        $this->assertSame(0, $this->user->unreadNotifications()->count());
        $this->assertSame(1, $outroUsuario->unreadNotifications()->count());
    }

    // =========================================================
    // Seção 12/23 — badge/lista respeitam acesso atual à obra
    // =========================================================

    public function test_badge_e_lista_excluem_notificacao_de_obra_sem_acesso_atual(): void
    {
        $obraSemAcesso = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // nunca vinculada ao usuário
        $this->enviar($this->user, ['obra_id' => $obraSemAcesso->id]);
        $this->enviar($this->user, ['obra_id' => $this->obra->id]);

        $dropdown = Livewire::test('notificacoes-dropdown');
        $this->assertSame(1, $dropdown->instance()->naoLidas);
        $this->assertCount(1, $dropdown->instance()->notificacoes);

        $central = Livewire::test('pages::notificacoes.index');
        $this->assertCount(1, $central->instance()->notificacoes);
    }

    public function test_notificacao_sem_obra_nunca_e_escondida_pelo_escopo(): void
    {
        $this->enviar($this->user, ['obra_id' => null]); // legado sem conceito de obra

        $dropdown = Livewire::test('notificacoes-dropdown');
        $this->assertSame(1, $dropdown->instance()->naoLidas);
    }

    // =========================================================
    // Segurança — Seção 24
    // =========================================================

    public function test_notificacao_de_outro_usuario_nunca_aparece_na_central(): void
    {
        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $outroUsuario, Papel::GerentePlanejamento->value);
        $this->enviar($outroUsuario);

        $componente = Livewire::test('pages::notificacoes.index');
        $this->assertCount(0, $componente->instance()->notificacoes);
    }

    public function test_notificacao_de_outro_tenant_nunca_aparece(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUsuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroUsuario) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroUsuario->tenant_id]);
            $this->vincularObra($outraObra, $outroUsuario, Papel::GerentePlanejamento->value);
            $this->enviar($outroUsuario);
        });

        $componente = Livewire::test('pages::notificacoes.index');
        $this->assertCount(0, $componente->instance()->notificacoes);
    }

    // =========================================================
    // Performance — Seção 29, zero N+1
    // =========================================================

    public function test_performance_badge_e_lista_nao_escalam_com_volume(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->enviar($this->user);
        }

        DB::enableQueryLog();
        Livewire::test('notificacoes-dropdown')->instance()->naoLidas;
        $queries10 = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog(); // nunca medir as queries de inserção das 90 abaixo

        for ($i = 0; $i < 90; $i++) {
            $this->enviar($this->user);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test('notificacoes-dropdown')->instance()->naoLidas;
        $queries100 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queries10, $queries100);
    }

    public function test_performance_estados_por_ocorrencia_1_query_por_pagina(): void
    {
        $ocorrencia = SituacaoOcorrencia::create([
            'obra_id' => $this->obra->id, 'tipo' => TipoSituacaoGerencial::MaterialCritico->value,
            'chave_logica' => 'perf', 'status' => 'ativa', 'episodio' => 1,
            'severidade_atual' => 'atencao', 'severidade_peso_comunicado' => 1,
            'entidade_tipo' => 'Atividade', 'entidade_id' => 'a1', 'descricao_atual' => 'd',
            'primeira_deteccao_em' => now(), 'ultima_deteccao_em' => now(),
        ]);

        for ($i = 0; $i < 20; $i++) {
            $this->enviar($this->user, ['ocorrencia_id' => $ocorrencia->id]);
        }

        DB::enableQueryLog();
        $componente = Livewire::test('pages::notificacoes.index');
        $componente->instance()->estadosPorOcorrencia;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Paginação + obras disponíveis + estados em lote — um número
        // pequeno e FIXO, nunca crescendo com as 20 notificações da
        // MESMA ocorrência (nunca 1 query por notificação exibida).
        $this->assertLessThanOrEqual(8, $queries);
    }
}
