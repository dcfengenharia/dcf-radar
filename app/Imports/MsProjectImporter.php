<?php

namespace App\Imports;

use App\DTOs\HorasPeriodo;
use App\DTOs\PlanoImportacao;
use App\DTOs\PredecessoraLink;
use App\DTOs\TarefaImportada;
use App\Enums\EventoFotografiaProgramacao;
use App\Enums\GranularidadePeriodo;
use App\Enums\OrigemAtividade;
use App\Enums\StatusAtividade;
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
use App\Models\AtividadeItemProntidao;
use App\Models\ItemProntidao;
use App\Models\ProgramacaoSemanal;
use App\Models\ProgramacaoSemanalItem;
use App\Models\Restricao;
use App\Models\Work;
use App\Services\DetectorInconsistenciasAvanco;
use App\Support\SincronizarRestricaoCadeiaSuprimento;
use App\Support\SincronizarRestricaoSuprimento;
use App\Support\TextoCustomizado;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
            $agora = now();

            // Ciclo 24 — atividade_id => Carbon (concluido_em) para toda
            // atividade reconciliada como fisicamente concluída NESTA
            // importação (ver regra canônica dentro do loop abaixo). Usado
            // depois do loop pra atender automaticamente os itens de
            // prontidão aplicáveis — nunca pra fechar Restrição.
            $atividadesReconciliadas = [];

            // Ciclo 24 — atividade_id => percentual_concluido ANTES desta
            // importação (só quando Avanço/Ambos), repassado ao Detector
            // pra ele conseguir sinalizar regressão de percentual numa
            // atividade cujo status já era Concluído — sem precisar
            // reconsultar Atividade ao vivo (o Detector continua um
            // serviço puro de F×O×P).
            $percentuaisAntesPorAtividade = [];

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

            // Ciclo 17, A.9.3/A.9.4.HARDENING — Fotografia O: TUDO que a
            // plataforma sabia sobre cada atividade ANTES desta importação
            // tocá-la — status/fora_do_cronograma, pronta, restrições
            // pendentes e itens de prontidão pendentes — é capturado num
            // ÚNICO instante, em lote, ANTES do loop de upsert. Antes do
            // hardening, status/fora_do_cronograma já eram lidos aqui, mas
            // pronta/restrições/prontidão só eram calculados DEPOIS do
            // upsert (dentro de gravarFotografiaOperacional()) — seguro só
            // porque o loop de upsert nunca escreve em Restricao/
            // ItemProntidao/AtividadeItemProntidao, mas uma suposição
            // implícita, não uma invariante garantida. Agora toda a
            // Fotografia O representa literalmente o mesmo instante,
            // eliminando essa suposição. Só relevante pra Avanço/Ambos —
            // Baseline pura não recebe Fotografia O (ver CLAUDE.md).
            // Atividade ausente destes mapas (criada NESTA própria
            // importação) fica sem "antes" — null/vazio, nunca um valor
            // inventado (não existia pra ter pronta/pendência nenhuma).
            $capturarFotografiaO = in_array($tipo, [TipoCronogramaImportacao::Avanco, TipoCronogramaImportacao::Ambos], true);
            $estadoOperacionalAntes = collect();
            $idsProntasAntes = [];
            $restricoesPendentesAntesPorAtividade = collect();
            $prontidaoPendenteAntesPorAtividade = [];

            if ($capturarFotografiaO) {
                $estadoOperacionalAntes = Atividade::where('obra_id', $obra->id)
                    ->where('origem', OrigemAtividade::MsProject)
                    ->get(['id', 'external_uid', 'status', 'fora_do_cronograma', 'real_inicio', 'real_termino', 'percentual_concluido'])
                    ->keyBy('external_uid');

                $atividadeIdsExistentesAntes = $estadoOperacionalAntes->pluck('id')->all();

                if (! empty($atividadeIdsExistentesAntes)) {
                    // `pronta` reaproveita literalmente Atividade::scopeProntas()
                    // (mesma fonte canônica de sempre) — só que agora resolvida
                    // ANTES do upsert, sobre os IDs que já existiam.
                    $idsProntasAntes = array_flip(
                        Atividade::query()
                            ->where('obra_id', $obra->id)
                            ->whereIn('id', $atividadeIdsExistentesAntes)
                            ->prontas()
                            ->pluck('id')
                            ->all()
                    );

                    // Mesmo conjunto canônico de status "aberta" usado por
                    // Atividade::estaPronta()/scopeProntas() — nunca uma lista nova.
                    $restricoesPendentesAntesPorAtividade = Restricao::whereIn('atividade_id', $atividadeIdsExistentesAntes)
                        ->whereIn('status', ['aberta', 'em_tratamento', 'aguardando_terceiros'])
                        ->get(['id', 'atividade_id', 'bloqueante', 'status'])
                        ->groupBy('atividade_id');

                    $itensObraAntes = ItemProntidao::where('obra_id', $obra->id)->get(['id']);

                    if ($itensObraAntes->isNotEmpty()) {
                        // Só rows com concluido=true contam como "feito" —
                        // mesma regra canônica de estaPronta(): ausência de
                        // row também é pendente.
                        $concluidosAntesPorAtividade = AtividadeItemProntidao::whereIn('atividade_id', $atividadeIdsExistentesAntes)
                            ->where('concluido', true)
                            ->get(['atividade_id', 'item_prontidao_id'])
                            ->groupBy('atividade_id');

                        // Rows explicitamente concluido=false — só pra
                        // preservar o ID da row real quando ela existir
                        // (nunca inventado quando não existe).
                        $pendentesExplicitosAntesPorAtividade = AtividadeItemProntidao::whereIn('atividade_id', $atividadeIdsExistentesAntes)
                            ->where('concluido', false)
                            ->get(['id', 'atividade_id', 'item_prontidao_id'])
                            ->groupBy('atividade_id');

                        foreach ($atividadeIdsExistentesAntes as $atividadeIdAntes) {
                            $idsConcluidos = $concluidosAntesPorAtividade->get($atividadeIdAntes, collect())
                                ->pluck('item_prontidao_id')->all();
                            $pendentesExplicitosPorItem = $pendentesExplicitosAntesPorAtividade->get($atividadeIdAntes, collect())
                                ->keyBy('item_prontidao_id');

                            $pendentes = [];
                            foreach ($itensObraAntes as $item) {
                                if (in_array($item->id, $idsConcluidos, true)) {
                                    continue;
                                }
                                $pendentes[] = [
                                    'item_prontidao_id' => $item->id,
                                    'atividade_item_prontidao_id' => $pendentesExplicitosPorItem->get($item->id)?->id,
                                ];
                            }

                            if (! empty($pendentes)) {
                                $prontidaoPendenteAntesPorAtividade[$atividadeIdAntes] = $pendentes;
                            }
                        }
                    }
                }
            }

            foreach (array_merge($plano->criar, $plano->atualizar) as $tarefa) {
                // Ciclo 24 — fatos "antes" desta tarefa (só populados
                // quando Avanço/Ambos, ver captura de Fotografia O acima).
                // Usados tanto pra nunca apagar silenciosamente uma data
                // real já conhecida (real_inicio/real_termino ausentes no
                // XML desta rodada) quanto pra decidir se esta importação
                // reconcilia a Atividade como fisicamente concluída.
                $antesReconciliacao = $estadoOperacionalAntes->get($tarefa->uid);
                $realInicioResultante = $tarefa->realInicio?->toDateString()
                    ?? $antesReconciliacao?->real_inicio?->toDateString();
                $realTerminoResultante = $tarefa->realTermino?->toDateString()
                    ?? $antesReconciliacao?->real_termino?->toDateString();

                // Regra canônica de "conclusão física importada" (Ciclo 24,
                // revisada após auditoria adversarial — NUNCA usar o
                // DetectorInconsistenciasAvanco::$concluiu, que é uma união
                // permissiva OR usada só pra fins evidenciais/detecção,
                // nunca pra sincronizar estado): PercentWorkComplete >= 100%
                // é o ÚNICO sinal autoritativo pra reconciliar status/
                // concluido_em/prontidão. ActualFinish sozinho (sem
                // percentual=100%, ex.: 80%+ActualFinish ou 0%+ActualFinish)
                // NUNCA sincroniza a atividade como concluída — o MSPDI
                // permite essa divergência genuinamente (parser independente,
                // sem validação cruzada), e tratá-la como "concluída" seria
                // inventar um fato que o próprio percentual contradiz.
                // Divergência (ActualFinish presente + percentual<100) vira
                // evidência explícita — ver DetectorInconsistenciasAvanco::
                // TerminoRealComPercentualIncompleto — nunca é silenciosamente
                // ignorada nem silenciosamente tratada como conclusão.
                $concluiuFisicamente = $tarefa->percentualConcluido !== null && (float) $tarefa->percentualConcluido >= 100.0;

                // Só sincroniza status/concluido_em na transição — uma
                // atividade já Concluída (manual ou de importação anterior)
                // nunca tem seu status/concluido_em/prontidão retocados de
                // novo aqui, mesmo que esta importação regrida o percentual
                // (100% → 80%) ou confirme a conclusão de novo. Preserva o
                // histórico já registrado sem reescrita silenciosa.
                $reconciliarConclusao = $capturarFotografiaO
                    && $concluiuFisicamente
                    && $antesReconciliacao?->status !== StatusAtividade::Concluido;

                $concluidoEmCalculado = null;
                if ($reconciliarConclusao) {
                    // Prioridade: 1) ActualFinish (fato físico); 2) data_status
                    // desta importação (o "as-of" mais confiável quando não há
                    // ActualFinish — ex.: 100% sem término real, DATE-003); 3)
                    // now() só como último recurso. Nunca usar now() quando há
                    // data física melhor disponível.
                    $concluidoEmCalculado = $realTerminoResultante !== null
                        ? Carbon::parse($realTerminoResultante)
                        : ($importacao->data_status !== null ? Carbon::parse($importacao->data_status) : $agora);
                }

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
                    $dadosAvanco = [
                        'real_horas'           => $tarefa->realHoras,
                        'work_horas'           => $tarefa->workHoras,
                        'real_inicio'          => $realInicioResultante,
                        'real_termino'         => $realTerminoResultante,
                        'data_termino'         => $tarefa->dataTermino?->toDateString(),
                        'percentual_concluido' => $tarefa->percentualConcluido,
                        'caminho_critico'      => $tarefa->caminhoCritico,
                        'is_marco'             => $tarefa->isMarco,
                        'external_synced_at'   => $agora,
                    ];

                    if ($reconciliarConclusao) {
                        // Ciclo 24 — regra de produto aprovada: cronograma
                        // reportou a atividade como fisicamente concluída,
                        // status é sincronizado aqui. Update em massa NUNCA
                        // dispara AtividadeObserver, então concluido_em é
                        // controlado com precisão total (nunca sobrescrito
                        // por now()). Restrição NUNCA é fechada
                        // automaticamente por isto (ver bloco 6 abaixo).
                        $dadosAvanco['status'] = StatusAtividade::Concluido->value;
                        $dadosAvanco['concluido_em'] = $concluidoEmCalculado;
                    }

                    Atividade::where('id', $atividadeId)->update($dadosAvanco);

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

                    $dadosUpsert = $dadosClassificacao + [
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
                        'real_inicio'        => $realInicioResultante,
                        'real_termino'       => $realTerminoResultante,
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
                    ];

                    if ($reconciliarConclusao) {
                        // Mesma regra do branch Avanço acima — aqui via
                        // updateOrCreate() (dispara AtividadeObserver), que
                        // preserva `concluido_em` explicitamente definido
                        // (isDirty) em vez de sobrescrever com now().
                        $dadosUpsert['status'] = StatusAtividade::Concluido->value;
                        $dadosUpsert['concluido_em'] = $concluidoEmCalculado;
                    }

                    $at = Atividade::updateOrCreate(
                        ['obra_id' => $obra->id, 'external_uid' => $tarefa->uid],
                        $dadosUpsert
                    );
                }

                if ($reconciliarConclusao) {
                    $atividadesReconciliadas[$at->id] = $concluidoEmCalculado;
                }

                if ($capturarFotografiaO) {
                    $percentuaisAntesPorAtividade[$at->id] = $antesReconciliacao?->percentual_concluido !== null
                        ? (float) $antesReconciliacao->percentual_concluido
                        : null;
                }

                $mapaAtividades[$tarefa->uid] = $at->id;

                // Snapshot histórico desta tarefa nesta importação — permite
                // reconstruir "qual era a tendência/baseline na importação X"
                // mais tarde, já que a linha de Atividade só guarda o valor atual.
                //
                // Ciclo 17, A.9.2 — Fotografia F: percentual_concluido/
                // real_inicio/real_termino vêm direto de $tarefa (a
                // TarefaImportada desta própria iteração), nunca de $at
                // relido após o upsert — queremos o fato exatamente como o
                // arquivo declarou nesta importação, não um efeito colateral
                // acidental do model ao vivo. $tarefa->percentualConcluido/
                // realInicio/realTermino são populados pelo parser de forma
                // uniforme (ActualStart/ActualFinish/PercentWorkComplete),
                // independente de $tipo — uma importação Baseline pura que
                // traga esses campos no XML também os registra aqui; isso é
                // só um FATO preservado, não torna a importação elegível
                // como Avanço/Tendência na UI (isso continua controlado
                // exclusivamente por CronogramaImportacao.tipo).
                $loteSnapshots[] = [
                    'id'                       => (string) Str::ulid(),
                    'tenant_id'                => $obra->tenant_id,
                    'cronograma_importacao_id' => $importacao->id,
                    'atividade_id'             => $at->id,
                    'inicio_planejado'         => $at->inicio_planejado?->toDateString(),
                    'data_termino'             => $at->data_termino?->toDateString(),
                    'baseline_inicio'          => $at->baseline_inicio?->toDateString(),
                    'baseline_termino'         => $at->baseline_termino?->toDateString(),
                    'percentual_concluido'     => $tarefa->percentualConcluido,
                    'real_inicio'              => $tarefa->realInicio?->toDateString(),
                    'real_termino'             => $tarefa->realTermino?->toDateString(),
                    'created_at'               => $agora,
                    'updated_at'               => $agora,
                ];
            }

            if (! empty($loteSnapshots)) {
                foreach (array_chunk($loteSnapshots, 1000) as $chunk) {
                    DB::table('atividade_snapshots')->insert($chunk);
                }
            }

            // --- 3a-bis. Ciclo 24 — reconciliação de prontidão para toda
            // atividade fisicamente concluída nesta importação (nunca fecha
            // Restrição — ver bloco 6 mais abaixo, que documenta essa
            // decisão de produto).
            if (! empty($atividadesReconciliadas)) {
                $this->reconciliarItensDeProntidao($obra, $importacao, $atividadesReconciliadas);
            }

            // --- 3b. Ciclo 17, A.9.3 — Fotografia O: estado operacional da
            // plataforma (Restrição/Prontidão/status) no instante desta
            // importação. Leitura pura, em lote — nunca altera Restricao,
            // AtividadeItemProntidao ou Atividade (zero autocorreção,
            // mesmo princípio da A.9.1).
            if ($capturarFotografiaO && ! empty($mapaAtividades)) {
                $this->gravarFotografiaOperacional(
                    $obra,
                    $importacao,
                    $mapaAtividades,
                    $estadoOperacionalAntes,
                    $idsProntasAntes,
                    $restricoesPendentesAntesPorAtividade,
                    $prontidaoPendenteAntesPorAtividade,
                    $agora,
                );

                // --- 3c. Ciclo 17, A.9.5 — Fotografia P: se cada atividade
                // que INICIOU/CONCLUIU (Fotografia F, `$loteSnapshots`
                // acabou de ser gravada acima) fazia parte da Programação
                // Semanal historicamente aplicável ao instante do evento.
                // Nunca lê/altera Programação Semanal ao vivo — só a
                // resolve historicamente e congela o resultado. Mesmo gate
                // de tipo de Fotografia O (só Avanço/Ambos).
                $this->capturarFotografiaProgramacao($obra, $importacao, $loteSnapshots, $agora);

                // --- 3d. Ciclo 17, A.9.4/A.9.5 — Detector de Inconsistências
                // de Avanço: compara F (recém-gravada acima) × O × P
                // (ambas recém-gravadas acima) desta MESMA importação. Roda
                // depois das três fotografias e ainda dentro desta
                // transação — se falhar, toda a importação reverte junto
                // (nunca existe importação sem suas inconsistências já
                // detectadas). Serviço puro: nunca altera Atividade/
                // Restricao/Prontidao/ProgramacaoSemanal/comentários.
                (new DetectorInconsistenciasAvanco())->detectar($importacao, $percentuaisAntesPorAtividade);
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

            // --- 6. Ciclo 17, A.9.1 / Ciclo 24 — NÃO auto-resolve Restrição.
            // Desde o Ciclo 24, uma atividade fisicamente concluída (ver
            // regra canônica dentro do loop acima) TEM seu `status`/
            // `concluido_em` reconciliados e seus itens de prontidão
            // aplicáveis marcados como atendidos automaticamente
            // (`reconciliarItensDeProntidao()`, origem sempre auditável via
            // `atendido_pela_importacao_id`) — mas Restricao NUNCA é
            // encerrada automaticamente aqui, em nenhuma hipótese. Uma
            // Restrição aberta numa atividade agora concluída permanece
            // aberta, e o par (concluída + restrição aberta) já é capturado
            // como evidência pelo DetectorInconsistenciasAvanco
            // (ConclusaoComRestricaoPendente, a partir da Fotografia O
            // "antes") — cabe à análise humana decidir se resolve, mantém
            // ou trata a Restrição. Ver App\Support\ConclusaoAutomaticaAtividades
            // (mantida como ferramenta de saneamento legado explícito,
            // nunca chamada automaticamente daqui — ela TAMBÉM resolveria
            // Restrição, o que o Ciclo 24 continua proibindo).

            // --- 7. inicio_planejado pode ter mudado (reimportação de
            // Linha de Base) — recalcula Tendência/status/restrição de
            // qualquer item de suprimento vinculado às atividades tocadas.
            SincronizarRestricaoSuprimento::aplicarParaAtividades(array_values($mapaAtividades), $userId);

            // Ciclo 19, Etapa 19.7, seção 43 — mesmo evento, cadeia
            // PARALELA (nunca a mesma Restrição): necessidade por
            // Atividade pode ter mudado, exigindo reabrir/resolver a
            // Restrição automática da cadeia formal de Suprimentos.
            SincronizarRestricaoCadeiaSuprimento::aplicarParaAtividades(array_values($mapaAtividades), $userId);

            return $importacao;
        });
    }

    /**
     * Ciclo 24 — regra de produto aprovada: se o cronograma reporta uma
     * atividade como fisicamente concluída, as pré-condições operacionais
     * necessárias à execução foram vencidas de alguma forma na realidade,
     * mesmo que ninguém tenha atualizado o checklist no DCF.ENG antes. Cada
     * item de prontidão do catálogo da obra ainda pendente (ou nunca
     * registrado) para essas atividades é marcado `concluido=true` com
     * `concluido_por=null` (nunca simula uma marcação humana) e
     * `atendido_pela_importacao_id` apontando pra esta importação — origem
     * 100% auditável, distinguível de uma marcação manual (`concluido_por`
     * preenchido). Item já `concluido=true` (manual OU de importação
     * anterior) NUNCA é reescrito aqui — preserva autor/data/origem
     * originais, mesmo que uma regressão de percentual venha depois (Ciclo
     * 24: "não desfazer silenciosamente itens de prontidão que já foram
     * considerados atendidos"). Restricao nunca é tocada por este método
     * (ver Ciclo 17, A.9.1, e o bloco 6 de aplicar()).
     *
     * @param  array<string, \Carbon\Carbon>  $atividadesReconciliadas  atividade_id => concluido_em
     */
    private function reconciliarItensDeProntidao(Work $obra, CronogramaImportacao $importacao, array $atividadesReconciliadas): void
    {
        $itensCatalogoObra = ItemProntidao::where('obra_id', $obra->id)->pluck('id');

        if ($itensCatalogoObra->isEmpty()) {
            return;
        }

        $atividadeIds = array_keys($atividadesReconciliadas);

        $existentesPorAtividade = AtividadeItemProntidao::whereIn('atividade_id', $atividadeIds)
            ->get(['id', 'atividade_id', 'item_prontidao_id', 'concluido'])
            ->groupBy('atividade_id');

        $agora = now();
        $lotesParaCriar = [];

        foreach ($atividadeIds as $atividadeId) {
            $concluidoEm = $atividadesReconciliadas[$atividadeId];
            $rowsDaAtividade = $existentesPorAtividade->get($atividadeId, collect());
            $itensJaComRow = $rowsDaAtividade->pluck('item_prontidao_id');

            // Itens já com row PENDENTE (concluido=false) desta atividade —
            // passam a atendidos automaticamente.
            $idsPendentes = $rowsDaAtividade->where('concluido', false)->pluck('id');
            if ($idsPendentes->isNotEmpty()) {
                AtividadeItemProntidao::whereIn('id', $idsPendentes)->update([
                    'concluido'                   => true,
                    'concluido_por'               => null,
                    'concluido_em'                => $concluidoEm,
                    'atendido_pela_importacao_id' => $importacao->id,
                ]);
            }

            // Itens do catálogo sem NENHUMA row ainda pra esta atividade —
            // cria já atendida (ausência de row também é pendente, mesma
            // regra canônica de Atividade::estaPronta()).
            foreach ($itensCatalogoObra->diff($itensJaComRow) as $itemId) {
                $lotesParaCriar[] = [
                    'id'                          => (string) Str::ulid(),
                    'tenant_id'                   => $obra->tenant_id,
                    'atividade_id'                => $atividadeId,
                    'item_prontidao_id'           => $itemId,
                    'concluido'                   => true,
                    'concluido_por'               => null,
                    'concluido_em'                => $concluidoEm,
                    'atendido_pela_importacao_id' => $importacao->id,
                    'created_at'                  => $agora,
                    'updated_at'                  => $agora,
                ];
            }
        }

        if (! empty($lotesParaCriar)) {
            foreach (array_chunk($lotesParaCriar, 1000) as $chunk) {
                DB::table('atividade_itens_prontidao')->insert($chunk);
            }
        }
    }

    /**
     * Ciclo 17, A.9.3/A.9.4.HARDENING — Fotografia O: só PERSISTE, em lote
     * (zero N+1, zero query própria), o estado operacional que já foi
     * capturado ANTES do loop de upsert (status/fora_do_cronograma/pronta/
     * restrições pendentes/prontidão pendente — todos o MESMO instante
     * pré-importação, ver bloco de captura em aplicar()). Antes do
     * hardening, este método ainda fazia suas próprias queries de
     * `pronta`/restrições/prontidão DEPOIS do upsert — seguro só porque o
     * upsert nunca escrevia em Restricao/ItemProntidao/AtividadeItemProntidao,
     * mas uma suposição implícita. Agora o método é puramente "gravar o que
     * já foi capturado", sem reinterpretar nada. Nunca escreve em
     * Restricao/AtividadeItemProntidao/Atividade (mesmo princípio de zero
     * autocorreção da A.9.1).
     *
     * @param  array<string, string>  $mapaAtividades  uid => atividade_id
     * @param  Collection<string, Atividade>  $estadoOperacionalAntes  external_uid => Atividade (status/fora_do_cronograma pré-importação, só parcialmente hidratada: id/external_uid/status/fora_do_cronograma)
     * @param  array<string, true>  $idsProntasAntes  atividade_id => true, pra atividades que já existiam e já estavam prontas ANTES desta importação (Atividade::scopeProntas(), resolvida antes do upsert)
     * @param  Collection<string, \Illuminate\Support\Collection>  $restricoesPendentesAntesPorAtividade  atividade_id => Restricao[] (id/atividade_id/bloqueante/status), só das que já existiam antes
     * @param  array<string, array<int, array{item_prontidao_id: string, atividade_item_prontidao_id: ?string}>>  $prontidaoPendenteAntesPorAtividade  atividade_id => itens pendentes, só das que já existiam antes
     */
    private function gravarFotografiaOperacional(
        Work $obra,
        CronogramaImportacao $importacao,
        array $mapaAtividades,
        Collection $estadoOperacionalAntes,
        array $idsProntasAntes,
        Collection $restricoesPendentesAntesPorAtividade,
        array $prontidaoPendenteAntesPorAtividade,
        Carbon $agora,
    ): void {
        $tenantId = $obra->tenant_id;

        $loteOperacional = [];
        $loteRestricoes = [];
        $loteProntidao = [];

        foreach ($mapaAtividades as $uid => $atividadeId) {
            $antes = $estadoOperacionalAntes->get($uid);

            $loteOperacional[] = [
                'id'                       => (string) Str::ulid(),
                'tenant_id'                => $tenantId,
                'cronograma_importacao_id' => $importacao->id,
                'atividade_id'             => $atividadeId,
                'status'                   => $antes?->status?->value,
                'fora_do_cronograma'       => $antes?->fora_do_cronograma,
                // Atividade nova nesta própria importação (sem "antes"
                // genuíno) nunca aparece em $idsProntasAntes — pronta fica
                // false aqui, mas isso é irrelevante pra ela: o Detector
                // (A.9.4) já ignora qualquer atividade cujo status
                // pré-importação seja null, antes mesmo de olhar pronta.
                'pronta'                   => isset($idsProntasAntes[$atividadeId]),
                'created_at'               => $agora,
                'updated_at'               => $agora,
            ];

            foreach ($restricoesPendentesAntesPorAtividade->get($atividadeId, collect()) as $restricao) {
                $loteRestricoes[] = [
                    'id'                       => (string) Str::ulid(),
                    'tenant_id'                => $tenantId,
                    'cronograma_importacao_id' => $importacao->id,
                    'atividade_id'             => $atividadeId,
                    'restricao_id'             => $restricao->id,
                    'bloqueante'               => $restricao->bloqueante,
                    'status'                   => $restricao->status->value,
                    'created_at'               => $agora,
                    'updated_at'               => $agora,
                ];
            }

            foreach ($prontidaoPendenteAntesPorAtividade[$atividadeId] ?? [] as $pendente) {
                $loteProntidao[] = [
                    'id'                           => (string) Str::ulid(),
                    'tenant_id'                    => $tenantId,
                    'cronograma_importacao_id'     => $importacao->id,
                    'atividade_id'                 => $atividadeId,
                    'item_prontidao_id'            => $pendente['item_prontidao_id'],
                    'atividade_item_prontidao_id'  => $pendente['atividade_item_prontidao_id'],
                    'created_at'                   => $agora,
                    'updated_at'                   => $agora,
                ];
            }
        }

        foreach (array_chunk($loteOperacional, 1000) as $chunk) {
            DB::table('atividade_snapshot_operacionais')->insert($chunk);
        }
        foreach (array_chunk($loteRestricoes, 1000) as $chunk) {
            DB::table('atividade_snapshot_restricoes')->insert($chunk);
        }
        foreach (array_chunk($loteProntidao, 1000) as $chunk) {
            DB::table('atividade_snapshot_prontidao')->insert($chunk);
        }
    }

    /**
     * Ciclo 17, A.9.5 — Fotografia P: grava, em lote (zero N+1), se cada
     * atividade que declarou `real_inicio`/`real_termino` NESTA importação
     * (Fotografia F, `$loteSnapshots`) fazia parte da Programação Semanal
     * historicamente aplicável ao instante desse evento factual — NUNCA a
     * data/hora da própria importação (`$agora`)/`importado_em`, porque
     * avanço importado com atraso é um cenário real deste projeto e as duas
     * datas podem divergir (decisão do usuário, Ciclo 17 A.9.5).
     *
     * `data_factual` exige a data REAL correspondente (`real_inicio` pro
     * evento `inicio`, `real_termino` pro evento `conclusao`) — nunca cai
     * pra outra data quando ausente (ex.: "iniciou" via só percentual>0,
     * sem `real_inicio`, não gera linha de evento `inicio` aqui: sem data
     * genuína, não há semana pra resolver — regra própria de P,
     * deliberadamente MAIS estrita que o INICIOU/CONCLUIU do Detector de
     * O, que não precisa de data nenhuma). Uma atividade pode gerar até 2
     * linhas nesta importação (início E conclusão são fatos
     * independentes, mesmo princípio já usado pelo Detector).
     *
     * Resolução 100% em lote: 2 queries (`ProgramacaoSemanal`/
     * `ProgramacaoSemanalItem`, ambas escopadas pelo conjunto de semanas
     * realmente necessárias) + inserts em chunk — nunca 1 query por
     * atividade/evento. Vigência é resolvida em memória com a MESMA regra
     * de `ProgramacaoSemanal::vigenteEm()` (reimplementada aqui só pra
     * evitar N chamadas ao banco — nunca uma regra paralela diferente).
     * Granularidade de DIA (fim do dia de `data_factual`), não de
     * timestamp exato — `real_inicio`/`real_termino` nunca carregam hora.
     *
     * Nunca lê/altera `ProgramacaoSemanal`/`ProgramacaoSemanalItem`/
     * `Atividade` fora desta leitura pura (mesmo princípio de zero
     * autocorreção da A.9.1) — o resultado é só congelado.
     *
     * @param  array<int, array{atividade_id:string, percentual_concluido:mixed, real_inicio:?string, real_termino:?string}>  $loteSnapshots
     */
    private function capturarFotografiaProgramacao(
        Work $obra,
        CronogramaImportacao $importacao,
        array $loteSnapshots,
        Carbon $agora,
    ): void {
        $eventos = [];

        foreach ($loteSnapshots as $snap) {
            if ($snap['real_inicio'] !== null) {
                $eventos[] = [
                    'atividade_id' => $snap['atividade_id'],
                    'evento' => EventoFotografiaProgramacao::Inicio,
                    'data_factual' => $snap['real_inicio'],
                ];
            }

            if ($snap['real_termino'] !== null) {
                $eventos[] = [
                    'atividade_id' => $snap['atividade_id'],
                    'evento' => EventoFotografiaProgramacao::Conclusao,
                    'data_factual' => $snap['real_termino'],
                ];
            }
        }

        if (empty($eventos)) {
            return;
        }

        foreach ($eventos as &$evento) {
            $evento['semana_inicio'] = Carbon::parse($evento['data_factual'])
                ->startOfWeek(Carbon::MONDAY)->toDateString();
            $evento['instante'] = Carbon::parse($evento['data_factual'])->endOfDay();
        }
        unset($evento);

        $semanasNecessarias = array_values(array_unique(array_column($eventos, 'semana_inicio')));

        // Todas as versões de ProgramacaoSemanal das semanas necessárias,
        // numa única query — nunca 1 query por semana/atividade.
        $todasVersoes = ProgramacaoSemanal::where('obra_id', $obra->id)
            ->whereIn('semana_inicio', $semanasNecessarias)
            ->get(['id', 'semana_inicio', 'versao', 'congelada_em', 'superseded_at']);

        $versoesPorSemana = $todasVersoes->groupBy(fn ($v) => $v->semana_inicio->toDateString());

        $todosItens = $todasVersoes->isNotEmpty()
            ? ProgramacaoSemanalItem::whereIn('programacao_semanal_id', $todasVersoes->pluck('id'))
                ->get(['id', 'programacao_semanal_id', 'atividade_id', 'created_at'])
            : collect();

        $itensPorProgramacao = $todosItens->groupBy('programacao_semanal_id');

        $lote = [];

        foreach ($eventos as $evento) {
            $candidatas = $versoesPorSemana->get($evento['semana_inicio'], collect());

            // Mesma regra de ProgramacaoSemanal::vigenteEm(), resolvida em
            // memória: vigente = já existia (congelada_em <= instante) e
            // ainda não tinha sido substituída (superseded_at nulo, ou só
            // passou a valer depois do instante).
            $headerVigente = $candidatas
                ->filter(fn ($v) => $v->congelada_em->lte($evento['instante'])
                    && ($v->superseded_at === null || $v->superseded_at->gt($evento['instante'])))
                ->sortByDesc('versao')
                ->first();

            $itemEncontrado = $headerVigente
                ? $itensPorProgramacao->get($headerVigente->id, collect())
                    ->first(fn ($item) => $item->atividade_id === $evento['atividade_id']
                        && $item->created_at->lte($evento['instante']))
                : null;

            $lote[] = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $obra->tenant_id,
                'cronograma_importacao_id' => $importacao->id,
                'atividade_id' => $evento['atividade_id'],
                'evento' => $evento['evento']->value,
                'data_factual' => $evento['data_factual'],
                'semana_inicio_resolvida' => $evento['semana_inicio'],
                'programacao_semanal_id' => $headerVigente?->id,
                'programacao_semanal_versao' => $headerVigente?->versao,
                'atividade_estava_na_programacao' => $itemEncontrado !== null,
                'programacao_semanal_item_id' => $itemEncontrado?->id,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        foreach (array_chunk($lote, 1000) as $chunk) {
            DB::table('atividade_snapshot_programacoes')->insert($chunk);
        }
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
