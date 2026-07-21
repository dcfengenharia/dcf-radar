<?php

namespace App\Actions\ProgramacaoSemanal;

use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\SerieAvanco;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\ProgramacaoSemanal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Monta as linhas de `programacao_semanal_itens` (datas/HH previstos
 * capturados AO VIVO da Atividade no momento da chamada) — compartilhado
 * entre RegistrarComprometimentoSemanal (comprometimento normal) e
 * CriarRevisaoProgramacaoSemanal (revisão recaptura os mesmos itens da
 * versão anterior, com valores atualizados). Só monta o array; quem
 * chama decide como inserir (insertOrIgnore vs insert simples).
 */
class ProgramacaoSemanalSnapshot
{
    public static function linhasParaItens(
        ProgramacaoSemanal $header,
        string $semanaInicio,
        Collection $atividades,
        OrigemProgramacaoSemanalItem $origem
    ): array {
        $idsAtividades = $atividades->pluck('id');

        $horasPorAtividade = AvancoPeriodo::where('serie', SerieAvanco::Previsto->value)
            ->where('granularidade', GranularidadePeriodo::Semanal->value)
            ->where('periodo_inicio', $semanaInicio)
            ->whereIn('atividade_id', $idsAtividades)
            ->pluck('horas', 'atividade_id');

        $agora = now();

        return $atividades->map(fn (Atividade $at) => [
            'id' => (string) Str::ulid(),
            'tenant_id' => $header->tenant_id,
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
    }
}
