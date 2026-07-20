<?php

namespace App\Support;

use App\Enums\PilarLean;
use App\Enums\StatusItemSuprimento;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\ItemSuprimento;
use App\Models\Restricao;
use App\Services\SuprimentoScheduler;

/**
 * Ponte entre o Mapa de Suprimentos e o motor de restrições/prontidão já
 * existente (App\Models\Atividade::estaPronta()/scopeProntas()) — espelha
 * App\Support\ConclusaoAutomaticaAtividades, mas na direção oposta (abre
 * bloqueio em vez de resolver).
 *
 * Como o vínculo Item↔Atividade é N:N, uma Restricao é por PAR (item,
 * atividade) — um item em risco bloqueia CADA atividade que depende dele,
 * não uma restrição única "solta".
 */
class SincronizarRestricaoSuprimento
{
    private const STATUS_ABERTOS = [
        StatusRestricao::Aberta->value,
        StatusRestricao::EmTratamento->value,
        StatusRestricao::AguardandoTerceiros->value,
    ];

    /**
     * Recalcula Tendência/status de todo ItemSuprimento vinculado a
     * qualquer uma das atividades informadas, e sincroniza as restrições
     * resultantes. Ponto de entrada usado pela reimportação de cronograma
     * (inicio_planejado pode ter mudado) e pelo comando diário.
     *
     * @param  iterable<string>  $atividadeIds
     */
    public static function aplicarParaAtividades(iterable $atividadeIds, ?string $userId): void
    {
        $ids = collect($atividadeIds)->values();
        if ($ids->isEmpty()) {
            return;
        }

        $itens = ItemSuprimento::whereHas('atividades', function ($query) use ($ids) {
            $query->whereIn('atividades.id', $ids);
        })->get();

        foreach ($itens as $item) {
            static::sincronizarItem($item, $userId);
        }
    }

    /**
     * Recalcula Tendência/status de UM item e sincroniza suas restrições —
     * ponto de entrada usado após criar/editar o item ou lançar uma data de
     * etapa (Realizado).
     */
    public static function sincronizarItem(ItemSuprimento $item, ?string $userId): void
    {
        $scheduler = new SuprimentoScheduler();
        $scheduler->recalcularTendencia($item);
        $status = $scheduler->statusDoItem($item);

        $item = $item->fresh(['atividades']);
        $emRisco = in_array($status, [StatusItemSuprimento::EmRisco, StatusItemSuprimento::Atrasado], true);

        foreach ($item->atividades as $atividade) {
            $restricao = static::buscarRestricao($item, $atividade);

            if ($emRisco) {
                static::abrirOuAtualizar($item, $atividade, $restricao);
            } elseif ($restricao && in_array($restricao->status->value, self::STATUS_ABERTOS, true)) {
                static::resolverAutomaticamente($restricao, $userId);
            }
        }
    }

    /**
     * Desvincula uma atividade específica do item (sem apagar o item) —
     * resolve só a restrição daquele par; as demais atividades ainda
     * vinculadas ao mesmo item continuam bloqueadas normalmente, se o item
     * continuar em risco.
     */
    public static function desvincularAtividade(ItemSuprimento $item, Atividade $atividade, ?string $userId): void
    {
        $restricao = static::buscarRestricao($item, $atividade);

        if ($restricao && in_array($restricao->status->value, self::STATUS_ABERTOS, true)) {
            static::resolverAutomaticamente($restricao, $userId);
        }
    }

    /**
     * Resolve TODAS as restrições ainda abertas originadas por este item —
     * chamado ao excluir (soft delete) o item, pra não deixar bloqueios
     * órfãos.
     */
    public static function resolverTudo(ItemSuprimento $item, ?string $userId): void
    {
        $restricoesAbertas = Restricao::where('origem_suprimento_item_id', $item->id)
            ->whereIn('status', self::STATUS_ABERTOS)
            ->get();

        foreach ($restricoesAbertas as $restricao) {
            static::resolverAutomaticamente($restricao, $userId);
        }
    }

    private static function buscarRestricao(ItemSuprimento $item, Atividade $atividade): ?Restricao
    {
        return Restricao::where('atividade_id', $atividade->id)
            ->where('origem_suprimento_item_id', $item->id)
            ->first();
    }

    private static function abrirOuAtualizar(ItemSuprimento $item, Atividade $atividade, ?Restricao $restricao): void
    {
        $necessidade = $item->necessidade();

        if ($restricao && in_array($restricao->status->value, self::STATUS_ABERTOS, true)) {
            if (! $restricao->prazo_limite?->isSameDay($necessidade)) {
                $restricao->update(['prazo_limite' => $necessidade]);
            }

            return;
        }

        if ($restricao) {
            // Existia e já tinha sido resolvida — o item voltou a ficar em
            // risco, reabre em vez de duplicar.
            $restricao->update([
                'status' => StatusRestricao::Aberta->value,
                'prazo_limite' => $necessidade,
                'resolvida_em' => null,
            ]);

            return;
        }

        Restricao::create([
            'atividade_id' => $atividade->id,
            'categoria_id' => static::categoriaMateriais($item->tenant_id)->id,
            'descricao' => "Suprimentos: item \"{$item->nome}\" em risco de não chegar a tempo.",
            'bloqueante' => true,
            'prazo_limite' => $necessidade,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
            'origem_suprimento_item_id' => $item->id,
        ]);
    }

    private static function resolverAutomaticamente(Restricao $restricao, ?string $userId): void
    {
        if ($userId) {
            $restricao->acoes()->create([
                'autor_id' => $userId,
                'descricao' => 'Restrição resolvida automaticamente: item de suprimento voltou ao prazo.',
            ]);
        }

        $restricao->update([
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);
    }

    private static function categoriaMateriais(string $tenantId): CategoriaRestricao
    {
        return CategoriaRestricao::where('tenant_id', $tenantId)
            ->where('pilar_lean', PilarLean::Materiais->value)
            ->first() ?? CategoriaRestricao::create([
                'tenant_id' => $tenantId,
                'nome' => 'Suprimentos',
                'pilar_lean' => PilarLean::Materiais->value,
            ]);
    }
}
