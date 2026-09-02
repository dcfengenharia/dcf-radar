<?php

namespace App\Support\Gestao;

use App\Enums\EstadoCoberturaMaterial;
use App\Enums\StatusAtividade;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\Work;
use App\Support\Estoque\ConciliacaoDestinacao;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.1 — resposta à pergunta central da auditoria 20.9:
 * "Quais atividades que iniciarão dentro do horizonte possuem
 * necessidade de material ainda sem cobertura suficiente?"
 *
 * **Relação Atividade ↔ Material, confirmada por fresh-read, NUNCA
 * heurística**: `Atividade` liga-se a `Material` através de
 * `App\Models\ItemSuprimento` (Pacote de Compra) — `Atividade::
 * itensSuprimento()` é uma N:N REAL (FK via pivô `item_suprimento_atividades`,
 * mesma tabela que já alimenta `App\Services\SuprimentoScheduler` desde
 * o Ciclo 13). Essa ligação é MANUALMENTE CURADA (o usuário vincula
 * atividade(s) ao Pacote na mesma tela onde o cria/edita,
 * `⚡suprimentos.blade.php`), nunca inferida por WBS/descrição/
 * disciplina — quando ausente (Pacote sem nenhuma Atividade vinculada,
 * ou Atividade sem nenhum Pacote vinculado), a resposta correta é
 * `EstadoCoberturaMaterial::InformacaoInsuficiente`, nunca "coberta".
 * Do Pacote em diante, a cadeia É determinística por FK: `ItemSuprimento`
 * → `AlocacaoRequisicaoPacote` → `RequisicaoPlanejamentoItem` →
 * `ItemTakeOff` → `Material`.
 *
 * **Físico é da OBRA, compromisso é do PACOTE**: `disponivel`/`deficit`
 * ao nível de Material são sempre obra-inteira (`SaldoEstoque`/
 * `SaldoReserva`, via `PipelineMaterialQuery`) — nunca fatiados por
 * Pacote (fisicamente não existe "a fatia do Pacote X" num monte de
 * material). O que É específico do Pacote é `reservado_pacote`
 * (`ReservaEstoque.item_suprimento_id`) — o compromisso formal que ESTE
 * Pacote garantiu sobre o físico da obra. Se o Material como um todo
 * está em déficit (reservas de TODOS os pacotes somadas > físico), todo
 * par que dependa dele vira `DeficitAposConsumoEmergencial`, mesmo que
 * `reservado_pacote` sozinho pareça suficiente — reflete honestamente
 * que a reserva pode já não estar 100% coberta fisicamente.
 */
