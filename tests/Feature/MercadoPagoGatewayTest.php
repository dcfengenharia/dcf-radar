<?php

namespace Tests\Feature;

use App\Services\MercadoPagoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MercadoPagoGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mercadopago.access_token' => 'token-teste']);
    }

    public function test_criar_assinatura_cartao_envia_payload_correto(): void
    {
        Http::fake(['api.mercadopago.com/preapproval' => Http::response(['id' => 'mp-preapproval-1', 'status' => 'authorized'], 201)]);

        $resposta = (new MercadoPagoGateway())->criarAssinaturaCartao(
            'Plano Pro — DCF Radar',
            'cliente@obra.com',
            'card-token-abc',
            299.90,
            'tenant-123',
            'https://dcf.eng.br/app/empresa/assinatura'
        );

        $this->assertSame('mp-preapproval-1', $resposta['id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mercadopago.com/preapproval'
                && $request->hasHeader('Authorization', 'Bearer token-teste')
                && $request['card_token_id'] === 'card-token-abc'
                && $request['auto_recurring']['transaction_amount'] === 299.90
                && $request['auto_recurring']['frequency_type'] === 'months';
        });
    }

    public function test_criar_cobranca_pix_envia_payload_correto(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 123, 'status' => 'pending'], 201)]);

        $resposta = (new MercadoPagoGateway())->criarCobrancaPix(
            'Plano Pro — DCF Radar',
            'cliente@obra.com',
            299.90,
            'tenant-123',
            Carbon::parse('2026-08-01')
        );

        $this->assertSame(123, $resposta['id']);

        Http::assertSent(fn ($request) => $request['payment_method_id'] === 'pix'
            && $request->hasHeader('X-Idempotency-Key'));
    }

    public function test_buscar_pagamento_reconfirma_status_na_api(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/123' => Http::response(['id' => 123, 'status' => 'approved'], 200)]);

        $resposta = (new MercadoPagoGateway())->buscarPagamento('123');

        $this->assertSame('approved', $resposta['status']);
    }

    public function test_resposta_de_erro_lanca_excecao(): void
    {
        Http::fake(['api.mercadopago.com/preapproval' => Http::response(['message' => 'invalid card token'], 400)]);

        $this->expectException(RuntimeException::class);

        (new MercadoPagoGateway())->criarAssinaturaCartao('Plano', 'a@b.com', 'token-invalido', 100, 'tenant-1', 'https://x');
    }

    public function test_sem_access_token_configurado_lanca_excecao(): void
    {
        config(['services.mercadopago.access_token' => null]);
        Http::fake();

        $this->expectException(RuntimeException::class);

        (new MercadoPagoGateway())->buscarPagamento('123');

        Http::assertNothingSent();
    }
}
