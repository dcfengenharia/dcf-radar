<?php

namespace App\Enums;

/**
 * Etapa 3 (Seções 13/17/19) — sinaliza EXPLICITAMENTE quando um número do
 * read-model de atendimento não pode ser afirmado com a mesma certeza dos
 * demais — nunca escondido atrás de um zero/null silencioso. Múltiplas
 * flags podem coexistir na mesma necessidade (são independentes entre
 * si), por isso `EstadoAtendimentoNecessidadeMaterialQuery` retorna um
 * ARRAY destas, nunca um único valor "pior caso".
 */
enum QualidadeInformacaoAtendimento: string
{
    /**
     * O `PedidoCompraItem` que atende esta necessidade tem 2+ parcelas
     * (dividido entre 2+ necessidades) — `RecebimentoPedido` é registrado
     * no nível do ITEM, nunca da parcela (Seção 17/18 do pedido: fresh-
     * read confirmou que essa granularidade não existe hoje). O total
     * recebido do item é um fato real, mas NUNCA atribuível com certeza a
     * esta necessidade específica — por isso ela conta como 0 recebido
     * aqui, nunca uma estimativa proporcional inventada.
     */
    case RecebimentoNaoAtribuivelPorParcela = 'recebimento_nao_atribuivel_por_parcela';

    /**
     * A quantidade pedida pra esta necessidade vem de 2+ Pedidos Emitidos
     * com `data_prevista_entrega` DIFERENTES entre si — não existe uma
     * única "data prometida relevante" honesta (Seção 18: nunca MIN/MAX
     * perdendo quantidade). `data_prometida_relevante`/`folga_dias`
     * ficam `null`; a curva quantitativa (Seção 19) é quem carrega a
     * informação real neste caso.
     */
    case PromessaAmbiguaMultiploPedido = 'promessa_ambigua_multiplo_pedido';

    /**
     * A Atividade desta necessidade está `fora_do_cronograma` ou nunca
     * teve `inicio_planejado` importado — sem data de necessidade, a
     * curva temporal (antes/depois) e `folga_dias` não podem ser
     * calculados; só `qtd_sem_prazo`/`qtd_nao_pedida` continuam válidos.
     */
    case SemDataDeNecessidade = 'sem_data_de_necessidade';

    public function label(): string
    {
        return match ($this) {
            self::RecebimentoNaoAtribuivelPorParcela => 'Recebimento conhecido só no total do item (não distribuído por necessidade)',
            self::PromessaAmbiguaMultiploPedido => 'Múltiplos Pedidos com prazos diferentes — sem data única relevante',
            self::SemDataDeNecessidade => 'Atividade sem data de necessidade conhecida',
        };
    }
}
