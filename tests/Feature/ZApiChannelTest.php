<?php

namespace Tests\Feature;

use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\AlertaPrazoSuprimentoNotification;
use App\Notifications\Channels\ZApiChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZApiChannelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        config([
            'services.zapi.instance_id' => 'instancia-teste',
            'services.zapi.token' => 'token-teste',
            'services.zapi.client_token' => 'client-token-teste',
        ]);
    }

    private function criarItem(): ItemSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);

        return ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item de Teste',
        ]);
    }

    public function test_envia_mensagem_para_numero_normalizado_quando_credenciais_configuradas(): void
    {
        Http::fake(['api.z-api.io/*' => Http::response(['sent' => true], 200)]);

        $responsavel = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'telefone' => '(11) 98888-7777',
        ]);

        // Chama o canal direto (não $user->notify()/notifyNow()) — a
        // notification também vai por 'broadcast', que tentaria mesmo
        // em teste alcançar o Reverb de verdade (indisponível aqui) e
        // derrubaria o teste por um motivo que nada tem a ver com o
        // canal WhatsApp sendo testado.
        (new ZApiChannel())->send($responsavel, new AlertaPrazoSuprimentoNotification($this->criarItem(), 21));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.z-api.io/instances/instancia-teste/token/token-teste/send-text'
                && $request->header('Client-Token')[0] === 'client-token-teste'
                && $request['phone'] === '5511988887777'
                && str_contains($request['message'], 'Item de Teste')
                && str_contains($request['message'], '21 dias');
        });
    }

    public function test_nao_envia_quando_credenciais_zapi_nao_configuradas(): void
    {
        config(['services.zapi.instance_id' => null, 'services.zapi.token' => null]);
        Http::fake();

        $responsavel = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'telefone' => '11988887777',
        ]);

        (new ZApiChannel())->send($responsavel, new AlertaPrazoSuprimentoNotification($this->criarItem(), 21));

        Http::assertNothingSent();
    }

    public function test_nao_envia_quando_usuario_sem_telefone(): void
    {
        Http::fake();

        $responsavel = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'telefone' => null,
        ]);

        (new ZApiChannel())->send($responsavel, new AlertaPrazoSuprimentoNotification($this->criarItem(), 21));

        Http::assertNothingSent();
    }

    public function test_nao_envia_quando_notification_nao_implementa_towhatsapp(): void
    {
        Http::fake();

        $responsavel = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'telefone' => '11988887777',
        ]);

        (new ZApiChannel())->send($responsavel, new \Illuminate\Auth\Notifications\ResetPassword('token-fake'));

        Http::assertNothingSent();
    }
}
