<?php

namespace App\Services;

use App\Enums\GranularidadePeriodo;
use App\Enums\SerieAvanco;
use App\Enums\StatusAtividade;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\PacoteTrabalho;
use App\Models\Report;
use App\Models\ReportCurva;
use App\Models\ReportDesvio;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gera o Report semanal — a peça central da feature de reports.
 *
 * FILOSOFIA DE "FOTOGRAFIA" (documentada em detalhe aqui porque é a
 * decisão arquitetural mais importante desta feature, e o usuário pediu
 * explicitamente código bem documentado pra manutenção futura):
 *
 * Este serviço é o ÚNICO lugar do sistema que lê AvancoPeriodo/
 * PacoteTrabalho AO VIVO pra montar um report. Uma vez que gerarRascunho()
 * roda, todos os números (HH por período, % acumulado, término de linha
 * de base/tendência, peso/desvio/impacto da tabela de desvios) já estão
 * GRAVADOS nas tabelas report_curva_datapoints/report_desvios/
 * report_curvas. Nada mais no sistema volta a consultar dados "ao vivo"
 * pra um report já existente — nem a tela de detalhe, nem o PDF, nem o
 * Excel. Isso garante que um report já emitido e mostrado ao cliente
 * (VOPAK) nunca muda sozinho depois de uma reimportação do cronograma.
 * Mesma filosofia já usada em AtividadeSnapshot e LinhaBase.
 *
 * COMO peso/%previsto/%real/%desvio/%impacto SÃO CALCULADOS (verificado
 * célula a célula contra a planilha real do usuário, com uma decisão de
 * design explícita onde a fórmula da planilha era ambígua — ver detalhes
 * no método calcularLinhaDesvio()):
 *
 * - peso = HH de linha de base do escopo desta linha (pacote + todos os
 *   descendentes) dividido pelo HH de linha de base do escopo da curva
 *   inteira (o pacote "pai" da curva, ou a obra inteira). A linha do
 *   "nível pai" (o próprio pacote da curva) sempre tem peso = 1.
 * - %previsto = HH de linha de base ACUMULADO ATÉ A DATA DE STATUS,
 *   dividido pelo HH TOTAL de linha de base — ambos calculados no
 *   escopo PRÓPRIO daquela linha (não relativo ao total da curva). Ou
 *   seja: é o quanto aquele pacote específico já deveria ter avançado,
 *   segundo o planejamento original.
 * - %real = mesma ideia, mas com HH REALIZADO acumulado até a data de
 *   status — IMPORTANTE: dividido pelo MESMO denominador de %previsto
 *   (o HH de linha de base do escopo), não pelo HH realizado total. Isso
 *   é o que permite comparar %previsto e %real diretamente.
 * - %desvio = %real - %previsto (negativo = atrasado, positivo =
 *   adiantado) — confirmado com o usuário como a convenção correta,
 *   batendo com a fórmula real da planilha (「=G110-F110」).
 * - %impacto = %desvio × peso — o quanto o desvio DESTA linha pesa no
 *   resultado da curva como um todo.
 */
class ReportGerador
{
    public function __construct(private readonly CurvaAvanco $curvaAvanco)
    {
    }

