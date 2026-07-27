<?php

namespace App\Services;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Models\AtividadeSnapshot;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\CurvaAjuste;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Work;

class CurvaAvanco
{
    /**
     * Monta a curva S de um escopo (obra ou pacote), por série e granularidade.
     *
     * Quando $linhaBaseId for fornecido E $serie for Previsto, usa o
     * cronograma_importacao_id da linha de base em vez da importação mais recente.
     *
     * Quando $pacoteId for fornecido, o escopo inclui o próprio pacote E
     * todos os seus descendentes na EAP (ver PacoteTrabalho::descendantIds()) —
     * necessário porque uma curva pode ser gerada em qualquer nível da árvore
     * (ex: "CIVIL" agregando vários pacotes-filho), não só em folhas.
     *
     * $avancoImportacaoId fixa qual importação de Realizado/Tendência usar
     * (mesmo espírito de $linhaBaseId, mas pro lado do avanço) — só se aplica
     * quando $serie não for Previsto.
     *
     * $atividadeId escopa a curva a UMA ÚNICA atividade (filtro direto por
     * coluna, sem whereHas) — usado pela Curva S por atividade do popup de
     * detalhe do Lookahead. Independente dos demais filtros de escopo.
     */
    public function calcular(
        Work $obra,
        SerieAvanco $serie,
        GranularidadePeriodo $gran,
        ?string $pacoteId = null,
        ?string $linhaBaseId = null,
        ?string $avancoImportacaoId = null,
        ?string $etapaId = null,
        ?string $disciplinaId = null,
        ?string $frenteId = null,
        ?string $entregavelId = null,
        ?string $equipeResponsavelId = null,
        ?string $personalizado1Id = null,
        ?string $personalizado2Id = null,
        ?string $personalizado3Id = null,
        ?string $personalizado4Id = null,
        ?string $personalizado5Id = null,
        ?bool $faturamentoDireto = null,
        ?string $atividadeId = null,
    ): array {
        $importacaoId = $this->resolverImportacaoId($obra, $serie, $linhaBaseId, $avancoImportacaoId);

        if (!$importacaoId) {
            return [];
        }

        $query = AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
            ->where('serie', $serie->value)
            ->where('granularidade', $gran->value);

        if ($atividadeId) {
            $query->where('atividade_id', $atividadeId);
        }

        $temFiltroAtividade = $pacoteId || $etapaId || $disciplinaId || $frenteId
            || $entregavelId || $equipeResponsavelId || $personalizado1Id || $personalizado2Id
            || $personalizado3Id || $personalizado4Id || $personalizado5Id || $faturamentoDireto !== null;
        if ($temFiltroAtividade) {
            $pacoteIds = $pacoteId ? $this->resolverEscopoPacoteIds($pacoteId) : null;
            $query->whereHas('atividade', function ($q) use (
                $pacoteIds, $etapaId, $disciplinaId, $frenteId,
                $entregavelId, $equipeResponsavelId,
                $personalizado1Id, $personalizado2Id, $personalizado3Id, $personalizado4Id, $personalizado5Id,
                $faturamentoDireto
            ) {
                if ($pacoteIds) {
                    $q->whereIn('pacote_trabalho_id', $pacoteIds);
                }
                if ($etapaId) {
                    $q->where('etapa_id', $etapaId);
                }
                if ($disciplinaId) {
                    $q->where('disciplina_id', $disciplinaId);
                }
                if ($frenteId) {
                    $q->where('frente_trabalho_id', $frenteId);
                }
                if ($entregavelId) {
                    $q->where('entregavel_id', $entregavelId);
                }
                if ($equipeResponsavelId) {
                    $q->where('equipe_responsavel_id', $equipeResponsavelId);
                }
                if ($personalizado1Id) {
                    $q->where('personalizado_1_id', $personalizado1Id);
                }
                if ($personalizado2Id) {
                    $q->where('personalizado_2_id', $personalizado2Id);
                }
                if ($personalizado3Id) {
                    $q->where('personalizado_3_id', $personalizado3Id);
                }
                if ($personalizado4Id) {
                    $q->where('personalizado_4_id', $personalizado4Id);
                }
                if ($personalizado5Id) {
                    $q->where('personalizado_5_id', $personalizado5Id);
                }
                if ($faturamentoDireto !== null) {
                    $q->where('faturamento_direto', $faturamentoDireto);
                }
            });
        }

        $horasPorPeriodo = $query
            ->selectRaw('periodo_inicio, SUM(horas) as horas')
            ->groupBy('periodo_inicio')
            ->orderBy('periodo_inicio')
            ->pluck('horas', 'periodo_inicio')
            ->all();

        if (empty($horasPorPeriodo)) {
            return [];
        }

        $totalCalculado = array_sum($horasPorPeriodo);

        // Ajustes são escopados pelos MESMOS filtros de atividade usados
        // acima pra calcular a curva — um ajuste feito na curva geral
        // (todos os 4 filtros null) nunca deve "vazar" pra uma curva
        // filtrada (ex: por Disciplina) e vice-versa, senão o valor
        // ajustado é aplicado sobre um total calculado diferente e o
        // acumulado em % pode passar de 100%.
        $ajustes = CurvaAjuste::where('obra_id', $obra->id)
            ->where('serie', $serie->value)
            ->where('granularidade', $gran->value)
            ->where('pacote_trabalho_id', $pacoteId)
            ->where('etapa_id', $etapaId)
            ->where('disciplina_id', $disciplinaId)
            ->where('frente_trabalho_id', $frenteId)
            ->where('entregavel_id', $entregavelId)
            ->where('equipe_responsavel_id', $equipeResponsavelId)
            ->where('personalizado_1_id', $personalizado1Id)
            ->where('personalizado_2_id', $personalizado2Id)
            ->where('personalizado_3_id', $personalizado3Id)
            ->where('personalizado_4_id', $personalizado4Id)
            ->where('personalizado_5_id', $personalizado5Id)
            ->where('faturamento_direto', $faturamentoDireto)
            ->with('autor:id,first_name,last_name')
            ->get()
            ->keyBy(fn($a) => $a->periodo_inicio->toDateString());

        $acumuladoCalculado = 0.0;
        $acumuladoExibido   = 0.0;
        $resultado = [];

        foreach ($horasPorPeriodo as $periodo => $horas) {
            $horas       = (float) $horas;
            $ajuste      = $ajustes[$periodo] ?? null;
            $horasExibir = $ajuste !== null ? (float) $ajuste->valor_ajustado : $horas;

            $acumuladoCalculado += $horas;
            $acumuladoExibido   += $horasExibir;

            $resultado[] = [
                'periodo_inicio'      => $periodo,
                'horas'               => round($horas, 2),
                'acumulado_calculado' => round($acumuladoCalculado, 2),
                'horas_exibir'        => round($horasExibir, 2),
                'acumulado'           => round($acumuladoExibido, 2),
                'percentual_periodo'  => $totalCalculado > 0
                    ? round($horasExibir / $totalCalculado * 100, 2)
                    : 0.0,
                'percentual'          => $totalCalculado > 0
                    ? round($acumuladoExibido / $totalCalculado * 100, 2)
                    : 0.0,
                'ajustado'            => $ajuste !== null,
                'valor_original'      => $ajuste ? (float) $ajuste->valor_calculado_no_ajuste : null,
                'ajustado_por'        => $ajuste?->autor
                    ? trim($ajuste->autor->first_name . ' ' . $ajuste->autor->last_name)
                    : null,
                'ajuste_id'           => $ajuste?->id,
                'obsoleto'            => $ajuste !== null && $ajuste->obsoletoFrente($horas),
            ];
        }

        return $resultado;
    }

