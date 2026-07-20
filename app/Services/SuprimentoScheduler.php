<?php

namespace App\Services;

use App\Enums\SerieAvanco;
use App\Enums\StatusItemSuprimento;
use App\Models\ItemSuprimento;
use App\Models\ItemSuprimentoEtapa;
use App\Models\ItemSuprimentoEtapaData;
use App\Notifications\AlertaPrazoSuprimentoNotification;
use App\Support\DiasUteisCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Motor de agendamento do Mapa de Suprimentos: papel equivalente ao
 * App\Services\CurvaAvanco, mas pro lado do previsto/tendência de cada
 * etapa de um item de suprimento.
 *
 * Filosofia (igual App\Actions\ProgramacaoSemanal\RegistrarComprometimentoSemanal
 * e CLAUDE.md): Previsto é congelado uma vez (insert imutável) no momento
 * em que o item é criado; Tendência é sempre recalculada e sobrescrita a
 * partir do estado atual (Realizado das etapas já concluídas + prazos das
 * pendentes, ou do encadeamento retroativo se nada foi realizado ainda).
 */
class SuprimentoScheduler
{
    /**
     * Copia as etapas do fluxo escolhido pro item (snapshot imutável) —
     * roda uma vez, na criação do item. Editar o FluxoSuprimento depois
     * não deve alterar retroativamente um item já em andamento.
     */
    public function criarEtapasDoItem(ItemSuprimento $item): void
    {
        $etapasFluxo = $item->fluxo?->etapas ?? collect();

        foreach ($etapasFluxo as $etapaFluxo) {
            $item->etapas()->create([
                'tenant_id' => $item->tenant_id,
                'etapa_fluxo_suprimento_id' => $etapaFluxo->id,
                'ordem' => $etapaFluxo->ordem,
                'nome' => $etapaFluxo->nome,
                'prazo_dias_uteis' => $etapaFluxo->prazo_dias_uteis,
            ]);
        }
    }

    /**
     * Congela o Previsto: encadeamento retroativo a partir da necessidade
     * (a atividade vinculada mais cedo). Grava via insertOrIgnore — nunca
     * mais atualizado depois (mesma filosofia de RegistrarComprometimentoSemanal).
     */
    public function congelarPrevisto(ItemSuprimento $item): void
    {
        $necessidade = $item->necessidade();
        if (! $necessidade) {
            return;
        }

        $etapas = $item->etapas()->orderBy('ordem')->get()->values();
        if ($etapas->isEmpty()) {
            return;
        }

        $calc = DiasUteisCalculator::paraObra($item->obra);
        $datas = $this->encadearRetroativo($etapas, $necessidade, $calc);

        $agora = now();
        $linhas = $etapas->map(fn (ItemSuprimentoEtapa $etapa, int $indice) => [
            'id' => (string) Str::ulid(),
            'tenant_id' => $item->tenant_id,
            'item_suprimento_etapa_id' => $etapa->id,
            'serie' => SerieAvanco::Previsto->value,
            'data' => $datas[$indice]->toDateString(),
            'atualizado_por' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ])->all();

        DB::table('itens_suprimento_etapa_datas')->insertOrIgnore($linhas);
    }

    /**
     * Recalcula a Tendência (sempre sobrescrita): etapas com Realizado
     * preenchido não se movem mais; as pendentes encadeiam pra frente a
     * partir da última realizada. Se nada foi realizado ainda, cai pro
     * mesmo encadeamento retroativo do Previsto, mas contra a necessidade
     * AO VIVO (reflete replanejamentos, ao contrário do Previsto congelado).
     */
    public function recalcularTendencia(ItemSuprimento $item): void
    {
        $etapas = $item->etapas()->with('datas')->orderBy('ordem')->get()->values();
        if ($etapas->isEmpty()) {
            return;
        }

        $calc = DiasUteisCalculator::paraObra($item->obra);

        $ultimoIndiceRealizado = null;
        foreach ($etapas as $indice => $etapa) {
            if ($this->dataDaSerie($etapa, SerieAvanco::Realizado)) {
                $ultimoIndiceRealizado = $indice;
            }
        }

        if ($ultimoIndiceRealizado !== null) {
            $datas = [];
            for ($i = 0; $i <= $ultimoIndiceRealizado; $i++) {
                $datas[$i] = $this->dataDaSerie($etapas[$i], SerieAvanco::Realizado);
            }
            for ($i = $ultimoIndiceRealizado + 1; $i < $etapas->count(); $i++) {
                $etapaAtual = $etapas[$i];
                $datas[$i] = $etapaAtual->nao_aplicavel
                    ? $datas[$i - 1]->copy()
                    : $calc->somar($datas[$i - 1], $etapaAtual->prazo_dias_uteis);
            }
        } else {
            $necessidade = $item->necessidade();
            if (! $necessidade) {
                return;
            }
            $datas = $this->encadearRetroativo($etapas, $necessidade, $calc);
        }

        foreach ($etapas as $indice => $etapa) {
            ItemSuprimentoEtapaData::updateOrCreate(
                ['item_suprimento_etapa_id' => $etapa->id, 'serie' => SerieAvanco::Tendencia->value],
                ['tenant_id' => $item->tenant_id, 'data' => $datas[$indice]->toDateString()]
            );
        }
    }

    /**
     * Calcula o status do item (comparando a última etapa com a
     * necessidade atual) e já persiste em itens_suprimento.status, pra
     * manter filtro/ordenação na listagem baratos.
     */
    public function statusDoItem(ItemSuprimento $item): StatusItemSuprimento
    {
        $status = $this->calcularStatus($item);
        $item->update(['status' => $status->value]);

        return $status;
    }