    /**
     * Cria o Report (status=rascunho) e todas as suas curvas, a partir
     * de dados AO VIVO no momento da chamada.
     *
     * $opcoes espera:
     *   - periodo_referencia: Carbon|string (a semana do report)
     *   - linha_base_id: ?string (usado só pra série Previsto)
     *   - avanco_importacao_id: ?string (usado pra Realizado/Tendência)
     *   - titulo: ?string
     *   - curvas: array de ['pacote_trabalho_id' => ?string, 'ordem' => int,
     *             'pontos_atencao' => [['categoria' => ?string, 'texto' => string], ...]]
     *             (pacote_trabalho_id null = curva da obra inteira)
     */
    public function gerarRascunho(Work $obra, User $usuario, array $opcoes): Report
    {
        $periodoReferencia = Carbon::parse($opcoes['periodo_referencia'])->startOfWeek();
        $linhaBaseId = $opcoes['linha_base_id'] ?? null;
        $avancoImportacaoId = $opcoes['avanco_importacao_id'] ?? null;

        // O cronograma_importacao_id gravado no Report representa "em cima
        // de qual atualização de avanço este report foi gerado" — por isso
        // resolve pela série Realizado (nunca Previsto, que segue a Linha
        // de Base separadamente).
        $cronogramaImportacaoId = $this->cronogramaParaSerie(SerieAvanco::Realizado, $obra, $linhaBaseId, $avancoImportacaoId);
        if (! $cronogramaImportacaoId) {
            throw new \RuntimeException('Esta obra ainda não tem nenhuma importação de avanço (realizado/tendência).');
        }

        $dataStatus = CronogramaImportacao::find($cronogramaImportacaoId)?->data_status;

        return DB::transaction(function () use ($obra, $usuario, $opcoes, $periodoReferencia, $linhaBaseId, $avancoImportacaoId, $cronogramaImportacaoId, $dataStatus) {
            $report = Report::create([
                'obra_id' => $obra->id,
                'periodo_referencia' => $periodoReferencia,
                'data_status' => $dataStatus,
                'linha_base_id' => $linhaBaseId,
                'cronograma_importacao_id' => $cronogramaImportacaoId,
                'titulo' => $opcoes['titulo'] ?? null,
                'criado_por' => $usuario->id,
            ]);

            foreach ($opcoes['curvas'] ?? [] as $dadosCurva) {
                $this->gerarCurva($report, $obra, $dadosCurva, $periodoReferencia, $linhaBaseId, $avancoImportacaoId, $dataStatus);
            }

            return $report->fresh(['curvas.datapoints', 'curvas.desvios', 'curvas.pontosAtencao']);
        });
    }

    public function emitir(Report $report, User $usuario): void
    {
        $report->emitir($usuario);
    }

    // =========================================================================
    // Geração de uma curva
    // =========================================================================

    private function gerarCurva(Report $report, Work $obra, array $dadosCurva, Carbon $periodoReferencia, ?string $linhaBaseId, ?string $avancoImportacaoId, ?Carbon $dataStatus): ReportCurva
    {
        $pacoteId = $dadosCurva['pacote_trabalho_id'] ?? null;
        $pacote = $pacoteId ? PacoteTrabalho::findOrFail($pacoteId) : null;

        $escopoIds = $this->idsDoEscopo($obra, $pacote);

        // Total de HH de linha de base do escopo desta curva — denominador
        // ÚNICO compartilhado por %previsto/%tendência/%realizado (ver
        // CurvaAvanco::rebasearPercentual()) e base do "peso" dos filhos
        // no quadro de desvios (calcularLinhaDesvio()).
        $totalHhPrevisto = $pacote
            ? $pacote->totalHhBaseline($this->cronogramaParaSerie(SerieAvanco::Previsto, $obra, $linhaBaseId))
            : $this->curvaAvanco->totalCalculado($obra, SerieAvanco::Previsto, GranularidadePeriodo::Mensal, null, $linhaBaseId);

        $contagem = $this->contarAtividades($escopoIds, $dataStatus);

        $curva = ReportCurva::create([
            'report_id' => $report->id,
            'pacote_trabalho_id' => $pacoteId,
            'ordem' => $dadosCurva['ordem'] ?? 0,
            'titulo_exibicao' => $pacote ? "{$pacote->codigo} - {$pacote->nome}" : $obra->name,
            'termino_linha_base' => $this->terminoMaisTardio($escopoIds, 'baseline_termino'),
            'termino_tendencia' => $this->terminoMaisTardio($escopoIds, 'data_termino'),
            'total_hh_previsto' => $totalHhPrevisto,
            'total_atividades' => $contagem['total'],
            'atividades_concluidas' => $contagem['concluidas'],
            'atividades_atrasadas' => $contagem['atrasadas'],
        ]);

        // Curva S: 3 séries × 2 granularidades, sempre reaproveitando o
        // serviço CurvaAvanco já existente — nada de agregação duplicada.
        //
        // IMPORTANTE: o 'percentual' que CurvaAvanco::calcular() retorna
        // divide o acumulado pelo total DA PRÓPRIA série — o que faria
        // %realizado sempre convergir a 100% (sem sentido: o realizado
        // teria que "completar" a si mesmo). rebasearPercentual() troca
        // esse denominador pelo total de linha de base ($totalHhPrevisto),
        // o mesmo usado por %previsto — só assim as três séries acumuladas
        // ficam comparáveis no mesmo eixo 0–100%.
        foreach ([GranularidadePeriodo::Mensal, GranularidadePeriodo::Semanal] as $granularidade) {
            foreach (SerieAvanco::cases() as $serie) {
                $pontos = $this->curvaAvanco->calcular($obra, $serie, $granularidade, $pacoteId, $linhaBaseId, $avancoImportacaoId);
                $pontos = $this->curvaAvanco->rebasearPercentual($pontos, $totalHhPrevisto);

                if ($granularidade === GranularidadePeriodo::Semanal) {
                    $pontos = $this->recortarUltimasQuatroSemanas($pontos, $periodoReferencia);
                }

                foreach ($pontos as $ponto) {
                    $curva->datapoints()->create([
                        'granularidade' => $granularidade->value,
                        'serie' => $serie->value,
                        'periodo_inicio' => $ponto['periodo_inicio'],
                        'horas' => $ponto['horas_exibir'],
                        'percentual_acumulado' => $ponto['percentual'],
                    ]);
                }
            }
        }

        $this->gerarQuadroDesvios($curva, $obra, $pacote, $escopoIds, $linhaBaseId, $avancoImportacaoId, $dataStatus);

        foreach ($dadosCurva['pontos_atencao'] ?? [] as $i => $ponto) {
            $curva->pontosAtencao()->create([
                'categoria' => $ponto['categoria'] ?? null,
                'texto' => $ponto['texto'],
                'ordem' => $i,
            ]);
        }

        return $curva;
    }

