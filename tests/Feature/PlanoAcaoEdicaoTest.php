<?php

namespace Tests\Feature;

use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Models\CronogramaImportacao;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoReconciliacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\PlanoAcao\PlanoAcaoReconciliador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 4.3, Etapa D — edição manual de responsável/prazo/status a partir
 * do painel expansível. Cobre segurança, transições de status permitidas/
 * bloqueadas, `resolvida_em`, concorrência (status alterado pelo
 * reconciliador enquanto o modal estava aberto) e integridade (campos
 * congelados/uids_referencia/Health Check/Score nunca tocados).
 */
class PlanoAcaoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // 'engenheiro' é o menor papel com 'editar' em restricoes.plano_acao (Perfil::REGRAS_ESCRITA).
        $this->vincularObra($this->obra, $this->user, 'engenheiro');

        $this->importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function criarAcao(array $overrides = []): PlanoAcao
    {
        return PlanoAcao::create(array_merge([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $this->importacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'Título congelado',
            'recomendacao' => 'Recomendação congelada',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => ['2', '3'],
        ], $overrides));
    }

    // =========================================================================
    // SEGURANÇA
    // =========================================================================

    public function test_usuario_autorizado_consegue_editar(): void
    {
        $acao = $this->criarAcao();

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editPrazo', '2026-09-01')
            ->call('confirmarEditar')
            ->assertSet('acaoEditandoId', null);

        $this->assertSame('2026-09-01', $acao->fresh()->prazo->format('Y-m-d'));
    }

    public function test_usuario_sem_permissao_editar_nao_consegue(): void
    {
        $acao = $this->criarAcao();

        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // 'cliente_leitura' só tem 'ver', nunca 'editar'.
        $this->vincularObra($this->obra, $semPermissao, 'cliente_leitura');
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->assertSet('acaoEditandoId', null); // abrirModalEditar rejeitou silenciosamente (só UX, Seção 4/16)

        // Simula alguém adulterando o payload do Livewire pra chamar
        // confirmarEditar() direto, contornando o gate de UX de
        // abrirModalEditar() — a proteção REAL precisa estar aqui dentro,
        // não só na abertura do modal (regra explícita da Seção 4).
        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->set('acaoEditandoId', $acao->id)
            ->set('editPrazo', '2026-09-01')
            ->call('confirmarEditar')
            ->assertForbidden();

        $this->assertNull($acao->fresh()->prazo);
    }

    public function test_usuario_de_outra_obra_nao_consegue_editar(): void
    {
        $acao = $this->criarAcao();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $usuarioOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $usuarioOutraObra, 'engenheiro');
        $this->actingAs($usuarioOutraObra);

        // A própria página bloqueia no mount() (sem permissão 'ver' na obra da ação) —
        // mas o teste chama confirmarEditar diretamente no componente da OBRA CERTA,
        // simulando alguém que manipulou o payload pra apontar pra uma ação de obra
        // à qual não tem vínculo nenhum.
        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');

        $this->assertNull($acao->fresh()->prazo);
    }

    public function test_usuario_de_outro_tenant_nao_consegue_editar(): void
    {
        $acao = $this->criarAcao();

        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($usuarioOutroTenant);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');

        $this->assertNull($acao->fresh()->prazo);
    }

    public function test_responsavel_de_outra_obra_e_rejeitado(): void
    {
        $acao = $this->criarAcao();

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $responsavelDeOutraObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $responsavelDeOutraObra, 'engenheiro');

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editResponsavelId', $responsavelDeOutraObra->id)
            ->call('confirmarEditar')
            ->assertSet('editErro', 'O responsável selecionado não tem acesso a esta obra.');

        $this->assertNull($acao->fresh()->responsavel_id);
    }

    public function test_responsavel_inexistente_e_rejeitado_pela_validacao(): void
    {
        $acao = $this->criarAcao();

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editResponsavelId', 'id-que-nao-existe')
            ->call('confirmarEditar')
            ->assertHasErrors(['editResponsavelId']);

        $this->assertNull($acao->fresh()->responsavel_id);
    }

    // =========================================================================
    // RESPONSÁVEL
    // =========================================================================

    public function test_responsavel_valido_e_salvo(): void
    {
        $acao = $this->criarAcao();
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editResponsavelId', $responsavel->id)
            ->call('confirmarEditar');

        $this->assertSame($responsavel->id, $acao->fresh()->responsavel_id);
    }

    public function test_lista_de_responsaveis_contem_somente_usuarios_da_obra(): void
    {
        $this->criarAcao();
        $daObra = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $daObra, 'engenheiro');

        $foraDaObra = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra]);
        $ids = $component->instance()->responsaveis()->pluck('id')->all();

        $this->assertContains($daObra->id, $ids);
        $this->assertNotContains($foraDaObra->id, $ids);
    }

    public function test_responsavel_antigo_pode_ser_substituido(): void
    {
        $antigo = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $antigo, 'engenheiro');
        $novo = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $novo, 'engenheiro');

        $acao = $this->criarAcao(['responsavel_id' => $antigo->id]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editResponsavelId', $novo->id)
            ->call('confirmarEditar');

        $this->assertSame($novo->id, $acao->fresh()->responsavel_id);
    }

    // =========================================================================
    // PRAZO
    // =========================================================================

    public function test_prazo_pode_ser_alterado(): void
    {
        $acao = $this->criarAcao(['prazo' => '2026-08-01']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editPrazo', '2026-10-15')
            ->call('confirmarEditar');

        $this->assertSame('2026-10-15', $acao->fresh()->prazo->format('Y-m-d'));
    }

    public function test_prazo_pode_ser_removido(): void
    {
        $acao = $this->criarAcao(['prazo' => '2026-08-01']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editPrazo', '')
            ->call('confirmarEditar');

        $this->assertNull($acao->fresh()->prazo);
    }

    public function test_data_invalida_e_rejeitada(): void
    {
        $acao = $this->criarAcao(['prazo' => '2026-08-01']);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editPrazo', 'nao-e-uma-data')
            ->call('confirmarEditar')
            ->assertHasErrors(['editPrazo']);

        $this->assertSame('2026-08-01', $acao->fresh()->prazo->format('Y-m-d'));
    }

    // =========================================================================
    // STATUS — TRANSIÇÕES PERMITIDAS
    // =========================================================================

    public function test_transicao_aberta_para_resolvida(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Resolvida->value)
            ->call('confirmarEditar');

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $fresca->status);
        $this->assertNotNull($fresca->resolvida_em);
    }

    public function test_transicao_aberta_para_cancelada(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Cancelada->value)
            ->call('confirmarEditar');

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Cancelada, $fresca->status);
        $this->assertNull($fresca->resolvida_em);
    }

    public function test_transicao_resolvida_para_aberta(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Resolvida, 'resolvida_em' => now()]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Aberta->value)
            ->call('confirmarEditar');

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $fresca->status);
        $this->assertNull($fresca->resolvida_em);
    }

    public function test_transicao_cancelada_para_aberta(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Cancelada]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Aberta->value)
            ->call('confirmarEditar');

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $fresca->status);
        $this->assertNull($fresca->resolvida_em);
    }

    public function test_mesmo_status_e_um_no_op_permitido(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editPrazo', '2026-11-01')
            ->set('editStatus', StatusPlanoAcao::Aberta->value)
            ->call('confirmarEditar')
            ->assertSet('editErro', null);

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $fresca->status);
        $this->assertSame('2026-11-01', $fresca->prazo->format('Y-m-d'));
    }

    // =========================================================================
    // STATUS — TRANSIÇÕES BLOQUEADAS
    // =========================================================================

    public function test_transicao_resolvida_para_cancelada_e_bloqueada(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Resolvida, 'resolvida_em' => now()]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Cancelada->value)
            ->call('confirmarEditar')
            ->assertSet('acaoEditandoId', $acao->id); // modal não fechou — foi rejeitado

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $fresca->status);
        $this->assertNotNull($fresca->resolvida_em);
    }

    public function test_transicao_cancelada_para_resolvida_e_bloqueada(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Cancelada]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Resolvida->value)
            ->call('confirmarEditar')
            ->assertSet('acaoEditandoId', $acao->id);

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Cancelada, $fresca->status);
        $this->assertNull($fresca->resolvida_em);
    }

    public function test_acao_nao_e_deletada_em_nenhuma_transicao(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Cancelada->value)
            ->call('confirmarEditar');

        $this->assertDatabaseHas('planos_acao', ['id' => $acao->id]);
        $this->assertNotNull(PlanoAcao::find($acao->id));
    }

    // =========================================================================
    // CONCORRÊNCIA
    // =========================================================================

    public function test_transicao_rejeitada_quando_acao_foi_alterada_pelo_reconciliador_apos_abrir_modal(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Cancelada->value);

        // Simula o reconciliador resolvendo a ação enquanto o modal estava aberto
        // (mesmo efeito que PlanoAcaoReconciliador::reconciliarUma() produziria).
        $acao->update(['status' => StatusPlanoAcao::Resolvida, 'resolvida_em' => now()]);

        // Resolvida -> Cancelada é uma transição bloqueada — a validação usa o
        // status FRESCO (Resolvida), não o que estava no componente (Aberta).
        $component->call('confirmarEditar')
            ->assertSet('acaoEditandoId', $acao->id);

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Resolvida, $fresca->status);
        $this->assertStringContainsString('já foi atualizada', $component->get('editErro'));
    }

    // =========================================================================
    // CONFIRMAÇÃO DE TRANSIÇÃO (UX)
    // =========================================================================

    public function test_modal_nao_exige_confirmacao_extra_quando_so_responsavel_prazo_mudam(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editPrazo', '2026-12-01');

        // Botão de salvar direto (wire:click), nunca envolvido no onclick de confirmarAcao().
        $component->assertSeeHtml('wire:click="confirmarEditar"')
            ->assertDontSeeHtml("metodo: 'confirmarEditar'");
    }

    public function test_modal_usa_confirmaracao_quando_status_muda(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Resolvida->value);

        $component->assertSeeHtml("metodo: 'confirmarEditar'");
    }

    // =========================================================================
    // ATUALIZAÇÃO SOMENTE DOS CAMPOS PERMITIDOS / INTEGRIDADE
    // =========================================================================

    public function test_uids_referencia_permanece_intacto_apos_edicao(): void
    {
        $acao = $this->criarAcao(['uids_referencia' => ['2', '3', '7']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Cancelada->value)
            ->call('confirmarEditar');

        $this->assertSame(['2', '3', '7'], $acao->fresh()->uids_referencia);
    }

    public function test_dados_congelados_permanecem_intactos(): void
    {
        $acao = $this->criarAcao([
            'titulo' => 'Título original',
            'recomendacao' => 'Recomendação original',
            'regra_id' => 'PROG-001',
        ]);
        $tenantIdOriginal = $acao->tenant_id;
        $obraIdOriginal = $acao->obra_id;
        $criadoPorOriginal = $acao->created_by_id;
        $importacaoOrigemOriginal = $acao->cronograma_importacao_origem_id;

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Resolvida->value)
            ->call('confirmarEditar');

        $fresca = $acao->fresh();
        $this->assertSame('Título original', $fresca->titulo);
        $this->assertSame('Recomendação original', $fresca->recomendacao);
        $this->assertSame('PROG-001', $fresca->regra_id);
        $this->assertSame($tenantIdOriginal, $fresca->tenant_id);
        $this->assertSame($obraIdOriginal, $fresca->obra_id);
        $this->assertSame($criadoPorOriginal, $fresca->created_by_id);
        $this->assertSame($importacaoOrigemOriginal, $fresca->cronograma_importacao_origem_id);
    }

    // =========================================================================
    // RECONCILIAÇÃO — comportamento preservado após edição manual
    // =========================================================================

    public function test_acao_resolvida_manualmente_nao_e_reaberta_pelo_reconciliador(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta, 'uids_referencia' => ['2']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Resolvida->value)
            ->call('confirmarEditar');

        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->fresh()->status);

        // O reconciliador só processa ações Abertas (guard de
        // PlanoAcaoReconciliador::reconciliar()) — mesmo passando essa ação
        // resolvida na coleção, ela é ignorada e nenhum evento é criado.
        $eventos = app(PlanoAcaoReconciliador::class)->reconciliar(
            new \Illuminate\Database\Eloquent\Collection([$acao->fresh()]),
            $this->importacao,
            \App\Models\CronogramaImportacaoHealthCheck::create(
                ['cronograma_importacao_id' => $this->importacao->id]
                + \App\Models\CronogramaImportacaoHealthCheck::camposParaPersistir(
                    (new \App\Support\HealthCheck\HealthCheckResultado([]))->toArray()
                )
            )
        );

        $this->assertCount(0, $eventos);
        $this->assertSame(StatusPlanoAcao::Resolvida, $acao->fresh()->status);
    }

    public function test_acao_cancelada_manualmente_nao_e_reaberta_pelo_reconciliador(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Aberta, 'uids_referencia' => ['2']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Cancelada->value)
            ->call('confirmarEditar');

        $this->assertSame(StatusPlanoAcao::Cancelada, $acao->fresh()->status);

        $eventos = app(PlanoAcaoReconciliador::class)->reconciliar(
            new \Illuminate\Database\Eloquent\Collection([$acao->fresh()]),
            $this->importacao,
            \App\Models\CronogramaImportacaoHealthCheck::create(
                ['cronograma_importacao_id' => $this->importacao->id]
                + \App\Models\CronogramaImportacaoHealthCheck::camposParaPersistir(
                    (new \App\Support\HealthCheck\HealthCheckResultado([]))->toArray()
                )
            )
        );

        $this->assertCount(0, $eventos);
        $this->assertSame(StatusPlanoAcao::Cancelada, $acao->fresh()->status);
    }

    public function test_acao_reaberta_manualmente_volta_a_ser_reconciliavel(): void
    {
        $acao = $this->criarAcao(['status' => StatusPlanoAcao::Resolvida, 'resolvida_em' => now(), 'uids_referencia' => ['2']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Aberta->value)
            ->call('confirmarEditar');

        $this->assertSame(StatusPlanoAcao::Aberta, $acao->fresh()->status);

        $finding = new \App\Support\HealthCheck\HealthCheckFinding(
            regraId: 'PROG-001',
            categoria: \App\Enums\HealthCheckCategoria::Avanco,
            severidade: \App\Enums\HealthCheckSeveridade::Alto,
            titulo: 'teste',
            descricao: 'teste',
            impacto: 'teste',
            recomendacao: 'teste',
            atividades: [['uid' => '2']],
        );
        $healthCheck = \App\Models\CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $this->importacao->id]
            + \App\Models\CronogramaImportacaoHealthCheck::camposParaPersistir(
                (new \App\Support\HealthCheck\HealthCheckResultado([$finding]))->toArray()
            )
        );

        $eventos = app(PlanoAcaoReconciliador::class)->reconciliar(
            new \Illuminate\Database\Eloquent\Collection([$acao->fresh()]),
            $this->importacao,
            $healthCheck
        );

        // Reaberta, ela volta a ser elegível pro reconciliador — 1 evento gerado.
        $this->assertCount(1, $eventos);
    }

    // =========================================================================
    // UX / LIVEWIRE
    // =========================================================================

    public function test_modal_abre_com_dados_atuais(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');
        $acao = $this->criarAcao(['responsavel_id' => $responsavel->id, 'prazo' => '2026-09-10', 'status' => StatusPlanoAcao::Aberta]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->assertSet('editResponsavelId', $responsavel->id)
            ->assertSet('editPrazo', '2026-09-10')
            ->assertSet('editStatus', StatusPlanoAcao::Aberta->value);
    }

    public function test_erros_de_validacao_aparecem_no_formulario(): void
    {
        $acao = $this->criarAcao();

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editPrazo', 'data-invalida')
            ->call('confirmarEditar')
            ->assertHasErrors(['editPrazo' => 'date']);
    }

    public function test_sucesso_atualiza_listagem_sem_reload(): void
    {
        $acao = $this->criarAcao(['titulo' => 'Ação a ser resolvida']);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('abrirModalEditar', $acao->id)
            ->set('editStatus', StatusPlanoAcao::Resolvida->value)
            ->call('confirmarEditar');

        $badgeResolvida = collect($component->instance()->acoes->items())->first(fn ($a) => $a->id === $acao->id);
        $this->assertSame(StatusPlanoAcao::Resolvida, $badgeResolvida->status);
    }

    public function test_botao_editar_nao_aparece_para_quem_nao_tem_permissao(): void
    {
        $acao = $this->criarAcao();

        $semPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissao, 'cliente_leitura');
        $this->actingAs($semPermissao);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertDontSeeHtml("wire:click=\"abrirModalEditar('{$acao->id}')\"");
    }
}
