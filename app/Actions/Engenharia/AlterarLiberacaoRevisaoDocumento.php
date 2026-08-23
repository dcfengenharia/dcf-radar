<?php

namespace App\Actions\Engenharia;

use App\Exceptions\RevisaoDocumentoNaoVigenteException;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\User;

/**
 * Ciclo 18, Etapa 18.3 — único ponto de escrita do histórico de
 * liberação/revogação de uma revisão de Documento de Engenharia. Sem
 * checagem de PERMISSÃO aqui (responsabilidade do chamador, mesmo padrão
 * de App\Actions\Atividade\AnexarArquivoAtividade/MarcarNaoConcluido) —
 * mas, desde a 18.3.CORREÇÃO, com uma checagem de DOMÍNIO própria (ver
 * garantirRevisaoVigente()).
 *
 * Nunca atualiza uma row existente — cada chamada cria um evento novo em
 * `revisao_liberacoes` (append-only). O estado "atual" da revisão é
 * sempre o ÚLTIMO evento (DocumentoEngenhariaRevisao::
 * estaLiberadaParaConstrucao(), via ofMany) — nunca uma coluna
 * sobrescrita. Ver docblock da migration pra justificativa completa.
 *
 * Etapa 18.3.CORREÇÃO (achado C da auditoria adversarial, fechado como
 * dívida de defesa em profundidade — item 15/16 do pedido de correção):
 * - **Guarda de vigência**: antes de registrar qualquer evento, confirma
 *   que `$revisao` É a revisão vigente CANÔNICA do seu Documento
 *   (`DocumentoEngenhariaRevisao::scopeOrdenadasPorVigencia()`) — nunca
 *   uma revisão histórica superada. Consulta direta por
 *   `documento_engenharia_id` (nunca depende de `$revisao->documento`
 *   estar eager-loaded, então nunca dispara LazyLoadingViolationException
 *   nem uma query a mais desnecessária). Os dois únicos pontos de entrada
 *   alcançáveis por UI/Livewire (`liberarRevisaoVigente()`/
 *   `revogarLiberacaoRevisaoVigente()`) sempre resolvem a vigente fresca
 *   antes de chamar a Action, então esta guarda nunca deveria disparar
 *   por esse caminho — é defesa contra chamada direta (bypass de UI).
 * - **Idempotência**: se o estado JÁ solicitado (`$liberar`) é o mesmo
 *   que o estado atual da revisão, a chamada é um no-op — nenhum evento
 *   redundante é criado. Clicar duas vezes em "Liberar" (ex.: duplo
 *   clique, requisição repetida) nunca infla o histórico com eventos
 *   idênticos consecutivos; o histórico volta a representar só
 *   TRANSIÇÕES de estado. Um ciclo liberar→revogar→liberar continua
 *   gravando as 3 transições normalmente.
 */
class AlterarLiberacaoRevisaoDocumento
{
    public function liberar(DocumentoEngenhariaRevisao $revisao, User $usuario, ?string $observacao = null): void
    {
        $this->registrar($revisao, true, $usuario, $observacao);
    }

    public function revogar(DocumentoEngenhariaRevisao $revisao, User $usuario, ?string $observacao = null): void
    {
        $this->registrar($revisao, false, $usuario, $observacao);
    }

    private function registrar(DocumentoEngenhariaRevisao $revisao, bool $liberar, User $usuario, ?string $observacao): void
    {
        $this->garantirRevisaoVigente($revisao);

        // Idempotência: consulta o estado atual direto (nunca confia em
        // relação `ultimaLiberacao` potencialmente cacheada no objeto
        // $revisao recebido) — se já é o estado pedido, no-op.
        $estadoAtual = DocumentoEngenhariaRevisao::query()
            ->whereKey($revisao->getKey())
            ->with('ultimaLiberacao')
            ->first()
            ?->estaLiberadaParaConstrucao() ?? false;

        if ($estadoAtual === $liberar) {
            return;
        }

        $revisao->historicoLiberacoes()->create([
            'tenant_id' => $revisao->tenant_id,
            'liberada_para_construcao' => $liberar,
            'alterado_por' => $usuario->id,
            'ocorrido_em' => now(),
            'observacao' => $observacao,
        ]);
    }

    private function garantirRevisaoVigente(DocumentoEngenhariaRevisao $revisao): void
    {
        $vigente = DocumentoEngenhariaRevisao::query()
            ->where('documento_engenharia_id', $revisao->documento_engenharia_id)
            ->ordenadasPorVigencia()
            ->first();

        if ($vigente === null || $vigente->isNot($revisao)) {
            throw new RevisaoDocumentoNaoVigenteException($revisao);
        }
    }
}
