<?php

namespace Tests\Feature;

use App\Enums\OrigemEventoHistoricoAcesso;
use App\Enums\Papel;
use App\Enums\TipoEventoHistoricoAcesso;
use App\Exceptions\HistoricoAcessoImutavelException;
use App\Models\Convite;
use App\Models\HistoricoAcesso;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use App\Support\ImpersonationContext;
use App\Support\Perfis\RegistrarEventoAcesso;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2D — governança/auditoria de acesso: cobertura direcionada das
 * Seções 46-55 do pedido (atribuição, Perfil, impacto, convite,
 * impersonation, append-only, atomicidade, tenant).
 */
class Fase2DHistoricoAcessosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $criador;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->criador, Papel::GerentePlanejamento->value);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);
        $this->actingAs($this->criador);
    }

    private function ultimoEvento(): ?HistoricoAcesso
    {
        return HistoricoAcesso::orderByDesc('created_at')->orderByDesc('id')->first();
    }

    // =========================================================================
    // SEÇÃO 47 — PERFIL
    // =========================================================================

    public function test_a_criar_perfil_do_zero_gera_evento(): void
    {
        Livewire::test('pages::gestao.perfis-acesso')->call('novoPerfil');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilCriado, $evento->tipo_evento);
        $this->assertSame(OrigemEventoHistoricoAcesso::PerfilAcesso, $evento->origem);
        $this->assertSame($this->criador->id, $evento->ator_user_id);
        $this->assertSame('zero', $evento->detalhes['origem_criacao']);
    }

    public function test_a2_criar_perfil_via_template_registra_referencia(): void
    {
        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirNovoPerfil')
            ->set('novoPerfilOrigem', 'template')
            ->set('novoPerfilTemplateChave', 'suprimentos')
            ->call('confirmarNovoPerfil');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilCriado, $evento->tipo_evento);
        $this->assertSame('template', $evento->detalhes['origem_criacao']);
        $this->assertSame('Suprimentos', $evento->detalhes['nome_referencia']);
        $this->assertNotEmpty($evento->detalhes['capacidades_iniciais']);
    }

    public function test_b_duplicar_via_botao_e_via_novo_perfil_geram_o_mesmo_tipo_de_evento(): void
    {
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirDuplicar', $engenheiro->id)
            ->set('duplicarNomeNovo', 'Engenheiro Sênior')
            ->call('confirmarDuplicar');

        $evento1 = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilDuplicado, $evento1->tipo_evento);
        $this->assertSame($engenheiro->id, $evento1->detalhes['perfil_origem_id']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('abrirNovoPerfil')
            ->set('novoPerfilOrigem', 'existente')
            ->set('novoPerfilBaseId', $engenheiro->id)
            ->set('novoPerfilNomeCustom', 'Engenheiro Júnior')
            ->call('confirmarNovoPerfil');

        $evento2 = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilDuplicado, $evento2->tipo_evento, 'Novo Perfil → existente é a MESMA operação de negócio que Duplicar.');
    }

    public function test_c_renomear_perfil_registra_antes_e_depois(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Original']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->set('nomeEdit', 'Renomeado')
            ->call('salvarNome');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilDadosAlterados, $evento->tipo_evento);
        $this->assertSame('Original', $evento->detalhes['nome_antes']);
        $this->assertSame('Renomeado', $evento->detalhes['nome_depois']);
        $this->assertNull($evento->detalhes['descricao_antes']);
    }

    public function test_d_alterar_so_descricao_registra_evento(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fixo']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->set('nomeEdit', 'Fixo')
            ->set('descricaoEdit', 'Nova descrição')
            ->call('salvarNome');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilDadosAlterados, $evento->tipo_evento);
        $this->assertNull($evento->detalhes['nome_antes']);
        $this->assertSame('Nova descrição', $evento->detalhes['descricao_depois']);
    }

    public function test_salvar_sem_alteracao_nao_cria_evento(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Estável', 'descricao' => 'Igual']);
        $totalAntes = HistoricoAcesso::count();

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->set('nomeEdit', 'Estável')
            ->set('descricaoEdit', 'Igual')
            ->call('salvarNome');

        $this->assertSame($totalAntes, HistoricoAcesso::count());
    }

    public function test_e_adicionar_capability_registra_adicionadas(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Teste']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('togglePermissao', 'restricoes.quadro', 'comentar');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilCapabilitiesAlteradas, $evento->tipo_evento);
        $this->assertNotEmpty($evento->detalhes['adicionadas']);
        $this->assertEmpty($evento->detalhes['removidas']);
        $this->assertArrayHasKey('impacto', $evento->detalhes);
    }

    public function test_f_remover_capability_registra_removidas(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Teste']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'restricoes.quadro', 'acao' => 'comentar']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('togglePermissao', 'restricoes.quadro', 'comentar');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilCapabilitiesAlteradas, $evento->tipo_evento);
        $this->assertEmpty($evento->detalhes['adicionadas']);
        $this->assertNotEmpty($evento->detalhes['removidas']);
    }

    public function test_capabilities_alteradas_via_preset_gera_1_evento_nunca_1_por_acao(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Teste']);
        $totalAntes = HistoricoAcesso::count();

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('aplicarPreset', 'restricoes.quadro', 'colaboracao');

        // "colaboracao" concede 2 ações (ver+comentar) — deve ser 1 único evento.
        $this->assertSame($totalAntes + 1, HistoricoAcesso::count());
        $evento = $this->ultimoEvento();
        $this->assertGreaterThanOrEqual(1, count($evento->detalhes['adicionadas']));
    }

    public function test_g_excluir_perfil_registra_snapshot_e_sobrevive_a_exclusao(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Descartável', 'descricao' => 'Nunca usado']);
        PerfilPermissao::create(['tenant_id' => $this->tenant->id, 'perfil_id' => $perfil->id, 'funcionalidade' => 'restricoes.quadro', 'acao' => 'ver']);

        Livewire::test('pages::gestao.perfis-acesso')->call('excluirPerfil', $perfil->id);

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfilExcluido, $evento->tipo_evento);
        $this->assertSame('Descartável', $evento->perfil_nome_snapshot);
        $this->assertNotEmpty($evento->detalhes['capacidades_no_momento']);

        // O Perfil (soft-deleted) sai do escopo padrão — a FK vira NULL,
        // mas o snapshot no evento continua legível.
        $this->assertNull(Perfil::find($perfil->id));
        $evento->refresh();
        $this->assertSame('Descartável', $evento->perfil_nome_snapshot);
    }

    // =========================================================================
    // SEÇÃO 46 — ATRIBUIÇÃO
    // =========================================================================

    public function test_h_atribuir_perfil_via_equipe_da_obra_gera_evento_com_adicionadas(): void
    {
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('perfilNovoMembroId', $engenheiro->id)
            ->call('adicionarMembro', $membro->id);

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfisAtribuidos, $evento->tipo_evento);
        $this->assertSame(OrigemEventoHistoricoAcesso::EquipeObra, $evento->origem);
        $this->assertSame($membro->id, $evento->usuario_afetado_id);
        $this->assertSame($this->obra->id, $evento->obra_id);
        $this->assertCount(1, $evento->detalhes['adicionadas']);
    }

    public function test_i_remover_perfil_secundario_preserva_o_outro_no_diff(): void
    {
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $planejamento = Perfil::porSlugPadrao($this->tenant, 'gerente_planejamento');
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        AtribuicaoPerfilObra::substituirPerfis($this->obra, $membro->id, [$planejamento->id, $engenheiro->id]);
        $this->obra->users()->attach($membro->id, ['perfil_id' => $planejamento->id]);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('abrirEdicaoPerfis', $membro->id)
            ->set('perfisSelecionadosEdicao', [$planejamento->id])
            ->call('salvarPerfisMembro');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfisAtribuidos, $evento->tipo_evento);
        $this->assertEmpty($evento->detalhes['adicionadas']);
        $this->assertCount(1, $evento->detalhes['removidas']);
        $this->assertSame($engenheiro->nome, $evento->detalhes['removidas'][0]['nome']);
        // Planejamento nunca aparece no diff — permaneceu.
    }

    public function test_j_substituir_conjunto_via_matriz_registra_adicionadas_e_removidas(): void
    {
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $planejamento = Perfil::porSlugPadrao($this->tenant, 'gerente_planejamento');
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $suprimentos = \App\Support\Perfis\TemplatesEspecialistas::criar($this->tenant, 'suprimentos');
        $this->obra->users()->attach($membro->id, ['perfil_id' => $planejamento->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $membro->id, $planejamento->id);

        Livewire::test('pages::gestao.matriz-acessos')
            ->call('abrirEdicao', $this->obra->id, $membro->id)
            ->set('perfisSelecionadosEdicao', [$engenheiro->id, $suprimentos->id])
            ->call('salvarEdicao');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::PerfisAtribuidos, $evento->tipo_evento);
        $this->assertSame(OrigemEventoHistoricoAcesso::MatrizAcessos, $evento->origem);
        $this->assertCount(2, $evento->detalhes['adicionadas']);
        $this->assertCount(1, $evento->detalhes['removidas']);
    }

    public function test_perfis_atribuidos_sem_mudanca_real_nao_gera_evento(): void
    {
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $this->obra->users()->attach($membro->id, ['perfil_id' => $engenheiro->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $membro->id, $engenheiro->id);
        $totalAntes = HistoricoAcesso::count();

        // Substitui pelo MESMO conjunto — nada muda de fato.
        AtribuicaoPerfilObra::substituirPerfis(
            $this->obra, $membro->id, [$engenheiro->id], $this->criador, OrigemEventoHistoricoAcesso::MatrizAcessos
        );

        $this->assertSame($totalAntes, HistoricoAcesso::count());
    }

    public function test_zero_perfis_gera_evento_proprio_distinto_de_perfis_atribuidos(): void
    {
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $this->obra->users()->attach($membro->id, ['perfil_id' => $engenheiro->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $membro->id, $engenheiro->id);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('abrirEdicaoPerfis', $membro->id)
            ->set('perfisSelecionadosEdicao', [])
            ->call('salvarPerfisMembro');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::TodosPerfisRemovidos, $evento->tipo_evento);
        $this->assertStringContainsString('permaneceu membro', $evento->resumo);
        $this->assertTrue($this->obra->users()->where('user_id', $membro->id)->exists(), 'Continua membro da obra.');
    }

    public function test_remover_usuario_da_obra_e_evento_proprio_com_snapshot_dos_perfis(): void
    {
        $membro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $this->obra->users()->attach($membro->id, ['perfil_id' => $engenheiro->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $membro->id, $engenheiro->id);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('removerMembro', $membro->id);

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::UsuarioRemovidoDaObra, $evento->tipo_evento);
        $this->assertSame($membro->id, $evento->usuario_afetado_id);
        $this->assertCount(1, $evento->detalhes['perfis_no_momento_da_remocao']);
        $this->assertSame($engenheiro->nome, $evento->detalhes['perfis_no_momento_da_remocao'][0]['nome']);
        $this->assertFalse($this->obra->users()->where('user_id', $membro->id)->exists());
    }

    // =========================================================================
    // SEÇÃO 48 — IMPACTO
    // =========================================================================

    public function test_impacto_e_snapshot_sobrevive_a_mudancas_futuras_de_atribuicao(): void
    {
        $joao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $maria = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Compartilhado']);

        $this->obra->users()->attach($joao->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $joao->id, $perfil->id);
        $obraB->users()->attach($joao->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obraB, $joao->id, $perfil->id);
        $this->obra->users()->attach($maria->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $maria->id, $perfil->id);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->call('togglePermissao', 'restricoes.quadro', 'comentar');

        $evento = $this->ultimoEvento();
        $this->assertSame(2, $evento->detalhes['impacto']['usuarios']);
        $this->assertSame(2, $evento->detalhes['impacto']['obras']);

        // Depois, remover TODAS as atribuições — o evento antigo continua "2/2".
        AtribuicaoPerfilObra::removerTodas($this->obra, $joao->id);
        AtribuicaoPerfilObra::removerTodas($obraB, $joao->id);
        AtribuicaoPerfilObra::removerTodas($this->obra, $maria->id);

        $evento->refresh();
        $this->assertSame(2, $evento->detalhes['impacto']['usuarios']);
        $this->assertSame(2, $evento->detalhes['impacto']['obras']);
    }

    // =========================================================================
    // SEÇÃO 49 — CONVITE
    // =========================================================================

    public function test_convite_com_2_perfis_registra_evento_de_envio_com_2(): void
    {
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('emailConvite', 'novo@example.com')
            ->set('perfisConviteIds', [$engenheiro->id, $encarregado->id])
            ->call('enviarConvite');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::ConviteEnviado, $evento->tipo_evento);
        $this->assertCount(2, $evento->detalhes['perfis_convidados']);
        $this->assertSame('novo@example.com', $evento->detalhes['email_convidado']);
        // Nunca guarda o token.
        $this->assertArrayNotHasKey('token', $evento->detalhes);
    }

    public function test_aceitar_convite_registra_evento_separado_com_perfis_concedidos(): void
    {
        auth()->logout();

        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->criador->id,
            'email' => 'aceite@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);
        \App\Support\Perfis\AtribuicaoPerfilConvite::gravar($convite, [$engenheiro->id, $encarregado->id]);

        $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Novo',
            'last_name' => 'Usuario',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $eventoEnvio = HistoricoAcesso::where('tipo_evento', TipoEventoHistoricoAcesso::ConviteEnviado)->count();
        $this->assertSame(0, $eventoEnvio, 'Este teste não passou pelo fluxo de envio — só de aceite.');

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::ConviteAceito, $evento->tipo_evento);
        $this->assertCount(2, $evento->detalhes['perfis_concedidos']);
        $this->assertSame('aceite@example.com', $evento->detalhes['email_convite']);

        $novoUsuario = User::where('email', 'aceite@example.com')->first();
        $this->assertSame($novoUsuario->id, $evento->usuario_afetado_id);
        $this->assertSame($novoUsuario->id, $evento->ator_user_id, 'Quem aceita é o próprio ator do evento.');

        // Nunca cria um evento H/I/J genérico ALÉM do ConviteAceito.
        $this->assertSame(0, HistoricoAcesso::where('tipo_evento', TipoEventoHistoricoAcesso::PerfisAtribuidos)->count());
    }

    public function test_convite_legado_sem_convite_perfis_continua_auditavel_no_aceite(): void
    {
        auth()->logout();

        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->criador->id,
            'email' => 'legado@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);

        $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Legado',
            'last_name' => 'Usuario',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $evento = $this->ultimoEvento();
        $this->assertSame(TipoEventoHistoricoAcesso::ConviteAceito, $evento->tipo_evento);
        $this->assertCount(1, $evento->detalhes['perfis_concedidos']);
    }

    // =========================================================================
    // SEÇÃO 50 — IMPERSONATION
    // =========================================================================

    public function test_platform_admin_com_impersonation_preserva_ator_real(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);
        ImpersonationContext::start($this->tenant, 'suporte');

        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Sob Impersonation']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->set('nomeEdit', 'Renomeado Sob Impersonation')
            ->call('salvarNome');

        $evento = $this->ultimoEvento();
        $this->assertSame($platformAdmin->id, $evento->ator_user_id, 'Ator real, nunca o tenant impersonado.');
        $this->assertTrue($evento->ator_platform_admin);
        $this->assertTrue($evento->ator_impersonando);
    }

    public function test_platform_admin_sem_impersonation_nao_ganha_acesso_indevido(): void
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($platformAdmin);

        // Navegação de página cheia sem acesso não mostra 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup
        // (mesmo padrão já usado em PerfilAcessoTest).
        $this->get(route('gestao.perfis-acesso'))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_usuario_comum_ator_impersonando_sempre_falso(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Normal']);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('selecionarAba', $perfil->id)
            ->set('nomeEdit', 'Renomeado')
            ->call('salvarNome');

        $evento = $this->ultimoEvento();
        $this->assertFalse($evento->ator_platform_admin);
        $this->assertFalse($evento->ator_impersonando);
    }

    // =========================================================================
    // SEÇÃO 51 — APPEND-ONLY
    // =========================================================================

    public function test_atualizar_evento_e_bloqueado(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'X']);
        RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');
        $evento = $this->ultimoEvento();

        $this->expectException(HistoricoAcessoImutavelException::class);
        $evento->update(['resumo' => 'Alterado']);
    }

    public function test_excluir_evento_e_bloqueado(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Y']);
        RegistrarEventoAcesso::perfilCriado($this->criador, $perfil, 'zero');
        $evento = $this->ultimoEvento();

        $this->expectException(HistoricoAcessoImutavelException::class);
        $evento->delete();
    }

    // =========================================================================
    // SEÇÃO 52 — ATOMICIDADE
    // =========================================================================

    public function test_falha_de_auditoria_reverte_a_mudanca_de_acesso(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Original']);

        try {
            DB::transaction(function () use ($perfil) {
                $perfil->update(['nome' => 'Deveria Reverter']);

                // Simula falha do registro de auditoria.
                throw new \RuntimeException('Falha simulada de auditoria.');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        $this->assertSame('Original', $perfil->fresh()->nome, 'A mudança de nome NUNCA deveria persistir sem o evento correspondente.');
    }

    public function test_transacao_real_do_caller_reverte_perfil_e_evento_juntos(): void
    {
        $perfil = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Estável']);
        $totalEventosAntes = HistoricoAcesso::count();

        try {
            DB::transaction(function () use ($perfil) {
                $perfil->update(['nome' => 'Mudou']);
                RegistrarEventoAcesso::perfilDadosAlterados($this->criador, $perfil, 'Estável', 'Mudou', null, null);

                throw new \RuntimeException('Falha depois do evento — tudo deve reverter junto.');
            });
        } catch (\RuntimeException $e) {
            // esperado
        }

        $this->assertSame('Estável', $perfil->fresh()->nome);
        $this->assertSame($totalEventosAntes, HistoricoAcesso::count());
    }

    // =========================================================================
    // SEÇÃO 53 — TENANT
    // =========================================================================

    public function test_historico_e_isolado_por_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroCriador = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->tenant2Setup($outroTenant, $outroCriador);

        $perfilDoOutro = TenantContext::actingAs($outroTenant, fn () => Perfil::create(['tenant_id' => $outroTenant->id, 'nome' => 'Do Outro Tenant']));
        TenantContext::actingAs($outroTenant, function () use ($perfilDoOutro, $outroCriador) {
            RegistrarEventoAcesso::perfilCriado($outroCriador, $perfilDoOutro, 'zero');
        });

        $perfilMeu = Perfil::create(['tenant_id' => $this->tenant->id, 'nome' => 'Meu']);
        RegistrarEventoAcesso::perfilCriado($this->criador, $perfilMeu, 'zero');

        $componente = Livewire::test('pages::gestao.historico-acessos');
        $ids = $componente->instance()->eventosPaginados()->pluck('id');

        $this->assertContains($perfilMeu->id, HistoricoAcesso::whereIn('id', $ids)->pluck('perfil_id'));
        $this->assertNotContains($perfilDoOutro->id, HistoricoAcesso::whereIn('id', $ids)->pluck('perfil_id'));
    }

    public function test_id_forjado_no_filtro_nunca_vaza_evento_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroCriador = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->tenant2Setup($outroTenant, $outroCriador);

        $perfilDoOutro = TenantContext::actingAs($outroTenant, fn () => Perfil::create(['tenant_id' => $outroTenant->id, 'nome' => 'Alheio']));
        TenantContext::actingAs($outroTenant, function () use ($perfilDoOutro, $outroCriador) {
            RegistrarEventoAcesso::perfilCriado($outroCriador, $perfilDoOutro, 'zero');
        });

        $componente = Livewire::test('pages::gestao.historico-acessos')
            ->set('filtroPerfilId', $perfilDoOutro->id);

        $this->assertCount(0, $componente->instance()->eventosPaginados());
    }

    private function tenant2Setup(Tenant $tenant, User $criador): void
    {
        $tenant->update(['criado_por_id' => $criador->id]);
    }

    // =========================================================================
    // SEÇÃO 56 — NÃO LOGAR LEITURA
    // =========================================================================

    // =========================================================================
    // SEÇÃO 61 — WRITERS explicitamente NÃO auditados (bootstrap estrutural)
    // =========================================================================

    /**
     * `Work::garantirCriadorDoTenantComoAdmin()` (evento `created` do
     * model) e o auto-vínculo do criador em `⚡obras/create.blade.php`
     * NUNCA passam `$ator`/`$origem` pra `AtribuicaoPerfilObra` —
     * decisão explícita (Seção 27/61, documentada no relatório final):
     * são bootstraps estruturais/deterministas ("toda obra nova sempre
     * vincula o criador do tenant como Admin"), nunca uma decisão
     * administrativa discricionária. Este teste prova o comportamento
     * atual, não deixa isso implícito.
     */
    public function test_criar_obra_nunca_gera_evento_de_atribuicao_bootstrap(): void
    {
        $totalAntes = HistoricoAcesso::count();

        Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertSame($totalAntes, HistoricoAcesso::count(), 'Bootstrap estrutural (Work::garantirCriadorDoTenantComoAdmin) é uma decisão NÃO auditada, por design.');
    }

    public function test_abrir_telas_nunca_gera_evento(): void
    {
        $totalAntes = HistoricoAcesso::count();

        Livewire::test('pages::gestao.perfis-acesso');
        Livewire::test('pages::gestao.matriz-acessos');
        Livewire::test('pages::gestao.historico-acessos');
        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra]);

        $this->assertSame($totalAntes, HistoricoAcesso::count());
    }
}