    /**
     * Lista as atividades (com HH individual) que compõem um período
     * específico da curva — mesmo escopo (pacote/etapa/disciplina/frente) e
     * mesma importação que calcular() resolveria pros mesmos parâmetros.
     * Usado pelo popup de detalhe ao clicar numa barra do gráfico.
     */
    public function detalhePeriodo(
        Work $obra,
        SerieAvanco $serie,
        GranularidadePeriodo $gran,
        string $periodoInicio,
        ?string $pacoteId = null,
        ?string $linhaBaseId = null,
        ?string $avancoImportacaoId = null,
        ?string $etapaId = null,
        ?string $disciplinaId = null,
        ?string $frenteId = null,
        ?string $entregavelId = null,
        ?string $equipeResponsavelId = null,
        ?string $personalizado1Id = null,
        ?string $personalizado2Id = null,
        ?string $personalizado3Id = null,
        ?string $personalizado4Id = null,
        ?string $personalizado5Id = null,
        ?bool $faturamentoDireto = null,
    ): array {
        $importacaoId = $this->resolverImportacaoId($obra, $serie, $linhaBaseId, $avancoImportacaoId);

        if (!$importacaoId) {
            return [];
        }

        $query = AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
            ->where('serie', $serie->value)
            ->where('granularidade', $gran->value)
            ->where('periodo_inicio', $periodoInicio);

        $pacoteIds = $pacoteId ? $this->resolverEscopoPacoteIds($pacoteId) : null;
        $query->whereHas('atividade', function ($q) use (
            $pacoteIds, $etapaId, $disciplinaId, $frenteId,
            $entregavelId, $equipeResponsavelId,
            $personalizado1Id, $personalizado2Id, $personalizado3Id, $personalizado4Id, $personalizado5Id,
            $faturamentoDireto
        ) {
            if ($pacoteIds) {
                $q->whereIn('pacote_trabalho_id', $pacoteIds);
            }
            if ($etapaId) {
                $q->where('etapa_id', $etapaId);
            }
            if ($disciplinaId) {
                $q->where('disciplina_id', $disciplinaId);
            }
            if ($frenteId) {
                $q->where('frente_trabalho_id', $frenteId);
            }
            if ($entregavelId) {
                $q->where('entregavel_id', $entregavelId);
            }
            if ($equipeResponsavelId) {
                $q->where('equipe_responsavel_id', $equipeResponsavelId);
            }
            if ($personalizado1Id) {
                $q->where('personalizado_1_id', $personalizado1Id);
            }
            if ($personalizado2Id) {
                $q->where('personalizado_2_id', $personalizado2Id);
            }
            if ($personalizado3Id) {
                $q->where('personalizado_3_id', $personalizado3Id);
            }
            if ($personalizado4Id) {
                $q->where('personalizado_4_id', $personalizado4Id);
            }
            if ($personalizado5Id) {
                $q->where('personalizado_5_id', $personalizado5Id);
            }
            if ($faturamentoDireto !== null) {
                $q->where('faturamento_direto', $faturamentoDireto);
            }
        });

        $linhasBrutas = $query->with('atividade:id,nome,codigo_cronograma,pacote_trabalho_id,disciplina_id,etapa_id,frente_trabalho_id,baseline_inicio,baseline_termino')
            ->with('atividade.disciplina:id,nome')
            ->with('atividade.etapa:id,nome')
            ->with('atividade.frenteTrabalho:id,nome')
            ->get()
            ->groupBy('atividade_id');

        // Datas de linha de base por atividade vêm do snapshot gravado na
        // importação da PRÓPRIA linha de base (histórico fiel) — mesma fonte
        // que ⚡linhas-base.blade.php::arvoreAtividades() usa —, caindo pros
        // campos ao vivo da Atividade só se não houver snapshot (dado
        // antigo). Quando $serie não é Previsto (Realizado/Tendência), a
        // importação relevante pra baseline não é necessariamente
        // $importacaoId, então resolve separadamente.
        $importacaoBaselineId = $serie === SerieAvanco::Previsto
            ? $importacaoId
            : $this->resolverImportacaoId($obra, SerieAvanco::Previsto, $linhaBaseId);

        $snapshots = $importacaoBaselineId
            ? AtividadeSnapshot::where('cronograma_importacao_id', $importacaoBaselineId)
                ->whereIn('atividade_id', $linhasBrutas->keys())
                ->get(['atividade_id', 'baseline_inicio', 'baseline_termino'])
                ->keyBy('atividade_id')
            : collect();

        $linhas = $linhasBrutas
            ->map(function ($grupo, $atividadeId) use ($snapshots) {
                $atividade = $grupo->first()->atividade;
                $horas     = (float) $grupo->sum('horas');
                $snap      = $snapshots->get($atividadeId);

                return [
                    'atividade_id'       => $atividade?->id,
                    'pacote_trabalho_id' => $atividade?->pacote_trabalho_id,
                    'nome'               => $atividade?->nome ?? '(atividade removida)',
                    'codigo_cronograma'  => $atividade?->codigo_cronograma,
                    'disciplina'         => $atividade?->disciplina?->nome,
                    'etapa'              => $atividade?->etapa?->nome,
                    'frente'             => $atividade?->frenteTrabalho?->nome,
                    'horas'              => round($horas, 2),
                    'baseline_inicio'    => $snap?->baseline_inicio ?? $atividade?->baseline_inicio,
                    'baseline_termino'   => $snap?->baseline_termino ?? $atividade?->baseline_termino,
                ];
            })
            ->sortByDesc('horas')
            ->values();

        $totalPeriodo = (float) $linhas->sum('horas');

        return $linhas->map(function ($linha) use ($totalPeriodo) {
            $linha['percentual'] = $totalPeriodo > 0 ? round($linha['horas'] / $totalPeriodo * 100, 1) : 0.0;
            return $linha;
        })->all();
    }

