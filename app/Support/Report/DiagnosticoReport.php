<?php

namespace App\Support\Report;

use App\Enums\GranularidadePeriodo;
use App\Models\Atividade;
use App\Models\AtividadeSnapshot;
use App\Models\CausaNaoCumprimento;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportDesvio;
use App\Support\ReportCurvaSerializer;
use Illuminate\Support\Carbon;

/**
 * Extraído de ⚡relatorio-detalhe.blade.php (fonte original dos 8
 * diagnósticos da Fase 5 + dadosGraficos()) para ser a ÚNICA fonte de
 * cálculo, reaproveitada tanto pelo detalhe quanto pelo assistente de
 * criação de Report — sem duplicar lógica entre os dois.
 *
 * Cópia FIEL dos métodos #[Computed] originais: nenhum threshold, filtro,
 * data, ordenação, label ou estrutura de retorno foi alterado — só a
 * fonte do Report (parâmetro em vez de propriedade Livewire) e o
 * compartilhamento de dadosGraficos()/impactoRestricoes() (variáveis
 * locais calculadas uma vez em calcular(), em vez do cache automático de
 * #[Computed] do Livewire).
 *
 * Sem NENHUMA dependência de Livewire/Blade/UI — recebe um Report,
 * devolve arrays simples. calcular() garante suas próprias relações via
 * loadMissing() (lista própria, não reaproveitada de
 * relacoesReportCompletas() do componente) — nunca assume que o chamador
 * já fez eager loading.
 */
class DiagnosticoReport
{
    public function calcular(Report $report): array
    {
        $report->loadMissing([
            'cronogramaImportacao.healthCheck',
            'curvas.datapoints',
            'curvas.desvios.pacoteTrabalho',
            'curvas.desvios.restricaoImpacto',
            'curvas.pacoteTrabalho',
        ]);

        // dadosGraficos e impactoRestricoes primeiro — aderenciaPlanejamento
        // depende do primeiro, topRiscos/decisoesPrioritarias do segundo.
        // Calculados uma única vez aqui (variável local) porque, fora do
        // Livewire, não há cache automático de #[Computed] entre chamadas.
        $dadosGraficos = $this->dadosGraficos($report);
        $impactoRestricoes = $this->impactoRestricoes($report);

        return [
            'confiabilidadeCronograma' => $this->confiabilidadeCronograma($report),
            'principaisDesvios' => $this->principaisDesvios($report),
            'causasDoDesvio' => $this->causasDoDesvio($report),
            'hhExpostaPorAtraso' => $this->hhExpostaPorAtraso($report),
            'impactoRestricoes' => $impactoRestricoes,
            'topRiscos' => $this->topRiscos($report, $impactoRestricoes),
            'proximosEventosRelevantes' => $this->proximosEventosRelevantes($report),
            'aderenciaPlanejamento' => $this->aderenciaPlanejamento($report, $dadosGraficos),
            'decisoesPrioritarias' => $this->decisoesPrioritarias($report, $impactoRestricoes),
            'dadosGraficos' => $dadosGraficos,
        ];
    }

    private function confiabilidadeCronograma(Report $report): array
    {
        $healthCheck = $report->cronogramaImportacao->healthCheck;

        if (! $healthCheck) {
            return ['estado' => 'indisponivel'];
        }

        $scoreResultado = $healthCheck->scoreResultado();

        if (! $scoreResultado) {
            return [
                'estado' => 'sem_score',
                'total_ocorrencias' => $healthCheck->total_ocorrencias,
                'total_criticos' => $healthCheck->total_criticos,
                'total_altos' => $healthCheck->total_altos,
            ];
        }

        return [
            'estado' => 'ok',
            'score' => $scoreResultado->score,
            'faixa_label' => $scoreResultado->faixa->label(),
            'faixa_cor' => $scoreResultado->faixa->cor(),
            'total_ocorrencias' => $healthCheck->total_ocorrencias,
            'total_criticos' => $healthCheck->total_criticos,
            'total_altos' => $healthCheck->total_altos,
        ];
    }

