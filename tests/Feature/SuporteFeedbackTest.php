<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgradecimentoFeedbackNotification;
use App\Notifications\NovoFeedbackNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class SuporteFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('suporte.popup');
    }

    public function test_envia_feedback_do_tipo_erro_gravando_tenant_e_usuario_corretos(): void
    {
        Notification::fake();

        $this->componente()
            ->call('abrir', 'https://dcf.eng.br/app/radar/restricoes')
            ->set('tipo', 'erro')
            ->set('mensagem', 'O botão de salvar não está funcionando corretamente.')
            ->call('enviar')
            ->assertHasNoErrors();

        $feedback = Feedback::first();
        $this->assertNotNull($feedback);
        $this->assertSame('erro', $feedback->tipo->value);
        $this->assertSame($this->tenant->id, $feedback->tenant_id);
        $this->assertSame($this->user->id, $feedback->user_id);
        $this->assertSame('https://dcf.eng.br/app/radar/restricoes', $feedback->url_origem);
    }

    public function test_envia_feedback_do_tipo_melhoria(): void
    {
        Notification::fake();

        $this->componente()
            ->set('tipo', 'melhoria')
            ->set('mensagem', 'Seria ótimo ter um filtro por data aqui.')
            ->call('enviar')
            ->assertHasNoErrors();

        $this->assertSame('melhoria', Feedback::first()->tipo->value);
    }

    public function test_envia_feedback_do_tipo_critica(): void
    {
        Notification::fake();

        $this->componente()
            ->set('tipo', 'critica')
            ->set('mensagem', 'O carregamento da página está muito lento.')
            ->call('enviar')
            ->assertHasNoErrors();

        $this->assertSame('critica', Feedback::first()->tipo->value);
    }

    public function test_valida_tipo_obrigatorio(): void
    {
        $this->componente()
            ->set('tipo', '')
            ->set('mensagem', 'Mensagem válida com tamanho suficiente.')
            ->call('enviar')
            ->assertHasErrors(['tipo' => 'required']);

        $this->assertSame(0, Feedback::count());
    }

    public function test_valida_mensagem_obrigatoria(): void
    {
        $this->componente()
            ->set('tipo', 'erro')
            ->set('mensagem', '')
            ->call('enviar')
            ->assertHasErrors(['mensagem' => 'required']);
    }

    public function test_valida_mensagem_minima(): void
    {
        $this->componente()
            ->set('tipo', 'erro')
            ->set('mensagem', 'curta')
            ->call('enviar')
            ->assertHasErrors(['mensagem' => 'min']);
    }

    public function test_valida_mensagem_maxima(): void
    {
        $this->componente()
            ->set('tipo', 'erro')
            ->set('mensagem', str_repeat('a', 2001))
            ->call('enviar')
            ->assertHasErrors(['mensagem' => 'max']);
    }

    public function test_notifica_equipe_e_agradece_usuario_ao_enviar_com_sucesso(): void
    {
        Notification::fake();

        $this->componente()
            ->set('tipo', 'erro')
            ->set('mensagem', 'Mensagem válida com tamanho suficiente.')
            ->call('enviar');

        Notification::assertSentOnDemand(
            NovoFeedbackNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'contato@dcf.eng.br'
        );

        Notification::assertSentTo($this->user, AgradecimentoFeedbackNotification::class);
    }

    public function test_rate_limit_bloqueia_apos_muitas_tentativas(): void
    {
        Notification::fake();
        RateLimiter::clear('enviar-feedback:'.$this->user->id);

        for ($i = 0; $i < 5; $i++) {
            $this->componente()
                ->set('tipo', 'erro')
                ->set('mensagem', 'Mensagem válida com tamanho suficiente.')
                ->call('enviar');
        }

        $this->componente()
            ->set('tipo', 'erro')
            ->set('mensagem', 'Mais uma mensagem válida aqui.')
            ->call('enviar')
            ->assertHasErrors('mensagem');

        $this->assertSame(5, Feedback::count());
    }

    public function test_falha_no_envio_preserva_mensagem_digitada_e_nao_confirma_sucesso(): void
    {
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('Falha simulada'));

        $componente = $this->componente()
            ->set('tipo', 'erro')
            ->set('mensagem', 'Mensagem que não pode ser perdida.')
            ->call('enviar');

        $componente->assertSet('mensagem', 'Mensagem que não pode ser perdida.');
        $this->assertSame(0, Feedback::count());
    }
}