    // =========================================================================
    // Quadro de análise de desvios (nível pai + filhos imediatos)
    // =========================================================================

    private function gerarQuadroDesvios(ReportCurva $curva, Work $obra, ?PacoteTrabalho $pacote, array $escopoIdsPai, ?string $linhaBaseId, ?string $avancoImportacaoId, ?Carbon $dataStatus): void
    {
        $cronogramaPrevisto = $this->cronogramaParaSerie(SerieAvanco::Previsto, $obra, $linhaBaseId);
        $cronogramaReal = $this->cronogramaParaSerie(SerieAvanco::Realizado, $obra, $linhaBaseId, $avancoImportacaoId);

        $totalHhPai = $this->totalHhBaselineDoEscopo($escopoIdsPai, $cronogramaPrevisto);

        $ordem = 0;

        $curva->desvios()->create($this->calcularLinhaDesvio(
            pacoteTitulo: $pacote ? "{$pacote->codigo} - {$pacote->nome}" : $obra->name,
            pacoteId: $pacote?->id,
            escopoIds: $escopoIdsPai,
            pesoBaseHh: $totalHhPai,
            cronogramaPrevisto: $cronogramaPrevisto,
            cronogramaReal: $cronogramaReal,
            dataStatus: $dataStatus,
            ehNivelPai: true,
            ordem: $ordem++,
        ));

        // Nota: SEM orderBy('codigo') na query — ordenação SQL de string
        // colocaria "5.10" antes de "5.3". Reordenado em PHP logo abaixo
        // com compararCodigos() (comparação natural, segmento a segmento).
        $filhos = $pacote ? $pacote->filhos()->get()
            : PacoteTrabalho::where('obra_id', $obra->id)->whereNull('parent_id')->get();

        $filhos = $filhos->sort(fn ($a, $b) => $this->compararCodigos($a->codigo, $b->codigo))->values();

        // Se a curva é de um pacote específico, o próprio pacote também
        // pode ser um dos "filhos" da lista de raízes — não deve se
        // listar como filho de si mesmo.
        if ($pacote) {
            $filhos = $filhos->reject(fn ($f) => $f->id === $pacote->id);
        }

        foreach ($filhos as $filho) {
            $escopoFilho = [$filho->id, ...$filho->descendantIds()];

            $curva->desvios()->create($this->calcularLinhaDesvio(
                pacoteTitulo: "{$filho->codigo} - {$filho->nome}",
                pacoteId: $filho->id,
                escopoIds: $escopoFilho,
                pesoBaseHh: $totalHhPai,
                cronogramaPrevisto: $cronogramaPrevisto,
                cronogramaReal: $cronogramaReal,
                dataStatus: $dataStatus,
                ehNivelPai: false,
                ordem: $ordem++,
            ));
        }
    }