    private function principaisDesvios(Report $report): array
    {
        return $report->curvas
            ->flatMap(fn (ReportCurva $c) => $c->desvios)
            ->filter(fn (ReportDesvio $d) => ! $d->eh_nivel_pai && (float) $d->percentual_impacto < 0)
            ->sortBy(fn (ReportDesvio $d) => [
                (float) $d->percentual_impacto,
                (float) $d->percentual_desvio,
                $d->id,
            ])
            ->values()
            ->take(3)
            ->map(fn (ReportDesvio $d) => [
                'id' => $d->id,
                'titulo_exibicao' => $d->titulo_exibicao,
                'percentual_previsto' => (float) $d->percentual_previsto,
                'percentual_real' => (float) $d->percentual_real,
                'percentual_desvio' => (float) $d->percentual_desvio,
                'percentual_impacto' => (float) $d->percentual_impacto,
            ])
            ->all();
    }

    private function causasDoDesvio(Report $report): array
    {
        $dataReferencia = $report->data_status ?? $report->periodo_referencia;

        $pacoteIdsPorDesvio = [];
        $todosPacoteIds = [];

        foreach ($report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $pacote = $desvio->eh_nivel_pai ? $curva->pacoteTrabalho : $desvio->pacoteTrabalho;

                $pacoteIds = $pacote
                    ? [$pacote->id, ...$pacote->descendantIds()]
                    : PacoteTrabalho::where('obra_id', $report->obra_id)->pluck('id')->all();

                $pacoteIdsPorDesvio[$desvio->id] = $pacoteIds;
                $todosPacoteIds = [...$todosPacoteIds, ...$pacoteIds];
            }
        }

        $todosPacoteIds = array_values(array_unique($todosPacoteIds));

        $atividades = Atividade::where('obra_id', $report->obra_id)
            ->whereIn('pacote_trabalho_id', $todosPacoteIds)
            ->get(['id', 'pacote_trabalho_id', 'nome', 'codigo_cronograma']);

        $causasPorAtividade = CausaNaoCumprimento::whereIn('atividade_id', $atividades->pluck('id'))
            ->whereDate('created_at', '<=', $dataReferencia)
            ->orderByDesc('created_at')
            ->get(['id', 'atividade_id', 'descricao', 'created_at'])
            ->groupBy('atividade_id');

        $linhas = [];

        foreach ($pacoteIdsPorDesvio as $desvioId => $pacoteIds) {
            $atividadesDoEscopo = $atividades->whereIn('pacote_trabalho_id', $pacoteIds);
            $comCausa = $atividadesDoEscopo->filter(fn (Atividade $a) => $causasPorAtividade->has($a->id));

            $linhas[$desvioId] = [
                'total_atividades' => $atividadesDoEscopo->count(),
                'atividades_com_causa' => $comCausa->count(),
                'atividades_sem_causa' => $atividadesDoEscopo->count() - $comCausa->count(),
                'causas' => $comCausa
                    ->flatMap(fn (Atividade $a) => $causasPorAtividade->get($a->id)->map(fn (CausaNaoCumprimento $c) => [
                        'atividade_nome' => $a->nome,
                        'atividade_codigo' => $a->codigo_cronograma,
                        'descricao' => $c->descricao,
                        'registrada_em' => $c->created_at,
                    ]))
                    ->sortByDesc('registrada_em')
                    ->values()
                    ->all(),
            ];
        }

