<?php

namespace App\Services;

use App\Enums\StatusAssinatura;
use App\Enums\StatusFatura;
use App\Models\Assinatura;
use App\Models\AssinaturaFatura;
use App\Notifications\AssinaturaInadimplenteNotification;
use App\Notifications\FaturaGeradaNotification;
use Illuminate\Support\Carbon;

/**
 * Renovação de assinaturas Pix/Boleto — sem recorrência automática
 * nativa do Mercado Pago (só cartão via /preapproval tem isso), então a
 * cada ciclo é preciso gerar uma cobrança avulsa nova. Mesmo padrão de
 * "cruzar um marco de dias, agir uma vez" já usado em
 * App\Services\SuprimentoScheduler::verificarMarcoDeAlerta() — aqui a
 * idempotência é por EXISTÊNCIA de uma assinatura_faturas pro mesmo
 * `renovar_em` (não por coluna de timestamp, já que os ciclos se repetem
 * indefinidamente em vez de serem 2 marcos fixos).
 */
class CobrancaScheduler
{
    private const DIAS_ANTECEDENCIA = 5;

    /**
     * Dá tempo de um Pix/Boleto recém-gerado compensar antes de cortar
     * acesso — evita marcar Inadimplente um tenant que já pagou mas cuja
     * confirmação ainda não chegou via webhook.
     */
    private const DIAS_CARENCIA = 3;

    public function __construct(private readonly MercadoPagoGateway $gateway)
    {
    }

    public function gerarProximaCobrancaSeNecessario(Assinatura $assinatura): ?AssinaturaFatura
    {
        if ($assinatura->status !== StatusAssinatura::Ativa) {
            return null;
        }

        if (! in_array($assinatura->metodo_pagamento, ['pix', 'boleto'], true)) {
            return null;
        }

        if (! $assinatura->renovar_em) {
            return null;
        }

        $diasRestantes = Carbon::today()->diffInDays($assinatura->renovar_em, false);
        if ($diasRestantes > self::DIAS_ANTECEDENCIA) {
            return null;
        }

        $jaExiste = AssinaturaFatura::where('assinatura_id', $assinatura->id)
            ->where('vencimento', $assinatura->renovar_em->toDateString())
            ->exists();
        if ($jaExiste) {
            return null;
        }

        $tenant = $assinatura->tenant;
        $plano = $assinatura->plano;
        $payerEmail = $tenant->criador?->email;
        if (! $payerEmail) {
            return null;
        }

        $descricao = "Plano {$plano->nome} — {$tenant->name}";
        $vencimento = $assinatura->renovar_em;

        $resposta = $assinatura->metodo_pagamento === 'pix'
            ? $this->gateway->criarCobrancaPix($descricao, $payerEmail, (float) $plano->preco_mensal, $tenant->id, $vencimento)
            : $this->gateway->criarCobrancaBoleto($descricao, $payerEmail, (float) $plano->preco_mensal, $tenant->id, $vencimento);

        $fatura = AssinaturaFatura::create([
            'tenant_id' => $tenant->id,
            'assinatura_id' => $assinatura->id,
            'metodo_pagamento' => $assinatura->metodo_pagamento,
            'valor' => $plano->preco_mensal,
            'status' => StatusFatura::Pendente->value,
            'vencimento' => $vencimento->toDateString(),
            'mp_payment_id' => (string) ($resposta['id'] ?? ''),
            'link_pagamento' => $resposta['point_of_interaction']['transaction_data']['ticket_url'] ?? ($resposta['transaction_details']['external_resource_url'] ?? null),
            'qr_code' => $resposta['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
        ]);

        if ($tenant->criador) {
            $tenant->criador->notify(new FaturaGeradaNotification($fatura));
        }

        return $fatura;
    }

    /**
     * Marca Inadimplente automaticamente: Trial vencido sem nenhuma
     * assinatura paga, ou Ativa cujo ciclo venceu há mais de
     * DIAS_CARENCIA dias sem fatura paga correspondente. Uniforme pros 3
     * métodos de pagamento — cartão com cobrança recorrente rejeitada
     * também deixa `renovar_em` estagnado (o webhook só avança
     * `renovar_em` quando o pagamento é confirmado), então cai no mesmo
     * caminho de Pix/Boleto sem tratamento especial.
     */
    public function verificarInadimplenciaSeNecessario(Assinatura $assinatura): ?Assinatura
    {
        if ($assinatura->status === StatusAssinatura::Trial) {
            if (! $assinatura->fim_trial || Carbon::today()->lte($assinatura->fim_trial)) {
                return null;
            }

            return $this->marcarInadimplente($assinatura);
        }

        if ($assinatura->status === StatusAssinatura::Ativa) {
            if (! $assinatura->renovar_em) {
                return null;
            }

            $diasEmAtraso = $assinatura->renovar_em->diffInDays(Carbon::today(), false);
            if ($diasEmAtraso <= self::DIAS_CARENCIA) {
                return null;
            }

            $jaPago = AssinaturaFatura::where('assinatura_id', $assinatura->id)
                ->where('vencimento', $assinatura->renovar_em->toDateString())
                ->where('status', StatusFatura::Pago->value)
                ->exists();
            if ($jaPago) {
                return null;
            }

            return $this->marcarInadimplente($assinatura);
        }

        return null;
    }

    private function marcarInadimplente(Assinatura $assinatura): Assinatura
    {
        $tenant = $assinatura->tenant;

        $nova = $tenant->assinaturas()->create([
            'plano_id' => $assinatura->plano_id,
            'status' => StatusAssinatura::Inadimplente->value,
            'origem' => $assinatura->origem ?? 'manual',
            'metodo_pagamento' => $assinatura->metodo_pagamento,
            'inicio' => $assinatura->inicio,
        ]);

        if ($tenant->criador) {
            $tenant->criador->notify(new AssinaturaInadimplenteNotification($nova));
        }

        return $nova;
    }
}
