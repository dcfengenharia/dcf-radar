<?php

namespace App\Support\Grd;

use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\User;
use App\Models\Work;
use App\Notifications\GrdCandidatosNovaEntregaNotification;
use App\Notifications\GrdCopiasObsoletasNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Ciclo 18, Etapa 18.5.5 — orquestra os 2 alertas internos de
 * distribuição (obsolescência de cópias em campo / candidato a nova
 * entrega). NUNCA reimplementa a regra de obsoleta/candidato/vigência —
 * consome exclusivamente DetectorCopiasObsoletasGrd/CandidatosNovaEntregaGrd
 * (18.5.1, já aprovados) e DocumentoEngenharia::revisaoVigente() (18.4),
 * filtrando o resultado já derivado por Documento em memória.
 *
 * Chamado a partir de 2 Observers (App\Observers\
 * DocumentoEngenhariaRevisaoObserver/RevisaoLiberacaoObserver), sempre de
 * dentro de um DB::afterCommit() — nunca antes do commit da transação que
 * criou o fato (revisão nova / liberação), pra nunca notificar algo que
 * pode sofrer rollback.
 *
 * Etapa 18.5.5.HARDENING — idempotência estrutural, sem migration nova.
 * A identidade lógica de um alerta (tipo + obra + documento + revisão +
 * usuário destinatário) é traduzida num UUID DETERMINÍSTICO (`idAlerta()`,
 * UUIDv5, mesmo valor pra mesma chave sempre) atribuído a `$notification->id`
 * ANTES de `$user->notify()`. `Illuminate\Notifications\NotificationSender::
 * sendToNotifiable()/queueNotification()` só gera um UUID aleatório quando
 * `! $notification->id` — um id já setado é respeitado tal como está, tanto
 * no envio síncrono quanto no despacho pra fila. Como `notifications.id` é a
 * PRIMARY KEY (confirmado via `SHOW CREATE TABLE`), uma segunda tentativa de
 * gravar a MESMA chave nunca pode resultar em 2 linhas — é a própria
 * constraint do banco que garante isso, não uma convenção `if (!exists())`
 * (essa checagem continua existindo abaixo, mas só como ATALHO pra evitar
 * despachar um job de fila fadado a duplicar no caso comum; quem protege de
 * verdade sob corrida é a PK). R2/R3 continuam eventos distintos porque a
 * chave inclui o ID da revisão — nunca "o Documento" sozinho.
 */
class AlertaDistribuicaoGrd
{
    private const NAMESPACE_ALERTA = 'grd-alerta-distribuicao';

