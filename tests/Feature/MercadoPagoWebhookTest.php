<?php

namespace Tests\Feature;

use App\Enums\StatusAssinatura;
use App\Enums\StatusFatura;
use App\Models\Assinatura;
use App\Models\AssinaturaFatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PagamentoConfirmadoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MercadoPagoWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        config([
            'services.mercadopago.access_token' => 'token-teste',
            'services.mercadopago.webhook_secret' => 'segredo-teste',
        ]);
    }

    private function headersAssinados(string $dataId, string $requestId = 'req-abc'): array
    {
        $ts = (string) now()->timestamp;
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'segredo-teste');

        return [
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ];
    }

    public function test_sem_assinatura_valida_retorna_401(): void
    {
        $this->postJson(route('webhooks.mercadopago'), ['type' => 'payment', 'data' => ['id' => '123']])
            ->assertStatus(401);
    }

    public function test_assinatura_com_secret_errado_retorna_401(): void
    {
        $headers = $this->headersAssinados('123');
        $headers['x-signature'] = str_replace('v1=', 'v1=deadbeef', $headers['x-signature']);

        $this->postJson(route('webhooks.mercadopago'), ['type' => 'payment', 'data' => ['id' => '123']], $headers)
            ->assertStatus(401);
    }

    public function test_pagamento_pix_aprovado_marca_fatura_paga_e_assinatura_ativa(): void
    {
        $tenant = Tenant::factory()->create();
        $criador = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $criador->id]);
        $plano = Plano::factory()->create();
        $assinatura = Assinatura::factory()->create([
            'tenant_id' => $tenant->id,
            'plano_id' => $plano->id,
            'status' => StatusAssinatura::Trial->value,
            'metodo_pagamento' => 'pix',
        ]);
        $fatura = AssinaturaFatura::factory()->create([
            'tenant_id' => $tenant->id,
            'assinatura_id' => $assinatura->id,
            'metodo_pagamento' => 'pix',
            'mp_payment_id' => 'pay-999',
            'status' => StatusFatura::Pendente->value,
        ]);

        Http::fake(['api.mercadopago.com/v1/payments/pay-999' => Http::response([
            'id' => 'pay-999',
            'status' => 'approved',
            'transaction_amount' => 299.90,
            'external_reference' => $tenant->id,
        ], 200)]);

        $headers = $this->headersAssinados('pay-999');
        $this->postJson(route('webhooks.mercadopago'), ['type' => 'payment', 'data' => ['id' => 'pay-999']], $headers)
            ->assertOk();

        $fatura->refresh();
        $this->assertSame(StatusFatura::Pago, $fatura->status);
        $this->assertNotNull($fatura->pago_em);

        $novaAssinatura = $tenant->fresh()->assinaturaAtual();
        $this->assertSame(StatusAssinatura::Ativa, $novaAssinatura->status);
        $this->assertSame('mercadopago', $novaAssinatura->origem);

        Notification::assertSentTo($criador, PagamentoConfirmadoNotification::class);
    }

    public function test_preapproval_cancelado_marca_assinatura_cancelada(): void
    {
        $tenant = Tenant::factory()->create();
        $plano = Plano::factory()->create();
        Assinatura::factory()->create([
            'tenant_id' => $tenant->id,
            'plano_id' => $plano->id,
            'status' => StatusAssinatura::Ativa->value,
            'metodo_pagamento' => 'cartao',
            'mp_preapproval_id' => 'preap-777',
        ]);

        Http::fake(['api.mercadopago.com/preapproval/preap-777' => Http::response([
            'id' => 'preap-777',
            'status' => 'cancelled',
        ], 200)]);

        $headers = $this->headersAssinados('preap-777');
        $this->postJson(route('webhooks.mercadopago'), ['type' => 'preapproval', 'data' => ['id' => 'preap-777']], $headers)
            ->assertOk();

        $novaAssinatura = $tenant->fresh()->assinaturaAtual();
        $this->assertSame(StatusAssinatura::Cancelada, $novaAssinatura->status);
    }

    public function test_evento_sem_correspondencia_local_nao_quebra(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/pay-inexistente' => Http::response(['id' => 'pay-inexistente', 'status' => 'approved'], 200)]);

        $headers = $this->headersAssinados('pay-inexistente');
        $this->postJson(route('webhooks.mercadopago'), ['type' => 'payment', 'data' => ['id' => 'pay-inexistente']], $headers)
            ->assertOk();
    }
}
