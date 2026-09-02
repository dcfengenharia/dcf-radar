<?php

namespace App\Enums;

/**
 * Ciclo 21, Etapa 21.2 — catálogo TIPADO e fechado das situações
 * gerenciais que `App\Support\Gestao\SituacoesGerenciaisQuery` consegue
 * detectar DETERMINISTICAMENTE hoje. Nunca strings soltas espalhadas
 * pelo código (Seção 5 do pedido).
 *
 * **`ReservaDescoberta` absorve o conceito de "RecomposicaoNecessaria"**
 * (decisão desta etapa, fresh-read confirmou): `App\Support\Estoque\
 * CoberturaReservas` já retorna `deficit` E `reposicao_necessaria` como
 * o MESMO número (`reposicao_necessaria` é só um alias de exibição,
 * Ciclo 20.4) — criar 2 tipos de situação pro mesmo fato seria uma
 * taxonomia falsa, não um reflexo do domínio real.
 *
 * **`RecebimentoPendente` é distinto de `PedidoAtrasado`**: Pedido
 * Emitido com entrega ainda não completa, mas DENTRO do prazo
 * (`diasAtrasoAtual() === null` porque ainda não passou de
 * `data_prevista_entrega`) — informativo ("uma entrega está a
 * caminho"), nunca confundido com o Pedido já atrasado.
 */
enum TipoSituacaoGerencial: string
{
    case MaterialCritico = 'material_critico';
    case ReservaDescoberta = 'reserva_descoberta';
    case PedidoAtrasado = 'pedido_atrasado';
    case RecebimentoPendente = 'recebimento_pendente';
    case MaterialSemDestinacao = 'material_sem_destinacao';
    case SaidaSemConciliacao = 'saida_sem_conciliacao';
    case DesvioAplicacao = 'desvio_aplicacao';
    case InventarioAguardandoDecisao = 'inventario_aguardando_decisao';
    case DocumentoBloqueante = 'documento_bloqueante';
    case IndustrializacaoPendente = 'industrializacao_pendente';
    case MaterialParado = 'material_parado';
    /**
     * Ciclo 22, Etapa 22.3 — ÚNICO fato de Engenharia (22.1) promovido ao
     * motor gerencial global, após inventário completo. Nunca duplica
     * `DocumentoBloqueante` (fenômeno diferente: aceite de GRD, não
     * liberação de documento). Nunca duplica o legado GRD do Ciclo 18.5.5
     * (`GrdCopiasObsoletasNotification`/`GrdPendenciasDigestNotification`
     * cobrem SÓ cópia obsoleta/candidatos — confirmado por grep, nenhuma
     * notification cobre "aceite" até esta etapa). Identidade lógica:
     * `grd_aguardando_aceite:{grd_destinatario_id}` — o MESMO destinatário
     * da MESMA GRD, estável enquanto não houver `GrdAceiteEntrega` ativo.
     */
    case GrdAguardandoAceite = 'grd_aguardando_aceite';

    public function label(): string
    {
        return match ($this) {
            self::MaterialCritico => 'Material crítico para atividade próxima',
            self::ReservaDescoberta => 'Reserva descoberta / recomposição necessária',
            self::PedidoAtrasado => 'Pedido de Compra atrasado',
            self::RecebimentoPendente => 'Recebimento pendente (dentro do prazo)',
            self::MaterialSemDestinacao => 'Material recebido sem Destinação Planejada',
            self::SaidaSemConciliacao => 'Saída de estoque sem conciliação',
            self::DesvioAplicacao => 'Aplicação diferente da destinação planejada',
            self::InventarioAguardandoDecisao => 'Inventário aguardando decisão',
            self::DocumentoBloqueante => 'Documento de Engenharia não liberado bloqueando atividade',
            self::IndustrializacaoPendente => 'Industrialização com saldo pendente',
            self::MaterialParado => 'Material sem movimentação recente',
            self::GrdAguardandoAceite => 'GRD aguardando aceite de recebimento',
        };
    }

    /**
     * Domínio de origem — usado só pra agrupamento no Resumo Executivo
     * (Seção 16), nunca pra classificação/severidade.
     */
    public function dominio(): string
    {
        return match ($this) {
            self::MaterialCritico, self::ReservaDescoberta, self::MaterialSemDestinacao,
            self::SaidaSemConciliacao, self::DesvioAplicacao, self::MaterialParado => 'estoque',
            self::PedidoAtrasado, self::RecebimentoPendente => 'suprimentos',
            self::InventarioAguardandoDecisao => 'inventario',
            self::DocumentoBloqueante, self::GrdAguardandoAceite => 'engenharia',
            self::IndustrializacaoPendente => 'industrializacao',
        };
    }
}
