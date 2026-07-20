<?php

namespace App\Http\Controllers;

use App\Enums\StatusAssinatura;
use App\Enums\StatusFatura;
use App\Models\AssinaturaFatura;
use App\Models\Tenant;
use App\Notifications\PagamentoConfirmadoNotification;
use App\Services\MercadoPagoGateway;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Recebe eventos do Mercado Pago (pagamento Pix/Boleto confirmado,
 * mudança de status de assinatura recorrente de cartão). Rota pública
 * (fora de `auth`), fora do CSRF (ver App\Http\Middleware\VerifyCsrfToken).
 *
 * Nunca confia no payload do webhook pra decidir o status — sempre
 * reconfirma direto na API do Mercado Pago (App\Services\MercadoPagoGateway),
 * seguindo a recomendação de segurança do próprio Mercado Pago. Sempre
 * responde 200 rapidamente (mesmo em erro interno, só reporta) pra não
 * entrar em loop de retentativa.
 */
class MercadoPagoWebhookController extends Controller
{
    public function __construct(private readonly MercadoPagoGateway $gateway)
    {
    }

    public function handle(Request $request): Response
    {
        if (! $this->assinaturaValida($request)) {
            return response('assinatura inválida', 401);
        }

        try {
            $tipo = $request->input('type');
            $dataId = (string) $request->input('data.id', '');

            if ($dataId !== '') {
                match ($tipo) {
                    'payment' => $this->processarPagamento($dataId),
                    'preapproval' => $this->processarPreapproval($dataId),
                    default => null,
                };
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response('', 200);
    }

    /**
     * Algoritmo documentado pelo Mercado Pago: manifest
     * "id:{data.id};request-id:{x-request-id};ts:{ts};" assinado com
     * HMAC-SHA256 usando o webhook_secret, comparado ao `v1` do header
     * `x-signature` (formato "ts=...,v1=...").
     */
    private function assinaturaValida(Request $request): bool
    {
        $secret = config('services.mercadopago.webhook_secret');
        $header = $request->header('x-signature');
        $requestId = $request->header('x-request-id');

        if (! $secret || ! $header || ! $requestId) {
            return false;
        }

        $partes = [];
        foreach (explode(',', $header) as $parte) {
            [$chave, $valor] = array_pad(explode('=', trim($parte), 2), 2, null);
            if ($chave !== null) {
                $partes[$chave] = $valor;
            }
        }

        $ts = $partes['ts'] ?? null;
        $v1 = $partes['v1'] ?? null;
        if (! $ts || ! $v1) {
            return false;
        }

        $dataId = (string) $request->input('data.id', '');
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $esperado = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($esperado, $v1);
    }

    private function processarPagamento(string $mpPaymentId): void
    {
        $pagamento = $this->gateway->buscarPagamento($mpPaymentId);

        $tenantId = DB::table('assinatura_faturas')->where('mp_payment_id', $mpPaymentId)->value('tenant_id')
            ?? ($pagamento['external_reference'] ?? null);

        if (! $tenantId) {
            return;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return;
        }

        TenantContext::actingAs($tenant, function () use ($tenant, $mpPaymentId, $pagamento) {
            $novoStatus = $this->mapStatusFatura($pagamento['status'] ?? null);
            $fatura = AssinaturaFatura::where('mp_payment_id', $mpPaymentId)->first();

            if (! $fatura) {
                // Cobrança recorrente de cartão gerada pelo Mercado Pago
                // (sem fatura pré-criada por nós, diferente do fluxo
                // Pix/Boleto) — resolve a Assinatura vigente do tenant e
                // registra a linha de auditoria agora.
                $assinatura = $tenant->assinaturaAtual();
                if (! $assinatura || ! $assinatura->mp_preapproval_id) {
                    return;
                }

                $fatura = AssinaturaFatura::create([
                    'tenant_id' => $tenant->id,
                    'assinatura_id' => $assinatura->id,
                    'metodo_pagamento' => 'cartao',
                    'valor' => $pagamento['transaction_amount'] ?? 0,
                    'status' => $novoStatus->value,
                    'vencimento' => now()->toDateString(),
                    'mp_payment_id' => $mpPaymentId,
                    'mp_preapproval_id' => $assinatura->mp_preapproval_id,
                ]);
            }

            $fatura->update([
                'status' => $novoStatus->value,
                'pago_em' => $novoStatus === StatusFatura::Pago ? ($fatura->pago_em ?? now()) : $fatura->pago_em,
            ]);

            if ($novoStatus === StatusFatura::Pago) {
                $assinatura = $fatura->assinatura;
                if ($assinatura->status !== StatusAssinatura::Ativa) {
                    $tenant->assinaturas()->create([
                        'plano_id' => $assinatura->plano_id,
                        'status' => StatusAssinatura::Ativa->value,
                        'origem' => 'mercadopago',
                        'metodo_pagamento' => $assinatura->metodo_pagamento,
                        'mp_preapproval_id' => $assinatura->mp_preapproval_id,
                        'inicio' => $assinatura->inicio,
                        'renovar_em' => now()->addMonth()->toDateString(),
                    ]);
                }

                if ($tenant->criador) {
                    $tenant->criador->notify(new PagamentoConfirmadoNotification($fatura));
                }
            }
        });
    }

    private function processarPreapproval(string $mpPreapprovalId): void
    {
        $tenantId = DB::table('assinaturas')->where('mp_preapproval_id', $mpPreapprovalId)->value('tenant_id');
        if (! $tenantId) {
            return;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return;
        }

        TenantContext::actingAs($tenant, function () use ($tenant, $mpPreapprovalId) {
            $preapproval = $this->gateway->buscarAssinatura($mpPreapprovalId);
            $assinaturaAtual = $tenant->assinaturas()->where('mp_preapproval_id', $mpPreapprovalId)->latest('inicio')->first();
            if (! $assinaturaAtual) {
                return;
            }

            $novoStatus = match ($preapproval['status'] ?? null) {
                'authorized' => StatusAssinatura::Ativa,
                'paused' => StatusAssinatura::Suspensa,
                'cancelled' => StatusAssinatura::Cancelada,
                default => null,
            };

            if (! $novoStatus || $novoStatus === $assinaturaAtual->status) {
                return;
            }

            $tenant->assinaturas()->create([
                'plano_id' => $assinaturaAtual->plano_id,
                'status' => $novoStatus->value,
                'origem' => 'mercadopago',
                'metodo_pagamento' => 'cartao',
                'mp_preapproval_id' => $mpPreapprovalId,
                'inicio' => $assinaturaAtual->inicio,
                'renovar_em' => $novoStatus === StatusAssinatura::Ativa ? now()->addMonth()->toDateString() : null,
                'cancelada_em' => $novoStatus === StatusAssinatura::Cancelada ? now() : null,
            ]);
        });
    }

    private function mapStatusFatura(?string $statusMp): StatusFatura
    {
        return match ($statusMp) {
            'approved' => StatusFatura::Pago,
            'rejected', 'cancelled' => StatusFatura::Cancelado,
            'refunded', 'charged_back' => StatusFatura::Estornado,
            default => StatusFatura::Pendente,
        };
    }
}
