<?php

namespace App\Support\LicoesAprendidas;

use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\Work;
use Illuminate\Support\Collection;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 28) — leitura em lote de reaplicações,
 * nunca 1 query por card/lição. Reaproveitado pelos 3 pontos de UI
 * (Lookahead, Estoque, Biblioteca) — nenhum deles resolve reaplicação
 * sozinho.
 */
final class ReaplicacaoLicaoQuery
{
    /**
     * "Quais destas lições já foram reaplicadas NESTA obra?" — 1 única
     * query (mais o eager-load de `avaliacoes`, também em lote), nunca
     * N. `avaliacoes` sempre ordenada `created_at desc, id desc` — é o
     * que garante que `ultimaAvaliacao()`/`resultadoAtual()` funcionem
     * corretamente sobre o resultado já eager-loaded, sem query extra.
     *
     * @param  iterable<int, string>  $licaoIds
     * @return Collection<string, LicaoAprendidaReaplicacao> chave = licao_aprendida_id
     */
    public static function porObraELicoes(Work $obra, iterable $licaoIds): Collection
    {
        $licaoIds = collect($licaoIds)->filter()->unique()->values();
        if ($licaoIds->isEmpty()) {
            return collect();
        }

        return LicaoAprendidaReaplicacao::query()
            ->where('obra_id', $obra->id)
            ->whereIn('licao_aprendida_id', $licaoIds->all())
            ->with(['avaliacoes' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id')])
            ->get()
            ->keyBy('licao_aprendida_id');
    }

    /** Conveniência de 1 Lição — nunca reimplementa a regra, só delega. */
    public static function porObraELicao(Work $obra, LicaoAprendida $licao): ?LicaoAprendidaReaplicacao
    {
        return self::porObraELicoes($obra, [$licao->id])->get($licao->id);
    }

    /**
     * Histórico completo de reaplicações de UMA lição, através de
     * QUALQUER obra de destino — usado no detalhe da Biblioteca
     * Corporativa (Seção 22). Nunca expõe dado operacional da obra além
     * do nome (`obra:id,name`) — "sem acesso operacional indevido"
     * (Seção 22).
     *
     * @return Collection<int, LicaoAprendidaReaplicacao>
     */
    public static function historicoDaLicao(LicaoAprendida $licao): Collection
    {
        return LicaoAprendidaReaplicacao::query()
            ->where('licao_aprendida_id', $licao->id)
            ->with([
                'obra:id,name',
                'criadoPor:id,first_name,last_name',
                'avaliacoes' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'),
                'avaliacoes.avaliadoPor:id,first_name,last_name',
            ])
            ->orderByDesc('created_at')
            ->get();
    }
}
