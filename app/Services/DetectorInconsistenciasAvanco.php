<?php

namespace App\Services;

use App\Enums\EntidadeInconsistenciaAvanco;
use App\Enums\EventoFotografiaProgramacao;
use App\Enums\SeveridadeInconsistenciaAvanco;
use App\Enums\TipoCronogramaImportacao;
use App\Enums\TipoInconsistenciaAvanco;
use App\Models\AtividadeSnapshot;
use App\Models\AtividadeSnapshotOperacional;
use App\Models\AtividadeSnapshotProgramacao;
use App\Models\AtividadeSnapshotProntidao;
use App\Models\AtividadeSnapshotRestricao;
use App\Models\CronogramaImportacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclo 17, A.9.4/A.9.5 — núcleo do Detector de Inconsistências de Avanço.
 *
 * Compara, para cada atividade tocada por UMA importação Avanço/Ambos,
 * Fotografia F (`AtividadeSnapshot` — o que o cronograma declarou NESTA
 * importação) contra duas outras fotografias independentes:
 * - Fotografia O (`AtividadeSnapshotOperacional`/`Restricao`/`Prontidao`,
 *   A.9.4) — o que a plataforma sabia IMEDIATAMENTE ANTES dela;
 * - Fotografia P (`AtividadeSnapshotProgramacao`, A.9.5) — se a atividade
 *   fazia parte da Programação Semanal historicamente aplicável ao
 *   instante do evento.
 *
 * Serviço PURO: só leitura de F+O+P da própria importação (nunca consulta
 * Restricao/AtividadeItemProntidao/Atividade/ProgramacaoSemanal ao vivo,
 * nunca importações anteriores) + insert em lote de `inconsistencias_avanco`.
 * NUNCA altera Atividade/Restricao/Prontidao/ProgramacaoSemanal/
 * comentários/anexos — mesmo princípio de zero autocorreção da A.9.1.
 * Nunca dispara Notification/Job/Command.
 *
 * Regra factual de INÍCIO (documentada, não uma dedução silenciosa — ver
 * relatório da A.9.4): `MsProjectImporter::percentualTrabalho()` lê
 * `PercentWorkComplete` e `realInicio` lê `ActualStart`, cada um
 * independentemente, sem nenhuma validação cruzada no parser — o MSPDI
 * permite genuinamente um sem o outro. Por isso INICIOU é a união dos dois
 * sinais (`real_inicio != null OR percentual_concluido > 0`), nunca só um
 * dos dois isoladamente. Mesma lógica para CONCLUIU
 * (`percentual_concluido >= 100 OR real_termino != null`).
 *
 * Ciclo 17, A.9.4.HARDENING — guarda de tipo própria: `detectar()` só
 * produz ocorrência para importação Avanço/Ambos, mesmo que chamado
 * diretamente. Antes deste hardening, a segurança contra Baseline vinha
 * inteiramente do CHAMADOR (`MsProjectImporter` só grava Fotografia O e só
 * chama o detector dentro do mesmo gate de tipo) — funcionava porque
 * Fotografia O nunca é gravada pra Baseline, mas era defesa por ausência
 * de dado, não uma invariante do próprio serviço. O gate do
 * `MsProjectImporter` continua existindo (defesa em profundidade, não
 * substituição) — esta guarda é a segunda camada, dentro do serviço. A
 * mesma guarda, no topo do método, já cobre o bloco de Fotografia P
 * abaixo (não precisa de guarda própria — Fotografia P também só é
 * gravada pra Avanço/Ambos).
 *
 * Ciclo 17, A.9.5 — 4 tipos novos, comprováveis por Fotografia F ×
 * Fotografia P (`AtividadeSnapshotProgramacao` — se a atividade fazia
 * parte da Programação Semanal historicamente aplicável ao instante do
 * evento). Ao contrário do bloco O (que pula atividade nova via
 * `status === null`), o bloco P NUNCA pula atividade nova — uma atividade
 * que nasce nesta própria importação já iniciada/concluída não poderia
 * ESTRUTURALMENTE estar em nenhuma Programação Semanal pré-existente
 * (`ProgramacaoSemanalItem.atividade_id` só pode referenciar uma
 * atividade que já existia no instante do comprometimento), então a
 * resolução natural de P já produz "fora/sem programação" pra ela sem
 * precisar de nenhum guard especial — decisão documentada no relatório da
 * A.9.5 (investigação explícita, não copiada do bloco O).
 */
