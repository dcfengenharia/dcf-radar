<?php

namespace App\Imports;

use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemAtividade;
use App\Enums\SerieAvanco;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AvancoPeriodo;
use App\Models\CronogramaImportacao;
use App\Models\Obra;
use App\Models\PacoteTrabalho;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa o cronograma do MS Project (.xml / MSPDI) e prepara os dados de
 * avanço (HH) para as curvas S.
 *
 * REGRAS DE HH VALIDADAS CONTRA ARQUIVO REAL (ver docs/ONDA-7):
 *  1. Só atribuições a recursos do tipo TRABALHO (Resource Type = 1).
 *     Recurso material/custo/nulo NÃO é homem-hora e inflava os totais.
 *  2. O Value do trabalho faseado é uma DURAÇÃO (PT..H..M..S), não minutos.
 *  3. Distribuição pelo PONTO MÉDIO do bloco (menor desvio vs MS Project).
 *  Séries: Previsto = tipo 4; Realizado = tipo 2; Tendência = tipo 1 + 2.
 *
 * Reconhecemos que a reconstrução tem desvio de fronteira < ~0,3%/mês
 * (compressão de blocos do MSPDI). Por isso registramos o método e o
 * usuário pode ajustar os valores manualmente (ver CurvaAjuste).
 *
 * NOTA DE PRODUÇÃO: cronograma real pode ter dezenas de MB. Em produção,
 * troque o simplexml por um parser de streaming (XMLReader) para não
 * estourar memória. Aqui usamos simplexml por legibilidade da referência.
 */
class MsProjectImporter implements ImportadorCronograma
{
    public const METODO = 'ponto_medio_recurso_trabalho';

    private const TP_REMAINING = 1;
    private const TP_ACTUAL = 2;
    private const TP_BASELINE = 4;

    public function analisar(string $caminhoArquivo, Obra $obra): PlanoImportacao
    {
        $parse = $this->parsear($caminhoArquivo);
        $tarefas = $parse['tarefas'];

        $pacotes = array_values(array_filter($tarefas, fn (TarefaImportada $t) => $t->resumo));
        $folhas = array_filter($tarefas, fn (TarefaImportada $t) => ! $t->resumo);

        $existentes = Atividade::query()
            ->where('obra_id', $obra->id)
            ->where('origem', OrigemAtividade::MsProject)
            ->pluck('id', 'external_uid');

        $criar = [];
        $atualizar = [];
        $uidsArquivo = [];
        foreach ($folhas as $a) {
            $uidsArquivo[] = $a->uid;
            isset($existentes[$a->uid]) ? $atualizar[] = $a : $criar[] = $a;
        }

        $removerIds = $existentes
            ->reject(fn ($id, $uid) => in_array($uid, $uidsArquivo, true))
            ->values()->all();
        $removerNomes = Atividade::whereIn('id', $removerIds)->pluck('nome')->all();

        return new PlanoImportacao($pacotes, $criar, $atualizar, $removerIds, $removerNomes, $parse['dataStatus']);
    }