class CoberturaMaterialAtividadeQuery
{
    /**
     * @return Collection<int, array> uma linha por Atividade no
     *   horizonte, com `estado_agregado` (EstadoCoberturaMaterial) e
     *   `pares` (detalhe por Pacote+Material, pra drill-down)
     */
    public static function porObra(Work $obra, int $horizonteDias, ?CarbonInterface $referencia = null): Collection
    {
        $referencia ??= Carbon::today();
        $fimHorizonte = $referencia->copy()->addDays($horizonteDias);

        $atividades = Atividade::query()
            ->where('obra_id', $obra->id)
            ->where('fora_do_cronograma', false)
            ->where('status', '!=', StatusAtividade::Concluido->value)
            ->whereNotNull('inicio_planejado')
            ->whereBetween('inicio_planejado', [$referencia->toDateString(), $fimHorizonte->toDateString()])
            ->with(['itensSuprimento:id,nome,codigo'])
            ->orderBy('inicio_planejado')
            ->get();

        if ($atividades->isEmpty()) {
            return collect();
        }

        // --- Resolve todos os pares (Pacote, Material) envolvidos, em lote ---
        $pacoteIds = $atividades->flatMap(fn (Atividade $a) => $a->itensSuprimento->pluck('id'))->unique()->values();

        if ($pacoteIds->isEmpty()) {
            // Nenhuma das atividades do horizonte tem Pacote vinculado —
            // informação insuficiente pra TODAS, nunca "coberta".
            return $atividades->map(fn (Atividade $a) => self::montarLinhaSemPacote($a));
        }

        $paresBrutos = AlocacaoRequisicaoPacote::query()
            ->whereIn('item_suprimento_id', $pacoteIds)
            ->with('requisicaoItem.itemTakeOff:id,material_id')
            ->get()
            ->map(fn (AlocacaoRequisicaoPacote $a) => [
                'item_suprimento_id' => $a->item_suprimento_id,
                'material_id' => $a->requisicaoItem?->itemTakeOff?->material_id,
            ])
            ->filter(fn ($p) => $p['material_id'] !== null)
            ->unique(fn ($p) => $p['item_suprimento_id'] . '|' . $p['material_id'])
            ->values();

        $materialIds = $paresBrutos->pluck('material_id')->unique()->values()->all();

        $destinacaoPorPar = ConciliacaoDestinacao::porPares($paresBrutos)
            ->keyBy(fn ($r) => $r['item_suprimento_id'] . '|' . $r['material_id']);
        $reservadoPacotePorPar = SaldoReserva::porPacotesEMateriais($paresBrutos);
        $compraPorPar = PipelineMaterialQuery::porPacotesEMateriais($paresBrutos, $obra->id);
        $fisicoObra = SaldoEstoque::porMateriaisNaObra($materialIds, $obra->id);
        $reservadoObra = SaldoReserva::porMateriaisNaObra($materialIds, $obra->id);

        $estadoPorPar = $paresBrutos->mapWithKeys(function (array $par) use (
            $destinacaoPorPar, $reservadoPacotePorPar, $compraPorPar, $fisicoObra, $reservadoObra
        ) {
            $chave = $par['item_suprimento_id'] . '|' . $par['material_id'];
            $demanda = (float) ($destinacaoPorPar[$chave]['formal'] ?? 0.0);
            $reservadoPacote = (float) ($reservadoPacotePorPar[$chave] ?? 0.0);
            $compra = $compraPorPar[$chave] ?? ['requisitado' => 0.0, 'alocado' => 0.0, 'em_rc' => 0.0, 'em_pedido' => 0.0, 'recebido' => 0.0];
            $fisico = (float) ($fisicoObra[$par['material_id']] ?? 0.0);
            $reservadoTotal = (float) ($reservadoObra[$par['material_id']] ?? 0.0);
            $deficitObra = round(max(0, $reservadoTotal - $fisico), 3);

            $estado = self::classificar($demanda, $reservadoPacote, $deficitObra, $compra);

            return [$chave => [
                'item_suprimento_id' => $par['item_suprimento_id'],
                'material_id' => $par['material_id'],
                'demanda' => $demanda,
                'reservado_pacote' => $reservadoPacote,
                'deficit_obra_material' => $deficitObra,
                'requisitado' => $compra['requisitado'],
                'alocado' => $compra['alocado'],
                'em_rc' => $compra['em_rc'],
                'em_pedido' => $compra['em_pedido'],
                'recebido' => $compra['recebido'],
                'estado' => $estado,
            ]];
        });

        return $atividades->map(function (Atividade $atividade) use ($estadoPorPar) {
            $pacoteIdsDaAtividade = $atividade->itensSuprimento->pluck('id');

            if ($pacoteIdsDaAtividade->isEmpty()) {
                return self::montarLinhaSemPacote($atividade);
            }

            $paresDaAtividade = $estadoPorPar->filter(
                fn ($p) => $pacoteIdsDaAtividade->contains($p['item_suprimento_id'])
            )->values();

            if ($paresDaAtividade->isEmpty()) {
                // Atividade tem Pacote(s) vinculado(s), mas nenhum deles
                // tem ainda nenhuma Alocação de material formalizada —
                // informação insuficiente, nunca "coberta".
                return self::montarLinha($atividade, EstadoCoberturaMaterial::InformacaoInsuficiente, collect());
            }

            $estadoAgregado = $paresDaAtividade
                ->map(fn ($p) => $p['estado'])
                ->sortByDesc(fn (EstadoCoberturaMaterial $e) => $e->severidade())
                ->first();

            return self::montarLinha($atividade, $estadoAgregado, $paresDaAtividade);
        })->values();
    }

    /**
     * Árvore de decisão ÚNICA (Seção 8/9 do pedido) — nunca duplicada em
     * nenhum outro ponto do código. Tolerância de 0.0005 pra comparação
     * de ponto flutuante, mesmo padrão já usado em toda a Etapa 20.
     */
    private static function classificar(float $demanda, float $reservadoPacote, float $deficitObraMaterial, array $compra): EstadoCoberturaMaterial
    {
        if ($demanda <= 0.0005) {
            return EstadoCoberturaMaterial::InformacaoInsuficiente;
        }

        if ($reservadoPacote >= $demanda - 0.0005) {
            return $deficitObraMaterial > 0.0005
                ? EstadoCoberturaMaterial::DeficitAposConsumoEmergencial
                : EstadoCoberturaMaterial::Coberto;
        }

        if ($reservadoPacote > 0.0005) {
            return EstadoCoberturaMaterial::ParcialmenteCoberto;
        }

        if ($compra['recebido'] > 0.0005) {
            return EstadoCoberturaMaterial::RecebidoAguardandoDisponibilizacao;
        }

        if ($compra['em_pedido'] > 0.0005) {
            return EstadoCoberturaMaterial::CompradoAguardandoRecebimento;
        }

        if ($compra['em_rc'] > 0.0005 || $compra['alocado'] > 0.0005 || $compra['requisitado'] > 0.0005) {
            return EstadoCoberturaMaterial::AguardandoCompra;
        }

        return EstadoCoberturaMaterial::SemCobertura;
    }

    private static function montarLinhaSemPacote(Atividade $atividade): array
    {
        return self::montarLinha($atividade, EstadoCoberturaMaterial::InformacaoInsuficiente, collect());
    }

    private static function montarLinha(Atividade $atividade, EstadoCoberturaMaterial $estadoAgregado, Collection $pares): array
    {
        return [
            'atividade_id' => $atividade->id,
            'atividade_nome' => $atividade->nome,
            'atividade_codigo' => $atividade->codigo_cronograma,
            'inicio_planejado' => $atividade->inicio_planejado,
            'frente_trabalho_id' => $atividade->frente_trabalho_id,
            'estado_agregado' => $estadoAgregado,
            'pares' => $pares,
        ];
    }
}
