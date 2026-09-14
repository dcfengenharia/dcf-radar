<?php

namespace App\Enums;

/**
 * Etapa 2 (Adjudicação) — Ativa|Cancelada. Justificado (não é enum por
 * estética): sem ele não haveria como distinguir "decisão vigente" de
 * "decisão reconsiderada" sem apagar/sobrescrever histórico (Seção 9 do
 * pedido — "não sobrescrever silenciosamente"). Uma adjudicação Cancelada
 * NUNCA é fisicamente removida (`App\Observers\RequisicaoCompraAdjudicacaoObserver`
 * bloqueia delete incondicionalmente) — só deixa de contar como
 * "quantidade adjudicada oficialmente" nas guardas de saldo. Sem
 * reabertura nesta V1 (mesma decisão já usada em `StatusInconsistenciaAvanco`/
 * `StatusPlanoAcao`): reconsiderar significa criar uma NOVA adjudicação.
 */
enum StatusAdjudicacaoRequisicaoCompra: string
{
    case Ativa = 'ativa';
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Ativa => 'Ativa',
            self::Cancelada => 'Cancelada',
        };
    }
}
