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
use App\Notifications\SuprimentosPedidoAtrasadoNotification;
use App\Notifications\SuprimentosRestricaoCriadaNotification;
use App\Notifications\SuprimentosRiscoProjetadoNotification;
use App\Support\Suprimentos\AlertaCadeiaSuprimento;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.7 — App\Support\Suprimentos\AlertaCadeiaSuprimento:
 * destinatários (decisão do usuário via AskUserQuestion) + idempotência.
 */
class AlertaCadeiaSuprimentoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private AlertaCadeiaSuprimento $alerta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        // As 3 Notifications desta etapa fixam $this->connection = 'redis'
        // (mesmo padrão de ProntidaoSemanalNotification) — sem worker
        // rodando durante o teste, o job só seria enfileirado, nunca
        // processado, e nada apareceria em `notifications`. Redireciona a
        // conexão 'redis' pro driver 'sync' SÓ nesta suíte, pra testar a
        // idempotência real (PK) sem alterar nenhum código de produção.
        config(['queue.connections.redis.driver' => 'sync']);

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->alerta = new AlertaCadeiaSuprimento();
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

    // ---- A-J: destinatários ----

    public function test_a_admin_da_obra_recebe(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => true]);
        $this->vincularObra($this->obra, $admin, Papel::Admin->value);

        $destinatarios = $this->alerta->destinatarios($this->obra);

        $this->assertTrue($destinatarios->contains('id', $admin->id));
    }

    public function test_b_admin_de_outra_obra_nao_recebe(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => true]);
        $this->vincularObra($outraObra, $admin, Papel::Admin->value);

        $destinatarios = $this->alerta->destinatarios($this->obra);

        $this->assertFalse($destinatarios->contains('id', $admin->id));
    }

    public function test_c_admin_de_outro_tenant_nao_recebe(): void
    {
        $outroTenant = Tenant::factory()->create();
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $admin = User::factory()->create(['tenant_id' => $outroTenant->id, 'ativo' => true]);
            $this->vincularObra($outraObra, $admin, Papel::Admin->value);
        });

        // $this->user (GerentePlanejamento no setUp) já é um destinatário
        // LEGÍTIMO desta obra — a asserção certa é "o admin de outro
        // tenant não está na lista", nunca "a lista está vazia".
        $adminId = DB::table('users')->where('tenant_id', $outroTenant->id)->value('id');
        $destinatarios = $this->alerta->destinatarios($this->obra);

        $this->assertFalse($destinatarios->contains('id', $adminId));
    }

    public function test_d_planejamento_recebe(): void
    {
        $planejador = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => true]);
        $this->vincularObra($this->obra, $planejador, Papel::GerentePlanejamento->value);

        $this->assertTrue($this->alerta->destinatarios($this->obra)->contains('id', $planejador->id));
    }

    public function test_e_suprimentos_recebe(): void
    {
        $suprimentos = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => true]);
        $this->vincularObra($this->obra, $suprimentos, Papel::Encarregado->value); // suprimentos.mapa|ver liberado a partir de Encarregado

        $this->assertTrue($this->alerta->destinatarios($this->obra)->contains('id', $suprimentos->id));
    }

    public function test_f_usuario_sem_nenhum_criterio_nao_recebe(): void
    {
        // "ver" é liberado a TODO perfil em TODA funcionalidade por
        // design (Perfil::seedPadrao() — "ninguém é bloqueado de
        // visualizar hoje") — logo nenhum perfil vinculado à obra fica
        // de fora dos 3 grupos. "Sem nenhum critério" só existe de fato
        // pra um usuário SEM NENHUM vínculo com esta obra.
        $semVinculo = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => true]);

        $this->assertFalse($this->alerta->destinatarios($this->obra)->contains('id', $semVinculo->id));
    }

    public function test_g_usuario_inativo_nao_recebe(): void
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => false]);
        $this->vincularObra($this->obra, $admin, Papel::Admin->value);

        $this->assertFalse($this->alerta->destinatarios($this->obra)->contains('id', $admin->id));
    }

    public function test_h_mesmo_usuario_nos_3_grupos_recebe_uma_vez(): void
    {
        // GerentePlanejamento já cobre planejamento.requisicoes|ver E suprimentos.mapa|ver
        // (nível acima de Encarregado/Engenheiro) — e nada impede também ser Admin da obra
        // se o mesmo usuário estiver vinculado com o slug 'admin'. Troca o
        // perfil do vínculo já existente (setUp) em vez de attach() de
        // novo (violaria a PK composta de obra_user).
        $perfilAdmin = \App\Models\Perfil::porSlugPadrao($this->tenant, Papel::Admin->value);
        $this->obra->users()->updateExistingPivot($this->user->id, ['perfil_id' => $perfilAdmin->id]);

        $destinatarios = $this->alerta->destinatarios($this->obra);

        $this->assertSame(1, $destinatarios->where('id', $this->user->id)->count());
    }

    public function test_i_usuario_com_acesso_a_duas_obras_recebe_so_da_obra_correspondente(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $suprimentos = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => true]);
        $this->vincularObra($this->obra, $suprimentos, Papel::Encarregado->value);
        // NÃO vinculado à outra obra.

        $this->assertTrue($this->alerta->destinatarios($this->obra)->contains('id', $suprimentos->id));
        $this->assertFalse($this->alerta->destinatarios($outraObra)->contains('id', $suprimentos->id));
    }

    public function test_j_resolucao_funciona_sem_auth_via_tenantcontext(): void
    {
        \Illuminate\Support\Facades\Auth::logout();

        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'ativo' => true]);
        \App\Support\TenantContext::actingAs($this->tenant, function () use ($admin) {
            $this->vincularObra($this->obra, $admin, Papel::Admin->value);
        });

        $destinatarios = \App\Support\TenantContext::actingAs($this->tenant, fn () => $this->alerta->destinatarios($this->obra));

        $this->assertTrue($destinatarios->contains('id', $admin->id));
    }

    // ---- Y/Z/AA: os 3 alertas disparam ----

    public function test_y_alerta_risco_projetado(): void
    {
        Notification::fake();
        $pacote = $this->criarPacote();
        // necessidade 05/01, mas o Pedido só promete entregar em 10/01 —
        // atendimento projetado ULTRAPASSA a necessidade = risco.
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2027-01-05']);
        $pacote->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote, 100, dataPrevista: '2027-01-10');

        $this->alerta->dispararRiscoProjetado($pacote->fresh());

        Notification::assertSentTo($this->user, SuprimentosRiscoProjetadoNotification::class);
    }

    public function test_z_alerta_pedido_atrasado(): void
    {
        Notification::fake();
        $pacote = $this->criarPacote();
        $item = $this->pedidoItemEmitido($pacote, 100); // previsão 2026-12-01, hoje 2026-12-15 -> atrasado

        $this->alerta->dispararPedidoAtrasado($item->pedidoCompra->fresh());

        Notification::assertSentTo($this->user, SuprimentosPedidoAtrasadoNotification::class);
    }

    public function test_aa_alerta_restricao_criada(): void
    {
        Notification::fake();
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote, 100);

        \App\Support\SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);

        Notification::assertSentTo($this->user, SuprimentosRestricaoCriadaNotification::class);
    }

    // ---- AB: rerun não duplica ----

    /**
     * Achado de teste (não de produção): `SincronizarRestricaoCadeiaSuprimento`
     * adia o disparo real via `DB::afterCommit()` — sob `RefreshDatabase`,
     * a transação externa de isolamento do teste NUNCA chega ao nível 0
     * (confirmado lendo `DatabaseTransactionsManager::
     * afterCommitCallbacksShouldBeExecuted()`: só executa quando
     * `$level === 0`), então callbacks `afterCommit()` registrados durante
     * QUALQUER teste `RefreshDatabase` nunca disparam, mesmo aninhados
     * dentro da própria transação de uma Action. Os testes AB/AC/AD
     * testam a lógica de IDEMPOTÊNCIA/EPISÓDIO do próprio
     * `AlertaCadeiaSuprimento` (não o mecanismo de `afterCommit` em si —
     * já coberto por análise de código/precedente do projeto) chamando
     * `dispararRestricaoCriada()` DIRETO, decoupled do wrapper adiado.
     */
    public function test_ab_rerun_nao_duplica(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote, 100);

        \App\Support\SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $restricao = Restricao::where('atividade_id', $atividade->id)->where('origem_cadeia_suprimento_id', $pacote->id)->first();

        for ($i = 0; $i < 5; $i++) {
            $this->alerta->dispararRestricaoCriada($restricao->fresh(), $pacote->fresh(), $atividade->fresh());
        }

        $count = DB::table('notifications')
            ->where('notifiable_id', $this->user->id)
            ->where('type', SuprimentosRestricaoCriadaNotification::class)
            ->count();

        $this->assertSame(1, $count);
    }

    // ---- AC: resolução não apaga Notification ----

    public function test_ac_resolucao_nao_apaga_notification(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $item = $this->pedidoItemEmitido($pacote, 100);

        \App\Support\SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $restricao = Restricao::where('atividade_id', $atividade->id)->where('origem_cadeia_suprimento_id', $pacote->id)->first();
        $this->alerta->dispararRestricaoCriada($restricao->fresh(), $pacote->fresh(), $atividade->fresh());

        $countAntes = DB::table('notifications')->where('type', SuprimentosRestricaoCriadaNotification::class)->count();
        $this->assertGreaterThan(0, $countAntes);

        (new \App\Actions\Suprimentos\RegistrarRecebimentoPedido())->execute($item->fresh(), 100, Carbon::today(), $this->user);

        $this->assertSame(StatusRestricao::Resolvida, $restricao->fresh()->status);
        $countDepois = DB::table('notifications')->where('type', SuprimentosRestricaoCriadaNotification::class)->count();
        $this->assertSame($countAntes, $countDepois);
    }

    // ---- AD: novo episódio gera novo evento ----

    public function test_ad_novo_episodio_gera_novo_evento(): void
    {
        $pacote = $this->criarPacote();
        $atividade = Atividade::factory()->create(['obra_id' => $this->obra->id, 'inicio_planejado' => '2026-12-01']);
        $pacote->atividades()->attach($atividade->id);
        $this->pedidoItemEmitido($pacote, 100);

        \App\Support\SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $restricao = Restricao::where('atividade_id', $atividade->id)->where('origem_cadeia_suprimento_id', $pacote->id)->first();
        $this->alerta->dispararRestricaoCriada($restricao->fresh(), $pacote->fresh(), $atividade->fresh());
        $countPrimeiroEpisodio = DB::table('notifications')->where('type', SuprimentosRestricaoCriadaNotification::class)->count();

        // resolve (reprograma pro futuro) e reabre (reprograma pro passado de novo) — novo `aberta_em`.
        // Avança o relógio entre os 2 episódios pra `aberta_em` genuinamente
        // mudar (sem isso, os 2 episódios cairiam no MESMO segundo — mesma
        // chave de idempotência, dedup correto mas indistinguível do bug).
        $atividade->update(['inicio_planejado' => '2027-01-10']);
        \App\Support\SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        Carbon::setTestNow(Carbon::parse('2026-12-16'));
        $atividade->update(['inicio_planejado' => '2026-11-01']);
        \App\Support\SincronizarRestricaoCadeiaSuprimento::sincronizarPacote($pacote->fresh(), $this->user->id);
        $this->alerta->dispararRestricaoCriada($restricao->fresh(), $pacote->fresh(), $atividade->fresh());

        $countSegundoEpisodio = DB::table('notifications')->where('type', SuprimentosRestricaoCriadaNotification::class)->count();

        $this->assertGreaterThan($countPrimeiroEpisodio, $countSegundoEpisodio);
    }

    // ---- AJ: cross-tenant nunca recebe (evento real, não só destinatarios()) ----

    public function test_aj_cross_tenant_nunca_recebe_evento_real(): void
    {
        Notification::fake();
        $outroTenant = Tenant::factory()->create();
        $userOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id, 'ativo' => true]);

        $pacote = $this->criarPacote();
        $item = $this->pedidoItemEmitido($pacote, 100);

        $this->alerta->dispararPedidoAtrasado($item->pedidoCompra->fresh());

        Notification::assertNotSentTo($userOutroTenant, SuprimentosPedidoAtrasadoNotification::class);
    }
}
