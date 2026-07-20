<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chamadas HTTP diretas à API do Mercado Pago (mesmo padrão de
 * App\Notifications\Channels\ZApiChannel — sem SDK oficial, só
 * Http:: facade, 100% testável com Http::fake()).
 *
 * Diferente do ZApiChannel (best-effort, nunca lança exceção), aqui
 * SEM credencial configurada é erro de verdade — pagamento não pode
 * falhar em silêncio como uma notificação WhatsApp pode.
 */
class MercadoPagoGateway
{
    private const BASE_URL = 'https://api.mercadopago.com';

    private function accessToken(): string
    {
        $token = config('services.mercadopago.access_token');

        if (! $token) {
            throw new RuntimeException('MERCADOPAGO_ACCESS_TOKEN não configurado.');
        }

        return $token;
    }

    private function http()
    {
        return Http::withToken($this->accessToken())->baseUrl(self::BASE_URL)->acceptJson();
    }

    /**
     * Cria uma assinatura recorrente de cartão (cobrança automática de
     * verdade). $cardTokenId vem do Card Payment Brick, tokenizado no
     * navegador — o dado do cartão nunca passa pelo nosso servidor.
     */
    public function criarAssinaturaCartao(string $motivo, string $payerEmail, string $cardTokenId, float $valorMensal, string $externalReference, string $backUrl): array
    {
        $resposta = $this->http()->post('/preapproval', [
            'reason' => $motivo,
            'external_reference' => $externalReference,
            'payer_email' => $payerEmail,
            'card_token_id' => $cardTokenId,
            'back_url' => $backUrl,
            'status' => 'authorized',
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => $valorMensal,
                'currency_id' => 'BRL',
            ],
        ]);

        return $this->tratarResposta($resposta, 'criar assinatura de cartão');
    }

    public function cancelarAssinatura(string $mpPreapprovalId): array
    {
        $resposta = $this->http()->put("/preapproval/{$mpPreapprovalId}", [
            'status' => 'cancelled',
        ]);

        return $this->tratarResposta($resposta, 'cancelar assinatura');
    }

    public function buscarAssinatura(string $mpPreapprovalId): array
    {
        $resposta = $this->http()->get("/preapproval/{$mpPreapprovalId}");

        return $this->tratarResposta($resposta, 'buscar assinatura');
    }

    public function criarCobrancaPix(string $descricao, string $payerEmail, float $valor, string $externalReference, \DateTimeInterface $vencimento): array
    {
        $resposta = $this->http()->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])->post('/v1/payments', [
            'transaction_amount' => $valor,
            'description' => $descricao,
            'payment_method_id' => 'pix',
            'external_reference' => $externalReference,
            'date_of_expiration' => $vencimento->format('Y-m-d\TH:i:s.vP'),
            'payer' => ['email' => $payerEmail],
        ]);

        return $this->tratarResposta($resposta, 'criar cobrança Pix');
    }

    public function criarCobrancaBoleto(string $descricao, string $payerEmail, float $valor, string $externalReference, \DateTimeInterface $vencimento): array
    {
        $resposta = $this->http()->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])->post('/v1/payments', [
            'transaction_amount' => $valor,
            'description' => $descricao,
            'payment_method_id' => 'bolbradesco',
            'external_reference' => $externalReference,
            'date_of_expiration' => $vencimento->format('Y-m-d\TH:i:s.vP'),
            'payer' => ['email' => $payerEmail],
        ]);

        return $this->tratarResposta($resposta, 'criar cobrança de boleto');
    }

    /**
     * Sempre reconfirma o status direto na API — nunca confiar no
     * payload do webhook, seguindo a recomendação de segurança do
     * próprio Mercado Pago.
     */
    public function buscarPagamento(string $mpPaymentId): array
    {
        $resposta = $this->http()->get("/v1/payments/{$mpPaymentId}");

        return $this->tratarResposta($resposta, 'buscar pagamento');
    }

    private function tratarResposta($resposta, string $acao): array
    {
        if ($resposta->failed()) {
            throw new RuntimeException("Falha ao {$acao} no Mercado Pago (HTTP {$resposta->status()}): {$resposta->body()}");
        }

        return $resposta->json();
    }
}
