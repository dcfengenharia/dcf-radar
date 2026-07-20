<?php

namespace App\Support;

use App\Models\Feriado;
use App\Models\Work;
use Carbon\Carbon;

/**
 * Calcula datas somando/subtraindo dias ÚTEIS (pula fim de semana e os
 * feriados cadastrados pra obra) — usado pelo agendamento retroativo do
 * Suprimentos (App\Services\SuprimentoScheduler). Os feriados da obra são
 * pré-carregados uma vez por instância pra evitar N+1 ao agendar dezenas
 * de etapas por vários itens.
 */
class DiasUteisCalculator
{
    /** @param array<string, true> $feriados datas no formato Y-m-d */
    private function __construct(private readonly array $feriados)
    {
    }

    public static function paraObra(Work $obra): self
    {
        $feriados = Feriado::where('obra_id', $obra->id)
            ->pluck('data')
            ->mapWithKeys(fn (string $data) => [Carbon::parse($data)->toDateString() => true])
            ->all();

        return new self($feriados);
    }

    public function eDiaUtil(Carbon $data): bool
    {
        return ! $data->isWeekend() && ! isset($this->feriados[$data->toDateString()]);
    }

    public function somar(Carbon $data, int $diasUteis): Carbon
    {
        $resultado = $data->copy();

        for ($restantes = $diasUteis; $restantes > 0;) {
            $resultado->addDay();
            if ($this->eDiaUtil($resultado)) {
                $restantes--;
            }
        }

        return $resultado;
    }

    public function subtrair(Carbon $data, int $diasUteis): Carbon
    {
        $resultado = $data->copy();

        for ($restantes = $diasUteis; $restantes > 0;) {
            $resultado->subDay();
            if ($this->eDiaUtil($resultado)) {
                $restantes--;
            }
        }

        return $resultado;
    }
}
