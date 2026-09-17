<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2C, Seção 4-18 — redesenho da tela Perfis de Acesso: listagem
 * com tipo/impacto, Novo Perfil (zero/template/existente), Duplicar,
 * Modo Simples (presets) sempre derivado das capacidades reais, nunca
 * uma segunda autoridade.
 */
class Fase2CPerfisAcessoRedesignTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $criador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);
    }

    public function test_a_listagem_mostra_tipo_padrao_e_personalizado(): void
    {
        $this->actingAs($this->criador);

        Livewire::test('pages::gestao.perfis-acesso')
            ->assertSee('Perfil Padrão DCF.ENG')
            ->assertSee('Administrador');
    }

    public function test_b_listagem_mostra_impacto_de_usuarios_e_obras(): void
    {
        $this->actingAs($this->criador);
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $encarregado = Perfil::porSlugPadrao($this->tenant, 'encarregado');
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obra, $membro, 'encarregado');

        $componente = Livewire::test('pages::gestao.perfis-acesso');
        $linha = $componente->instance()->perfisComImpacto()->firstWhere('perfil.id', $encarregado->id);

        $this->assertSame(1, $linha->usuarios);
        $this->assertSame(1, $linha->obras);
    }

    public function test_c_busca_filtra_por_nome(): void
    {
        $this->actingAs($this->criador);

        Livewire::test('pages::gestao.perfis-acesso')
            ->set('busca', 'Encarregado')
            ->assertSee('Encarregado')
            ->assertDontSee('Administrador');
    }

    public function test_d_busca_filtra_por_descricao(): void
    {
        $this->actingAs($this->criador);
        Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fiscal de Obra', 'descricao' => 'Acompanha qualidade em campo']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->set('busca', 'qualidade')
            ->assertSee('Fiscal de Obra');
    }

    public function test_e_novo_perfil_do_zero_via_modal(): void
    {
        $this->actingAs($this->criador);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirNovoPerfil')
            ->set('novoPerfilNomeCustom', 'Consultor Externo')
            ->call('confirmarNovoPerfil');

        $perfil = Perfil::where('tenant_id', $this->tenant->id)->where('nome', 'Consultor Externo')->first();
        $this->assertNotNull($perfil);
        $this->assertNull($perfil->slug_padrao);
        $this->assertSame(0, PerfilPermissao::where('perfil_id', $perfil->id)->count());
    }

    public function test_f_novo_perfil_a_partir_de_template(): void
    {
        $this->actingAs($this->criador);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirNovoPerfil')
            ->set('novoPerfilOrigem', 'template')
            ->set('novoPerfilTemplateChave', 'suprimentos')
            ->call('confirmarNovoPerfil');

        $perfil = Perfil::where('tenant_id', $this->tenant->id)->where('slug_padrao', 'especialista_suprimentos')->first();
        $this->assertNotNull($perfil);
        $this->assertTrue(
            PerfilPermissao::where('perfil_id', $perfil->id)
                ->where('funcionalidade', 'suprimentos.mapa')->where('acao', 'editar')->exists()
        );
    }

    public function test_g_novo_perfil_a_partir_de_existente_duplica(): void
    {
        $this->actingAs($this->criador);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirNovoPerfil')
            ->set('novoPerfilOrigem', 'existente')
            ->set('novoPerfilBaseId', $engenheiro->id)
            ->set('novoPerfilNomeCustom', 'Engenheiro Sênior')
            ->call('confirmarNovoPerfil');

        $copia = Perfil::where('tenant_id', $this->tenant->id)->where('nome', 'Engenheiro Sênior')->first();
        $this->assertNotNull($copia);
        $this->assertNull($copia->slug_padrao);
        $this->assertSame(
            PerfilPermissao::where('perfil_id', $engenheiro->id)->count(),
            PerfilPermissao::where('perfil_id', $copia->id)->count()
        );
    }

    public function test_h_duplicar_copia_capacidades_nunca_associacoes(): void
    {
        $this->actingAs($this->criador);
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $encarregado = $this->vincularObra($obra, $membro, 'encarregado');

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirDuplicar', $encarregado->id)
            ->set('duplicarNomeNovo', 'Encarregado Turno B')
            ->call('confirmarDuplicar');

        $copia = Perfil::where('tenant_id', $this->tenant->id)->where('nome', 'Encarregado Turno B')->first();
        $this->assertNotNull($copia);
        $this->assertNull($copia->slug_padrao);
        $this->assertSame(0, \App\Models\ObraUserPerfil::where('perfil_id', $copia->id)->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('obra_user')->where('perfil_id', $copia->id)->count());
    }

    public function test_i_modo_simples_aplicar_preset_troca_acoes_exatas(): void
    {
        $this->actingAs($this->criador);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Teste Simples']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('aplicarPreset', 'restricoes.quadro', 'colaboracao');

        $acoes = PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'restricoes.quadro')->pluck('acao')->sort()->values()->all();

        $this->assertSame(['comentar', 'ver'], $acoes);
    }

    public function test_j_modo_simples_preset_nunca_afeta_outra_funcionalidade(): void
    {
        $this->actingAs($this->criador);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Teste Isolamento']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'suprimentos.mapa', 'acao' => 'ver']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('aplicarPreset', 'restricoes.quadro', 'consulta');

        $this->assertTrue(
            PerfilPermissao::where('perfil_id', $perfil->id)
                ->where('funcionalidade', 'suprimentos.mapa')->where('acao', 'ver')->exists()
        );
    }

    public function test_k_preset_ativo_e_null_para_combinacao_personalizada(): void
    {
        $this->actingAs($this->criador);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Teste Custom']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'restricoes.quadro', 'acao' => 'ver']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'restricoes.quadro', 'acao' => 'excluir']);

        $componente = Livewire::test('pages::gestao.perfis-acesso')->call('selecionarAba', $perfil->id);

        $this->assertNull($componente->instance()->presetAtivoPorFuncionalidade()['restricoes.quadro']);
    }

    public function test_l_editor_mostra_impacto_correto(): void
    {
        $this->actingAs($this->criador);
        $obraA = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraA, $membro, 'engenheiro');
        $this->vincularObra($obraB, $membro, 'engenheiro');

        $componente = Livewire::test('pages::gestao.perfis-acesso')->call('selecionarAba', $engenheiro->id);
        $impacto = $componente->instance()->impactoAtivo();

        $this->assertSame(1, $impacto['usuarios']);
        $this->assertSame(2, $impacto['obras']);
    }

    public function test_m_admin_nao_aparece_com_botao_excluir(): void
    {
        $this->actingAs($this->criador);
        $admin = Perfil::porSlugPadrao($this->tenant, 'admin');

        Livewire::test('pages::gestao.perfis-acesso')
            ->assertDontSee("args: ['{$admin->id}']", false);
    }

    public function test_n_cross_tenant_novo_perfil_de_existente_nunca_aceita_id_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $perfilDeOutroTenant = Perfil::create(['tenant_id' => $outroTenant->id, 'nome' => 'De Outro Tenant']);

        $this->actingAs($this->criador);
        $totalAntes = Perfil::where('tenant_id', $this->tenant->id)->count();

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirNovoPerfil')
            ->set('novoPerfilOrigem', 'existente')
            ->set('novoPerfilBaseId', $perfilDeOutroTenant->id)
            ->call('confirmarNovoPerfil')
            ->assertHasErrors('novoPerfilBaseId');

        $this->assertSame($totalAntes, Perfil::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_o_selecionar_aba_de_outro_tenant_e_bloqueado(): void
    {
        $outroTenant = Tenant::factory()->create();
        $perfilDeOutroTenant = Perfil::create(['tenant_id' => $outroTenant->id, 'nome' => 'De Outro Tenant']);

        $this->actingAs($this->criador);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfilDeOutroTenant->id);
    }

    public function test_p_ir_para_lista_volta_a_visualizacao_de_listagem(): void
    {
        $this->actingAs($this->criador);
        $perfil = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        $componente = Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id);
        $this->assertSame('editor', $componente->get('visualizacao'));

        $componente->call('irParaLista');
        $this->assertSame('lista', $componente->get('visualizacao'));
        $this->assertSame('', $componente->get('abaAtiva'));
    }

    /**
     * FASE 2C, fechamento adversarial, Seção 38/39 — o preview de
     * impacto não pode ser SÓ contagem: precisa mostrar concretamente o
     * que foi ganho/perdido nesta sessão de edição.
     */
    public function test_q_diff_de_permissoes_mostra_ganhos_e_perdas_da_sessao(): void
    {
        $this->actingAs($this->criador);
        $perfil = Perfil::porSlugPadrao($this->tenant, 'engenheiro');

        $componente = Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id);

        // Nada mudou ainda — diff vazio.
        $diff = $componente->instance()->diffPermissoesSessao();
        $this->assertSame([], $diff['adicionadas']);
        $this->assertSame([], $diff['removidas']);

        // Engenheiro já tem 'editar' em restricoes.quadro — remover, e
        // adicionar algo que ele não tinha (excluir, tier Admin/GP).
        $componente->call('togglePermissao', 'restricoes.quadro', 'editar');
        $componente->call('togglePermissao', 'restricoes.quadro', 'excluir');

        $diff = $componente->instance()->diffPermissoesSessao();
        $this->assertContains('Quadro de Restrições — Excluir', $diff['adicionadas']);
        $this->assertContains('Quadro de Restrições — Editar', $diff['removidas']);
    }

    public function test_q2_diff_reseta_ao_trocar_de_perfil(): void
    {
        $this->actingAs($this->criador);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        $componente = Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $engenheiro->id)
            ->call('togglePermissao', 'restricoes.quadro', 'excluir');

        $diff = $componente->instance()->diffPermissoesSessao();
        $this->assertNotEmpty($diff['adicionadas']);

        // Trocar de perfil re-tira o snapshot — nunca carrega o diff da
        // edição anterior pro perfil novo.
        $componente->call('selecionarAba', $encarregado->id);
        $diff = $componente->instance()->diffPermissoesSessao();
        $this->assertSame([], $diff['adicionadas']);
        $this->assertSame([], $diff['removidas']);
    }
}