    /**
     * Monta os atributos de UMA linha do quadro de desvios (nível pai ou
     * um filho imediato). Ver o docblock da classe pra explicação
     * completa das fórmulas.
     */
    private function calcularLinhaDesvio(
        string $pacoteTitulo,
        ?string $pacoteId,
        array $escopoIds,
        float $pesoBaseHh,
        ?string $cronogramaPrevisto,
        ?string $cronogramaReal,
        ?Carbon $dataStatus,
        bool $ehNivelPai,
        int $ordem,
    ): array {
        $totalHhEscopo = $this->totalHhBaselineDoEscopo($escopoIds, $cronogramaPrevisto);
        $peso = $ehNivelPai ? 1.0 : ($pesoBaseHh > 0 ? round($totalHhEscopo / $pesoBaseHh, 4) : 0.0);

        $hhPrevistoAteStatus = $this->hhAcumuladoAteData($escopoIds, SerieAvanco::Previsto, $cronogramaPrevisto, $dataStatus);
        $hhRealAteStatus = $this->hhAcumuladoAteData($escopoIds, SerieAvanco::Realizado, $cronogramaReal, $dataStatus);

        // %real usa o MESMO denominador de %previsto (o HH de linha de
        // base do escopo) — não o HH realizado total — pra permitir
        // comparação direta entre os dois percentuais.
        $percentualPrevisto = $totalHhEscopo > 0 ? round($hhPrevistoAteStatus / $totalHhEscopo * 100, 2) : 0.0;
        $percentualReal = $totalHhEscopo > 0 ? round($hhRealAteStatus / $totalHhEscopo * 100, 2) : 0.0;

        $percentualDesvio = round($percentualReal - $percentualPrevisto, 2);
        $percentualImpacto = round($percentualDesvio * $peso, 2);

        return [
            'pacote_trabalho_id' => $pacoteId ?? $this->pacoteVirtualDaObra($escopoIds),
            'eh_nivel_pai' => $ehNivelPai,
            'titulo_exibicao' => $pacoteTitulo,
            'peso' => $peso,
            'percentual_previsto' => $percentualPrevisto,
            'percentual_real' => $percentualReal,
            'percentual_desvio' => $percentualDesvio,
            'percentual_impacto' => $percentualImpacto,
            'ordem' => $ordem,
        ];
    }

    /**
     * Compara dois códigos de EAP (ex: "5.10" vs "5.3") segmento a segmento
     * como números — mesmo helper duplicado em ⚡relatorio-novo.blade.php/
     * ⚡linhas-base.blade.php/⚡lookahead.blade.php (convenção do projeto:
     * um comparador por arquivo, não compartilhado via trait).
     */
    private function compararCodigos(?string $a, ?string $b): int
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

    /**
     * Conta atividades no escopo da curva pros cards de KPI do dashboard —
     * congelado na geração (fotografia), nunca recalculado depois.
     *
     * "Atrasada" = término atual/tendência (data_termino) já passou da data
     * de status do report E a atividade não está concluída.
     */
    private function contarAtividades(array $escopoIds, ?Carbon $dataStatus): array
    {
        if (empty($escopoIds)) {
            return ['total' => 0, 'concluidas' => 0, 'atrasadas' => 0];
        }

        $total = Atividade::whereIn('pacote_trabalho_id', $escopoIds)->count();

        $concluidas = Atividade::whereIn('pacote_trabalho_id', $escopoIds)
            ->where('status', StatusAtividade::Concluido->value)
            ->count();

        $atrasadas = $dataStatus
            ? Atividade::whereIn('pacote_trabalho_id', $escopoIds)
                ->where('data_termino', '<', $dataStatus)
                ->where('status', '!=', StatusAtividade::Concluido->value)
                ->count()
            : 0;

        return compact('total', 'concluidas', 'atrasadas');
    }

    // =========================================================================
    // Helpers de escopo / agregação
    // =========================================================================

    /** IDs de pacotes no escopo desta curva — o próprio pacote + descendentes, ou null (obra inteira, sem filtro de pacote). */
    private function idsDoEscopo(Work $obra, ?PacoteTrabalho $pacote): array
    {
        if (! $pacote) {
            return PacoteTrabalho::where('obra_id', $obra->id)->pluck('id')->all();
        }

        return [$pacote->id, ...$pacote->descendantIds()];
    }

    /**
     * A linha "nível pai" de uma curva de obra inteira não tem um
     * pacote_trabalho_id de verdade — mas a coluna é obrigatória (FK).
     * Como fallback, usa o primeiro pacote do escopo (só pra satisfazer
     * a constraint; a exibição usa titulo_exibicao, não este ID).
     *
     * Nota: esta é uma limitação conhecida do desenho atual — reports da
     * "obra inteira" exigem pelo menos um PacoteTrabalho cadastrado. Como
     * toda obra com cronograma importado sempre tem ao menos um pacote
     * (a própria raiz da EAP), isso não é uma restrição prática.
     */
    private function pacoteVirtualDaObra(array $escopoIds): string
    {
        if (empty($escopoIds)) {
            throw new \RuntimeException('Não é possível gerar o quadro de desvios: a obra não tem nenhum pacote de trabalho cadastrado.');
        }

        return $escopoIds[0];
    }

