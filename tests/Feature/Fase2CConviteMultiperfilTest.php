<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Convite;
use App\Models\ConvitePerfil;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\AdicionadoAObraNotification;
use App\Support\Perfis\AtribuicaoPerfilConvite;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FASE 2C — Convite multiperfil (Seção 4-8 do fechamento adversarial):
 * um Convite passa a poder conceder 1..N Perfis, preservando
 * compatibilidade total com convites legados de 1 perfil só.
 */
class Fase2CConviteMultiperfilTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $gerente;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->gerente = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // `criado_por_id` ainda é null aqui de propósito: `Work::
        // garantirCriadorDoTenantComoAdmin()` (evento `created` do
        // model) auto-vincularia $this->gerente como Admin desta obra
        // se ele já fosse o criador do tenant no instante em que a
        // obra nasce — colidindo com o `vincularObra()` explícito logo
        // abaixo (mesmo par obra+usuário, violação de PK composta).
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->gerente, Papel::GerentePlanejamento->value);
        // Necessário pro Gate `gerenciar-perfis-acesso` (usado por
        // ⚡perfis-acesso.blade.php, exercitado no teste H) — setado só
        // DEPOIS da obra já existir, então não reaciona o hook acima.
        $this->tenant->update(['criado_por_id' => $this->gerente->id]);
        $this->actingAs($this->gerente);
    }

    private function perfil(string $slugPadrao): Perfil
    {
        return Perfil::porSlugPadrao($this->tenant, $slugPadrao);
    }

    /**
     * `BelongsToTenant::bootBelongsToTenant()` carimba `tenant_id` a
     * partir do usuário autenticado em `creating`, ignorando qualquer
     * valor explícito passado — criar um Perfil "de outro tenant"
     * precisa rodar dentro de `TenantContext::actingAs()`, senão a
     * linha nasce silenciosamente no tenant do ator autenticado (mesma
     * lição já documentada em várias fases anteriores do projeto).
     */
    private function perfilDeOutroTenant(Tenant $outroTenant, string $nome = 'Perfil Alheio'): Perfil
    {
        return TenantContext::actingAs($outroTenant, fn () => Perfil::create([
            'tenant_id' => $outroTenant->id,
            'nome' => $nome,
        ]));
    }

    private function aceitar(Convite $convite): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Novo',
            'last_name' => 'Usuario',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);
    }

    // =========================================================================
    // A — envio com N perfis
    // =========================================================================

    public function test_a_enviar_convite_com_multiplos_perfis_persiste_todos(): void
    {
        Notification::fake();

        $engenheiro = $this->perfil('engenheiro');
        $encarregado = $this->perfil('encarregado');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('emailConvite', 'multiperfil@example.com')
            ->set('perfisConviteIds', [$engenheiro->id, $encarregado->id])
            ->call('enviarConvite');

        $convite = Convite::where('email', 'multiperfil@example.com')->first();
        $this->assertNotNull($convite);
        // Compatibilidade: coluna legada grava o PRIMEIRO selecionado.
        $this->assertSame($engenheiro->id, $convite->perfil_id);

        $idsGravados = ConvitePerfil::where('convite_id', $convite->id)->pluck('perfil_id')->sort()->values()->all();
        $this->assertSame(
            collect([$engenheiro->id, $encarregado->id])->sort()->values()->all(),
            $idsGravados
        );
    }

    public function test_a2_sem_nenhum_perfil_selecionado_e_bloqueado_pela_validacao(): void
    {
        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('emailConvite', 'sem-perfil@example.com')
            ->set('perfisConviteIds', [])
            ->call('enviarConvite')
            ->assertHasErrors(['perfisConviteIds']);

        $this->assertSame(0, Convite::count());
    }

    // =========================================================================
    // B — aceite de convite novo (membresia nova) concede TODOS os perfis
    // =========================================================================

    public function test_b_aceitar_convite_novo_com_multiplos_perfis_concede_todos(): void
    {
        auth()->logout();

        $engenheiro = $this->perfil('engenheiro');
        $encarregado = $this->perfil('encarregado');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'aceite-multi@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);
        AtribuicaoPerfilConvite::gravar($convite, [$engenheiro->id, $encarregado->id]);

        $this->aceitar($convite)->assertRedirect(route('radar.entrar', $this->obra));

        $usuario = User::where('email', 'aceite-multi@example.com')->first();
        $this->assertNotNull($usuario);

        $idsEfetivos = $usuario->perfisNaObra($this->obra)->pluck('id')->sort()->values()->all();
        $this->assertSame(
            collect([$engenheiro->id, $encarregado->id])->sort()->values()->all(),
            $idsEfetivos
        );
    }

    // =========================================================================
    // B2 — convite LEGADO (sem convite_perfis, só perfil_id) continua válido
    // =========================================================================

    public function test_b2_convite_legado_sem_convite_perfis_concede_o_unico_perfil(): void
    {
        auth()->logout();

        $engenheiro = $this->perfil('engenheiro');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'legado@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);

        $this->assertSame(0, ConvitePerfil::where('convite_id', $convite->id)->count());

        $this->aceitar($convite)->assertRedirect(route('radar.entrar', $this->obra));

        $usuario = User::where('email', 'legado@example.com')->first();
        $this->assertSame([$engenheiro->id], $usuario->perfisNaObra($this->obra)->pluck('id')->all());
    }

    // =========================================================================
    // C — usuário JÁ é membro da obra no instante do aceite: ADICIONA, nunca substitui
    // =========================================================================

    public function test_c_aceitar_convite_com_usuario_ja_membro_adiciona_perfis_sem_remover_os_existentes(): void
    {
        auth()->logout();

        $admin = $this->perfil('admin');
        $encarregado = $this->perfil('encarregado');

        // Usuário convidado já existe e JÁ é Admin desta obra — cenário
        // real: foi adicionado direto por outro caminho enquanto o
        // convite (pra um perfil mais restrito) ainda estava pendente.
        $usuario = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ja-membro@example.com']);
        $this->obra->users()->attach($usuario->id, ['perfil_id' => $admin->id]);
        \App\Support\AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $usuario->id, $admin->id);

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'ja-membro@example.com',
            'perfil_id' => $encarregado->id,
            'status' => 'pendente',
        ]);
        AtribuicaoPerfilConvite::gravar($convite, [$encarregado->id]);

        $this->aceitar($convite);

        $idsEfetivos = $usuario->fresh()->perfisNaObra($this->obra)->pluck('id')->sort()->values()->all();
        // Admin PRESERVADO + Encarregado ADICIONADO — nunca substituído.
        $this->assertSame(
            collect([$admin->id, $encarregado->id])->sort()->values()->all(),
            $idsEfetivos
        );
        $this->assertTrue($usuario->fresh()->temPerfilNaObra($this->obra, 'admin'));
    }

    // =========================================================================
    // D — Perfil de outro tenant nunca pode ser selecionado no envio
    // =========================================================================

    public function test_d_perfil_de_outro_tenant_e_rejeitado_no_envio(): void
    {
        $outroTenant = Tenant::factory()->create();
        $perfilDeOutroTenant = $this->perfilDeOutroTenant($outroTenant);
        $encarregado = $this->perfil('encarregado');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('emailConvite', 'cross-tenant@example.com')
            ->set('perfisConviteIds', [$encarregado->id, $perfilDeOutroTenant->id])
            ->call('enviarConvite')
            ->assertHasErrors(['perfisConviteIds.1']);

        $this->assertSame(0, Convite::count());
    }

    // =========================================================================
    // E — Perfil removido entre envio e aceite: falha segura, nunca atribuição
    //     parcial silenciosa, nunca crash, nunca bloqueia o aceite inteiro
    // =========================================================================

    public function test_e_perfil_excluido_entre_envio_e_aceite_e_descartado_com_seguranca(): void
    {
        auth()->logout();

        $engenheiro = $this->perfil('engenheiro');
        $encarregado = $this->perfil('encarregado');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'perfil-removido@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);
        AtribuicaoPerfilConvite::gravar($convite, [$engenheiro->id, $encarregado->id]);

        // "Excluir" o perfil Engenheiro depois do envio (soft delete —
        // mesmo mecanismo real de ⚡perfis-acesso.blade.php::excluirPerfil()).
        $engenheiro->permissoes()->delete();
        $engenheiro->delete();

        $this->aceitar($convite)->assertRedirect(route('radar.entrar', $this->obra));

        $usuario = User::where('email', 'perfil-removido@example.com')->first();
        $this->assertNotNull($usuario);
        // Só o perfil AINDA VÁLIDO (Encarregado) foi concedido — o
        // excluído nunca aparece, nunca quebra o fluxo.
        $this->assertSame([$encarregado->id], $usuario->perfisNaObra($this->obra)->pluck('id')->all());
    }

    public function test_e2_todos_os_perfis_do_convite_excluidos_ainda_cria_membresia_sem_nenhum_perfil(): void
    {
        auth()->logout();

        $engenheiro = $this->perfil('engenheiro');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'todos-removidos@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);
        AtribuicaoPerfilConvite::gravar($convite, [$engenheiro->id]);

        $engenheiro->permissoes()->delete();
        $engenheiro->delete();

        $this->aceitar($convite)->assertRedirect(route('radar.entrar', $this->obra));

        $usuario = User::where('email', 'todos-removidos@example.com')->first();
        $this->assertNotNull($usuario);
        $this->assertTrue($this->obra->users()->where('user_id', $usuario->id)->exists());
        $this->assertSame([], $usuario->perfisNaObra($this->obra)->pluck('id')->all());
    }

    // =========================================================================
    // F — Admin via convite multiperfil funciona normalmente
    // =========================================================================

    public function test_f_convite_com_perfil_admin_funciona_e_usuario_vira_admin_da_obra(): void
    {
        auth()->logout();

        $admin = $this->perfil('admin');
        $engenheiro = $this->perfil('engenheiro');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'novo-admin@example.com',
            'perfil_id' => $admin->id,
            'status' => 'pendente',
        ]);
        AtribuicaoPerfilConvite::gravar($convite, [$admin->id, $engenheiro->id]);

        $this->aceitar($convite);

        $usuario = User::where('email', 'novo-admin@example.com')->first();
        $this->assertTrue($usuario->temPerfilNaObra($this->obra, 'admin'));
        $this->assertTrue($usuario->temPerfilNaObra($this->obra, 'engenheiro'));
    }

    // =========================================================================
    // G — defesa em profundidade na gravação (payload manipulado)
    // =========================================================================

    public function test_g_gravar_rejeita_perfil_de_outro_tenant_mesmo_chamado_direto(): void
    {
        $outroTenant = Tenant::factory()->create();
        $perfilDeOutroTenant = $this->perfilDeOutroTenant($outroTenant);

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        AtribuicaoPerfilConvite::gravar($convite, [$perfilDeOutroTenant->id]);
    }

    // =========================================================================
    // H — Perfil referenciado por convite_perfis não pode ser excluído
    // =========================================================================

    public function test_h_perfil_referenciado_por_convite_pendente_nao_pode_ser_excluido(): void
    {
        $encarregado = $this->perfil('encarregado');
        $engenheiro = $this->perfil('engenheiro');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'perfil_id' => $encarregado->id,
        ]);
        AtribuicaoPerfilConvite::gravar($convite, [$encarregado->id, $engenheiro->id]);

        Livewire::test('pages::gestao.perfis-acesso')
            ->call('excluirPerfil', $engenheiro->id);

        $this->assertNotNull($engenheiro->fresh());
        $this->assertNull($engenheiro->fresh()->deleted_at);
    }

    // =========================================================================
    // I — UI: seleção múltipla via checkbox, sem IDs técnicos visíveis
    // =========================================================================

    public function test_i_ui_mostra_nomes_dos_perfis_nunca_ids_tecnicos(): void
    {
        $encarregado = $this->perfil('encarregado');
        $engenheiro = $this->perfil('engenheiro');

        // O checkbox de seleção continua na aba "Equipe" (a "Visão
        // Geral" é a aba padrão) — o rótulo visível ao lado de cada
        // checkbox é sempre o nome do Perfil, nunca o slug/ID.
        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('setAba', 'equipe')
            ->assertSee($encarregado->nome)
            ->assertSee($engenheiro->nome)
            ->assertSeeHtml('perfil-convite-'.$encarregado->id);
    }

    // =========================================================================
    // J — notificação de "adicionado direto" lista todos os perfis
    // =========================================================================

    public function test_j_notificacao_de_adicao_direta_lista_todos_os_perfis(): void
    {
        Notification::fake();

        $existente = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'direto@example.com']);
        $engenheiro = $this->perfil('engenheiro');
        $encarregado = $this->perfil('encarregado');

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('emailConvite', 'direto@example.com')
            ->set('perfisConviteIds', [$engenheiro->id, $encarregado->id])
            ->call('enviarConvite');

        Notification::assertSentTo(
            $existente,
            AdicionadoAObraNotification::class,
            function ($notification) use ($existente, $engenheiro, $encarregado) {
                $mail = $notification->toMail($existente);
                $texto = implode(' ', $mail->introLines);

                return str_contains($texto, $engenheiro->nome) && str_contains($texto, $encarregado->nome);
            }
        );
    }
}
