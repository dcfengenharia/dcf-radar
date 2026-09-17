<?php

namespace Tests\Feature;

use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\Papel;
use App\Enums\PilarLean;
use App\Enums\StatusAtividade;
use App\Enums\StatusProgramacaoSemanal;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\CronogramaImportacao;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\LinhaBase;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 2A (Hardening de Autorização Cross-Obra) — suíte adversarial
 * dedicada aos 8 achados da auditoria (Fase 1) + o gap de "Minhas Obras"
 * + o cabeçalho vazio do menu. Nomeada explicitamente "CrossObra" (Seção
 * 27 do pedido): `TenantIsolationTest` prova só tenant A × tenant B — esta
 * suíte prova, pra CADA achado, obra A × obra B DENTRO DO MESMO tenant,
 * com um usuário autorizado só na obra A tentando manipular um recurso da
 * obra B (leitura e escrita), e — quando aplicável — um segundo cenário
 * de "membro da obra MAS sem a permissão" (autorização negativa), além do
 * cenário positivo (usuário realmente autorizado continua funcionando).
 *
 * Convenção obrigatória em TODO teste desta classe: `actingAs()` é sempre
 * a PRIMEIRA linha do corpo do teste — `BelongsToTenant::creating()` só
 * carimba `tenant_id` a partir de `TenantContext::currentId()` quando há
 * usuário autenticado; criar qualquer fixture tenant-scoped ANTES de
 * autenticar produz `tenant_id` nulo (erro de banco), não um erro de
 * autorização — nada a ver com o que esta suíte quer provar.
 */
class CrossObraHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Work $obraA;

    private Work $obraB;

    /** Acesso só à Obra A, papel Admin (satisfaz toda capacidade testada). */
    private User $userA;

    /** Membro da Obra A, mas com um Perfil customizado SEM NENHUMA PerfilPermissao (autorização negativa real). */
    private User $userASemPermissao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obraA = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->userA = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraA, $this->userA, Papel::Admin->value);

        $this->userASemPermissao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfilVazio = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Sem Permissão ' . uniqid()]);
        // Fase 2F.CORREÇÃO.2 — 'ver' agora é reafirmado no backend em
        // restricoes.quadro/restricoes.lookahead (mount()); este Perfil
        // precisa de 'ver' pra continuar provando a negação da AÇÃO
        // específica testada (marcarItemProntidao/marcarItemNaDetalhe),
        // nunca a negação de simplesmente abrir a página (propriedade já
        // coberta por outro teste).
        // Fase 2F.CORREÇÃO.3 — 'restricoes.plano_semanal'/'obras.linhas_base'/
        // 'restricoes.minhas_programacoes' também passaram a reafirmar 'ver'
        // no backend (Achado E23); este Perfil precisa de 'ver' nessas 3
        // também, pelo mesmo motivo acima — provar a negação da AÇÃO
        // (marcarItemNaDetalhe/excluir/fecharProgramacao), nunca a negação
        // de simplesmente abrir a página.
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfilVazio->id, 'funcionalidade' => 'restricoes.quadro', 'acao' => 'ver']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfilVazio->id, 'funcionalidade' => 'restricoes.lookahead', 'acao' => 'ver']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfilVazio->id, 'funcionalidade' => 'restricoes.plano_semanal', 'acao' => 'ver']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfilVazio->id, 'funcionalidade' => 'obras.linhas_base', 'acao' => 'ver']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfilVazio->id, 'funcionalidade' => 'restricoes.minhas_programacoes', 'acao' => 'ver']);
        $this->obraA->users()->attach($this->userASemPermissao->id, ['perfil_id' => $perfilVazio->id]);
    }

    // -----------------------------------------------------------------
    // Helpers (sempre chamados DEPOIS de actingAs() dentro do teste)
    // -----------------------------------------------------------------

    private function criarAtividade(Work $obra, array $overrides = []): Atividade
    {
        return Atividade::create(array_merge([
            'obra_id' => $obra->id,
            'nome' => 'Atividade ' . uniqid(),
            'codigo_cronograma' => 'A' . uniqid(),
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => now()->addDays(3),
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function criarImportacao(Work $obra): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'obra_id' => $obra->id,
            'user_id' => $this->userA->id,
            'arquivo' => 'teste.xml',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'criadas' => 0,
            'atualizadas' => 0,
            'removidas' => 0,
            'importado_em' => now(),
        ]);
    }

    /**
     * `ModelNotFoundException` lançada DENTRO de uma chamada de método
     * Livewire nem sempre vira uma resposta HTTP 404 observável via
     * `assertStatus()` no ciclo de `Livewire::test()` (mesmo achado já
     * documentado várias vezes no histórico deste projeto — Take Off/
     * Estoque) — o teste correto é capturar a exceção diretamente. A
     * garantia de segurança real (o recurso de outra obra é literalmente
     * inalcançável, `findOrFail` nunca o encontra) continua verificada;
     * só a FORMA de observar isso muda.
     */
    private function assertBloqueadoPorRecursoDeOutraObra(\Closure $chamada): void
    {
        try {
            $chamada();
            $this->fail('Esperava ModelNotFoundException — o recurso de outra obra deveria ser inalcançável.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
    }

    private function criarProgramacao(Work $obra, string $status = 'aberta'): ProgramacaoSemanal
    {
        $semanaInicio = now()->startOfWeek();

        return ProgramacaoSemanal::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $obra->id,
            'semana_inicio' => $semanaInicio->toDateString(), 'semana_fim' => $semanaInicio->copy()->endOfWeek()->toDateString(),
            'congelada_em' => $semanaInicio, 'criado_por' => $this->userA->id,
            'status' => $status, 'versao' => 1,
        ]);
    }

    // =================================================================
    // ACHADO 1 — Checklist de prontidão (3 arquivos)
    // =================================================================

    public static function checklistComponentesProvider(): array
    {
        return [
            'restricoes.marcarItemProntidao' => ['pages::radar.restricoes', 'marcarItemProntidao'],
            'restricoes.marcarItemNaDetalhe' => ['pages::radar.restricoes', 'marcarItemNaDetalhe'],
            'plano-semanal.marcarItemNaDetalhe' => ['pages::radar.plano-semanal', 'marcarItemNaDetalhe'],
            'lookahead.marcarItemNaDetalhe' => ['pages::radar.lookahead', 'marcarItemNaDetalhe'],
        ];
    }

    /** @dataProvider checklistComponentesProvider */
    public function test_achado1_checklist_cross_obra_e_bloqueado(string $componente, string $metodo): void
    {
        $this->actingAs($this->userA);

        $atividadeB = $this->criarAtividade($this->obraB);
        $item = ItemProntidao::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obraA->id, 'nome' => 'Item']);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test($componente, ['obra' => $this->obraA])->call($metodo, $atividadeB->id, $item->id, true)
        );

        $this->assertDatabaseMissing('atividade_itens_prontidao', [
            'atividade_id' => $atividadeB->id,
            'item_prontidao_id' => $item->id,
        ]);
    }

    /** @dataProvider checklistComponentesProvider */
    public function test_achado1_checklist_membro_sem_permissao_e_bloqueado(string $componente, string $metodo): void
    {
        $this->actingAs($this->userASemPermissao);

        $atividadeA = $this->criarAtividade($this->obraA);
        $item = ItemProntidao::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obraA->id, 'nome' => 'Item']);

        Livewire::test($componente, ['obra' => $this->obraA])
            ->call($metodo, $atividadeA->id, $item->id, true)
            ->assertForbidden();

        $this->assertDatabaseMissing('atividade_itens_prontidao', [
            'atividade_id' => $atividadeA->id,
            'item_prontidao_id' => $item->id,
        ]);
    }

    /** @dataProvider checklistComponentesProvider */
    public function test_achado1_checklist_usuario_autorizado_continua_funcionando(string $componente, string $metodo): void
    {
        $this->actingAs($this->userA);

        $atividadeA = $this->criarAtividade($this->obraA);
        $item = ItemProntidao::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obraA->id, 'nome' => 'Item']);

        Livewire::test($componente, ['obra' => $this->obraA])
            ->call($metodo, $atividadeA->id, $item->id, true)
            ->assertOk();

        $this->assertDatabaseHas('atividade_itens_prontidao', [
            'atividade_id' => $atividadeA->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);
    }

    // =================================================================
    // ACHADO 2 — Suprimentos (ItemSuprimento)
    // =================================================================

    public function test_achado2_abrir_modal_editar_cross_obra_e_bloqueado(): void
    {
        $this->actingAs($this->userA);

        $pacoteB = ItemSuprimento::create(['obra_id' => $this->obraB->id, 'nome' => 'Pacote B']);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test('pages::radar.suprimentos', ['obra' => $this->obraA])->call('abrirModalEditar', $pacoteB->id)
        );
    }

    public function test_achado2_salvar_item_edicao_cross_obra_e_bloqueado(): void
    {
        $this->actingAs($this->userA);

        $pacoteB = ItemSuprimento::create(['obra_id' => $this->obraB->id, 'nome' => 'Pacote B', 'codigo' => 'PB-1']);
        // A validação de `atividadesIdsNovo` (required|min:1) precisa
        // passar pra chegar no ponto vulnerável de fato — qualquer
        // atividade real satisfaz a regra (ela só checa `exists`, nunca
        // a obra), o que importa é que o PACOTE em si é de outra obra.
        $atividadeQualquer = $this->criarAtividade($this->obraA);

        // Achado real de teste: salvarItem() roda inteiro dentro de
        // `transacaoSegura()`, que captura QUALQUER Throwable que não seja
        // Authorization/ValidationException (inclusive ModelNotFoundException)
        // e vira um toast de erro em vez de propagar — mesmo padrão já
        // documentado no projeto. A garantia de segurança real (nunca
        // mutar o Pacote de outra obra) continua provada pela ausência de
        // mutação abaixo; aqui só confirmamos que a "transação segura"
        // registrou a falha.
        $componente = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obraA])
            ->set('editandoItemId', $pacoteB->id)
            ->set('nomeNovo', 'Nome adulterado')
            ->set('atividadesIdsNovo', [$atividadeQualquer->id])
            ->call('salvarItem');

        $componente->assertOk();
        $componente->assertDispatched('show-toast');
        $this->assertDatabaseHas('itens_suprimento', ['id' => $pacoteB->id, 'nome' => 'Pacote B']);
    }

    public function test_achado2_comentar_cross_obra_e_bloqueado_e_nao_vaza_dado(): void
    {
        $this->actingAs($this->userA);

        $pacoteB = ItemSuprimento::create(['obra_id' => $this->obraB->id, 'nome' => 'Pacote Sigiloso B']);

        $componente = Livewire::test('pages::radar.suprimentos', ['obra' => $this->obraA])
            ->set('itemDetalheId', $pacoteB->id);

        // Leitura: o computed itemDetalhe() nunca resolve o Pacote de outra obra.
        $this->assertNull($componente->instance()->itemDetalhe);
        $componente->assertDontSee('Pacote Sigiloso B');

        $this->assertBloqueadoPorRecursoDeOutraObra(function () use ($componente) {
            $componente->set('comentarioNovo', 'comentário indevido')->call('adicionarComentario');
        });

        $this->assertDatabaseMissing('item_suprimento_comentarios', ['comentario' => 'comentário indevido']);
    }

    public function test_achado2_usuario_autorizado_continua_editando_e_comentando(): void
    {
        $this->actingAs($this->userA);

        $pacoteA = ItemSuprimento::create(['obra_id' => $this->obraA->id, 'nome' => 'Pacote A']);

        Livewire::test('pages::radar.suprimentos', ['obra' => $this->obraA])
            ->call('abrirModalEditar', $pacoteA->id)
            ->assertOk()
            ->set('itemDetalheId', $pacoteA->id)
            ->set('comentarioNovo', 'comentário válido')
            ->call('adicionarComentario')
            ->assertOk();

        $this->assertDatabaseHas('item_suprimento_comentarios', ['comentario' => 'comentário válido']);
    }

    // =================================================================
    // ACHADO 3 — Linhas de Base
    // =================================================================

    public function test_achado3_excluir_linha_base_cross_obra_e_bloqueado(): void
    {
        $this->actingAs($this->userA);

        $importacaoB = $this->criarImportacao($this->obraB);
        $lbB = LinhaBase::create([
            'obra_id' => $this->obraB->id, 'nome' => 'LB da Obra B',
            'cronograma_importacao_id' => $importacaoB->id, 'criado_por' => $this->userA->id,
        ]);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test('pages::radar.linhas-base', ['obra' => $this->obraA])->call('excluir', $lbB->id)
        );

        $this->assertDatabaseHas('linhas_base', ['id' => $lbB->id, 'deleted_at' => null]);
    }

    public function test_achado3_excluir_linha_base_membro_sem_permissao_e_bloqueado(): void
    {
        $this->actingAs($this->userASemPermissao);

        $importacaoA = $this->criarImportacao($this->obraA);
        $lbA = LinhaBase::create([
            'obra_id' => $this->obraA->id, 'nome' => 'LB da Obra A',
            'cronograma_importacao_id' => $importacaoA->id, 'criado_por' => $this->userA->id,
        ]);

        Livewire::test('pages::radar.linhas-base', ['obra' => $this->obraA])
            ->call('excluir', $lbA->id)
            ->assertForbidden();

        $this->assertDatabaseHas('linhas_base', ['id' => $lbA->id, 'deleted_at' => null]);
    }

    public function test_achado3_excluir_linha_base_usuario_autorizado_continua_funcionando(): void
    {
        $this->actingAs($this->userA);

        $importacaoA = $this->criarImportacao($this->obraA);
        $lbA = LinhaBase::create([
            'obra_id' => $this->obraA->id, 'nome' => 'LB da Obra A',
            'cronograma_importacao_id' => $importacaoA->id, 'criado_por' => $this->userA->id,
        ]);

        Livewire::test('pages::radar.linhas-base', ['obra' => $this->obraA])
            ->call('excluir', $lbA->id)
            ->assertOk();

        $this->assertSoftDeleted('linhas_base', ['id' => $lbA->id]);
    }

    // =================================================================
    // ACHADO 4 — Programação Semanal
    // =================================================================

    public function test_achado4_fechar_programacao_cross_obra_e_bloqueado(): void
    {
        $this->actingAs($this->userA);

        $programacaoB = $this->criarProgramacao($this->obraB);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test('pages::radar.programacoes', ['obra' => $this->obraA])->call('fecharProgramacao', $programacaoB->id)
        );

        $this->assertSame(StatusProgramacaoSemanal::Aberta->value, $programacaoB->fresh()->status->value);
    }

    public function test_achado4_criar_revisao_cross_obra_e_bloqueado(): void
    {
        $this->actingAs($this->userA);

        $programacaoB = $this->criarProgramacao($this->obraB, StatusProgramacaoSemanal::Fechada->value);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test('pages::radar.programacoes', ['obra' => $this->obraA])->call('criarRevisao', $programacaoB->id)
        );

        $this->assertDatabaseCount('programacoes_semanais', 1);
    }

    public function test_achado4_abrir_detalhe_cross_obra_nao_vaza_itens(): void
    {
        $this->actingAs($this->userA);

        $programacaoB = $this->criarProgramacao($this->obraB);
        $atividadeB = $this->criarAtividade($this->obraB, ['nome' => 'Atividade Sigilosa B']);
        ProgramacaoSemanalItem::create([
            'tenant_id' => $this->tenant->id, 'programacao_semanal_id' => $programacaoB->id,
            'atividade_id' => $atividadeB->id,
            'inicio_planejado_congelado' => now(), 'data_termino_congelado' => now()->addDays(5),
            'origem' => OrigemProgramacaoSemanalItem::Manual->value, 'criado_por' => $this->userA->id,
        ]);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test('pages::radar.programacoes', ['obra' => $this->obraA])->call('abrirDetalhe', $programacaoB->id)
        );
    }

    public function test_achado4_membro_sem_permissao_nao_fecha_programacao(): void
    {
        $this->actingAs($this->userASemPermissao);

        $programacaoA = $this->criarProgramacao($this->obraA);

        Livewire::test('pages::radar.programacoes', ['obra' => $this->obraA])
            ->call('fecharProgramacao', $programacaoA->id)
            ->assertForbidden();

        $this->assertSame(StatusProgramacaoSemanal::Aberta->value, $programacaoA->fresh()->status->value);
    }

    public function test_achado4_usuario_autorizado_continua_fechando_e_revisando(): void
    {
        $this->actingAs($this->userA);

        $programacaoA = $this->criarProgramacao($this->obraA);
        $atividadeA = $this->criarAtividade($this->obraA);
        // FecharProgramacaoSemanal exige ao menos 1 item — precondição de
        // negócio, nada a ver com autorização.
        ProgramacaoSemanalItem::create([
            'tenant_id' => $this->tenant->id, 'programacao_semanal_id' => $programacaoA->id,
            'atividade_id' => $atividadeA->id,
            'inicio_planejado_congelado' => now(), 'data_termino_congelado' => now()->addDays(5),
            'origem' => OrigemProgramacaoSemanalItem::Manual->value, 'criado_por' => $this->userA->id,
        ]);

        Livewire::test('pages::radar.programacoes', ['obra' => $this->obraA])
            ->call('fecharProgramacao', $programacaoA->id)
            ->assertOk();

        $this->assertSame(StatusProgramacaoSemanal::Fechada->value, $programacaoA->fresh()->status->value);

        Livewire::test('pages::radar.programacoes', ['obra' => $this->obraA])
            ->call('criarRevisao', $programacaoA->id)
            ->assertOk();

        $this->assertDatabaseCount('programacoes_semanais', 2);
    }

    // =================================================================
    // ACHADO 5 — Restrições / Detalhe da Atividade (leitura)
    // =================================================================

    public function test_achado5_ver_atividade_cross_obra_e_bloqueado_sem_vazar_dado(): void
    {
        $this->actingAs($this->userA);

        $atividadeB = $this->criarAtividade($this->obraB, ['nome' => 'Atividade Sigilosa da Obra B']);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test('pages::radar.restricoes', ['obra' => $this->obraA])->call('verAtividade', $atividadeB->id)
        );
    }

    public function test_achado5_modal_atividade_id_manipulado_direto_nao_vaza_dado(): void
    {
        // Seção 13 — defesa em profundidade: mesmo setando a propriedade
        // pública diretamente (bypass de verAtividade()), atividadeDetalhe()
        // nunca resolve a atividade de outra obra.
        $this->actingAs($this->userA);

        $atividadeB = $this->criarAtividade($this->obraB, ['nome' => 'Atividade Sigilosa da Obra B']);

        $componente = Livewire::test('pages::radar.restricoes', ['obra' => $this->obraA])
            ->set('modalAtividadeId', $atividadeB->id);

        $this->assertNull($componente->instance()->atividadeDetalhe);
        $componente->assertDontSee('Atividade Sigilosa da Obra B');
    }

    public function test_achado5_ver_atividade_da_propria_obra_continua_funcionando(): void
    {
        $this->actingAs($this->userA);

        $atividadeA = $this->criarAtividade($this->obraA, ['nome' => 'Atividade Legítima da Obra A']);

        $componente = Livewire::test('pages::radar.restricoes', ['obra' => $this->obraA])
            ->call('verAtividade', $atividadeA->id);

        $componente->assertOk();
        $this->assertNotNull($componente->instance()->atividadeDetalhe);
        $componente->assertSee('Atividade Legítima da Obra A');
    }

    // =================================================================
    // ACHADO 6 — Etiqueta de Estoque (UnidadeEstoque)
    // =================================================================

    public function test_achado6_exportar_etiqueta_unidade_cross_obra_e_bloqueado(): void
    {
        $this->actingAs($this->userA);

        $localB = \App\Models\LocalEstoque::create(['obra_id' => $this->obraB->id, 'nome' => 'Local B', 'tipo' => \App\Enums\TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
        $unidadeMedida = \App\Models\UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
        $material = \App\Models\Material::create([
            'tenant_id' => $this->tenant->id, 'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material',
            'unidade_medida_id' => $unidadeMedida->id,
            'modo_rastreabilidade' => \App\Enums\ModoRastreabilidadeMaterial::Lote->value, 'ativo' => true,
        ]);
        $unidadeB = \App\Models\UnidadeEstoque::create([
            'tenant_id' => $this->tenant->id, 'material_id' => $material->id, 'local_estoque_id' => $localB->id,
            'codigo_lote' => 'LOTE-B-001',
        ]);

        $this->assertBloqueadoPorRecursoDeOutraObra(
            fn () => Livewire::test('pages::radar.estoque', ['obra' => $this->obraA])->call('exportarEtiquetaUnidade', $unidadeB->id)
        );
    }

    public function test_achado6_exportar_etiqueta_unidade_da_propria_obra_continua_funcionando(): void
    {
        $this->actingAs($this->userA);

        $localA = \App\Models\LocalEstoque::create(['obra_id' => $this->obraA->id, 'nome' => 'Local A', 'tipo' => \App\Enums\TipoLocalEstoque::Almoxarifado->value, 'ativo' => true]);
        $unidadeMedida = \App\Models\UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN2', 'nome' => 'Unidade']);
        $material = \App\Models\Material::create([
            'tenant_id' => $this->tenant->id, 'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material',
            'unidade_medida_id' => $unidadeMedida->id,
            'modo_rastreabilidade' => \App\Enums\ModoRastreabilidadeMaterial::Lote->value, 'ativo' => true,
        ]);
        $unidadeA = \App\Models\UnidadeEstoque::create([
            'tenant_id' => $this->tenant->id, 'material_id' => $material->id, 'local_estoque_id' => $localA->id,
            'codigo_lote' => 'LOTE-A-001',
        ]);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obraA])
            ->call('exportarEtiquetaUnidade', $unidadeA->id)
            ->assertOk();
    }

    // =================================================================
    // ACHADO 7 — Relatórios de Restrições
    // =================================================================

    public function test_achado7_membro_sem_permissao_nao_acessa_relatorios(): void
    {
        $this->actingAs($this->userASemPermissao);

        // O guard vive em mount() — na PRIMEIRA renderização de um
        // componente de página cheia, uma AuthorizationException/403 é
        // interceptada por App\Exceptions\Handler::render() e vira um
        // redirect com flash.popup=acesso-negado (mesmo padrão já
        // documentado/testado em outras telas do projeto), nunca um 403
        // cru — só chamadas .call()/.set() subsequentes (com o header
        // X-Livewire) recebem o 403 direto.
        Livewire::test('pages::radar.relatorios-restricoes', ['obra' => $this->obraA])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_achado7_usuario_autorizado_continua_acessando_relatorios(): void
    {
        $this->actingAs($this->userA);

        Livewire::test('pages::radar.relatorios-restricoes', ['obra' => $this->obraA])
            ->assertOk();
    }

    // =================================================================
    // ACHADO 8 — Categorias de Restrição (tenant-wide)
    // =================================================================

    public function test_achado8_membro_sem_permissao_nao_salva_categoria(): void
    {
        $this->actingAs($this->userASemPermissao);

        Livewire::test('pages::cadastros.categorias-restricao')
            ->set('nome', 'Categoria Indevida')
            ->set('pilarLean', PilarLean::Materiais->value)
            ->call('salvar')
            ->assertStatus(403);

        $this->assertDatabaseMissing('categorias_restricao', ['nome' => 'Categoria Indevida']);
    }

    public function test_achado8_membro_sem_permissao_nao_exclui_categoria(): void
    {
        $this->actingAs($this->userASemPermissao);

        $categoria = CategoriaRestricao::create(['tenant_id' => $this->tenant->id, 'nome' => 'Categoria X', 'pilar_lean' => PilarLean::Materiais->value]);

        Livewire::test('pages::cadastros.categorias-restricao')
            ->call('excluir', $categoria->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('categorias_restricao', ['id' => $categoria->id]);
    }

    public function test_achado8_usuario_autorizado_continua_gerindo_categorias(): void
    {
        $this->actingAs($this->userA);

        Livewire::test('pages::cadastros.categorias-restricao')
            ->set('nome', 'Categoria Válida')
            ->set('pilarLean', PilarLean::Materiais->value)
            ->call('salvar')
            ->assertOk();

        $this->assertDatabaseHas('categorias_restricao', ['nome' => 'Categoria Válida']);

        $categoria = CategoriaRestricao::where('nome', 'Categoria Válida')->firstOrFail();

        Livewire::test('pages::cadastros.categorias-restricao')
            ->call('excluir', $categoria->id)
            ->assertOk();

        $this->assertDatabaseMissing('categorias_restricao', ['id' => $categoria->id]);
    }

    // =================================================================
    // MINHAS OBRAS — gap de listagem sem vínculo
    // =================================================================

    public function test_minhas_obras_so_lista_obras_com_vinculo_real(): void
    {
        $this->actingAs($this->userA);

        $componente = Livewire::test('pages::gestao.minhas-obras');

        $componente->assertSee($this->obraA->name);
        $componente->assertDontSee($this->obraB->name);
    }

    public function test_minhas_obras_acesso_direto_a_obra_sem_vinculo_continua_bloqueado(): void
    {
        $this->actingAs($this->userA);

        $this->get(route('radar.entrar', $this->obraB->id))->assertRedirect();
        $this->assertFalse($this->userA->fresh()->temAcessoAObra($this->obraB));
    }

    // =================================================================
    // MENU — cabeçalho vazio
    // =================================================================

    public function test_menu_cabecalho_de_secao_some_quando_nenhum_item_e_visivel(): void
    {
        // Perfil sem NENHUMA permissão em nenhum slug da seção "6. SUPRIMENTOS"
        // (só "Mapa de Suprimentos" existe hoje sob esse cabeçalho).
        $this->actingAs($this->userASemPermissao);

        $html = $this->get('/app/home')->getContent();

        $this->assertStringNotContainsString('6. SUPRIMENTOS', $html);
    }

    public function test_menu_cabecalho_de_secao_aparece_com_pelo_menos_1_item_visivel(): void
    {
        $this->actingAs($this->userA);

        $html = $this->get('/app/home')->getContent();

        $this->assertStringContainsString('6. SUPRIMENTOS', $html);
        $this->assertStringContainsString('Mapa de Suprimentos', $html);
    }
}