    /**
     * Organiza as atividades de detalhePeriodo() na hierarquia da EAP
     * (pacote → subpacote → atividade) — versão enxuta da mesma técnica
     * de ⚡linhas-base.blade.php::arvoreAtividades() (subir a cadeia
     * parent_id sobre um keyBy de todos os pacotes da obra), sem a
     * sofisticação de intercalar órfãs por código nem ordem_manual —
     * aqui o escopo é só o punhado de atividades com HH num período,
     * não a EAP inteira da obra.
     *
     * Retorna uma lista achatada de linhas
     * ['tipo' => 'pacote'|'atividade', 'nivel' => int, 'nome' => string,
     * 'codigo' => ?string, 'dados' => ?array] — 'dados' só nas linhas de
     * atividade, com a linha original de detalhePeriodo().
     */
    public function hierarquizarAtividades(Work $obra, array $atividades): array
    {
        if (empty($atividades)) {
            return [];
        }

        $pacoteIdsComAtividade = collect($atividades)->pluck('pacote_trabalho_id')->filter()->unique();

        $todosPacotes = PacoteTrabalho::where('obra_id', $obra->id)
            ->get(['id', 'nome', 'codigo', 'parent_id'])
            ->keyBy('id');

        $idsRelevantes = collect();
        foreach ($pacoteIdsComAtividade as $pid) {
            $atual = $pid;
            while ($atual && $todosPacotes->has($atual)) {
                $idsRelevantes->push($atual);
                $atual = $todosPacotes->get($atual)->parent_id;
            }
        }
        $idsRelevantes = $idsRelevantes->unique();

        $atividadesPorPacote = collect($atividades)->groupBy(fn($a) => $a['pacote_trabalho_id'] ?? 'sem_pacote');

        $ordenarAtividades = fn($grupo) => $grupo->sort(
            fn($a, $b) => $this->compararCodigosEap($a['codigo_cronograma'], $b['codigo_cronograma']) ?: strcmp($a['nome'], $b['nome'])
        )->values();

        $resultado = [];

        $percorrer = function (string $pacoteId, int $nivel) use (
            &$percorrer,
            &$resultado,
            $todosPacotes,
            $idsRelevantes,
            $atividadesPorPacote,
            $ordenarAtividades
        ) {
            $pacote = $todosPacotes->get($pacoteId);

            $resultado[] = [
                'tipo'   => 'pacote',
                'nivel'  => $nivel,
                'nome'   => $pacote->nome,
                'codigo' => $pacote->codigo,
                'dados'  => null,
            ];

            $filhos = $todosPacotes
                ->filter(fn($p) => $p->parent_id === $pacoteId && $idsRelevantes->contains($p->id))
                ->sort(fn($a, $b) => $this->compararCodigosEap($a->codigo, $b->codigo));

            foreach ($filhos as $filho) {
                $percorrer($filho->id, $nivel + 1);
            }

            foreach ($ordenarAtividades($atividadesPorPacote->get($pacoteId, collect())) as $atividade) {
                $resultado[] = [
                    'tipo'   => 'atividade',
                    'nivel'  => $nivel + 1,
                    'nome'   => $atividade['nome'],
                    'codigo' => $atividade['codigo_cronograma'],
                    'dados'  => $atividade,
                ];
            }
        };

        $raizes = $todosPacotes
            ->filter(fn($p) => $p->parent_id === null && $idsRelevantes->contains($p->id))
            ->sort(fn($a, $b) => $this->compararCodigosEap($a->codigo, $b->codigo));

        foreach ($raizes as $pacote) {
            $percorrer($pacote->id, 0);
        }

        foreach ($ordenarAtividades($atividadesPorPacote->get('sem_pacote', collect())) as $atividade) {
            $resultado[] = [
                'tipo'   => 'atividade',
                'nivel'  => 0,
                'nome'   => $atividade['nome'],
                'codigo' => $atividade['codigo_cronograma'],
                'dados'  => $atividade,
            ];
        }

        return $resultado;
    }

