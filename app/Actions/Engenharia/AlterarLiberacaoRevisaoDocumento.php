<?php

namespace App\Actions\Engenharia;

use App\Exceptions\RevisaoDocumentoNaoVigenteException;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\User;
use App\Support\Perfis\GarantirAutoridadeNaObra;

/**
 * Ciclo 18, Etapa 18.3 — único ponto de escrita do histórico de
 * liberação/revogação de uma revisão de Documento de Engenharia. Desde
 * a 18.3.CORREÇÃO, com uma checagem de DOMÍNIO própria (ver
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
 *
 * Fase 2E — defesa em profundidade (revisão da Fase 2B, Seção 36-40):
 * uma primeira tentativa de checar `temPermissaoEmAlgumaObraDoTenant(
 * 'engenharia.pacotes', 'liberar_para_construcao')` foi revertida por
 * quebrar 20 testes de 6 arquivos que usavam esta Action só como
 * infraestrutura de fixture, com um ator sem vínculo a
 * `engenharia.pacotes`. A Fase 2E.CORREÇÃO reavaliou essa decisão: a
 * capacidade `liberar_para_construcao` existe justamente para separar
 * "quem pode liberar para construção" de "quem pode editar Engenharia"
 * (perfis padrão colapsam as duas no mesmo tier Admin, mas um Perfil
 * customizado pode conceder só uma delas) — quebrar fixture não é
 * motivo suficiente pra deixar essa autoridade sem defesa em
 * profundidade contra chamada direta (bypass de UI/Job/Command mal
 * configurado). Os 6 arquivos foram corrigidos (Fase 2E.CORREÇÃO) dando
 * ao ator de fixture um segundo usuário com autoridade real de
 * `liberar_para_construcao` na obra, nunca promovendo o ator sob teste
 * a um tier que contaminaria as próprias asserções daquele teste.
 *
 * Obra sempre derivada do Documento REAL da revisão
 * (`documento_engenharia_id` → `DocumentoEngenharia.obra_id`, consulta
 * de coluna única, nunca a obra ativa da sessão) — nunca eager-loaded
 * por confiança, sempre uma query fresca e mínima.
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
        $obraId = (string) DocumentoEngenharia::query()
            ->whereKey($revisao->documento_engenharia_id)
            ->value('obra_id');

        GarantirAutoridadeNaObra::checar(
            $usuario, $obraId, 'engenharia.pacotes', 'liberar_para_construcao',
            'Você não tem autoridade para liberar/revogar esta revisão para construção.'
        );

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