class DetectorInconsistenciasAvanco
{
    public function detectar(CronogramaImportacao $importacao): void
    {
        if (! in_array($importacao->tipo, [TipoCronogramaImportacao::Avanco, TipoCronogramaImportacao::Ambos], true)) {
            return;
        }

        $snapshotsF = AtividadeSnapshot::where('cronograma_importacao_id', $importacao->id)
            ->get()
            ->keyBy('atividade_id');

        if ($snapshotsF->isEmpty()) {
            return;
        }

        $snapshotsOPai = AtividadeSnapshotOperacional::where('cronograma_importacao_id', $importacao->id)
            ->get()
            ->keyBy('atividade_id');

        $restricoesO = AtividadeSnapshotRestricao::where('cronograma_importacao_id', $importacao->id)
            ->get()
            ->groupBy('atividade_id');

        $prontidaoO = AtividadeSnapshotProntidao::where('cronograma_importacao_id', $importacao->id)
            ->get()
            ->groupBy('atividade_id');

        $agora = now();
        $lote = [];

        foreach ($snapshotsF as $atividadeId => $f) {
            $oPai = $snapshotsOPai->get($atividadeId);
            if (! $oPai) {
                continue;
            }

            // Atividade nascida NESTA própria importação (sem "antes" —
            // status/fora_do_cronograma capturados como null pela própria
            // Fotografia O): a plataforma não poderia ter conhecido nenhuma
            // pendência operacional dela antes de ela existir. Nunca gerar
            // falso positivo aqui.
            if ($oPai->status === null) {
                continue;
            }

            $percentual = $f->percentual_concluido !== null ? (float) $f->percentual_concluido : null;
            $iniciou = $f->real_inicio !== null || ($percentual !== null && $percentual > 0);
            $concluiu = ($percentual !== null && $percentual >= 100) || $f->real_termino !== null;

            if (! $iniciou && ! $concluiu) {
                continue;
            }

            $restricoesAtividade = $restricoesO->get($atividadeId, collect());
            $prontidaoAtividade = $prontidaoO->get($atividadeId, collect());

            if ($iniciou) {
                foreach ($restricoesAtividade as $r) {
                    $lote[] = $this->linha(
                        $importacao, $atividadeId,
                        TipoInconsistenciaAvanco::InicioComRestricaoPendente,
                        $r->bloqueante
                            ? SeveridadeInconsistenciaAvanco::Atencao
                            : SeveridadeInconsistenciaAvanco::Informativa,
                        EntidadeInconsistenciaAvanco::Restricao,
                        $r->restricao_id,
                        'Atividade iniciada com Restrição pendente',
                        ['restricao_id' => $r->restricao_id, 'bloqueante' => (bool) $r->bloqueante, 'status' => $r->status],
                        $agora,
                    );
                }

                foreach ($prontidaoAtividade as $p) {
                    $lote[] = $this->linha(
                        $importacao, $atividadeId,
                        TipoInconsistenciaAvanco::InicioComProntidaoPendente,
                        SeveridadeInconsistenciaAvanco::Atencao,
                        EntidadeInconsistenciaAvanco::ItemProntidao,
                        $p->item_prontidao_id,
                        'Atividade iniciada com item de Prontidão pendente',
                        ['item_prontidao_id' => $p->item_prontidao_id, 'atividade_item_prontidao_id' => $p->atividade_item_prontidao_id],
                        $agora,
                    );
                }
            }

            if ($concluiu) {
                foreach ($restricoesAtividade as $r) {
                    $lote[] = $this->linha(
                        $importacao, $atividadeId,
                        TipoInconsistenciaAvanco::ConclusaoComRestricaoPendente,
                        $r->bloqueante
                            ? SeveridadeInconsistenciaAvanco::Critica
                            : SeveridadeInconsistenciaAvanco::Atencao,
                        EntidadeInconsistenciaAvanco::Restricao,
                        $r->restricao_id,
                        'Atividade concluída com Restrição pendente',
                        ['restricao_id' => $r->restricao_id, 'bloqueante' => (bool) $r->bloqueante, 'status' => $r->status],
                        $agora,
                    );
                }

                foreach ($prontidaoAtividade as $p) {
                    $lote[] = $this->linha(
                        $importacao, $atividadeId,
                        TipoInconsistenciaAvanco::ConclusaoComProntidaoPendente,
                        SeveridadeInconsistenciaAvanco::Critica,
                        EntidadeInconsistenciaAvanco::ItemProntidao,
                        $p->item_prontidao_id,
                        'Atividade concluída com item de Prontidão pendente',
                        ['item_prontidao_id' => $p->item_prontidao_id, 'atividade_item_prontidao_id' => $p->atividade_item_prontidao_id],
                        $agora,
                    );
                }
            }
        }

        // Ciclo 17, A.9.5 — Fotografia P: 1 linha já congelada por
        // (atividade, evento) que teve data factual nesta importação —
        // nunca recalcula nada, só lê o que `MsProjectImporter::
        // capturarFotografiaProgramacao()` já resolveu e persistiu.
        $snapshotsP = AtividadeSnapshotProgramacao::where('cronograma_importacao_id', $importacao->id)->get();

        foreach ($snapshotsP as $p) {
            if ($p->atividade_estava_na_programacao) {
                continue;
            }

            $detalhes = [
                'semana_inicio_resolvida' => $p->semana_inicio_resolvida->toDateString(),
                'data_factual' => $p->data_factual->toDateString(),
                'programacao_semanal_id' => $p->programacao_semanal_id,
                'programacao_semanal_versao' => $p->programacao_semanal_versao,
            ];

            if ($p->programacao_semanal_id !== null) {
                // Existia Programação Semanal pra aquela semana — a
                // atividade só não estava nela.
                $lote[] = $this->linha(
                    $importacao, $p->atividade_id,
                    $p->evento === EventoFotografiaProgramacao::Inicio
                        ? TipoInconsistenciaAvanco::InicioForaProgramacaoSemanal
                        : TipoInconsistenciaAvanco::ConclusaoForaProgramacaoSemanal,
                    $p->evento === EventoFotografiaProgramacao::Inicio
                        ? SeveridadeInconsistenciaAvanco::Atencao
                        : SeveridadeInconsistenciaAvanco::Critica,
                    EntidadeInconsistenciaAvanco::ProgramacaoSemanal,
                    $p->programacao_semanal_id,
                    $p->evento === EventoFotografiaProgramacao::Inicio
                        ? 'Atividade iniciada fora da Programação Semanal'
                        : 'Atividade concluída fora da Programação Semanal',
                    $detalhes,
                    $agora,
                );
            } else {
                // Nenhuma Programação Semanal existia pra aquela semana —
                // fato diferente do anterior, nunca fundido no mesmo tipo.
                // Sem entidade de programação nenhuma pra referenciar —
                // entidade_id reaproveita o próprio atividade_id (ver
                // App\Enums\EntidadeInconsistenciaAvanco::Atividade).
                $lote[] = $this->linha(
                    $importacao, $p->atividade_id,
                    $p->evento === EventoFotografiaProgramacao::Inicio
                        ? TipoInconsistenciaAvanco::InicioSemProgramacaoSemanal
                        : TipoInconsistenciaAvanco::ConclusaoSemProgramacaoSemanal,
                    $p->evento === EventoFotografiaProgramacao::Inicio
                        ? SeveridadeInconsistenciaAvanco::Atencao
                        : SeveridadeInconsistenciaAvanco::Critica,
                    EntidadeInconsistenciaAvanco::Atividade,
                    $p->atividade_id,
                    $p->evento === EventoFotografiaProgramacao::Inicio
                        ? 'Atividade iniciada sem Programação Semanal publicada'
                        : 'Atividade concluída sem Programação Semanal publicada',
                    $detalhes,
                    $agora,
                );
            }
        }

        foreach (array_chunk($lote, 1000) as $chunk) {
            DB::table('inconsistencias_avanco')->insert($chunk);
        }
    }

    private function linha(
        CronogramaImportacao $importacao,
        string $atividadeId,
        TipoInconsistenciaAvanco $tipo,
        SeveridadeInconsistenciaAvanco $severidade,
        EntidadeInconsistenciaAvanco $entidadeTipo,
        string $entidadeId,
        string $titulo,
        array $detalhes,
        $agora,
    ): array {
        return [
            'id' => (string) Str::ulid(),
            'tenant_id' => $importacao->tenant_id,
            'obra_id' => $importacao->obra_id,
            'atividade_id' => $atividadeId,
            'cronograma_importacao_id' => $importacao->id,
            'tipo' => $tipo->value,
            'severidade' => $severidade->value,
            'entidade_tipo' => $entidadeTipo->value,
            'entidade_id' => $entidadeId,
            'titulo' => $titulo,
            'detalhes' => json_encode($detalhes),
            'detectada_em' => $agora,
            'created_at' => $agora,
            'updated_at' => $agora,
        ];
    }
}