    /**
     * Agrupa as atividades de detalhePeriodo() por disciplina, somando
     * HH e calculando % sobre o total do período — alimenta o gráfico
     * de barras por disciplina do popup de detalhe.
     */
    public function resumoPorDisciplina(array $atividades): array
    {
        if (empty($atividades)) {
            return [];
        }

        $totalPeriodo = array_sum(array_column($atividades, 'horas'));

        $porDisciplina = collect($atividades)
            ->groupBy(fn($a) => $a['disciplina'] ?? 'Sem disciplina')
            ->map(fn($grupo, $disciplina) => [
                'disciplina' => $disciplina,
                'horas'      => round((float) $grupo->sum('horas'), 2),
            ])
            ->sortByDesc('horas')
            ->values();

        return $porDisciplina->map(function ($linha) use ($totalPeriodo) {
            $linha['percentual'] = $totalPeriodo > 0 ? round($linha['horas'] / $totalPeriodo * 100, 1) : 0.0;
            return $linha;
        })->all();
    }

    /**
     * Compara dois códigos de EAP (ex: "5.1.10" vs "5.1.3") segmento a
     * segmento como números — mesmo helper já duplicado entre Lookahead
     * e Linhas de Base (⚡linhas-base.blade.php::compararCodigos()),
     * mantendo a mesma ordenação em todo o app.
     */
    private function compararCodigosEap(?string $a, ?string $b): int
    {
        $a = explode('.', $a ?? '');
        $b = explode('.', $b ?? '');

        foreach (range(0, max(count($a), count($b)) - 1) as $i) {
            $x = (int) ($a[$i] ?? 0);
            $y = (int) ($b[$i] ?? 0);
            if ($x !== $y) {
                return $x <=> $y;
            }
        }

        return 0;
    }

