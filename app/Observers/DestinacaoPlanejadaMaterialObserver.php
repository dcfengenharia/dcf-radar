<?php

namespace App\Observers;

use App\Exceptions\DestinacaoPlanejadaImutavelException;
use App\Models\DestinacaoPlanejadaMaterial;

/**
 * Ciclo 20, Etapa 20.2.CORREÇÃO — fecha o Achado C1 da auditoria
 * adversarial: `App\Actions\Estoque\AtualizarDestinacaoPlanejada::alterar()`
 * só expõe `quantidade_planejada` (nunca `frente_trabalho_id`) — mas
 * antes desta correção nada impedia um `$destinacao->update([...])`
 * direto de reescrever a identidade da linha (Pacote/Material/Frente),
 * reinterpretando retroativamente pra qual Frente uma Reserva já
 * vinculada "sempre pertenceu".
 *
 * **Decisão de domínio (Seção 5 do pedido de correção)**: identidade
 * ESTRUTURAL (`item_suprimento_id`/`material_id`/`frente_trabalho_id`/
 * `tenant_id`/`obra_id`) é imutável DESDE A CRIAÇÃO — não só depois de
 * existir uma Reserva. Simplifica a regra (uma única política, nunca
 * "antes/depois de Reserva") e é coerente com o resto do domínio: uma
 * troca de Frente é conceitualmente uma NOVA Destinação (Pacote+Material+
 * Frente nova), nunca uma edição da linha existente — mesma filosofia já
 * usada em toda a cadeia Ciclo 19 (RequisicaoPlanejamentoItem/
 * AlocacaoRequisicaoPacote nunca trocam a que RPItem/Pacote pertencem,
 * só a quantidade). `quantidade_planejada` continua livremente alterável
 * pela Action oficial (guards de saldo/reservado já existentes,
 * intocados por esta correção).
 *
 * Mesmo padrão dos Observers irmãos: barreira de DEFESA contra qualquer
 * escrita de INSTÂNCIA que não passe pela Action oficial — nunca o
 * mecanismo primário (que é a própria Action, que só expõe a mutação
 * permitida). **Limitação estrutural conhecida e aceita**: mass-update
 * via Query Builder (`DestinacaoPlanejadaMaterial::where(...)
 * ->update(...)`) nunca dispara `updating()` — confirmado por grep
 * (Ciclo 20, Etapa 20.2.AUDITORIA, item P10) que nenhum writer de
 * produção usa essa forma.
 */
class DestinacaoPlanejadaMaterialObserver
{
    private const CAMPOS_IDENTIDADE = ['item_suprimento_id', 'material_id', 'frente_trabalho_id', 'tenant_id', 'obra_id'];

    public function updating(DestinacaoPlanejadaMaterial $destinacao): void
    {
        foreach (self::CAMPOS_IDENTIDADE as $campo) {
            if ($destinacao->isDirty($campo)) {
                throw new DestinacaoPlanejadaImutavelException(
                    'A identidade desta Destinação Planejada (Pacote, Material e Frente de Trabalho) não pode ser alterada depois de criada — '
                    . 'crie uma nova Destinação Planejada em vez de reinterpretar esta.'
                );
            }
        }
    }
}