    /**
     * Identidade lógica determinística de UM alerta para UM destinatário —
     * mesma chave sempre produz o mesmo UUID (RFC 4122 v5, via ramsey/uuid,
     * já uma dependência transitiva do próprio Laravel/`Str::uuid()`).
     * `$tipo` distingue Alerta A ('copias_obsoletas') de Alerta B
     * ('candidatos_nova_entrega') — os 2 nunca colidem entre si mesmo pra
     * o mesmo obra+documento+revisão+usuário.
     */
    public static function idAlerta(string $tipo, string $obraId, string $documentoId, string $revisaoId, string $userId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::NAMESPACE_ALERTA . ":{$tipo}:{$obraId}:{$documentoId}:{$revisaoId}:{$userId}")->toString();
    }

    /**
     * Ponto único de envio idempotente — usado pelos 2 alertas. A checagem
     * `exists()` é só o atalho (evita 1 dispatch de fila desnecessário no
     * caso comum); a defesa estrutural real é a PRIMARY KEY de
     * `notifications.id`, que rejeita uma 2ª gravação da mesma chave mesmo
     * sob corrida — `Notification::failed()` (nos 2 Notifications) trata
     * esse erro específico (SQLSTATE 23000 / MySQL 1062) como idempotência
     * bem-sucedida, nunca como falha real (mesmo critério já usado em
     * `PlanoAcao::transformarEmRestricoes()`).
     */
    private function enviarComIdempotencia(User $user, string $tipo, string $obraId, string $documentoId, string $revisaoId, \Closure $fabricaNotification): void
    {
        $id = self::idAlerta($tipo, $obraId, $documentoId, $revisaoId, $user->id);

        if (DB::table('notifications')->where('id', $id)->exists()) {
            return;
        }

        $notification = $fabricaNotification();
        $notification->id = $id;

        try {
            $user->notify($notification);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            // Corrida concorrente: outro processo já gravou a mesma chave
            // entre o exists() acima e este notify() síncrono — quem
            // protegeu de verdade foi a PRIMARY KEY. Idempotência, não erro.
        }
    }

    /**
     * Alerta A. A existência de cópia obsoleta NUNCA depende da revisão
     * nova estar liberada — só de ela ser, agora, a vigente do Documento.
     *
     * Guarda de revisão retroativa (import de LD com `data_emissao`
     * antiga pode criar uma revisão que NÃO vira a vigente — ex.: R2 já
     * existe com data mais recente, e agora insere-se uma "R1b" com data
     * anterior): revalida a vigência FRESCA no momento do disparo (nunca
     * confia em `$revisaoNova` como sendo automaticamente a vigente só
     * por ter acabado de ser criada) — se não for a vigente atual, não
     * há evento de obsolescência novo, nenhum alerta é gerado.
     */
    public function dispararCopiasObsoletas(DocumentoEngenhariaRevisao $revisaoNova): void
    {
        $documento = $revisaoNova->documento()->firstOrFail();

        if (! $this->ehVigenteAgora($documento, $revisaoNova)) {
            return;
        }

        $obra = $documento->obra()->firstOrFail();

        $quantidadePendente = (new DetectorCopiasObsoletasGrd())->porObra($obra)
            ->filter(fn ($registro) => $registro->documento->id === $documento->id)
            ->sum('quantidade_pendente');

        if ($quantidadePendente <= 0) {
            return;
        }

        $destinatarios = $this->usuariosComPermissaoNaObra($obra);
        if ($destinatarios->isEmpty()) {
            return;
        }

        foreach ($destinatarios as $user) {
            $this->enviarComIdempotencia(
                $user,
                'copias_obsoletas',
                $obra->id,
                $documento->id,
                $revisaoNova->id,
                fn () => new GrdCopiasObsoletasNotification(
                    $documento->tenant_id,
                    $obra->id,
                    $obra->name,
                    $documento->id,
                    $documento->codigo,
                    $revisaoNova->id,
                    $revisaoNova->revisao,
                    (int) $quantidadePendente,
                ),
            );
        }
    }

    /**
     * Alerta B. `AlterarLiberacaoRevisaoDocumento::registrar()` já garante
     * (`garantirRevisaoVigente()`) que só é possível liberar a revisão
     * VIGENTE — liberar uma revisão antiga lança exceção antes de gravar
     * qualquer evento, então este método nunca é alcançado nesse caso.
     * A revalidação abaixo é defesa em profundidade contra uma revisão
     * mais nova ter nascido no intervalo entre o commit e este disparo
     * (janela teoricamente possível, nunca reproduzida, mas barata de
     * fechar).
     */
    public function dispararCandidatosNovaEntrega(DocumentoEngenhariaRevisao $revisaoLiberada): void
    {
        $documento = $revisaoLiberada->documento()->firstOrFail();

        if (! $this->ehVigenteAgora($documento, $revisaoLiberada)) {
            return;
        }

        $obra = $documento->obra()->firstOrFail();

        $quantidadeCandidatos = (new CandidatosNovaEntregaGrd())->porObra($obra)
            ->filter(fn ($registro) => $registro->documento->id === $documento->id)
            ->count();

        if ($quantidadeCandidatos <= 0) {
            return;
        }

        $destinatarios = $this->usuariosComPermissaoNaObra($obra);
        if ($destinatarios->isEmpty()) {
            return;
        }

        foreach ($destinatarios as $user) {
            $this->enviarComIdempotencia(
                $user,
                'candidatos_nova_entrega',
                $obra->id,
                $documento->id,
                $revisaoLiberada->id,
                fn () => new GrdCandidatosNovaEntregaNotification(
                    $documento->tenant_id,
                    $obra->id,
                    $obra->name,
                    $documento->id,
                    $documento->codigo,
                    $revisaoLiberada->id,
                    $revisaoLiberada->revisao,
                    $quantidadeCandidatos,
                ),
            );
        }
    }

    private function ehVigenteAgora(DocumentoEngenharia $documento, DocumentoEngenhariaRevisao $revisao): bool
    {
        $vigenteAtual = DocumentoEngenharia::findOrFail($documento->id)->revisaoVigente();

        return $vigenteAtual !== null && $vigenteAtual->is($revisao);
    }

    /**
     * Mesma composição já aprovada e em produção em
     * App\Console\Commands\NotificarProntidaoSemanalCommand::resolverDestinatarios()
     * (Ciclo 16, A.4) — vinculado à obra (`$obra->users()`, escopado por
     * `obra_user.work_id`, nunca `temPermissaoEmAlgumaObraDoTenant()`) +
     * ativo + permissão `engenharia.pacotes|ver` checada POR OBRA. Não
     * reinventa uma query em lote nova — reaproveita literalmente a mesma
     * regra já validada em produção.
     *
     * @return Collection<int, User>
     */
    private function usuariosComPermissaoNaObra(Work $obra): Collection
    {
        return $obra->users()
            ->where('users.ativo', true)
            ->get()
            ->filter(fn (User $user) => $user->temPermissaoNaObra($obra, 'engenharia.pacotes', 'ver'))
            ->values();
    }
}
