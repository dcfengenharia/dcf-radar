<?php

namespace Tests\Feature;

use App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade;
use App\Actions\Estoque\CriarMaterial;
use App\Exceptions\MaterialInvalidoException;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\OrigemCadastroMaterial;
use App\Enums\OrigemNecessidadeMaterialAtividade;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\DocumentoEngenharia;
use App\Models\FamiliaMaterial;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\ProgramacaoSemanal;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Continuação "Posto Operacional" — criação inline de Material Mestre
 * com rastreabilidade de origem (proveniência do CADASTRO, nunca
 * confundida com a proveniência da NECESSIDADE, já coberta desde
 * AtividadeNecessidadeMaterialTest/PlanoSemanalPostoOperacionalTest).
 * Cobertura A-L do pedido de fechamento.
 */
class MaterialCadastroInlinePlanoSemanalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private UnidadeMedida $unidade;
    private string $semanaInicio;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // Admin: cobre restricoes.plano_semanal|editar E estoque.movimentacao|criar.
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);
        $this->semanaInicio = Carbon::now()->startOfWeek()->toDateString();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function componente()
    {
        return Livewire::test('pages::radar.plano-semanal', ['obra' => $this->obra]);
    }

    private function atividadeNaSemana(array $overrides = []): Atividade
    {
        $inicioSemana = Carbon::parse($this->semanaInicio);

        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'status' => StatusAtividade::Planejado->value,
            'inicio_planejado' => $inicioSemana->copy()->addDay(),
            'data_termino' => $inicioSemana->copy()->addDays(3),
        ], $overrides));
    }

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(),
            'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ], $overrides));
    }

    private function criarItemTakeOff(?Material $material, float $quantidade): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'unidade_medida_id' => $this->unidade->id,
            'quantidade' => $quantidade, 'material_id' => $material?->id,
        ]);
    }

    /** Perfil com restricoes.plano_semanal|editar mas SEM estoque.movimentacao|criar — as 2 concessões são independentes por design. */
    private function usuarioSemPermissaoDeCriarMaterial(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Só Plano Semanal ' . uniqid()]);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'restricoes.plano_semanal', 'acao' => 'ver']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'restricoes.plano_semanal', 'acao' => 'editar']);
        $this->obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);

        return $user;
    }

    // ===================== A: Material existente =====================

    public function test_a_material_existente_selecionado_origem_operacional_preservada(): void
    {
        $at = $this->atividadeNaSemana();
        $material = $this->criarMaterial(['codigo' => 'MAT-EXIST-' . uniqid()]);
        // O helper de teste (Material::create() cru) nunca informa
        // origem_cadastro — o valor 'catalogo' só existe como DEFAULT no
        // banco, nunca reidratado automaticamente no objeto em memória
        // recém-criado (mesmo comportamento padrão do Eloquent pra
        // qualquer coluna com DEFAULT do banco) — por isso ->fresh().
        $this->assertSame(OrigemCadastroMaterial::Catalogo, $material->fresh()->origem_cadastro);

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('buscaMaterialNecessidade', $material->codigo)
            ->set('materialIdNovaNecessidade', $material->id)
            ->set('quantidadeNovaNecessidade', 25)
            ->set('observacaoNovaNecessidade', 'Necessário pra execução')
            ->call('salvarNecessidade');

        $necessidade = AtividadeNecessidadeMaterial::where('atividade_id', $at->id)->firstOrFail();
        $this->assertSame(OrigemNecessidadeMaterialAtividade::Operacional, $necessidade->origem);
        $this->assertSame($material->id, $necessidade->material_id);
        // A proveniência do CADASTRO do Material nunca muda por ele ser usado.
        $this->assertSame(OrigemCadastroMaterial::Catalogo, $material->fresh()->origem_cadastro);
    }

    // ===================== B: Material novo inline =====================

    public function test_b_material_novo_inline_proveniencia_plano_semanal_selecao_automatica_e_necessidade_operacional(): void
    {
        $at = $this->atividadeNaSemana();

        $componente = $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('buscaMaterialNecessidade', 'Abraçadeira')
            ->call('abrirModalNovoMaterial')
            ->assertSet('modalNovoMaterialAberto', true)
            ->assertSet('novoMaterialDescricao', 'Abraçadeira')
            ->set('novoMaterialCodigo', 'ABR-XYZ')
            ->set('novoMaterialDescricao', 'Abraçadeira especial XYZ')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->call('salvarNovoMaterialInline');

        $material = Material::where('codigo', 'ABR-XYZ')->firstOrFail();
        $this->assertSame(OrigemCadastroMaterial::PlanoSemanal, $material->origem_cadastro);
        $this->assertTrue($material->ativo);

        // Seleção automática: modal de necessidade continua aberto, Material já selecionado.
        $componente
            ->assertSet('modalNovoMaterialAberto', false)
            ->assertSet('materialIdNovaNecessidade', $material->id)
            ->assertSet('modalNecessidadeAberto', true);

        $componente
            ->set('quantidadeNovaNecessidade', 4)
            ->set('observacaoNovaNecessidade', 'Fixação emergencial de campo')
            ->call('salvarNecessidade');

        $necessidade = AtividadeNecessidadeMaterial::where('atividade_id', $at->id)->firstOrFail();
        $this->assertSame(OrigemNecessidadeMaterialAtividade::Operacional, $necessidade->origem);
        $this->assertSame($material->id, $necessidade->material_id);
        $this->assertSame(4.0, (float) $necessidade->quantidade_necessaria);
    }

    // ===================== C: Material novo + cancelar necessidade =====================

    public function test_c_material_novo_inline_com_necessidade_cancelada_permanece_no_catalogo(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'CANCEL-001')
            ->set('novoMaterialDescricao', 'Material Cancelado Depois')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->call('salvarNovoMaterialInline')
            // "Cancela" a necessidade fechando o modal sem chamar salvarNecessidade().
            ->set('modalNecessidadeAberto', false);

        $material = Material::where('codigo', 'CANCEL-001')->first();
        $this->assertNotNull($material, 'Material precisa permanecer no catálogo mesmo com a necessidade cancelada.');
        $this->assertSame(OrigemCadastroMaterial::PlanoSemanal, $material->origem_cadastro);
        $this->assertDatabaseCount('atividade_necessidades_material', 0);
    }

    // ===================== D: TakeOff nunca ganha proveniência falsa =====================

    public function test_d_necessidade_take_off_nunca_ganha_origem_operacional_nem_botao_de_criar_material(): void
    {
        $at = $this->atividadeNaSemana();
        $item = $this->criarItemTakeOff(null, 1000);

        $componente = $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'take_off')
            ->assertDontSee('Criar novo Material');

        $componente
            ->set('buscaItemTakeOffNecessidade', $item->codigo)
            ->set('itemTakeOffIdNovaNecessidade', $item->id)
            ->set('quantidadeNovaNecessidade', 300)
            ->call('salvarNecessidade');

        $necessidade = AtividadeNecessidadeMaterial::where('atividade_id', $at->id)->firstOrFail();
        $this->assertSame(OrigemNecessidadeMaterialAtividade::TakeOff, $necessidade->origem);
        $this->assertNull($necessidade->material_id);
        $this->assertSame($item->id, $necessidade->item_take_off_id);

        $componente
            ->call('fecharAtividadeDetalhe')
            ->call('verAtividadeDetalhe', $at->id)
            ->assertSee('Origem: Conforme Engenharia / TakeOff')
            ->assertDontSee('Origem: Necessidade operacional');
    }

    // ===================== E: Material de outro contexto usado operacionalmente =====================

    public function test_e_material_criado_no_catalogo_usado_operacionalmente_preserva_proveniencia_historica(): void
    {
        $at = $this->atividadeNaSemana();
        // Material que já existia "há meses", cadastrado pelo caminho oficial (Estoque).
        $material = $this->criarMaterial(['codigo' => 'TUBO-A106-6', 'descricao' => 'Tubo ASTM A106 6"']);
        $this->assertSame(OrigemCadastroMaterial::Catalogo, $material->fresh()->origem_cadastro);

        app(AtualizarNecessidadeMaterialAtividade::class)->criarOperacional($at, $material, 20, 'Necessário pra esta atividade', $this->user);

        $this->assertSame(OrigemCadastroMaterial::Catalogo, $material->fresh()->origem_cadastro);
    }

    // ===================== F: Nenhum vínculo artificial =====================

    public function test_f_criacao_inline_nunca_cria_vinculo_artificial(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'SEM-VINCULO')
            ->set('novoMaterialDescricao', 'Material Sem Vínculo Artificial')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->call('salvarNovoMaterialInline');

        $this->assertDatabaseCount('itens_take_off', 0);
        $this->assertDatabaseCount('listas_engenharia', 0);
        $this->assertDatabaseCount('documentos_engenharia', 0);
        $this->assertDatabaseCount('requisicoes_planejamento', 0);
        $this->assertDatabaseCount('requisicoes_compra', 0);
        $this->assertDatabaseCount('pedidos_compra', 0);
        $this->assertDatabaseCount('reservas_estoque', 0);
        $this->assertDatabaseCount('movimentacoes_estoque', 0);
        $this->assertDatabaseCount('atividade_necessidades_material', 0);
        $this->assertDatabaseCount('materiais', 1);
    }

    // ===================== G: Tenant isolation =====================

    public function test_g_material_criado_e_sempre_escopado_ao_tenant_atual_mesmo_com_codigo_repetido_em_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $u = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'MX', 'nome' => 'Metro X']);
            Material::create([
                'tenant_id' => $outroTenant->id, 'codigo' => 'CODIGO-REPETIDO', 'descricao' => 'Material de outro tenant',
                'unidade_medida_id' => $u->id, 'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
            ]);
        });

        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'CODIGO-REPETIDO')
            ->set('novoMaterialDescricao', 'Material do MEU tenant')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->call('salvarNovoMaterialInline')
            ->assertHasNoErrors();

        $meuMaterial = Material::where('tenant_id', $this->tenant->id)->where('codigo', 'CODIGO-REPETIDO')->first();
        $this->assertNotNull($meuMaterial, 'Código repetido em OUTRO tenant nunca deveria bloquear a criação no tenant atual.');
        $this->assertSame($this->tenant->id, $meuMaterial->tenant_id);
    }

    // ===================== H: Permission =====================

    public function test_h_usuario_sem_permissao_de_criar_material_nao_ve_botao_nem_consegue_executar(): void
    {
        $leitor = $this->usuarioSemPermissaoDeCriarMaterial();
        $this->actingAs($leitor);

        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('buscaMaterialNecessidade', 'qualquer')
            ->assertDontSee('Criar novo Material');

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->call('abrirModalNovoMaterial')
            ->assertForbidden();

        $this->assertDatabaseCount('materiais', 0);
    }

    // ===================== I: Validação =====================

    public function test_i_campos_obrigatorios_do_cadastro_oficial_continuam_iguais(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', '')
            ->set('novoMaterialDescricao', '')
            ->set('novoMaterialUnidadeMedidaId', null)
            ->call('salvarNovoMaterialInline')
            ->assertHasErrors(['novoMaterialCodigo', 'novoMaterialDescricao', 'novoMaterialUnidadeMedidaId']);

        $this->assertDatabaseCount('materiais', 0);
    }

    // ===================== J: Duplicidade =====================

    public function test_j_codigo_duplicado_no_mesmo_tenant_e_rejeitado_com_mensagem_amigavel(): void
    {
        $this->criarMaterial(['codigo' => 'JA-EXISTE']);
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'JA-EXISTE')
            ->set('novoMaterialDescricao', 'Tentativa Duplicada')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->call('salvarNovoMaterialInline')
            ->assertHasErrors(['novoMaterialCodigo']);

        $this->assertDatabaseCount('materiais', 1);
    }

    // ===================== K: PPC neutrality =====================

    public function test_k_criar_material_inline_e_necessidade_nunca_altera_ppc(): void
    {
        $comprometida = $this->atividadeNaSemana(['status' => StatusAtividade::Comprometido->value]);
        $at = $this->atividadeNaSemana();

        $componente = $this->componente();
        $ppcAntes = $componente->instance()->ppc;

        $componente
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'PPC-NEUTRO')
            ->set('novoMaterialDescricao', 'Material Neutro pro PPC')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->call('salvarNovoMaterialInline')
            ->set('quantidadeNovaNecessidade', 3)
            ->set('observacaoNovaNecessidade', 'Justificativa qualquer')
            ->call('salvarNecessidade');

        $this->assertSame($ppcAntes, $componente->instance()->ppc);
    }

    // ===================== L: Popup — origem operacional visível =====================

    public function test_l_apos_salvar_origem_operacional_plano_semanal_fica_visivel_no_popup(): void
    {
        $at = $this->atividadeNaSemana();

        $componente = $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'VISIVEL-001')
            ->set('novoMaterialDescricao', 'Material Visível no Popup')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->call('salvarNovoMaterialInline')
            ->set('quantidadeNovaNecessidade', 10)
            ->set('observacaoNovaNecessidade', 'Justificativa qualquer')
            ->call('salvarNecessidade');

        $componente
            ->assertSee('Material Visível no Popup')
            ->assertSee('Origem: Necessidade operacional — Plano Semanal')
            ->assertDontSee('Origem: Conforme Engenharia / TakeOff');
    }

    // =========================================================
    // HARDENING — isolamento tenant de unidade_medida_id/familia_material_id
    // (achado do relatório de fechamento: exists:.. no validate() do
    // Livewire nunca prova pertencimento ao tenant; a garantia real
    // agora vive na própria Action CriarMaterial).
    // =========================================================

    /** Cria uma UnidadeMedida/FamiliaMaterial num tenant B qualquer, fora do tenant do teste. */
    private function catalogoDoOutroTenant(): array
    {
        $outroTenant = Tenant::factory()->create();

        return \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $unidade = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'MB', 'nome' => 'Metro B']);
            $familia = FamiliaMaterial::create(['tenant_id' => $outroTenant->id, 'nome' => 'Família B']);

            return [$outroTenant, $unidade, $familia];
        });
    }

    // ---- A: unidade_medida_id de outro tenant, via popup (payload manipulado) ----

    public function test_hardening_a_unidade_de_outro_tenant_via_popup_e_rejeitada(): void
    {
        [, $unidadeOutroTenant] = $this->catalogoDoOutroTenant();
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'ADVERSARIAL-A')
            ->set('novoMaterialDescricao', 'Tentativa com unidade de outro tenant')
            // Manipulação direta da propriedade Livewire, simulando um
            // payload forjado — nunca algo que o <select> real ofereceria.
            ->set('novoMaterialUnidadeMedidaId', $unidadeOutroTenant->id)
            ->call('salvarNovoMaterialInline')
            ->assertHasErrors(['novoMaterialUnidadeMedidaId']);

        // assertDatabaseCount() não aceita mensagem custom como 3º
        // argumento (é o nome da connection) — nunca passar string livre ali.
        $this->assertDatabaseCount('materiais', 0);
    }

    // ---- B: familia_material_id de outro tenant, via popup (payload manipulado) ----

    public function test_hardening_b_familia_de_outro_tenant_via_popup_e_rejeitada(): void
    {
        [, , $familiaOutroTenant] = $this->catalogoDoOutroTenant();
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'ADVERSARIAL-B')
            ->set('novoMaterialDescricao', 'Tentativa com família de outro tenant')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->set('novoMaterialFamiliaId', $familiaOutroTenant->id)
            ->call('salvarNovoMaterialInline')
            ->assertHasErrors(['novoMaterialUnidadeMedidaId']);

        $this->assertDatabaseCount('materiais', 0);
    }

    // ---- C: unidade + família, ambas do tenant atual → criação normal ----

    public function test_hardening_c_unidade_e_familia_do_tenant_atual_criacao_normal(): void
    {
        $familia = FamiliaMaterial::create(['tenant_id' => $this->tenant->id, 'nome' => 'Família do Tenant Atual']);
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'NORMAL-C')
            ->set('novoMaterialDescricao', 'Material com Unidade e Família do Tenant')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->set('novoMaterialFamiliaId', $familia->id)
            ->call('salvarNovoMaterialInline')
            ->assertHasNoErrors();

        $material = Material::where('codigo', 'NORMAL-C')->firstOrFail();
        $this->assertSame($this->unidade->id, $material->unidade_medida_id);
        $this->assertSame($familia->id, $material->familia_material_id);
    }

    // ---- D: família nullable → criação normal sem família ----

    public function test_hardening_d_familia_nula_continua_permitindo_criacao(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'NORMAL-D')
            ->set('novoMaterialDescricao', 'Material Sem Família')
            ->set('novoMaterialUnidadeMedidaId', $this->unidade->id)
            ->set('novoMaterialFamiliaId', null)
            ->call('salvarNovoMaterialInline')
            ->assertHasNoErrors();

        $material = Material::where('codigo', 'NORMAL-D')->firstOrFail();
        $this->assertNull($material->familia_material_id);
    }

    // ---- E: a proteção vive na Action, independente do caller Livewire ----

    public function test_hardening_e_action_criarmaterial_rejeita_diretamente_sem_nenhum_caller_livewire(): void
    {
        [, $unidadeOutroTenant, $familiaOutroTenant] = $this->catalogoDoOutroTenant();

        $this->expectException(MaterialInvalidoException::class);

        try {
            app(CriarMaterial::class)->execute(
                'DIRETO-UNIDADE',
                'Chamada direta com unidade de outro tenant',
                $unidadeOutroTenant->id,
                null,
                ModoRastreabilidadeMaterial::Quantitativo->value,
                OrigemCadastroMaterial::PlanoSemanal,
            );
        } finally {
            $this->assertDatabaseCount('materiais', 0);
        }
    }

    public function test_hardening_e2_action_criarmaterial_rejeita_familia_de_outro_tenant_diretamente(): void
    {
        [, , $familiaOutroTenant] = $this->catalogoDoOutroTenant();

        $this->expectException(MaterialInvalidoException::class);

        try {
            app(CriarMaterial::class)->execute(
                'DIRETO-FAMILIA',
                'Chamada direta com família de outro tenant',
                $this->unidade->id,
                $familiaOutroTenant->id,
                ModoRastreabilidadeMaterial::Quantitativo->value,
                OrigemCadastroMaterial::Catalogo,
            );
        } finally {
            $this->assertDatabaseCount('materiais', 0);
        }
    }

    // ---- F: já coberto por A/B (payload manipulado via set() simula exatamente isso) ----
    // ---- G: tela de Estoque continua funcionando com a mesma Action — coberto por
    //         EstoquePageTest::test_* (salvarMaterial), revalidado na regressão desta rodada.

    // =========================================================
    // CORREÇÃO TARGETED — "+ Criar novo Material" não abria no
    // navegador (Seção 7 do pedido de correção, itens A-N)
    // =========================================================

    /**
     * Item A/B — Achado real (reproduzido em navegador de verdade, não
     * só via Livewire::test()): o sub-modal "Novo Material" renderizava
     * corretamente no HTML (Livewire::test()->assertSee() já passava
     * ANTES desta correção), mas com `z-index` MENOR que os modais já
     * abertos por baixo dele (o tema deste projeto computa `z-index:1090`
     * pros modais "Adicionar Material"/detalhe da atividade — confirmado
     * via `getComputedStyle()` real no navegador) — o sub-modal renderizava
     * literalmente ATRÁS dos outros, invisível e inclicável (o backdrop
     * do modal de cima intercepta 100% dos cliques). Corrigido subindo
     * pra `z-index:1100`.
     *
     * **Limitação explícita**: `Livewire::test()` nunca executa CSS/
     * layout — não é capaz de provar que um elemento está VISUALMENTE
     * por cima de outro. Esta asserção é só um guarda ESTRUTURAL contra
     * REGREDIR pro mesmo erro (nunca baixar o z-index de novo) — a prova
     * real de que o modal aparece e recebe cliques foi feita
     * manualmente nesta rodada, navegando de verdade no app (login real,
     * obra/atividade reais, clique real no botão, `getComputedStyle()`/
     * `elementFromPoint()` confirmando que o modal "Novo Material" fica
     * por cima e recebe o clique nos 3 pontos testados).
     */
    public function test_hardening_f_submodal_renderiza_com_zindex_acima_dos_modais_ja_abertos(): void
    {
        $at = $this->atividadeNaSemana();

        $html = $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->html();

        $this->assertMatchesRegularExpression('/Novo Material/', $html);

        // O `style` com z-index fica no <div class="modal"> que ENVOLVE o
        // heading "Novo Material" (portanto vem ANTES dele no HTML bruto,
        // nunca depois) — extrai todo z-index inline presente e confirma
        // que pelo menos um deles é MAIOR que 1090 (valor real já usado
        // pelos outros 2 modais empilhados nesta mesma tela, confirmado
        // empiricamente no navegador) — nunca um número "no escuro".
        preg_match_all('/z-index:\s*(\d+)/', $html, $m);
        $this->assertNotEmpty($m[1], 'Nenhum z-index inline encontrado no HTML renderizado.');
        $this->assertGreaterThan(1090, max(array_map('intval', $m[1])));
    }

    /** Item B — o formulário de criação (heading "Novo Material" + campos) fica visível após o clique. */
    public function test_hardening_g_formulario_de_criacao_fica_visivel_apos_o_clique(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->assertSee('Novo Material')
            ->assertSee('Salvar e usar')
            ->assertSee('criado a partir do Plano Semanal');
    }

    /** Item C — cancelar o cadastro de Material retorna pro modal "Adicionar Material" (que nunca fechou). */
    public function test_hardening_h_cancelar_material_retorna_para_adicionar_material(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->call('abrirModalNovoMaterial')
            ->assertSet('modalNovoMaterialAberto', true)
            ->call('fecharModalNovoMaterial')
            ->assertSet('modalNovoMaterialAberto', false)
            ->assertSet('modalNecessidadeAberto', true)
            ->assertSee('Adicionar Material');
    }

    /** Item D — cancelar o cadastro de Material preserva quantidade/justificativa já digitadas na necessidade. */
    public function test_hardening_i_cancelar_material_preserva_quantidade_e_justificativa_ja_digitadas(): void
    {
        $at = $this->atividadeNaSemana();

        $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional')
            ->set('quantidadeNovaNecessidade', 42)
            ->set('observacaoNovaNecessidade', 'Justificativa já digitada antes de abrir o sub-modal')
            ->call('abrirModalNovoMaterial')
            ->set('novoMaterialCodigo', 'ABANDONADO')
            ->call('fecharModalNovoMaterial')
            ->assertSet('quantidadeNovaNecessidade', 42.0)
            ->assertSet('observacaoNovaNecessidade', 'Justificativa já digitada antes de abrir o sub-modal');
    }

    /** Item N — abrir/cancelar/reabrir repetidamente nunca deixa estado residual (código do tentativa anterior nunca vaza pra próxima). */
    public function test_hardening_j_abrir_cancelar_reabrir_repetidamente_nao_deixa_estado_residual(): void
    {
        $at = $this->atividadeNaSemana();

        $componente = $this->componente()
            ->call('verAtividadeDetalhe', $at->id)
            ->call('abrirModalNecessidade')
            ->set('origemNovaNecessidade', 'operacional');

        for ($i = 1; $i <= 3; $i++) {
            $componente
                ->call('abrirModalNovoMaterial')
                ->set('novoMaterialCodigo', "TENTATIVA-{$i}")
                ->set('novoMaterialDescricao', "Descrição tentativa {$i}")
                ->call('fecharModalNovoMaterial')
                ->assertSet('modalNovoMaterialAberto', false);
        }

        // Reabrir uma última vez: o código digitado na tentativa anterior
        // (nunca salva) não deveria vazar pra cá — resetModalNovoMaterial
        // implícito de abrirModalNovoMaterial() sempre limpa os campos.
        $componente->call('abrirModalNovoMaterial')->assertSet('novoMaterialCodigo', '');

        $this->assertDatabaseCount('materiais', 0);
    }
}