        return $linhas;
    }

    private function hhExpostaPorAtraso(Report $report): array
    {
        $resultado = [];

        foreach ($report->curvas as $curva) {
            if ($curva->total_atividades <= 0) {
                $resultado[$curva->id] = ['estado' => 'sem_dado'];

                continue;
            }

            $percentualAtrasadas = round($curva->atividades_atrasadas / $curva->total_atividades * 100, 1);
            $hhExpostaEstimada = round((float) $curva->total_hh_previsto * $curva->atividades_atrasadas / $curva->total_atividades, 2);

            $resultado[$curva->id] = [
                'estado' => 'ok',
                'total_atividades' => $curva->total_atividades,
                'atividades_atrasadas' => $curva->atividades_atrasadas,
                'percentual_atividades_atrasadas' => $percentualAtrasadas,
                'hh_exposta_estimada' => $hhExpostaEstimada,
                'total_hh_previsto' => (float) $curva->total_hh_previsto,
            ];
        }

        return $resultado;
    }

    private function impactoRestricoes(Report $report): array
    {
        $linhas = [];

        foreach ($report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $snapshot = $desvio->restricaoImpacto;

                $linhas[$desvio->id] = $snapshot ? [
                    'total_abertas' => $snapshot->total_abertas,
                    'total_vencidas' => $snapshot->total_vencidas,
                    'total_criticas' => $snapshot->total_criticas,
                    'detalhes' => $snapshot->detalhes ?? [],
                ] : null;
            }
        }

        return $linhas;
    }

    private function topRiscos(Report $report, array $impactoRestricoes): array
    {
        $riscos = [];

        foreach ($report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $snapshot = $impactoRestricoes[$desvio->id] ?? null;

                if (! $snapshot) {
                    continue;
                }

                foreach ($snapshot['detalhes'] as $item) {
                    $riscos[] = [
                        ...$item,
                        'pacote_titulo' => $desvio->titulo_exibicao,
                    ];
                }
            }
        }

        return collect($riscos)
            ->sortBy(fn (array $r) => [
                $r['vencida'] ? 0 : 1,
                $r['classificacao_risco'] === 'alto' ? 0 : 1,
                $r['prazo_limite'] ?? '9999-12-31',
            ])
            ->values()
            ->take(3)
            ->all();
    }

    private function proximosEventosRelevantes(Report $report): array
    {
        $inicioJanela = $report->periodo_referencia->copy()->addWeek();
        $fimJanela = $inicioJanela->copy()->endOfWeek();

        $snapshots = AtividadeSnapshot::where('cronograma_importacao_id', $report->cronograma_importacao_id)
            ->where(function ($query) use ($inicioJanela, $fimJanela) {
                $query->whereBetween('inicio_planejado', [$inicioJanela->toDateString(), $fimJanela->toDateString()])
                    ->orWhereBetween('data_termino', [$inicioJanela->toDateString(), $fimJanela->toDateString()]);
            })
            ->get(['atividade_id', 'inicio_planejado', 'data_termino']);

        if ($snapshots->isEmpty()) {
            return [];
        }

        $atividades = Atividade::whereIn('id', $snapshots->pluck('atividade_id'))
            ->where('fora_do_cronograma', false)
            ->get(['id', 'nome', 'codigo_cronograma', 'is_marco', 'caminho_critico'])
            ->keyBy('id');

        $eventos = [];

        foreach ($snapshots as $snapshot) {
            $atividade = $atividades->get($snapshot->atividade_id);

            if (! $atividade) {
                continue;
            }

            $titulo = $atividade->codigo_cronograma
                ? "{$atividade->codigo_cronograma} - {$atividade->nome}"
                : $atividade->nome;

            if ($atividade->is_marco) {
                $dataMarco = $snapshot->data_termino ?? $snapshot->inicio_planejado;

                if ($dataMarco && $dataMarco->between($inicioJanela, $fimJanela)) {
                    $eventos[] = [
                        'atividade_id' => $atividade->id,
                        'titulo' => $titulo,
                        'tipo' => 'marco',
                        'data' => $dataMarco->toDateString(),
                        'caminho_critico' => $atividade->caminho_critico,
                    ];
                }

                continue;
            }

            if ($snapshot->inicio_planejado && $snapshot->inicio_planejado->between($inicioJanela, $fimJanela)) {
                $eventos[] = [
                    'atividade_id' => $atividade->id,
                    'titulo' => $titulo,
                    'tipo' => 'inicio',
                    'data' => $snapshot->inicio_planejado->toDateString(),
                    'caminho_critico' => $atividade->caminho_critico,
                ];
            }

            if ($snapshot->data_termino && $snapshot->data_termino->between($inicioJanela, $fimJanela)) {
                $eventos[] = [
                    'atividade_id' => $atividade->id,
                    'titulo' => $titulo,
                    'tipo' => 'termino',
                    'data' => $snapshot->data_termino->toDateString(),
                    'caminho_critico' => $atividade->caminho_critico,
                ];
            }
        }

        return collect($eventos)
            ->sortBy(fn (array $e) => [$e['data'], $e['atividade_id']])
            ->values()
            ->take(5)
            ->all();
    }

    private function aderenciaPlanejamento(Report $report, array $dadosGraficos): array
    {
        $curvas = $report->curvas;

        if ($curvas->isEmpty()) {
            return ['tem_dado' => false];
        }

        $aderenciaPorCurvaId = collect($dadosGraficos)->keyBy('id');

        $curvaReferencia = $curvas->firstWhere('pacote_trabalho_id', null);

        if (! $curvaReferencia) {
            $curvaReferencia = $curvas
                ->filter(fn (ReportCurva $c) => $aderenciaPorCurvaId[$c->id]['aderencia_atual'] !== null)
                ->sortBy(fn (ReportCurva $c) => $aderenciaPorCurvaId[$c->id]['aderencia_atual'])
                ->first();
        }

        if (! $curvaReferencia) {
            return ['tem_dado' => false];
        }

        $tabelaSemanal = ReportCurvaSerializer::serieParaGrafico($curvaReferencia, GranularidadePeriodo::Semanal)['tabela'];

        $semanasElegiveis = collect($tabelaSemanal)
            ->filter(fn (array $linha) => $linha['aderencia_periodo'] !== null)
            ->values();

        if ($semanasElegiveis->count() < 3) {
            return ['tem_dado' => false];
        }

        $semanasExibidas = $semanasElegiveis->slice(-4)->values();

        $media = $semanasExibidas->avg('aderencia_periodo');

        $faixa = match (true) {
            $media >= 90 => ['emoji' => '🟢', 'label' => 'Boa'],
            $media >= 75 => ['emoji' => '🟠', 'label' => 'Atenção'],
            default => ['emoji' => '🔴', 'label' => 'Crítica'],
        };

        return [
            'tem_dado' => true,
            'media' => $media,
            'faixa_emoji' => $faixa['emoji'],
            'faixa_label' => $faixa['label'],
            'semanas' => $semanasExibidas->map(fn (array $l) => [
                'label' => $l['label'],
                'aderencia' => $l['aderencia_periodo'],
            ])->all(),
        ];
    }

    private function decisoesPrioritarias(Report $report, array $impactoRestricoes): array
    {
        $inicioProximaSemana = $report->periodo_referencia->copy()->addWeek();
        $fimProximaSemana = $inicioProximaSemana->copy()->endOfWeek();

        $situacoes = [];

        foreach ($report->curvas as $curva) {
            foreach ($curva->desvios as $desvio) {
                $snapshot = $impactoRestricoes[$desvio->id] ?? null;

                if (! $snapshot) {
                    continue;
                }

                foreach ($snapshot['detalhes'] as $item) {
                    $dentroDaProximaSemana = $item['prazo_limite']
                        && Carbon::parse($item['prazo_limite'])->between($inicioProximaSemana, $fimProximaSemana);

                    $elegivel = $item['vencida']
                        || $item['bloqueante']
                        || $item['classificacao_risco'] === 'alto'
                        || $dentroDaProximaSemana;

                    if (! $elegivel) {
                        continue;
                    }

                    $situacoes[] = [
                        ...$item,
                        'pacote_titulo' => $desvio->titulo_exibicao,
                    ];
                }
            }
        }

        return collect($situacoes)
            ->sortBy(fn (array $s) => [
                $s['vencida'] ? 0 : 1,
                $s['bloqueante'] ? 0 : 1,
                $s['classificacao_risco'] === 'alto' ? 0 : 1,
                $s['prazo_limite'] ?? '9999-12-31',
            ])
            ->values()
            ->take(3)
            ->all();
    }

    private function dadosGraficos(Report $report): array
    {
        return $report->curvas->map(function (ReportCurva $curva) {
            $mensal = ReportCurvaSerializer::serieParaGrafico($curva, GranularidadePeriodo::Mensal);
            $semanal = ReportCurvaSerializer::serieParaGrafico($curva, GranularidadePeriodo::Semanal);

            return [
                'id' => $curva->id,
                'mensal' => $mensal,
                'semanal' => $semanal,
                'aderencia_atual' => $this->aderenciaDaUltimaSemanaAtualizada($semanal['tabela']),
            ];
        })->all();
    }

    private function aderenciaDaUltimaSemanaAtualizada(array $tabelaSemanal): ?float
    {
        for ($i = count($tabelaSemanal) - 1; $i >= 0; $i--) {
            if ($tabelaSemanal[$i]['aderencia_periodo'] !== null) {
                return $tabelaSemanal[$i]['aderencia_periodo'];
            }
        }

        return null;
    }
}
