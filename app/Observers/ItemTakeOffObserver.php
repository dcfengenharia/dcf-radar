<?php

namespace App\Observers;

use App\Exceptions\ItemTakeOffMaterialImutavelException;
use App\Exceptions\ItemTakeOffReferenciadoException;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\RequisicaoPlanejamentoItem;
use App\Support\Estoque\PoliticaAssociacaoMaterial;

/**
 * Ciclo 19, Etapa 19.1.HARDENING — toda mutação de item (criar/editar/
 * excluir) valida que a Lista dona pertence à revisão VIGENTE do
 * documento. Único ponto de verdade, reaproveitado automaticamente por
 * TODOS os escritores reais (cadastro manual, `TakeOffImporter::aplicar()`,
 * qualquer chamada futura) — nenhum precisa duplicar a checagem.
 *
 * Resolve a lista sempre FRESH via `lista_engenharia_id` (nunca confia
 * em `$item->lista` possivelmente não carregada/desatualizada).
 *
 * **Delete de item, revisão vigente**: permitido — `ItemTakeOff` já usa
 * `SoftDeletes` desde a 19.1 (exclusão nunca é física), a UI já expõe
 * essa ação desde a 19.1/19.1.CORREÇÃO (testada). É o mesmo
 * `garantirEditavel()` usado por criar/editar, só que no evento
 * `deleting`.
 *
 * **19.2.CORREÇÃO — referência de RP bloqueia exclusão (achado C da
 * auditoria adversarial da 19.2)**: um `ItemTakeOff` referenciado por
 * QUALQUER `RequisicaoPlanejamentoItem` — Rascunho OU Emitida — nunca
 * pode ser excluído (soft ou force). A FK `restrictOnDelete()` de
 * `requisicao_planejamento_itens.item_take_off_id` só protege
 * `forceDelete()` (DELETE físico); `SoftDeletes::delete()` é um simples
 * `UPDATE deleted_at`, que NUNCA aciona FK nenhuma — por isso essa
 * checagem de domínio é obrigatória aqui, não redundante com a FK.
 *
 * **Disciplina de lock (não neste Observer)**: a verificação abaixo
 * (`exists()`) só é livre de corrida quando o CHAMADOR já adquiriu
 * `ItemTakeOff::lockForUpdate()` na MESMA linha, dentro da MESMA
 * transação, ANTES de chamar `delete()` — exatamente a mesma disciplina
 * que `AtualizarRascunhoRequisicaoPlanejamento::adicionarItem()` já usa
 * pra criar um `RequisicaoPlanejamentoItem` novo. As duas operações
 * disputando o lock da MESMA linha é o que garante que nenhuma das duas
 * pode concluir com base num estado ("sem referência" / "não deletado")
 * que a outra já invalidou. Ver `⚡take-off.blade.php::excluirItem()`.
 * O Observer sozinho, sem essa disciplina no chamador, faria só uma
 * checagem "best-effort" — correta no instante em que roda, mas sem
 * garantia contra uma inserção concorrente de RPItem entre o `exists()`
 * e o `UPDATE deleted_at` real.
 *
 * **Ciclo 20, Etapa 20.1.CORREÇÃO — imutabilidade de material_id
 * reescrita pra usar App\Support\Estoque\PoliticaAssociacaoMaterial como
 * ÚNICA fonte de verdade** (achado da auditoria adversarial: a versão
 * anterior tinha sua própria lógica — `isDirty && utilizadoEmEstoque()`
 * — divergente da que a Action `AssociarMaterialAoItemTakeOff` viria a
 * precisar). Este Observer continua sendo só a barreira de DEFESA contra
 * qualquer escrita de INSTÂNCIA que não passe pela Action oficial
 * (`save()`/`update()` chamados direto) — nunca o mecanismo primário de
 * imutabilidade, que é a própria Action. **Limitação estrutural conhecida
 * e aceita, documentada explicitamente (nunca escondida nem "corrigida"
 * com trigger de banco)**: mass update via Query Builder
 * (`ItemTakeOff::where(...)->update([...])`) e `DB::table('itens_take_off')
 * ->update(...)` NUNCA disparam eventos de model (`creating`/`updating`),
 * bypassando este Observer por completo — mesma limitação já aceita pra
 * `RecebimentoPedido`/`MovimentacaoEstoque` desde o Ciclo 19. Grep
 * exaustivo (20.1.CORREÇÃO) confirmou **zero writer de produção** usa
 * qualquer uma das duas formas contra `material_id` — `material_id` só
 * pode ser alterado pela Action oficial, por convenção arquitetural, não
 * por garantia de banco.
 */
class ItemTakeOffObserver
{
    public function creating(ItemTakeOff $item): void
    {
        $this->garantirListaEditavel($item);
    }

    public function updating(ItemTakeOff $item): void
    {
        $this->garantirListaEditavel($item);
        $this->garantirMaterialNaoReescritoAposCorte($item);
    }

    public function deleting(ItemTakeOff $item): void
    {
        $this->garantirListaEditavel($item);
        $this->garantirSemReferenciaDeRequisicao($item);
    }

    private function garantirListaEditavel(ItemTakeOff $item): void
    {
        $lista = ListaEngenharia::find($item->lista_engenharia_id);

        // lista_engenharia_id é NOT NULL no schema — ausência aqui só
        // aconteceria com dado corrompido; deixa o erro de FK natural
        // acontecer em vez de mascarar com uma exceção de domínio.
        $lista?->garantirEditavel();
    }

    private function garantirSemReferenciaDeRequisicao(ItemTakeOff $item): void
    {
        if (RequisicaoPlanejamentoItem::where('item_take_off_id', $item->id)->exists()) {
            throw new ItemTakeOffReferenciadoException(
                'Este item já está vinculado a uma Requisição do Planejamento e não pode ser excluído. '
                . 'Se a vinculação estiver apenas em rascunho, remova o item da requisição antes de tentar novamente. '
                . 'Se a requisição já foi emitida, a exclusão não é permitida — o item é a origem estrutural de uma demanda formal já registrada.'
            );
        }
    }

    private function garantirMaterialNaoReescritoAposCorte(ItemTakeOff $item): void
    {
        if (! $item->isDirty('material_id')) {
            return;
        }

        if (! PoliticaAssociacaoMaterial::podeAlterarMaterial($item)) {
            throw new ItemTakeOffMaterialImutavelException(
                'Este item já possui um Pedido de Compra emitido, uma entrada em estoque, ou já participa de um Pacote com Destinação Planejada para este Material — a associação de Material não pode mais ser alterada.'
            );
        }
    }
}