    public function aplicar(
        PlanoImportacao $plano,
        Obra $obra,
        ?string $userId = null,
        ?string $arquivo = null,
    ): CronogramaImportacao {
        return DB::transaction(function () use ($plano, $obra, $userId, $arquivo) {
            $mapaPacotes = [];
            foreach ($plano->pacotes as $p) {
                $pacote = PacoteTrabalho::updateOrCreate(
                    ['obra_id' => $obra->id, 'external_uid' => $p->uid],
                    ['nome' => $p->nome, 'codigo' => $p->numero,
                     'parent_id' => $p->parentUid ? ($mapaPacotes[$p->parentUid] ?? null) : null],
                );
                $mapaPacotes[$p->uid] = $pacote->id;
            }

            $importacao = CronogramaImportacao::create([
                'obra_id' => $obra->id,
                'user_id' => $userId,
                'arquivo' => $arquivo,
                'data_status' => $plano->dataStatus ? Carbon::parse($plano->dataStatus) : null,
                'metodo_distribuicao' => self::METODO,
                'criadas' => $plano->totalCriar(),
                'atualizadas' => $plano->totalAtualizar(),
                'removidas' => $plano->totalRemover(),
                'importado_em' => now(),
            ]);

            $linhas = [];
            foreach ([...$plano->criar, ...$plano->atualizar] as $t) {
                $atividade = Atividade::updateOrCreate(
                    ['obra_id' => $obra->id, 'external_uid' => $t->uid],
                    [
                        'nome' => $t->nome,
                        'pacote_trabalho_id' => $t->parentUid ? ($mapaPacotes[$t->parentUid] ?? null) : null,
                        'data_planejada' => $this->data($t->inicio),
                        'data_termino' => $this->data($t->termino),
                        'baseline_inicio' => $this->data($t->baselineInicio),
                        'baseline_termino' => $this->data($t->baselineTermino),
                        'real_inicio' => $this->data($t->realInicio),
                        'real_termino' => $this->data($t->realTermino),
                        'baseline_horas' => $t->baselineHoras,
                        'work_horas' => $t->workHoras,
                        'real_horas' => $t->realHoras,
                        'textos' => $t->textos ?: null,
                        'caminho_critico' => $t->critico,
                        'is_marco' => $t->marco,
                        'origem' => OrigemAtividade::MsProject,
                        'fora_do_cronograma' => false,
                        'external_synced_at' => now(),
                    ],
                );

                foreach ($t->periodos as $p) {
                    $linhas[] = [
                        'id' => (string) Str::ulid(),
                        'tenant_id' => $obra->tenant_id,
                        'obra_id' => $obra->id,
                        'atividade_id' => $atividade->id,
                        'cronograma_importacao_id' => $importacao->id,
                        'granularidade' => $p->granularidade->value,
                        'serie' => $p->serie->value,
                        'periodo_inicio' => $p->periodoInicio,
                        'horas' => $p->horas,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
            foreach (array_chunk($linhas, 1000) as $chunk) {
                AvancoPeriodo::insert($chunk);
            }

            Atividade::whereIn('id', $plano->removerIds)->update([
                'fora_do_cronograma' => true, 'external_synced_at' => now(),
            ]);

            return $importacao;
        });
    }

    /**
     * @return array{dataStatus: ?string, tarefas: TarefaImportada[]}
     */
    private function parsear(string $caminhoArquivo): array
    {
        $raw = file_get_contents($caminhoArquivo);
        $raw = preg_replace('/\sxmlns="[^"]+"/', '', $raw, 1);
        $xml = simplexml_load_string($raw);
        if ($xml === false || ! isset($xml->Tasks)) {
            throw new \RuntimeException('Arquivo inválido ou não é um cronograma XML do MS Project.');
        }

        $dataStatus = (string) $xml->StatusDate ?: null;
        $mapaTextos = $this->mapaTextosPersonalizados($xml);
        $recursosTrabalho = $this->recursosTrabalho($xml);             // UIDs de recurso Type=1
        $baldes = $this->agregarTrabalhoFaseado($xml, $recursosTrabalho); // uid => HorasPeriodo[]

        $tarefas = [];
        $pilha = [];
        foreach ($xml->Tasks->Task as $task) {
            $nivel = (int) $task->OutlineLevel;
            if ($nivel === 0) {
                continue;
            }
            $uid = (string) $task->UID;
            $parentUid = $pilha[$nivel - 1] ?? null;
            $pilha[$nivel] = $uid;
            foreach (array_keys($pilha) as $n) {
                if ($n > $nivel) {
                    unset($pilha[$n]);
                }
            }
            $baseline = $this->baselinePrincipal($task);

            $tarefas[] = new TarefaImportada(
                uid: $uid,
                parentUid: $parentUid,
                nome: trim((string) $task->Name),
                numero: (string) $task->OutlineNumber,
                nivel: $nivel,
                resumo: (string) $task->Summary === '1',
                critico: (string) $task->Critical === '1',
                marco: (string) $task->Milestone === '1',
                inicio: (string) $task->Start ?: null,
                termino: (string) $task->Finish ?: null,
                baselineInicio: $baseline ? ((string) $baseline->Start ?: null) : null,
                baselineTermino: $baseline ? ((string) $baseline->Finish ?: null) : null,
                realInicio: (string) $task->ActualStart ?: null,
                realTermino: (string) $task->ActualFinish ?: null,
                baselineHoras: $baseline ? $this->horas((string) $baseline->Work) : 0.0,
                workHoras: $this->horas((string) $task->Work),
                realHoras: $this->horas((string) $task->ActualWork),
                textos: $this->textosDaTarefa($task, $mapaTextos),
                periodos: $baldes[$uid] ?? [],
            );
        }

        return ['dataStatus' => $dataStatus, 'tarefas' => $tarefas];
    }

    /** UIDs de recursos do tipo Trabalho (Type = 1). */
    private function recursosTrabalho(\SimpleXMLElement $xml): array
    {
        $set = [];
        if (isset($xml->Resources)) {
            foreach ($xml->Resources->Resource as $r) {
                if ((string) $r->Type === '1') {
                    $set[(string) $r->UID] = true;
                }
            }
        }
        return $set;
    }

    /**
     * Agrega o trabalho faseado por tarefa, em semanal e mensal, pelo PONTO
     * MÉDIO de cada bloco. Só conta atribuições a recursos de trabalho.
     *
     * @return array<string, HorasPeriodo[]>
     */
    private function agregarTrabalhoFaseado(\SimpleXMLElement $xml, array $recursosTrabalho): array
    {
        if (! isset($xml->Assignments)) {
            return [];
        }
        $acc = [];
        foreach ($xml->Assignments->Assignment as $a) {
            if (! isset($recursosTrabalho[(string) $a->ResourceUID])) {
                continue; // ignora material/custo/nulo
            }
            $uid = (string) $a->TaskUID;
            foreach ($a->TimephasedData as $td) {
                $series = $this->seriesDoTipo((int) $td->Type);
                if ($series === []) {
                    continue;
                }
                $horas = $this->horas((string) $td->Value); // Value é DURAÇÃO
                if ($horas == 0.0) {
                    continue;
                }
                $ini = (string) $td->Start;
                if ($ini === '') {
                    continue;
                }
                $inicio = Carbon::parse($ini);
                $fim = ((string) $td->Finish !== '') ? Carbon::parse((string) $td->Finish) : $inicio;
                // Ponto médio do bloco -> regra de menor desvio.
                $medio = $inicio->copy()->addSeconds((int) ($fim->diffInSeconds($inicio) / 2));
                $semana = $medio->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
                $mes = $medio->copy()->startOfMonth()->toDateString();

                foreach ($series as $serie) {
                    $acc[$uid]['semanal'][$serie->value][$semana] =
                        ($acc[$uid]['semanal'][$serie->value][$semana] ?? 0) + $horas;
                    $acc[$uid]['mensal'][$serie->value][$mes] =
                        ($acc[$uid]['mensal'][$serie->value][$mes] ?? 0) + $horas;
                }
            }
        }
        return $this->montarBaldes($acc);
    }

    private function seriesDoTipo(int $tipo): array
    {
        return match ($tipo) {
            self::TP_BASELINE => [SerieAvanco::Previsto],
            self::TP_ACTUAL => [SerieAvanco::Realizado, SerieAvanco::Tendencia],
            self::TP_REMAINING => [SerieAvanco::Tendencia],
            default => [],
        };
    }

    private function montarBaldes(array $acc): array
    {
        $res = [];
        foreach ($acc as $uid => $grans) {
            $baldes = [];
            foreach ($grans as $g => $series) {
                foreach ($series as $serie => $periodos) {
                    foreach ($periodos as $ini => $horas) {
                        $baldes[] = new HorasPeriodo(
                            GranularidadePeriodo::from($g),
                            SerieAvanco::from($serie),
                            $ini,
                            round($horas, 2),
                        );
                    }
                }
            }
            $res[$uid] = $baldes;
        }
        return $res;
    }

    private function mapaTextosPersonalizados(\SimpleXMLElement $xml): array
    {
        $mapa = [];
        if (isset($xml->ExtendedAttributes)) {
            foreach ($xml->ExtendedAttributes->ExtendedAttribute as $def) {
                $nome = strtolower((string) $def->FieldName);
                if (preg_match('/^text(2[0-9]|30)$/', $nome)) {
                    $mapa[(string) $def->FieldID] = $nome;
                }
            }
        }
        return $mapa;
    }

    private function textosDaTarefa(\SimpleXMLElement $task, array $mapaTextos): array
    {
        $textos = [];
        foreach ($task->ExtendedAttribute as $ea) {
            $fid = (string) $ea->FieldID;
            if (isset($mapaTextos[$fid])) {
                $textos[$mapaTextos[$fid]] = (string) $ea->Value;
            }
        }
        return $textos;
    }

    private function baselinePrincipal(\SimpleXMLElement $task): ?\SimpleXMLElement
    {
        foreach ($task->Baseline as $b) {
            if ((string) $b->Number === '0') {
                return $b;
            }
        }
        return null;
    }

    private function data(?string $iso): ?string
    {
        return $iso ? Carbon::parse($iso)->toDateString() : null;
    }

    private function horas(string $duracao): float
    {
        if ($duracao === '' || ! preg_match('/PT(?:([\d.]+)H)?(?:([\d.]+)M)?(?:([\d.]+)S)?/', $duracao, $m)) {
            return 0.0;
        }
        return round((float) ($m[1] ?? 0) + ((float) ($m[2] ?? 0)) / 60 + ((float) ($m[3] ?? 0)) / 3600, 2);
    }
}