    /**
     * Alerta contratual: dispara quando o item cruza os marcos de 21 ou 10
     * dias corridos antes da necessidade — cada marco só é enviado uma vez
     * por item (colunas alerta_21d_enviado_em/alerta_10d_enviado_em),
     * mesmo rodando este método todo dia. Chamado depois de statusDoItem()
     * já ter persistido o status atual, então recarrega o item pra não
     * confiar numa instância potencialmente desatualizada do chamador.
     */
    public function verificarMarcoDeAlerta(ItemSuprimento $item): void
    {
        $item = $item->fresh(['atividades', 'obra', 'responsavel', 'autor']);

        if ($item->status === StatusItemSuprimento::Concluido) {
            return;
        }

        $necessidade = $item->necessidade();
        if (! $necessidade) {
            return;
        }

        $destinatario = $item->responsavel ?? $item->autor;
        if (! $destinatario) {
            return;
        }

        $diasRestantes = Carbon::today()->diffInDays($necessidade, false);

        if ($diasRestantes <= 21 && ! $item->alerta_21d_enviado_em) {
            $destinatario->notify(new AlertaPrazoSuprimentoNotification($item, 21));
            $item->update(['alerta_21d_enviado_em' => now()]);
        }

        if ($diasRestantes <= 10 && ! $item->alerta_10d_enviado_em) {
            $destinatario->notify(new AlertaPrazoSuprimentoNotification($item, 10));
            $item->update(['alerta_10d_enviado_em' => now()]);
        }
    }

    private function calcularStatus(ItemSuprimento $item): StatusItemSuprimento
    {
        $necessidade = $item->necessidade();
        if (! $necessidade) {
            return StatusItemSuprimento::NoInicio;
        }

        $etapas = $item->etapas()->with('datas')->orderBy('ordem')->get()->values();
        if ($etapas->isEmpty()) {
            return StatusItemSuprimento::NoInicio;
        }

        $ultimaEtapa = $etapas->last();
        if ($this->dataDaSerie($ultimaEtapa, SerieAvanco::Realizado)) {
            return StatusItemSuprimento::Concluido;
        }

        $algumaRealizada = $etapas->contains(fn (ItemSuprimentoEtapa $e) => $this->dataDaSerie($e, SerieAvanco::Realizado) !== null);
        $dataFinal = $this->dataDaSerie($ultimaEtapa, SerieAvanco::Tendencia);

        // As checagens abaixo contra $dataFinal (Atrasado / folga curta) só
        // fazem sentido quando $dataFinal é uma PROJEÇÃO viva baseada em
        // progresso real (recalcularTendencia encadeou pra frente a partir
        // de um Realizado). Sem nenhum Realizado, recalcularTendencia cai no
        // encadeamento retroativo puro, que por construção sempre fixa a
        // última etapa exatamente igual à necessidade — comparar essa data
        // contra a própria necessidade seria tautológico e sempre acusaria
        // risco/atraso mesmo pra um item que nem começou.
        if ($algumaRealizada && $dataFinal && $dataFinal->gt($necessidade)) {
            return StatusItemSuprimento::Atrasado;
        }

        $hoje = Carbon::today();
        foreach ($etapas as $etapa) {
            if ($etapa->nao_aplicavel || $this->dataDaSerie($etapa, SerieAvanco::Realizado)) {
                continue;
            }

            $tendenciaEtapa = $this->dataDaSerie($etapa, SerieAvanco::Tendencia);
            if ($tendenciaEtapa && $tendenciaEtapa->lt($hoje)) {
                return StatusItemSuprimento::EmRisco;
            }
        }

        if ($algumaRealizada && $dataFinal) {
            $calc = DiasUteisCalculator::paraObra($item->obra);
            $limiteRisco = $calc->subtrair($necessidade, 5);
            if ($dataFinal->gt($limiteRisco)) {
                return StatusItemSuprimento::EmRisco;
            }
        }

        return $algumaRealizada ? StatusItemSuprimento::EmAndamento : StatusItemSuprimento::NoInicio;
    }

    /**
     * Encadeamento retroativo: data(etapa_N) = necessidade; pra cada
     * etapa anterior, data(etapa_i) = subtrair(data(etapa_i+1),
     * prazo_dias_uteis(etapa_i)). Etapas marcadas "não aplicável" herdam
     * a data da etapa seguinte (são puladas na cadeia).
     *
     * @return array<int, Carbon> indexado igual a $etapas
     */
    private function encadearRetroativo(Collection $etapas, Carbon $necessidade, DiasUteisCalculator $calc): array
    {
        $n = $etapas->count();
        $datas = array_fill(0, $n, null);
        $datas[$n - 1] = $necessidade->copy();

        for ($i = $n - 2; $i >= 0; $i--) {
            $etapaAtual = $etapas[$i];
            $datas[$i] = $etapaAtual->nao_aplicavel
                ? $datas[$i + 1]->copy()
                : $calc->subtrair($datas[$i + 1], $etapaAtual->prazo_dias_uteis);
        }

        return $datas;
    }

    private function dataDaSerie(ItemSuprimentoEtapa $etapa, SerieAvanco $serie): ?Carbon
    {
        $registro = $etapa->datas->firstWhere('serie', $serie);

        return $registro?->data ? Carbon::parse($registro->data) : null;
    }
}
