<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Convite;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\AdicionadoAObraNotification;
use App\Notifications\ConviteObraNotification;
use App\Support\TemplateConvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\TestCase;

class ConviteObraTest extends TestCase
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
        $this->vincularObra($this->obra, $this->gerente, Papel::GerentePlanejamento->value);
        $this->actingAs($this->gerente);
    }

    private function componente()
    {
        return Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra]);
    }

    private function perfil(string $slugPadrao): Perfil
    {
        return Perfil::porSlugPadrao($this->tenant, $slugPadrao);
    }

    public function test_convidar_email_ja_cadastrado_vincula_direto_sem_convite(): void
    {
        Notification::fake();

        $existente = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ja-existe@example.com']);
        $engenheiro = $this->perfil('engenheiro');

        $this->componente()
            ->set('emailConvite', 'ja-existe@example.com')
            ->set('perfilConviteId', $engenheiro->id)
            ->call('enviarConvite');

        $this->assertTrue($this->obra->users()->where('user_id', $existente->id)->exists());
        $this->assertEquals(
            $engenheiro->id,
            $this->obra->users()->where('user_id', $existente->id)->first()->pivot->perfil_id
        );
        $this->assertEquals(0, Convite::count());
        Notification::assertSentTo($existente, AdicionadoAObraNotification::class);
    }

    public function test_convidar_email_novo_cria_convite_pendente_e_notifica(): void
    {
        Notification::fake();

        $encarregado = $this->perfil('encarregado');

        $this->componente()
            ->set('emailConvite', 'novo@example.com')
            ->set('perfilConviteId', $encarregado->id)
            ->call('enviarConvite');

        $convite = Convite::where('email', 'novo@example.com')->first();
        $this->assertNotNull($convite);
        $this->assertEquals('pendente', $convite->status);
        $this->assertEquals($this->obra->id, $convite->obra_id);
        $this->assertEquals($encarregado->id, $convite->perfil_id);

        Notification::assertSentOnDemand(ConviteObraNotification::class);
    }

    public function test_nao_permite_convite_pendente_duplicado_para_mesmo_email_e_obra(): void
    {
        Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'email' => 'duplicado@example.com',
            'status' => 'pendente',
        ]);

        $this->componente()
            ->set('emailConvite', 'duplicado@example.com')
            ->call('enviarConvite')
            ->assertHasErrors(['emailConvite']);

        $this->assertEquals(1, Convite::where('email', 'duplicado@example.com')->count());
    }

    public function test_usuario_sem_papel_minimo_nao_pode_convidar(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('emailConvite', 'alguem@example.com')
            ->call('enviarConvite')
            ->assertForbidden();
    }

    public function test_aceitar_convite_valido_cria_usuario_vincula_obra_e_loga(): void
    {
        auth()->logout();

        $engenheiro = $this->perfil('engenheiro');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'convidado@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Novo',
            'last_name' => 'Usuario',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $usuario = User::where('email', 'convidado@example.com')->first();
        $this->assertNotNull($usuario);
        $this->assertEquals('Novo', $usuario->first_name);
        $this->assertEquals($this->tenant->id, $usuario->tenant_id);
        $this->assertNotNull($usuario->email_verified_at);
        $this->assertTrue($this->obra->users()->where('user_id', $usuario->id)->exists());
        $this->assertEquals(
            $engenheiro->id,
            $this->obra->users()->where('user_id', $usuario->id)->first()->pivot->perfil_id
        );

        $convite->refresh();
        $this->assertEquals('aceito', $convite->status);
        $this->assertNotNull($convite->aceito_em);

        $this->assertAuthenticatedAs($usuario);
        $response->assertRedirect(route('radar.entrar', $this->obra));
    }

    public function test_aceitar_convite_exige_aceite_dos_termos_quando_feature_habilitada(): void
    {
        auth()->logout();

        $this->assertTrue(Jetstream::hasTermsAndPrivacyPolicyFeature());

        $engenheiro = $this->perfil('engenheiro');

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'email' => 'sem-termos@example.com',
            'perfil_id' => $engenheiro->id,
            'status' => 'pendente',
        ]);

        $response = $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Novo',
            'last_name' => 'Usuario',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
            // 'terms' propositalmente omitido
        ]);

        $response->assertSessionHasErrors('terms');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'sem-termos@example.com']);
        $this->assertEquals('pendente', $convite->fresh()->status);
    }

    public function test_convite_expirado_nao_pode_ser_aceito(): void
    {
        auth()->logout();

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'status' => 'pendente',
            'expira_em' => now()->subDay(),
        ]);

        $this->get(route('convite.show', $convite->token))
            ->assertRedirect(route('login'));

        $response = $this->post(route('convite.aceitar', $convite->token), [
            'first_name' => 'Teste',
            'last_name' => 'Teste',
            'password' => 'senha-forte-123',
            'password_confirmation' => 'senha-forte-123',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => $convite->email]);
    }

    public function test_convite_cancelado_nao_pode_ser_aceito(): void
    {
        auth()->logout();

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'status' => 'cancelado',
        ]);

        $this->get(route('convite.show', $convite->token))
            ->assertRedirect(route('login'));
    }

    public function test_reenviar_convite_atualiza_expiracao_e_reenvia(): void
    {
        Notification::fake();

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'status' => 'pendente',
            'expira_em' => now()->addHour(),
        ]);

        $this->componente()->call('reenviarConvite', $convite->id);

        $convite->refresh();
        $this->assertTrue($convite->expira_em->greaterThan(now()->addDays(6)));
        Notification::assertSentOnDemand(ConviteObraNotification::class);
    }

    public function test_cancelar_convite_muda_status(): void
    {
        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'status' => 'pendente',
        ]);

        $this->componente()->call('cancelarConvite', $convite->id);

        $this->assertEquals('cancelado', $convite->fresh()->status);
    }

    public function test_usuario_sem_papel_minimo_nao_pode_reenviar_ou_cancelar(): void
    {
        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'status' => 'pendente',
        ]);

        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('cancelarConvite', $convite->id)
            ->assertForbidden();
    }

    public function test_template_customizado_do_tenant_aparece_no_email(): void
    {
        $this->tenant->update([
            'convite_email_assunto' => 'Bem-vindo(a) a {{obra}}!',
            'convite_email_mensagem' => 'Convidado por {{convidado_por}} como {{papel}}.',
        ]);

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'perfil_id' => $this->perfil('engenheiro')->id,
        ]);

        $notification = new ConviteObraNotification($convite);
        $mail = $notification->toMail(null);

        $this->assertStringContainsString($this->obra->name, $mail->subject);
        $this->assertStringContainsString($this->gerente->first_name, implode(' ', $mail->introLines));
        $this->assertStringContainsString('Engenheiro', implode(' ', $mail->introLines));
    }

    public function test_tenant_sem_configuracao_usa_texto_padrao(): void
    {
        $this->assertNull($this->tenant->convite_email_assunto);
        $this->assertNull($this->tenant->convite_email_mensagem);

        $convite = Convite::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'convidado_por_id' => $this->gerente->id,
            'perfil_id' => $this->perfil('engenheiro')->id,
        ]);

        $notification = new ConviteObraNotification($convite);
        $mail = $notification->toMail(null);

        $assuntoEsperado = TemplateConvite::substituir(
            TemplateConvite::assuntoPadrao(),
            $this->obra->name,
            "{$this->gerente->first_name} {$this->gerente->last_name}",
            Papel::Engenheiro->label()
        );

        $this->assertEquals($assuntoEsperado, $mail->subject);
    }
}
