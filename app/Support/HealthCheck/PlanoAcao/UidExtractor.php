<?php

namespace App\Support\HealthCheck\PlanoAcao;

/**
 * Extrai, de forma recursiva e genérica, todos os `uid` (external_uid do
 * MS Project) presentes na estrutura `HealthCheckFinding::$atividades`.
 *
 * Fase 4 (diagnóstico) confirmou, lendo o código real das 36 regras, que
 * existem pelo menos 3 formas dessa estrutura:
 * - Plana: [{uid, codigo, nome, ...}, ...] — maioria das regras.
 * - Agrupada por grupo: [{ciclo_id|componente_id, atividades: [{uid,...}],
 *   relacoes: [...]}] — STRUCT-004/STRUCT-005.
 * - Par: [{predecessora: {uid,...}, sucessora: {uid,...}, vinculos: [...]}]
 *   (LOGIC-005/009) e a variante com predecessoras_ativas/
 *   predecessoras_inativas (LOGIC-010).
 *
 * Em vez de hardcodar cada nome de chave de agrupamento (o que quebraria
 * silenciosamente a cada regra nova com uma forma diferente), a extração
 * percorre QUALQUER array aninhado e coleta o valor de toda chave literal
 * 'uid' — convenção já usada por TODAS as regras existentes (confirmado
 * por grep). Isso cobre as 3 formas atuais e continua funcionando para
 * formas agrupadas futuras, desde que a convenção seja mantida.
 *
 * Nunca modifica os findings persistidos — só lê.
 */
final class UidExtractor
{
    /**
     * @param array $atividades o campo bruto HealthCheckFinding::$atividades
     * @return string[] uids únicos (sem ordem garantida além de array_unique)
     */
    public static function extrair(array $atividades): array
    {
        $uids = [];
        self::coletar($atividades, $uids);

        return array_values(array_unique($uids));
    }

    /** @param string[] $uids */
    private static function coletar(array $estrutura, array &$uids): void
    {
        foreach ($estrutura as $chave => $valor) {
            if ($chave === 'uid' && is_string($valor) && $valor !== '') {
                $uids[] = $valor;

                continue;
            }

            if (is_array($valor)) {
                self::coletar($valor, $uids);
            }
        }
    }
}
