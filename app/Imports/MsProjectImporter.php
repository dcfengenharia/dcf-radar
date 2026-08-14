<?php

namespace App\Imports;

use App\DTOs\HorasPeriodo;
use App\DTOs\PlanoImportacao;
use App\DTOs\PredecessoraLink;
use App\DTOs\TarefaImportada;
use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemAtividade;
use App\Enums\SerieAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoRelacionamentoPredecessora;
use App\Enums\TipoRestricaoCronograma;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\Entregavel;
use App\Models\EquipeResponsavel;
use App\Models\Etapa;
use App\Models\FrenteTrabalho;
use App\Models\PacoteTrabalho;
use App\Models\Personalizado1;
use App\Models\Personalizado2;
use App\Models\Personalizado3;
use App\Models\Personalizado4;
use App\Models\Personalizado5;
use App\Models\Work;
use App\Support\ConclusaoAutomaticaAtividades;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\TextoCustomizado;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SimpleXMLElement;
use XMLReader;

class MsProjectImporter implements ImportadorCronograma
{
    private const METODO       = 'ponto_medio_recurso_trabalho';
    private const TP_REMAINING = 1;
    private const TP_ACTUAL    = 2;
    private const TP_BASELINE  = 4;

    // =========================================================================
    // Interface pública
    // =========================================================================

    public function analisar(
        string $caminhoArquivo,
        Work $obra,
        TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline
    ): PlanoImportacao {
        $recursosTrabalho = $this->parsearRecursosTipo1($caminhoArquivo);

        [$tarefas, $horasPeriodos, $dataStatus] = $this->parsearTarefasEAtribuicoes(
            $caminhoArquivo,
            $recursosTrabalho
        );

        $pacotes = array_values(array_filter($tarefas, fn($t) => $t->isSummary));
        $folhas  = array_values(array_filter($tarefas, fn($t) => !$t->isSummary));

        $existentes = Atividade::where('obra_id', $obra->id)
            ->where('origem', OrigemAtividade::MsProject)
            ->pluck('id', 'external_uid')
            ->all();

        $uidsFolhas = array_column($folhas, 'uid');

        $criarBruto = array_values(array_filter($folhas, fn($t) => !isset($existentes[$t->uid])));
        $atualizar  = array_values(array_filter($folhas, fn($t) => isset($existentes[$t->uid])));

        $removerIdsBruto   = array_values(array_diff_key($existentes, array_flip($uidsFolhas)));
        $removerNomesBruto = $removerIdsBruto
            ? Atividade::whereIn('id', $removerIdsBruto)->pluck('nome')->all()
            : [];

        $totalBaseline = round(array_sum(array_column($folhas, 'baselineHoras')), 2);
        $totalWork     = round(array_sum(array_column($folhas, 'workHoras')), 2);
        $totalReal     = round(array_sum(array_column($folhas, 'realHoras')), 2);

        // Modo Avanço: só atualiza progresso de atividades já existentes —
        // nunca cria, nunca arquiva, nunca mexe na EAP (pacotes). Tarefas do
        // XML sem atividade correspondente viram "ignoradas" na prévia, em
        // vez de "a criar".
        if ($tipo === TipoCronogramaImportacao::Avanco) {
            $criar          = [];
            $pacotes        = [];
            $removerIds     = [];
            $removerNomes   = [];
            $ignoradasNomes = array_column($criarBruto, 'nome');
        } else {
            $criar          = $criarBruto;
            $removerIds     = $removerIdsBruto;
            $removerNomes   = $removerNomesBruto;
            $ignoradasNomes = [];
        }

        // As regras de extração (ponto médio, só recurso de Trabalho) não
        // mudam — só filtramos aqui QUAIS séries essa importação específica
        // deve gravar, conforme seu propósito.
        $seriesPermitidas = match ($tipo) {
            TipoCronogramaImportacao::Baseline => [SerieAvanco::Previsto],
            TipoCronogramaImportacao::Avanco   => [SerieAvanco::Realizado, SerieAvanco::Tendencia],
            TipoCronogramaImportacao::Ambos    => SerieAvanco::cases(),
        };
        $horasPeriodos = array_values(array_filter(
            $horasPeriodos,
            fn (HorasPeriodo $hp) => in_array($hp->serie, $seriesPermitidas, true)
        ));

        return new PlanoImportacao(
            criar: $criar,
            atualizar: $atualizar,
            pacotes: $pacotes,
            removerIds: $removerIds,
            removerNomes: $removerNomes,
            ignoradasNomes: $ignoradasNomes,
            dataStatus: $dataStatus,
            totalBaselineHh: $totalBaseline,
            totalWorkHh: $totalWork,
            totalRealHh: $totalReal,
            horasPeriodos: $horasPeriodos,
        );
    }

