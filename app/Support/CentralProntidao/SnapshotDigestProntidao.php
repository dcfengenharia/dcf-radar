<?php

namespace App\Support\CentralProntidao;

use Carbon\Carbon;

/**
 * Payload REDUZIDO e imutável do Digest de Prontidão (Ciclo 16, Etapa
 * A.3) — o que de fato viaja dentro de uma Notification queued. Existe
 * porque a auditoria da A.2 confirmou que `ResumoDigestProntidao`
 * completo (com todas as `AtividadeProntidaoView` problemáticas, cada
 * uma carregando Restrições/PlanoAcao/Suprimentos/Engenharia aninhados)
 * é serializável, mas pode passar de 250-300KB numa obra com muitas
 * pendências — peso desnecessário pra um payload cujo propósito é só um
 * RESUMO ("N atividades exigem atenção"), nunca um substituto da Central
 * de Prontidão (o usuário investiga detalhe lá, nunca pela notificação).
 *
 * `deResumo()` é o ÚNICO ponto de conversão `ResumoDigestProntidao →
 * SnapshotDigestProntidao` — nunca duplicado em outro lugar.
 *
 * @param  DestaqueProntidao[]  $destaques  no máximo MAX_DESTAQUES, nunca a lista completa de atividadesProblematicas
 */
final readonly class SnapshotDigestProntidao
{
    public const MAX_DESTAQUES = 5;

    public function __construct(
        public string $obraId,
        public string $obraNome,
        public int $horizonteDias,
        public Carbon $geradoEm,
        public int $totalAtividadesUniverso,
        public int $totalNaoPronta,
        public int $totalAtencao,
        public int $totalExigeAtencao,
        public array $destaques,
    ) {
    }

    /**
     * Reduz um `ResumoDigestProntidao` (produzido por
     * `DigestProntidao::consolidar()`, Etapa A.2) pra este snapshot leve.
     * Só as contagens e no máximo `$maxDestaques` atividades sobrevivem —
     * `atividadesProblematicas` completo (com DTOs de contexto aninhados)
     * nunca é carregado adiante.
     *
     * Ordenação dos destaques (Ciclo 16, A.3, seção 3 — deliberadamente
     * simples, sem inventar classificação de prioridade nova): (1)
     * NAO_PRONTA antes de ATENCAO; (2) início planejado mais próximo
     * primeiro (sem início vai por último); (3) código WBS como
     * desempate final (mesmo comparador natural já usado em
     * `⚡central-prontidao.blade.php`/`⚡lookahead.blade.php`,
     * deliberadamente duplicado aqui — convenção do projeto de não
     * compartilhar comparador pequeno via trait).
     */
    public static function deResumo(ResumoDigestProntidao $resumo, int $maxDestaques = self::MAX_DESTAQUES): self
    {
        $destaques = collect($resumo->atividadesProblematicas)
            ->sort(fn (AtividadeProntidaoView $a, AtividadeProntidaoView $b) => self::compararParaDestaque($a, $b))
            ->take($maxDestaques)
            ->map(fn (AtividadeProntidaoView $view) => new DestaqueProntidao(
                codigo: $view->codigoCronograma,
                nome: $view->nome,
                statusOperacional: $view->statusOperacional,
                inicioPlanejado: $view->inicioPlanejado,
                resumoMotivos: $view->resumoMotivos,
            ))
            ->values()
            ->all();

        return new self(
            obraId: $resumo->obraId,
            obraNome: $resumo->obraNome,
            horizonteDias: $resumo->horizonteDias,
            geradoEm: $resumo->geradoEm,
            totalAtividadesUniverso: $resumo->totalAtividadesUniverso,
            totalNaoPronta: $resumo->totalNaoPronta,
            totalAtencao: $resumo->totalAtencao,
            totalExigeAtencao: $resumo->totalExigeAtencao,
            destaques: $destaques,
        );
    }

    private static function compararParaDestaque(AtividadeProntidaoView $a, AtividadeProntidaoView $b): int
    {
        $rankA = self::rankStatus($a->statusOperacional);
        $rankB = self::rankStatus($b->statusOperacional);
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        $dataA = $a->inicioPlanejado?->timestamp ?? PHP_INT_MAX;
        $dataB = $b->inicioPlanejado?->timestamp ?? PHP_INT_MAX;
        if ($dataA !== $dataB) {
            return $dataA <=> $dataB;
        }

        return self::compararCodigosWbs($a->codigoCronograma, $b->codigoCronograma);
    }

    private static function rankStatus(StatusOperacionalProntidao $status): int
    {
        return match ($status) {
            StatusOperacionalProntidao::NaoPronta => 1,
            StatusOperacionalProntidao::Atencao => 2,
            default => 3,
        };
    }

    private static function compararCodigosWbs(?string $a, ?string $b): int
    {
        $segA = explode('.', $a ?? '');
        $segB = explode('.', $b ?? '');

        foreach (range(0, max(count($segA), count($segB)) - 1) as $i) {
            $x = (int) ($segA[$i] ?? 0);
            $y = (int) ($segB[$i] ?? 0);
            if ($x !== $y) {
                return $x <=> $y;
            }
        }

        return 0;
    }
}
