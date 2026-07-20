<?php

namespace App\Actions\ProgramacaoSemanal;

use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\ProgramacaoSemanal;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Congela (insert imutável, nunca update) o lado PREVISTO do que foi
 * comprometido pra uma semana — datas planejadas + HH previsto daquela
 * semana, no momento do commit. O lado REALIZADO nunca é gravado aqui:
 * continua sendo lido ao vivo (status atual da Atividade, AvancoPeriodo
 * realizado) sempre que alguém for comparar previsto x realizado depois,
 * já que só o "o que foi prometido" precisa parar de mudar com o tempo —
 * mesma filosofia de AtividadeSnapshot/LinhaBase (CLAUDE.md).
 *
 * Idempotente: comprometer a mesma atividade duas vezes na mesma semana
 * nunca duplica (insertOrIgnore + unique constraint).
 */
class RegistrarComprometimentoSemanal
{
    public function execute(
        Work $obra,
        string $semanaInicio,
        Collection $atividades,
        OrigemProgramacaoSemanalItem $origem
    ): ProgramacaoSemanal {
        $semanaFim = Carbon::parse($semanaInicio)->endOfWeek()->toDateString();

        $header = ProgramacaoSemanal::firstOrCreate(
            ['obra_id' => $obra->id, 'semana_inicio' => $semanaInicio],
            ['semana_fim' => $semanaFim, 'congelada_em' => now(), 'criado_por' => Auth::id()]
        );

        if (! $header->wasRecentlyCreated) {
            $header->touch();
        }

        if ($atividades->isEmpty()) {
            return $header;
        }

        $idsAtividades = $atividades->pluck('id');

        $horasPorAtividade = AvancoPeriodo::where('serie', SerieAvanco::Previsto->value)
            ->where('granularidade', GranularidadePeriodo::Semanal->value)
            ->where('periodo_inicio', $semanaInicio)
            ->whereIn('atividade_id', $idsAtividades)
            ->pluck('horas', 'atividade_id');

        $agora = now();
        $linhas = $atividades->map(fn (Atividade $at) => [
            'id' => (string) Str::ulid(),
            'tenant_id' => $obra->tenant_id,
            'programacao_semanal_id' => $header->id,
            'atividade_id' => $at->id,
            'inicio_planejado_congelado' => $at->inicio_planejado?->toDateString(),
            'data_termino_congelado' => $at->data_termino?->toDateString(),
            'horas_previstas_congeladas' => $horasPorAtividade->get($at->id),
            'origem' => $origem->value,
            'criado_por' => Auth::id(),
            'created_at' => $agora,
            'updated_at' => $agora,
        ])->all();

        DB::table('programacao_semanal_itens')->insertOrIgnore($linhas);

        return $header;
    }
}
