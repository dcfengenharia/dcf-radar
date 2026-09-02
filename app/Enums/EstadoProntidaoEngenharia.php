<?php

namespace App\Enums;

/**
 * Ciclo 22, Etapa 22.1 — estado agregado de prontidão DOCUMENTAL de uma
 * Atividade, derivado da relação `Atividade::documentosEngenharia()`
 * (Ciclo 18.1) e da regra autoritativa `DocumentoEngenharia::
 * estaLiberadoParaConstrucao()`/`scopeNaoLiberados()` (Ciclo 18.4) —
 * NUNCA uma fórmula paralela. Mesma filosofia de `EstadoCoberturaMaterial`
 * (Ciclo 21.1): `InformacaoInsuficiente` cobre a AUSÊNCIA de vínculo
 * documental, nunca confundida com "liberada" (Seção 6/15 do pedido:
 * "não converter ausência de vínculo/documentação suficiente em
 * pronta"). Sem estado "NãoAplicável" — decisão deliberada (Seção 7:
 * "não introduzir estado sem necessidade"): o domínio não tem nenhum
 * sinal de "esta atividade definitivamente não precisa de documento",
 * só a ausência de vínculo, que já é `InformacaoInsuficiente`.
 *
 * **Diferença explícita em relação a `Atividade::scopeProntas()`**: o
 * bloqueio duro de prontidão OPERACIONAL (Plano Semanal/Lookahead) trata
 * QUALQUER documento não liberado como bloqueio total, sem distinguir
 * 4/5 liberados de 0/5 — este enum existe justamente pra capturar essa
 * granularidade mais rica (Seção 8), nunca pra substituir/duplicar a
 * regra de `scopeProntas()`.
 */
enum EstadoProntidaoEngenharia: string
{
    case Liberada = 'liberada';
    case Parcial = 'parcial';
    case Bloqueada = 'bloqueada';
    case InformacaoInsuficiente = 'informacao_insuficiente';

    public static function calcular(int $totalDocumentos, int $totalLiberados): self
    {
        if ($totalDocumentos === 0) {
            return self::InformacaoInsuficiente;
        }

        if ($totalLiberados === $totalDocumentos) {
            return self::Liberada;
        }

        if ($totalLiberados === 0) {
            return self::Bloqueada;
        }

        return self::Parcial;
    }

    public function label(): string
    {
        return match ($this) {
            self::Liberada => 'Liberada',
            self::Parcial => 'Parcialmente liberada',
            self::Bloqueada => 'Bloqueada',
            self::InformacaoInsuficiente => 'Informação insuficiente',
        };
    }
}
