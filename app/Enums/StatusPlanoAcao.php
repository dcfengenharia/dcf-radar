<?php

namespace App\Enums;

/**
 * Status principal de um PlanoAcao — deliberadamente enxuto (Fase 4,
 * decisão do usuário): "Agravado"/"Alterado"/"Persistente" NÃO são estados
 * permanentes do ciclo de vida da ação, são resultados de reconciliação
 * (ver ResultadoReconciliacaoPlanoAcao), registrados em
 * PlanoAcaoReconciliacao — nunca gravados aqui.
 */
enum StatusPlanoAcao: string
{
    case Aberta    = 'aberta';
    case Resolvida = 'resolvida';
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Aberta => 'Aberta',
            self::Resolvida => 'Resolvida',
            self::Cancelada => 'Cancelada',
        };
    }

    /** Aberta = pode ser reconciliada; Resolvida/Cancelada nunca voltam sozinhas. */
    public function estaAberta(): bool
    {
        return $this === self::Aberta;
    }

    /**
     * Fonte única de verdade da matriz de transições manuais (Fase 4.3,
     * Etapa D) — reaproveitada tanto pelas opções do `<select>` do modal de
     * edição quanto pela validação server-side em `confirmarEditar()`,
     * pra nunca duplicar essa regra em dois lugares. Sempre inclui o
     * próprio status atual (permite "manter", usado quando só
     * responsável/prazo mudam sem transição de status).
     *
     * Resolvida/Cancelada nunca trocam diretamente entre si — precisam
     * reabrir (voltar pra Aberta) antes.
     *
     * @return self[]
     */
    public function transicoesPermitidas(): array
    {
        return match ($this) {
            self::Aberta => [self::Aberta, self::Resolvida, self::Cancelada],
            self::Resolvida => [self::Resolvida, self::Aberta],
            self::Cancelada => [self::Cancelada, self::Aberta],
        };
    }
}