    public function aplicar(
        PlanoImportacao $plano,
        Work $obra,
        ?string $userId,
        ?string $arquivo,
        TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline
    ): CronogramaImportacao {
        return DB::transaction(function () use ($plano, $obra, $userId, $arquivo, $tipo) {

            // --- 1. Upsert PacoteTrabalho ---
            $mapaPacotes = [];

            foreach ($plano->pacotes as $pacote) {
                // A task-raiz do projeto (uid=0, "curva geral do
                // empreendimento") às vezes vem com <Name> vazio no MSPDI —
                // usa o nome da própria obra como fallback, pra não sobrar
                // um pacote sem nome no topo da EAP.
                $nome = ($pacote->uid === '0' && trim($pacote->nome) === '')
                    ? $obra->name
                    : $pacote->nome;

                $pt = PacoteTrabalho::updateOrCreate(
                    ['obra_id' => $obra->id, 'external_uid' => $pacote->uid],
                    ['nome' => $nome, 'codigo' => $pacote->codigo]
                );
                $mapaPacotes[$pacote->uid] = $pt->id;
            }

            // Resolver parent_id em segunda passagem
            foreach ($plano->pacotes as $pacote) {
                if ($pacote->parentUid !== null && isset($mapaPacotes[$pacote->parentUid])) {
                    PacoteTrabalho::where('id', $mapaPacotes[$pacote->uid])
                        ->update(['parent_id' => $mapaPacotes[$pacote->parentUid]]);
                }
            }

            // --- 2. Snapshot da importação ---
            $importacao = CronogramaImportacao::create([
                'obra_id'             => $obra->id,
                'user_id'             => $userId,
                'arquivo'             => $arquivo,
                'data_status'         => $plano->dataStatus,
                'metodo_distribuicao' => self::METODO,
                'tipo'                => $tipo->value,
                'criadas'             => count($plano->criar),
                'atualizadas'         => count($plano->atualizar),
                'removidas'           => count($plano->removerIds),
                'importado_em'        => now(),
            ]);

            // --- 3. Upsert Atividades ---
            $mapaAtividades   = [];
            $mapaDisciplinas  = [];
            $mapaFrentes      = [];
            $mapaEtapas       = [];
            $mapaEntregaveis  = [];
            $mapaEquipesResponsaveis = [];
            $mapaPersonalizados1 = [];
            $mapaPersonalizados2 = [];
            $mapaPersonalizados3 = [];
            $mapaPersonalizados4 = [];
            $mapaPersonalizados5 = [];
            $loteSnapshots    = [];
            $idsCompletos100  = [];
            $agora = now();

            // Modo Avanço: casamento por external_uid já foi resolvido em
            // analisar() (só chegam aqui tarefas de $plano->atualizar, já
            // que $plano->criar vem forçado vazio) — recarrega o mapa
            // uid->id pra escrever com update() direto, nunca updateOrCreate.
            $mapaExistentesAvanco = $tipo === TipoCronogramaImportacao::Avanco
                ? Atividade::where('obra_id', $obra->id)
                    ->where('origem', OrigemAtividade::MsProject)
                    ->pluck('id', 'external_uid')
                    ->all()
                : [];

            foreach (array_merge($plano->criar, $plano->atualizar) as $tarefa) {
                if ($tipo === TipoCronogramaImportacao::Avanco) {
                    $atividadeId = $mapaExistentesAvanco[$tarefa->uid] ?? null;
                    if ($atividadeId === null) {
                        // Segurança extra: não deveria acontecer, já que
                        // analisar() só manda pra cá tarefas já casadas.
                        continue;
                    }

                    // Só campos de PROGRESSO — nunca baseline, pacote,
                    // código, nome ou classificação, que continuam vindo
                    // exclusivamente da importação de Linha de Base.
                    Atividade::where('id', $atividadeId)->update([
                        'real_horas'           => $tarefa->realHoras,
                        'work_horas'           => $tarefa->workHoras,
                        'real_inicio'          => $tarefa->realInicio?->toDateString(),
                        'real_termino'         => $tarefa->realTermino?->toDateString(),
                        'data_termino'         => $tarefa->dataTermino?->toDateString(),
                        'percentual_concluido' => $tarefa->percentualConcluido,
                        'caminho_critico'      => $tarefa->caminhoCritico,
                        'is_marco'             => $tarefa->isMarco,
                        'external_synced_at'   => $agora,
                    ]);

                    $at = Atividade::find($atividadeId);
                } else {
                    // Classificação automática via campos customizados do MS Project:
                    // Texto20 = etapa, Texto21 = disciplina, Texto22 = frente de trabalho.
                    // O nome do campo pode vir em português ("Texto21") ou inglês
                    // ("Text21") dependendo do idioma do MS Project do usuário — por
                    // isso o helper TextoCustomizado::valor() tenta as duas grafias. Só
                    // classifica quando o XML traz o valor — não apaga classificação
                    // manual existente.
                    $dadosClassificacao = [];

                    $nomeEtapa = TextoCustomizado::valor($tarefa->textos, 20);
                    if ($nomeEtapa !== '') {
                        $dadosClassificacao['etapa_id'] = $mapaEtapas[$nomeEtapa]
                            ??= Etapa::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomeEtapa])->id;
                    }

                    $nomeDisciplina = TextoCustomizado::valor($tarefa->textos, 21);
                    if ($nomeDisciplina !== '') {
                        $dadosClassificacao['disciplina_id'] = $mapaDisciplinas[$nomeDisciplina]
                            ??= Disciplina::firstOrCreate(['nome' => $nomeDisciplina])->id;
                    }

                    $nomeFrente = TextoCustomizado::valor($tarefa->textos, 22);
                    if ($nomeFrente !== '') {
                        $dadosClassificacao['frente_trabalho_id'] = $mapaFrentes[$nomeFrente]
                            ??= FrenteTrabalho::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomeFrente])->id;
                    }

                    // Texto23 = faturamento direto (booleano, não é lookup);
                    // Texto24 = entregável; Texto25 = equipe/responsável;
                    // Texto26-30 = personalizado 1-5 — mesmo mecanismo de
                    // Texto20-22 acima (só classifica quando o XML traz valor).
                    $textoFaturamento = TextoCustomizado::valor($tarefa->textos, 23);
                    if ($textoFaturamento !== '') {
                        $dadosClassificacao['faturamento_direto'] = mb_strtolower($textoFaturamento) === 'sim';
                    }

                    $nomeEntregavel = TextoCustomizado::valor($tarefa->textos, 24);
                    if ($nomeEntregavel !== '') {
                        $dadosClassificacao['entregavel_id'] = $mapaEntregaveis[$nomeEntregavel]
                            ??= Entregavel::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomeEntregavel])->id;
                    }

                    $nomeEquipeResponsavel = TextoCustomizado::valor($tarefa->textos, 25);
                    if ($nomeEquipeResponsavel !== '') {
                        $dadosClassificacao['equipe_responsavel_id'] = $mapaEquipesResponsaveis[$nomeEquipeResponsavel]
                            ??= EquipeResponsavel::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomeEquipeResponsavel])->id;
                    }

                    $nomePersonalizado1 = TextoCustomizado::valor($tarefa->textos, 26);
                    if ($nomePersonalizado1 !== '') {
                        $dadosClassificacao['personalizado_1_id'] = $mapaPersonalizados1[$nomePersonalizado1]
                            ??= Personalizado1::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomePersonalizado1])->id;
                    }

                    $nomePersonalizado2 = TextoCustomizado::valor($tarefa->textos, 27);
                    if ($nomePersonalizado2 !== '') {
                        $dadosClassificacao['personalizado_2_id'] = $mapaPersonalizados2[$nomePersonalizado2]
                            ??= Personalizado2::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomePersonalizado2])->id;
                    }

                    $nomePersonalizado3 = TextoCustomizado::valor($tarefa->textos, 28);
                    if ($nomePersonalizado3 !== '') {
                        $dadosClassificacao['personalizado_3_id'] = $mapaPersonalizados3[$nomePersonalizado3]
                            ??= Personalizado3::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomePersonalizado3])->id;
                    }

                    $nomePersonalizado4 = TextoCustomizado::valor($tarefa->textos, 29);
                    if ($nomePersonalizado4 !== '') {
                        $dadosClassificacao['personalizado_4_id'] = $mapaPersonalizados4[$nomePersonalizado4]
                            ??= Personalizado4::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomePersonalizado4])->id;
                    }

                    $nomePersonalizado5 = TextoCustomizado::valor($tarefa->textos, 30);
                    if ($nomePersonalizado5 !== '') {
                        $dadosClassificacao['personalizado_5_id'] = $mapaPersonalizados5[$nomePersonalizado5]
                            ??= Personalizado5::firstOrCreate(['obra_id' => $obra->id, 'nome' => $nomePersonalizado5])->id;
                    }

                    $at = Atividade::updateOrCreate(
                        ['obra_id' => $obra->id, 'external_uid' => $tarefa->uid],
                        $dadosClassificacao + [
                            'obra_id'            => $obra->id,
                            'nome'               => $tarefa->nome,
                            'pacote_trabalho_id' => $tarefa->parentUid !== null
                                ? ($mapaPacotes[$tarefa->parentUid] ?? null)
                                : null,
                            'codigo_cronograma'  => $tarefa->codigo,
                            'inicio_planejado'   => $tarefa->dataInicio?->toDateString(),
                            'data_termino'       => $tarefa->dataTermino?->toDateString(),
                            'baseline_inicio'    => $tarefa->baselineInicio?->toDateString(),
                            'baseline_termino'   => $tarefa->baselineTermino?->toDateString(),
                            'real_inicio'        => $tarefa->realInicio?->toDateString(),
                            'real_termino'       => $tarefa->realTermino?->toDateString(),
                            'baseline_horas'     => $tarefa->baselineHoras,
                            'work_horas'         => $tarefa->workHoras,
                            'real_horas'         => $tarefa->realHoras,
                            'textos'             => $tarefa->textos ?: null,
                            'caminho_critico'    => $tarefa->caminhoCritico,
                            'percentual_concluido' => $tarefa->percentualConcluido,
                            'is_marco'           => $tarefa->isMarco,
                            'origem'             => OrigemAtividade::MsProject->value,
                            'fora_do_cronograma' => false,
                            'external_synced_at' => $agora,
                        ]
                    );
                }

                $mapaAtividades[$tarefa->uid] = $at->id;

                if ($tarefa->percentualConcluido !== null && $tarefa->percentualConcluido >= 100) {
                    $idsCompletos100[] = $at->id;
                }

                // Snapshot histórico desta tarefa nesta importação — permite
                // reconstruir "qual era a tendência/baseline na importação X"
                // mais tarde, já que a linha de Atividade só guarda o valor atual.
                $loteSnapshots[] = [
                    'id'                       => (string) Str::ulid(),
                    'tenant_id'                => $obra->tenant_id,
                    'cronograma_importacao_id' => $importacao->id,
                    'atividade_id'             => $at->id,
                    'inicio_planejado'         => $at->inicio_planejado?->toDateString(),
                    'data_termino'             => $at->data_termino?->toDateString(),
                    'baseline_inicio'          => $at->baseline_inicio?->toDateString(),
                    'baseline_termino'         => $at->baseline_termino?->toDateString(),
                    'created_at'               => $agora,
                    'updated_at'               => $agora,
                ];
            }

            if (! empty($loteSnapshots)) {
                foreach (array_chunk($loteSnapshots, 1000) as $chunk) {
                    DB::table('atividade_snapshots')->insert($chunk);
                }
            }

            // --- 4. Arquivar removidas (nunca apagar) ---
            if ($plano->removerIds) {
                Atividade::whereIn('id', $plano->removerIds)->update([
                    'fora_do_cronograma' => true,
                    'external_synced_at' => $agora,
                ]);
            }

            // --- 5. Bulk insert avanco_periodos em chunks de 1.000 ---
            $tenantId = $obra->tenant_id;
            $lote = [];

            foreach ($plano->horasPeriodos as $hp) {
                if (!isset($mapaAtividades[$hp->atividadeUid])) {
                    continue;
                }

                $lote[] = [
                    'id'                       => (string) Str::ulid(),
                    'tenant_id'                => $tenantId,
                    'cronograma_importacao_id' => $importacao->id,
                    'atividade_id'             => $mapaAtividades[$hp->atividadeUid],
                    'granularidade'            => $hp->granularidade->value,
                    'serie'                    => $hp->serie->value,
                    'periodo_inicio'           => $hp->periodoInicio,
                    'horas'                    => $hp->horas,
                    'created_at'               => $agora,
                    'updated_at'               => $agora,
                ];

                if (count($lote) >= 1000) {
                    DB::table('avanco_periodos')->insert($lote);
                    $lote = [];
                }
            }

            if ($lote) {
                DB::table('avanco_periodos')->insert($lote);
            }

            // --- 6. Atividades que atingiram 100% não fazem sentido com
            // restrições abertas ou itens de prontidão pendentes.
            ConclusaoAutomaticaAtividades::aplicar($idsCompletos100, $obra->id, $userId);

            // --- 7. inicio_planejado pode ter mudado (reimportação de
            // Linha de Base) — recalcula Tendência/status/restrição de
            // qualquer item de suprimento vinculado às atividades tocadas.
            SincronizarRestricaoSuprimento::aplicarParaAtividades(array_values($mapaAtividades), $userId);

            return $importacao;
        });
    }

    // =========================================================================
    // Parsing — passagem 1: mapa de recursos de Trabalho
    // =========================================================================

    /**
     * Retorna mapa [uid => true] dos recursos com Type=1 (Trabalho).
     * Regra 1: só atribuições a recurso de Trabalho geram HH.
     */
    private function parsearRecursosTipo1(string $arquivo): array
    {
        $trabalho = [];
        $reader = new XMLReader();
        $reader->open($arquivo);

        $emRecursos = false;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->localName === 'Resources'
            ) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'Resources') {
                $emRecursos = true;
                continue;
            }

            if ($emRecursos && $reader->localName === 'Resource') {
                $el = simplexml_load_string($this->semNamespace($reader->readOuterXML()));
                if ((string)($el->Type ?? '') === '1') {
                    $trabalho[(string)$el->UID] = true;
                }
            }
        }

        $reader->close();

        return $trabalho;
    }

    // =========================================================================
    // Parsing — passagem 2: tarefas + atribuições faseadas
    // =========================================================================

    /**
     * @param array<string, true> $recursosTrabalho
     * @return array{TarefaImportada[], HorasPeriodo[], ?Carbon}
     */
    private function parsearTarefasEAtribuicoes(string $arquivo, array $recursosTrabalho): array
    {
        $reader = new XMLReader();
        $reader->open($arquivo);

        $dataStatus    = null;
        $mapaTextosHdr = [];   // fieldId => fieldName
        $tarefasRaw    = [];   // uid => array
        $outlineToUid  = [];   // outlineNumber => uid
        $horasPeriodos = [];
        $secao         = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT) {
                if (in_array($reader->localName, ['ExtendedAttributes', 'Tasks', 'Assignments'], true)) {
                    $secao = null;
                }
                continue;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            // StatusDate (filho direto de Project, antes de qualquer secção)
            if ($reader->localName === 'StatusDate' && $secao === null) {
                $reader->read();
                $val = trim($reader->value);
                if ($val !== '') {
                    $dataStatus = Carbon::parse($val);
                }
                continue;
            }

            // Abertura das secções
            match ($reader->localName) {
                'ExtendedAttributes' => $secao = 'header_ext',
                'Tasks'              => $secao = 'tasks',
                'Assignments'        => $secao = 'assignments',
                default              => null,
            };

            // ----------------------------------------------------------------
            // ExtendedAttribute do cabeçalho (mapa FieldID → FieldName)
            // ----------------------------------------------------------------
            if ($secao === 'header_ext' && $reader->localName === 'ExtendedAttribute') {
                $el = simplexml_load_string($this->semNamespace($reader->readOuterXML()));
                $id = (string)($el->FieldID ?? '');
                $nm = (string)($el->FieldName ?? '');
                if ($id !== '' && $nm !== '') {
                    $mapaTextosHdr[$id] = $nm;
                }
                continue;
            }

            // ----------------------------------------------------------------
            // Task
            // ----------------------------------------------------------------
            if ($secao === 'tasks' && $reader->localName === 'Task') {
                $el  = simplexml_load_string($this->semNamespace($reader->readOuterXML()));
                $uid = (string)($el->UID ?? '');

                // UID vazio/inválido nunca deveria acontecer em XML válido —
                // guarda defensiva. UID='0' é a task-raiz do projeto (o
                // "empreendimento" inteiro no MS Project) — NÃO pula mais:
                // vira o pacote-raiz de toda a EAP (ver isSummary/codigo
                // forçados abaixo, já que essa task às vezes vem com
                // <Summary> e <OutlineNumber> vazios/inconsistentes
                // dependendo da versão do MS Project).
                if ($uid === '') {
                    continue;
                }

                $ehRaizDoProjeto = $uid === '0';

                $outline = $ehRaizDoProjeto ? '0' : (string)($el->OutlineNumber ?? '');
                if ($outline !== '') {
                    $outlineToUid[$outline] = $uid;
                }

                $baseline = $this->baselinePrincipal($el);

                $tarefasRaw[$uid] = [
                    'uid'             => $uid,
                    'nome'            => (string)($el->Name ?? ''),
                    'isSummary'       => $ehRaizDoProjeto || ((string)($el->Summary ?? '0')) === '1',
                    'isMarco'         => ((string)($el->Milestone  ?? '0')) === '1',
                    'caminhoCritico'  => ((string)($el->Critical   ?? '0')) === '1',
                    'outline'         => $outline,
                    'codigo'          => $outline,
                    'dataInicio'      => $this->parseData((string)($el->Start        ?? '')),
                    'dataTermino'     => $this->parseData((string)($el->Finish       ?? '')),
                    'baselineInicio'  => $this->parseData((string)($baseline?->Start  ?? '')),
                    'baselineTermino' => $this->parseData((string)($baseline?->Finish ?? '')),
                    'realInicio'      => $this->parseData((string)($el->ActualStart  ?? '')),
                    'realTermino'     => $this->parseData((string)($el->ActualFinish ?? '')),
                    'baselineHoras'   => $this->horas((string)($baseline?->Work ?? '')),
                    'workHoras'       => $this->horas((string)($el->Work        ?? '')),
                    'realHoras'       => $this->horas((string)($el->ActualWork  ?? '')),
                    'percentualConcluido' => $this->percentualTrabalho($el),
                    'textos'          => $this->textosDaTarefa($el, $mapaTextosHdr),
                    // --- Dados estruturais (Fase 2A do Health Check, aditivo) ---
                    'predecessoras'       => $this->parsearPredecessoras($el),
                    'totalSlack'          => $this->intOuNulo((string)($el->TotalSlack ?? '')),
                    'freeSlack'           => $this->intOuNulo((string)($el->FreeSlack ?? '')),
                    'constraintTypeCodigo' => $this->intOuNulo((string)($el->ConstraintType ?? '')),
                    'dataRestricao'       => $this->parseData((string)($el->ConstraintDate ?? '')),
                    // <Active> ausente do XML NUNCA é tratado como inativa —
                    // só '0' explícito marca a tarefa como inativa.
                    'ativa'               => ((string)($el->Active ?? '')) !== '0',
                    // --- Modo de agendamento (Fase 2B.2A do Health Check, aditivo) ---
                    'agendamentoManualBruto' => $this->stringOuNulo((string)($el->Manual ?? '')),
                ];
                continue;
            }

            // ----------------------------------------------------------------
            // Assignment
            // ----------------------------------------------------------------
            if ($secao === 'assignments' && $reader->localName === 'Assignment') {
                $el          = simplexml_load_string($this->semNamespace($reader->readOuterXML()));
                $resourceUid = (string)($el->ResourceUID ?? '');
                $taskUid     = (string)($el->TaskUID ?? '');

                // Regra 1: só recurso de Trabalho (Type=1)
                if (!isset($recursosTrabalho[$resourceUid]) || $taskUid === '' || $taskUid === '0') {
                    continue;
                }

                foreach ($el->TimephasedData as $td) {
                    $tipo   = (int)(string)($td->Type ?? -1);
                    $series = $this->seriesDoTipo($tipo);

                    if (empty($series)) {
                        continue;
                    }

                    // Regra 2: Value é DURAÇÃO (PT..H..M..S)
                    $horas = $this->horas((string)($td->Value ?? ''));
                    if ($horas <= 0) {
                        continue;
                    }

                    $inicio = Carbon::parse((string)($td->Start ?? ''));
                    $fim    = Carbon::parse((string)($td->Finish ?? ''));

                    // Regra 3: distribuição pelo ponto médio do bloco
                    $medio = $inicio->copy()->addSeconds(
                        (int)($fim->diffInSeconds($inicio) / 2)
                    );

                    $semanal = $medio->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
                    $mensal  = $medio->copy()->startOfMonth()->toDateString();

                    foreach ($series as $serie) {
                        $horasPeriodos[] = new HorasPeriodo(
                            atividadeUid: $taskUid,
                            serie: $serie,
                            granularidade: GranularidadePeriodo::Semanal,
                            periodoInicio: $semanal,
                            horas: $horas,
                        );
                        $horasPeriodos[] = new HorasPeriodo(
                            atividadeUid: $taskUid,
                            serie: $serie,
                            granularidade: GranularidadePeriodo::Mensal,
                            periodoInicio: $mensal,
                            horas: $horas,
                        );
                    }
                }
                continue;
            }
        }

        $reader->close();

        // Montar TarefaImportada com parentUid resolvido via OutlineNumber
        $tarefas = array_values(array_map(
            fn(array $raw) => $this->montarTarefa($raw, $outlineToUid),
            $tarefasRaw
        ));

        return [$tarefas, $horasPeriodos, $dataStatus];
    }

    // =========================================================================
    // Helpers de parsing
    // =========================================================================

    private function montarTarefa(array $raw, array $outlineToUid): TarefaImportada
    {
        $parentUid = null;
        $outline   = $raw['outline'];

        if ($outline !== '' && str_contains($outline, '.')) {
            $partes = explode('.', $outline);
            array_pop($partes);
            $parentOutline = implode('.', $partes);
            $parentUid = $outlineToUid[$parentOutline] ?? null;
        } elseif ($raw['uid'] !== '0' && isset($outlineToUid['0'])) {
            // Pacote de nível 1 (outline sem ponto, ex.: "1", "2") — vira
            // filho da task-raiz do projeto (uid=0), fiel à hierarquia real
            // do MS Project. Só se aplica quando a raiz existe nesta mesma
            // importação (arquivos antigos sem UID=0 continuam como raízes
            // soltas, igual sempre foi).
            $parentUid = '0';
        }

        return new TarefaImportada(
            uid: $raw['uid'],
            nome: $raw['nome'],
            isSummary: $raw['isSummary'],
            isMarco: $raw['isMarco'],
            caminhoCritico: $raw['caminhoCritico'],
            parentUid: $parentUid,
            codigo: $raw['codigo'],
            dataInicio: $raw['dataInicio'],
            dataTermino: $raw['dataTermino'],
            baselineInicio: $raw['baselineInicio'],
            baselineTermino: $raw['baselineTermino'],
            realInicio: $raw['realInicio'],
            realTermino: $raw['realTermino'],
            baselineHoras: $raw['baselineHoras'],
            workHoras: $raw['workHoras'],
            realHoras: $raw['realHoras'],
            percentualConcluido: $raw['percentualConcluido'],
            textos: $raw['textos'],
            predecessoras: $raw['predecessoras'],
            totalSlack: $raw['totalSlack'],
            freeSlack: $raw['freeSlack'],
            tipoRestricao: $raw['constraintTypeCodigo'] !== null
                ? TipoRestricaoCronograma::fromCodigoMsProject($raw['constraintTypeCodigo'])
                : null,
            tipoRestricaoCodigoOriginal: $raw['constraintTypeCodigo'],
            dataRestricao: $raw['dataRestricao'],
            ativa: $raw['ativa'],
            agendamentoManual: $this->boolOuNulo($raw['agendamentoManualBruto']),
            agendamentoManualBruto: $raw['agendamentoManualBruto'],
        );
    }

    /** Remove xmlns default para que simplexml_load_string acesse elementos diretamente. */
    private function semNamespace(string $xml): string
    {
        return preg_replace('/\sxmlns="[^"]*"/', '', $xml, 1);
    }

    /**
     * Extrai as relações de predecessora (<PredecessorLink>, 0 a N por
     * tarefa) — Fase 2A do Health Check. LinkLag/LagFormat são preservados
     * brutos, sem conversão de unidade (ver PredecessoraLink). Type ausente
     * assume FS (1), o default do próprio MS Project pra vínculos novos.
     */
    private function parsearPredecessoras(SimpleXMLElement $task): array
    {
        $predecessoras = [];

        foreach ($task->PredecessorLink as $link) {
            $predecessoraUid = (string)($link->PredecessorUID ?? '');
            if ($predecessoraUid === '') {
                continue;
            }

            // Type ausente assume FS (1) — default do próprio MS Project pra vínculos novos.
            $tipoCodigo = $this->intOuNulo((string)($link->Type ?? '')) ?? 1;

            $predecessoras[] = new PredecessoraLink(
                predecessoraUid: $predecessoraUid,
                tipo: TipoRelacionamentoPredecessora::fromCodigoMsProject($tipoCodigo),
                tipoCodigoOriginal: $tipoCodigo,
                linkLag: $this->intOuNulo((string)($link->LinkLag ?? '')),
                lagFormat: $this->intOuNulo((string)($link->LagFormat ?? '')),
            );
        }

        return $predecessoras;
    }

    /** Converte string vazia (elemento ausente) em null; senão faz cast pra int. Nunca trata ausência como zero. */
    private function intOuNulo(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }

    /** Converte string vazia (elemento ausente) em null; preserva o valor bruto como veio do XML nos demais casos. */
    private function stringOuNulo(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Normaliza um valor booleano bruto do MSPDI (<Manual>, tipo xs:boolean —
     * aceita tanto '1'/'0' quanto 'true'/'false' conforme o schema) em
     * true/false/null. Qualquer valor fora desses 4 literais (ou ausência)
     * vira null — nunca inventa um sentido pra um valor inesperado.
     */
    private function boolOuNulo(?string $valorBruto): ?bool
    {
        return match ($valorBruto) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };
    }

    /** Localiza o Baseline principal (Number === '0') da tarefa. */
    private function baselinePrincipal(SimpleXMLElement $task): ?SimpleXMLElement
    {
        foreach ($task->Baseline as $bl) {
            if ((string)($bl->Number ?? '') === '0') {
                return $bl;
            }
        }
        return null;
    }

    /**
     * Converte duração ISO 8601 (PT..H..M..S) em horas decimais.
     * Regra 2: o Value do TimephasedData é DURAÇÃO, não minutos.
     */
    private function horas(string $value): float
    {
        if ($value === '') {
            return 0.0;
        }

        if (!preg_match('/PT(?:([\d.]+)H)?(?:([\d.]+)M)?(?:([\d.]+)S)?/i', $value, $m)) {
            return 0.0;
        }

        $h = isset($m[1]) && $m[1] !== '' ? (float)$m[1] : 0.0;
        $min = isset($m[2]) && $m[2] !== '' ? (float)$m[2] : 0.0;
        $sec = isset($m[3]) && $m[3] !== '' ? (float)$m[3] : 0.0;

        return round($h + $min / 60 + $sec / 3600, 2);
    }

    /** Mapeia tipo de TimephasedData para as séries de avanço. */
    private function seriesDoTipo(int $tipo): array
    {
        return match ($tipo) {
            self::TP_BASELINE  => [SerieAvanco::Previsto],
            self::TP_ACTUAL    => [SerieAvanco::Realizado, SerieAvanco::Tendencia],
            self::TP_REMAINING => [SerieAvanco::Tendencia],
            default            => [],
        };
    }

    private function parseData(string $value): ?Carbon
    {
        if ($value === '' || str_starts_with(strtoupper($value), 'NA')) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * "% de trabalho concluído" (PercentWorkComplete do MSPDI) — campo nativo
     * do MS Project, diferente do Texto customizado. Ausente no XML vira
     * `null` (informação desconhecida), não `0` (que significaria "0% feito").
     */
    private function percentualTrabalho(SimpleXMLElement $task): ?float
    {
        $valor = (string) ($task->PercentWorkComplete ?? '');

        return $valor === '' ? null : (float) $valor;
    }

    /** Extrai campos Text20..Text30 da tarefa usando o mapa do cabeçalho. */
    private function textosDaTarefa(SimpleXMLElement $task, array $mapaTextosHdr): array
    {
        $textos = [];
        foreach ($task->ExtendedAttribute as $attr) {
            $fieldId = (string)($attr->FieldID ?? '');
            $value   = (string)($attr->Value   ?? '');
            if ($fieldId !== '' && $value !== '' && isset($mapaTextosHdr[$fieldId])) {
                $textos[$mapaTextosHdr[$fieldId]] = $value;
            }
        }
        return $textos;
    }
}
