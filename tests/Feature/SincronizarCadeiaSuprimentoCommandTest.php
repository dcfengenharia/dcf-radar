<?php

namespace Tests\Feature;

use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\Papel;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\PedidoCompraItem;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.7 — App\Console\Commands\SincronizarCadeiaSuprimentoCommand
 * (rede de segurança diária) + teste crítico ponta a ponta (seção 48 do
 * pedido).
 */
class SincronizarCadeiaSuprimentoCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private RegistrarRecebimentoPedido $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        // Mesmo achado/técnica de AlertaCadeiaSuprimentoTest: as 3
        // Notifications fixam connection='redis' — sem worker rodando no
        // teste, o job só enfileiraria, nunca persistiria em
        // `notifications`. Redireciona 'redis' pro driver 'sync' só nesta
        // suíte, pra poder verificar persistência real (histórico nunca
        // apagado), sem alterar nenhum código de produção.
        config(['queue.connections.redis.driver' => 'sync']);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->registrar = new RegistrarRecebimentoPedido();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarPacote(?Work $obra = null): ItemSuprimento
    {
        return ItemSuprimento::create(['obra_id' => ($obra ?? $this->obra)->id, 'nome' => 'Pacote' . uniqid()]);
    }

    private function pedidoItemEmitido(ItemSuprimento $pacote, float $quantidade = 100, ?Work $obra = null, string $dataPrevista = '2026-12-01'): PedidoCompraItem
    {
        $obraAlvo = $obra ?? $this->obra;
        $doc = DocumentoEngenharia::create(['obra_id' => $obraAlvo->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item A', 'quantidade' => 1000]);
        $rp = (new CriarRequisicaoPlanejamento())->execute($obraAlvo->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, max($quantidade, 100));
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem->fresh(), $pacote, max($quantidade, 100));
        $fluxo = FluxoSuprimento::create(['tenant_id' => $obraAlvo->tenant_id, 'nome' => 'Fluxo' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $obraAlvo->tenant_id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($pacote, $fluxo, null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);
        $fornecedor = Fornecedor::create(['obra_id' => $obraAlvo->id, 'nome' => 'F', 'cnpj' => '00.000.000/0001-00']);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, $dataPrevista, null, null, null, $this->user);
        $itemPedido = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade);
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);
        return $itemPedido->fresh();
    }

    private function restricaoDe(ItemSuprimento $pacote, Atividade $atividade): ?Restricao
    {
        return Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)
            ->first();
    }

    // ---- AK/AL: passagem do tempo cria Restrição sem mutação de model ----

    public function test_ak_al_passagem_do_tempo_cria_restricao_via_scheduler(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01'));
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote, 100, dataPrevista: '2026-11-15');

        // Ainda não chegou a necessidade — nenhum model muda daqui em diante.
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $this->assertNull($this->restricaoDe($pacote, $atividade));

        // Só o RELÓGIO avança — zero mutação de Atividade/Pedido/Pacote.
        Carbon::setTestNow(Carbon::parse('2026-12-05'));
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);

        $this->assertSame(StatusRestricao::Aberta, $this->restricaoDe($pacote, $atividade)->status);
    }

    // ---- AM: scheduler resolve quando causa desaparece ----

    public function test_am_scheduler_resolve_quando_causa_desaparece(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $item = $this->pedidoItemEmitido($pacote, 100);

        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $this->assertSame(StatusRestricao::Aberta, $this->restricaoDe($pacote, $atividade)->status);

        $this->registrar->execute($item->fresh(), 100, Carbon::today(), $this->user);
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);

        $this->assertSame(StatusRestricao::Resolvida, $this->restricaoDe($pacote, $atividade)->status);
    }

    // ---- AN: erro no Pacote A não impede B ----

    public function test_an_erro_pacote_a_nao_impede_pacote_b(): void
    {
        $pacoteA = $this->criarPacote();
        $atividadeA = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacoteA->atividades()->attach($atividadeA->id);
        $this->pedidoItemEmitido($pacoteA, 100);

        $pacoteB = $this->criarPacote();
        $atividadeB = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacoteB->atividades()->attach($atividadeB->id);
        $this->pedidoItemEmitido($pacoteB, 100);

        // Primeira execução cria a Restrição de A legitimamente — depois
        // corrompe SÓ o `status` dessa linha (coluna varchar solta, sem FK)
        // pra um valor que não existe no enum StatusRestricao. Na próxima
        // execução, `buscarRestricao()` hidrata essa linha e o cast do
        // Eloquent lança \ValueError — um \Throwable real, isolado só em A
        // (nenhuma corrupção de FK, nenhum dado fabricado artificialmente
        // fora do fluxo real de domínio).
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $restricaoA = $this->restricaoDe($pacoteA, $atividadeA);
        DB::table('restricoes')->where('id', $restricaoA->id)->update(['status' => 'valor_corrompido_invalido']);

        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);

        // B continua processado normalmente, mesmo com A quebrado.
        $this->assertSame(StatusRestricao::Aberta, $this->restricaoDe($pacoteB, $atividadeB)->status);
    }

    // ---- AO: tenant context correto sem auth ----

    public function test_ao_tenant_context_correto_sem_auth(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote, 100);

        \Illuminate\Support\Facades\Auth::logout();

        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);

        $this->assertSame(StatusRestricao::Aberta, $this->restricaoDe($pacote, $atividade)->status);
    }

    // ---- AP: segunda execução idempotente ----

    public function test_ap_segunda_execucao_idempotente(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote, 100);

        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);

        $this->assertSame(1, Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)->count());
    }

    // ---- AQ: performance N=100 — verifica LINEARIDADE, não um teto absoluto ----

    /**
     * A cadeia completa (Pacote→RC→Pedido→Item→Recebimento + Restrição +
     * Alerta) tem um custo constante por Pacote real e não-trivial
     * (derivações de várias entidades). O que importa pra "não virar N+1
     * explosivo" (seção 40 do pedido) é o crescimento ser LINEAR em N —
     * nunca quadrático/pior. Mede N=20 vs N=100 (proporção 5x) e confirma
     * que o total de queries cresce proporcionalmente, nunca de forma
     * super-linear.
     */
    public function test_aq_performance_linear_nao_explosiva(): void
    {
        $queries20 = $this->queriesParaNPacotes(20);
        $queries100 = $this->queriesParaNPacotes(100);

        $razao = $queries100 / max($queries20, 1);

        // Linear seria ~5x; tolerância generosa até 8x pra absorver
        // overhead fixo de setup do Command (categoria/tenant) que não
        // escala com N — nunca >8x, que já indicaria comportamento
        // super-linear.
        $this->assertLessThan(8.0, $razao, "Crescimento de queries não-linear: 20 pacotes={$queries20}, 100 pacotes={$queries100}, razão={$razao}");
    }

    private function queriesParaNPacotes(int $n): int
    {
        $tenant = Tenant::factory()->create();
        [$obra, $user] = \App\Support\TenantContext::actingAs($tenant, function () use ($tenant) {
            $user = User::factory()->create(['tenant_id' => $tenant->id]);
            $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
            $this->vincularObra($obra, $user, Papel::GerentePlanejamento->value);
            return [$obra, $user];
        });
        $this->actingAs($user);

        for ($i = 0; $i < $n; $i++) {
            $pacote = $this->criarPacote($obra);
            $atividade = Atividade::factory()->create(['obra_id' => $obra->id, 'inicio_planejado' => '2026-12-01']);
            $pacote->atividades()->attach($atividade->id);
            $this->pedidoItemEmitido($pacote, 10, $obra);
        }

        $this->actingAs($this->user);

        DB::enableQueryLog();
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }

    private function notificacoesDoTipo(string $tipo): int
    {
        return DB::table('notifications')
            ->where('notifiable_id', $this->user->id)
            ->where('type', $tipo)
            ->count();
    }

    // ---- Teste crítico ponta a ponta (seção 48) ----

    public function test_critico_ponta_a_ponta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15'));

        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-10-20']);
        $pacote->atividades()->attach($atividade->id);

        // Pedido com previsão 25/10 — depois da necessidade (20/10):
        // risco projetado, mas necessidade ainda é futura em 15/10.
        $item = $this->pedidoItemEmitido($pacote, 100, dataPrevista: '2026-10-25');

        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $this->assertNull($this->restricaoDe($pacote, $atividade));
        $this->assertGreaterThan(0, $this->notificacoesDoTipo(\App\Notifications\SuprimentosRiscoProjetadoNotification::class));
        // Previsão (25/10) ainda não passou em 15/10 — Pedido ainda não está comercialmente atrasado.
        $this->assertSame(0, $this->notificacoesDoTipo(\App\Notifications\SuprimentosPedidoAtrasadoNotification::class));

        // Avança pra 19/10 — necessidade ainda não chegou, Pedido ainda não atrasado.
        Carbon::setTestNow(Carbon::parse('2026-10-19'));
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $this->assertNull($this->restricaoDe($pacote, $atividade));
        $this->assertSame(0, $this->notificacoesDoTipo(\App\Notifications\SuprimentosPedidoAtrasadoNotification::class));

        // Avança pra 20/10 — necessidade chegou, saldo 100% pendente.
        Carbon::setTestNow(Carbon::parse('2026-10-20'));
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $restricao = $this->restricaoDe($pacote, $atividade);
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);
        $this->assertFalse($atividade->fresh()->estaPronta());
        $this->assertGreaterThan(0, $this->notificacoesDoTipo(\App\Notifications\SuprimentosRestricaoCriadaNotification::class));

        // Recebe 40 — Restrição continua.
        $this->registrar->execute($item->fresh(), 40, Carbon::today(), $this->user);
        $this->assertSame(StatusRestricao::Aberta, $restricao->fresh()->status);

        // Recebe 60 (completa 100) — Restrição resolve, atividade some do bloqueio.
        $this->registrar->execute($item->fresh(), 60, Carbon::today(), $this->user);
        $this->assertSame(StatusRestricao::Resolvida, $restricao->fresh()->status);
        $this->assertTrue($atividade->fresh()->estaPronta());

        // Notifications históricas permanecem (nunca apagadas pela resolução).
        $this->assertGreaterThan(0, $this->notificacoesDoTipo(\App\Notifications\SuprimentosRestricaoCriadaNotification::class));

        // Reexecutar sincronização: zero duplicata.
        $this->artisan('suprimentos:sincronizar-cadeia-formal')->assertExitCode(0);
        $this->assertSame(1, Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)->count());
    }
}