    private function totalHhBaselineDoEscopo(array $pacoteIds, ?string $cronogramaImportacaoId): float
    {
        if (! $cronogramaImportacaoId || empty($pacoteIds)) {
            return 0.0;
        }

        return (float) AvancoPeriodo::where('cronograma_importacao_id', $cronogramaImportacaoId)
            ->where('serie', SerieAvanco::Previsto->value)
            ->where('granularidade', GranularidadePeriodo::Mensal->value)
            ->whereHas('atividade', fn ($q) => $q->whereIn('pacote_trabalho_id', $pacoteIds))
            ->sum('horas');
    }

    /**
     * HH acumulado do escopo até a data de status — em granularidade
     * SEMANAL, não mensal. Corte por granularidade mensal contaria o mês
     * INTEIRO como "já ocorrido" sempre que `periodo_inicio` (dia 1 do mês)
     * caísse antes da data de status, mesmo quando a data de status é uma
     * semana no MEIO do mês — superestimando previsto/real do quadro de
     * desvios em relação à última semana realmente atualizada. Semanal dá
     * o corte exato na mesma semana que a tabela semanal do dashboard usa
     * como "última semana atualizada", mantendo os dois coerentes.
     */
    private function hhAcumuladoAteData(array $pacoteIds, SerieAvanco $serie, ?string $cronogramaImportacaoId, ?Carbon $dataStatus): float
    {
        if (! $cronogramaImportacaoId || empty($pacoteIds) || ! $dataStatus) {
            return 0.0;
        }

        return (float) AvancoPeriodo::where('cronograma_importacao_id', $cronogramaImportacaoId)
            ->where('serie', $serie->value)
            ->where('granularidade', GranularidadePeriodo::Semanal->value)
            ->where('periodo_inicio', '<=', $dataStatus)
            ->whereHas('atividade', fn ($q) => $q->whereIn('pacote_trabalho_id', $pacoteIds))
            ->sum('horas');
    }

    /** Data de término mais tardia entre as atividades do escopo, pro campo indicado ('baseline_termino' ou 'data_termino'). */
    private function terminoMaisTardio(array $pacoteIds, string $campo): ?Carbon
    {
        if (empty($pacoteIds)) {
            return null;
        }

        $valor = Atividade::whereIn('pacote_trabalho_id', $pacoteIds)->max($campo);

        return $valor ? Carbon::parse($valor) : null;
    }

    private function recortarUltimasQuatroSemanas(array $pontos, Carbon $periodoReferencia): array
    {
        $inicio = $periodoReferencia->copy()->subWeeks(3)->toDateString();
        $fim = $periodoReferencia->toDateString();

        return array_values(array_filter(
            $pontos,
            fn ($p) => $p['periodo_inicio'] >= $inicio && $p['periodo_inicio'] <= $fim
        ));
    }

    /**
     * Mesma resolução de importação que CurvaAvanco::resolverImportacaoId()
     * usa internamente (duplicada aqui de propósito — convenção do projeto
     * de não compartilhar helpers pequenos entre classes via trait):
     * — linha de base só vale pra Previsto; avanco_importacao_id só vale
     *   pra Realizado/Tendência; sem pin explícito, cai na última
     *   importação ELEGÍVEL (filtrada por tipo, nunca escolhe sozinha uma
     *   importação do tipo errado como "a mais recente").
     */
    private function cronogramaParaSerie(SerieAvanco $serie, Work $obra, ?string $linhaBaseId, ?string $avancoImportacaoId = null): ?string
    {
        if ($linhaBaseId && $serie === SerieAvanco::Previsto) {
            return LinhaBase::find($linhaBaseId)?->cronograma_importacao_id;
        }

        if ($avancoImportacaoId && $serie !== SerieAvanco::Previsto) {
            return $avancoImportacaoId;
        }

        $tiposElegiveis = $serie === SerieAvanco::Previsto
            ? [TipoCronogramaImportacao::Baseline->value, TipoCronogramaImportacao::Ambos->value]
            : [TipoCronogramaImportacao::Avanco->value, TipoCronogramaImportacao::Ambos->value];

        return CronogramaImportacao::where('obra_id', $obra->id)
            ->whereIn('tipo', $tiposElegiveis)
            ->orderByDesc('importado_em')
            ->orderByDesc('id')
            ->value('id');
    }
}
