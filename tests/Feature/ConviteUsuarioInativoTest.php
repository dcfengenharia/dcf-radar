<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Convite;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Jetstream;
use Tests\TestCase;

/**
 * Pré-produção, Etapa 2.2 (achado B1) — `ConviteController::aceitar()`,
 * branch de usuário EXISTENTE, nunca autentica nem muta `obra_user` pra
 * uma conta com `ativo=false`. Cobertura da seção 12 do ticket (A-E).
 */
class ConviteUsuarioInativoTest extends TestCase
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
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function perfil(string $slugPadrao): Perfil
    {
        return Perfil::porSlugPadrao($this->tenant, $slugPadrao);
    }

    private function payload(): array
    {
        return [
            'first_name' => 'Nome',
            'last_name' => 'Sobrenome',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ];
    }

    // A) usuário ATIVO existente aceita convite normalmente — fluxo intacto.
    public function test_a_usuario_ativo_existente_aceita_convite_normalmente(): void
    {
        $existente = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'ativo@example.com',
            'ativo' => true,
        ]);

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'ativo@example.com',
            'perfil_id' => $this->perfil('engenheiro')->id,
            'status' => 'pendente',
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), $this->payload());

        $this->assertAuthenticatedAs($existente);
        $this->assertTrue($this->obra->users()->where('user_id', $existente->id)->exists());
        $this->assertEquals('aceito', $convite->fresh()->status);
        $response->assertRedirect(route('radar.entrar', $this->obra));
    }

    // B) usuário INATIVO existente tenta aceitar.
    public function test_b_usuario_inativo_existente_nao_autentica_nem_muta_obra_user(): void
    {
        $inativo = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'inativo@example.com',
            'ativo' => false,
        ]);

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'inativo@example.com',
            'perfil_id' => $this->perfil('engenheiro')->id,
            'status' => 'pendente',
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), $this->payload());

        $this->assertGuest();
        $this->assertFalse($inativo->fresh()->ativo, 'a conta nunca pode ser reativada silenciosamente pelo aceite');
        $this->assertFalse(
            $this->obra->users()->where('user_id', $inativo->id)->exists(),
            'nenhuma associação obra_user pode ser criada para conta inativa'
        );
        // Convite permanece pendente — nada foi de fato aceito, pode ser
        // retomado depois que a conta for reativada pelo administrador.
        $this->assertEquals('pendente', $convite->fresh()->status);
        $this->assertNull($convite->fresh()->aceito_em);
        $response->assertRedirect(route('login'));
    }

    // C) usuário inativo já pertencente à obra (reenvio de convite, ex.).
    public function test_c_usuario_inativo_ja_pertencente_a_obra_nao_ganha_sessao(): void
    {
        $inativo = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'ja-na-obra@example.com',
            'ativo' => false,
        ]);
        $this->obra->users()->attach($inativo->id, ['perfil_id' => $this->perfil('encarregado')->id]);

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'ja-na-obra@example.com',
            'perfil_id' => $this->perfil('engenheiro')->id,
            'status' => 'pendente',
        ]);

        $vinculoAntes = $this->obra->users()->where('user_id', $inativo->id)->first()->pivot->perfil_id;

        $response = $this->post(route('convite.aceitar', $convite->token), $this->payload());

        $this->assertGuest();
        // O vínculo pré-existente nunca é alterado (nem o perfil trocado
        // pelo do convite) — a rejeição acontece antes de qualquer escrita.
        $vinculoDepois = $this->obra->users()->where('user_id', $inativo->id)->first()->pivot->perfil_id;
        $this->assertEquals($vinculoAntes, $vinculoDepois);
        $this->assertEquals('pendente', $convite->fresh()->status);
        $response->assertRedirect(route('login'));
    }

    // D) convite para OUTRO tenant, mesma tentativa — sem vazamento cross-tenant.
    public function test_d_convite_de_outro_tenant_nunca_expoe_ou_ativa_usuario_inativo_de_terceiros(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outroGerente = User::factory()->create(['tenant_id' => $outroTenant->id]);

        // Usuário inativo existe no tenant ORIGINAL, mas o convite é de um
        // tenant DIFERENTE com o mesmo e-mail — o WHERE já escopado por
        // tenant_id em aceitar() nunca encontra esse usuário como
        // "existente", então cai no fluxo de criação normal (não é o
        // cenário do achado B1). Confirma isolamento: a conta inativa do
        // tenant original permanece intocada.
        $inativoTenantOriginal = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'mesmo-email@example.com',
            'ativo' => false,
        ]);

        $convite = Convite::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'convidado_por_id' => $outroGerente->id,
            'email' => 'mesmo-email@example.com',
            'perfil_id' => Perfil::porSlugPadrao($outroTenant, 'engenheiro')->id,
            'status' => 'pendente',
        ]);

        $this->post(route('convite.aceitar', $convite->token), $this->payload());

        $this->assertFalse($inativoTenantOriginal->fresh()->ativo);
        $this->assertFalse($this->obra->users()->where('user_id', $inativoTenantOriginal->id)->exists());
        $this->assertFalse($outraObra->users()->where('user_id', $inativoTenantOriginal->id)->exists());
    }

    // E) usuário NOVO — fluxo normal permanece intacto (nenhum usuário
    // existente, `ativo` nem entra na conta — cria com o default true).
    public function test_e_usuario_novo_fluxo_normal_intacto(): void
    {
        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'totalmente-novo@example.com',
            'perfil_id' => $this->perfil('engenheiro')->id,
            'status' => 'pendente',
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), $this->payload());

        $novo = User::where('email', 'totalmente-novo@example.com')->first();
        $this->assertNotNull($novo);
        $this->assertTrue($novo->ativo);
        $this->assertAuthenticatedAs($novo);
        $this->assertTrue($this->obra->users()->where('user_id', $novo->id)->exists());
        $response->assertRedirect(route('radar.entrar', $this->obra));
    }
}