    /** Total de HH calculados (sem ajustes) para o escopo. */
    public function totalCalculado(
        Work $obra,
        SerieAvanco $serie,
        GranularidadePeriodo $gran,
        ?string $pacoteId = null,
        ?string $linhaBaseId = null,
        ?string $avancoImportacaoId = null
    ): float {
        $importacaoId = $this->resolverImportacaoId($obra, $serie, $linhaBaseId, $avancoImportacaoId);

        if (!$importacaoId) {
            return 0.0;
        }

        $query = AvancoPeriodo::where('cronograma_importacao_id', $importacaoId)
            ->where('serie', $serie->value)
            ->where('granularidade', $gran->value);

        if ($pacoteId) {
            $query->whereHas('atividade', fn($q) => $q->whereIn('pacote_trabalho_id', $this->resolverEscopoPacoteIds($pacoteId)));
        }

        return (float) $query->sum('horas');
    }

    /**
     * Substitui o campo 'percentual' de cada ponto (retornado por calcular())
     * por acumulado/$totalBase*100 — usado quando o denominador precisa ser
     * um total FIXO diferente do total da própria série.
     *
     * Caso de uso real: no Report semanal, %realizado não pode ser dividido
     * pelo total realizado (senão sempre converge a 100%, o que não faz
     * sentido) — tem que usar o mesmo total de linha de base (Previsto) que
     * %previsto usa, pra os dois serem comparáveis diretamente.
     */
    public function rebasearPercentual(array $pontos, float $totalBase): array
    {
        return array_map(function ($ponto) use ($totalBase) {
            $ponto['percentual'] = $totalBase > 0
                ? round($ponto['acumulado'] / $totalBase * 100, 2)
                : 0.0;

            return $ponto;
        }, $pontos);
    }

    /** Próprio pacote + todos os descendentes — o escopo real de uma curva/rollup. */
    private function resolverEscopoPacoteIds(string $pacoteId): array
    {
        $pacote = PacoteTrabalho::find($pacoteId);

        if (!$pacote) {
            return [$pacoteId];
        }

        return [$pacote->id, ...$pacote->descendantIds()];
    }

    /**
     * Resolve qual cronograma_importacao_id usar:
     * — quando linhaBaseId informado E série = Previsto → usa o import da LB
     * — quando avancoImportacaoId informado E série != Previsto → usa esse import
     * — caso contrário → última importação ELEGÍVEL da obra (filtrada por
     *   tipo: Previsto só pode vir de importação baseline/ambos; Realizado/
     *   Tendência só de avanço/ambos — nunca escolhe sozinho uma importação
     *   do tipo errado como "a mais recente").
     */
    private function resolverImportacaoId(
        Work $obra,
        SerieAvanco $serie,
        ?string $linhaBaseId,
        ?string $avancoImportacaoId = null
    ): ?string {
        if ($linhaBaseId && $serie === SerieAvanco::Previsto) {
            $lb = LinhaBase::find($linhaBaseId);
            return $lb?->cronograma_importacao_id;
        }

        if ($avancoImportacaoId && $serie !== SerieAvanco::Previsto) {
            return $avancoImportacaoId;
        }

        $tiposElegiveis = $serie === SerieAvanco::Previsto
            ? [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value]
            : [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value];

        $importacao = CronogramaImportacao::where('obra_id', $obra->id)
            ->whereIn('tipo', $tiposElegiveis)
            ->orderByDesc('importado_em')
            ->orderByDesc('id')
            ->first();

        return $importacao?->id;
    }
}
