<?php

namespace App\Actions\Estoque;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\SaidaEstoqueInvalidaException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\ReservaEstoque;
use App\Models\UnidadeEstoque;
use App\Models\User;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.3 — registra UM evento append-only de Saída física
 * de estoque. `MovimentacaoEstoque` continua sendo a ÚNICA entidade de
 * fato físico (Entrada e Saída) — investigação, Seção 4.
 *
 * **Saída física ≠ Aplicação definitiva** (princípio 8 do pedido): esta
 * Action só registra "o material saiu do controle físico do estoque" —
 * nunca calcula desvio, nunca concilia contra a Frente informada, nunca
 * cria Restrição/Notification. Isso é responsabilidade de uma fase
 * futura (20.4), que lerá esta Saída sem nunca reescrevê-la.
 *
 * **Cardinalidade Saída→Reserva (decisão do usuário)**: no máximo UMA
 * `ReservaEstoque` por Saída — uma retirada que precise consumir várias
 * Reservas gera N chamadas a esta Action, dentro da MESMA transação do
 * chamador (UI), nunca um algoritmo de rateio automático aqui dentro.
 *
 * **Saldo — decisão do usuário (item 15)**: Saída SEM Reserva valida
 * SEMPRE contra o saldo FÍSICO total (`App\Support\Estoque\SaldoEstoque`,
 * intocado desde 20.1/20.1.CORREÇÃO) — NUNCA contra o saldo disponível
 * não-reservado (`App\Support\Estoque\SaldoReserva::disponivel*()`).
 * Consumir estoque fisicamente reservado para outra demanda é permitido
 * (cenário real de emergência/desvio de frente do próprio pedido) — a
 * `ReservaEstoque` original NUNCA é tocada/reduzida/liberada
 * automaticamente por essa saída livre; o eventual déficit
 * (`físico < reservado`) fica derivável (`SaldoReserva`) para uma fase
 * futura (20.4) tratar, nunca escolhido automaticamente aqui qual
 * Reserva foi prejudicada. A advertência "isso vai consumir estoque
 * reservado" é responsabilidade da UI (mostrar e pedir confirmação),
 * NUNCA um bloqueio desta Action — o único bloqueio duro é sobre o
 * saldo FÍSICO (nunca permite saldo físico negativo).
 *
 * **Pacote/demanda (item 9, decisão do usuário)**: quando a Saída
 * consome uma Reserva, `item_suprimento_id` é sempre DERIVADO dela —
 * se o chamador também passar `$pacote` explicitamente, precisa bater
 * exatamente (nunca reinterpreta a demanda de uma Reserva). Sem Reserva,
 * `$pacote` é totalmente opcional — sua ausência nunca bloqueia a saída
 * (`null` = "demanda ainda não conciliada", nunca inventado como
 * Pacote inventado como fallback).
 *
 * **Frente informada (itens 7/8/32)**: `$frenteInformada` é só o destino
 * dito pelo operador NAQUELE INSTANTE — pode divergir livremente da
 * Frente da Destinação/Reserva original (nunca bloqueado, nunca altera
 * `DestinacaoPlanejadaMaterial`), e pode ser omitida (saída "pendente de
 * conciliação" — nunca uma Frente inventada como fallback).
 *
 * **Retirante — decisão de produto (Ciclo 20.3.CORREÇÃO, fecha o Achado
 * C1 e a Decisão D2 da auditoria adversarial)**: toda Saída precisa
 * identificar EXATAMENTE UM retirante — `$retiradoPor` (usuário
 * cadastrado) OU `$retiradoPorExterno` (pessoa sem login, texto livre
 * normalizado com `trim()` — string vazia/só espaço conta como
 * ausência), nunca os dois, nunca nenhum. `registrado_por` continua
 * significando exclusivamente "quem lançou no sistema" — nunca
 * preenchido automaticamente como retirante quando o operador não
 * informa (a Action não tem acesso ao usuário autenticado além de
 * `$usuarioRegistro`, e mesmo esse nunca é usado como fallback de
 * retirante). Quando `$retiradoPor` é um usuário cadastrado, ele
 * precisa pertencer ao MESMO tenant do Local/obra (checagem direta,
 * nunca delegada só à UI) E estar vinculado à MESMA obra
 * (`HasObraPapel::temAcessoAObra()`, decisão do usuário — um usuário
 * sem vínculo com a obra não deveria aparecer como retirante interno
 * dela, mesmo sendo do tenant certo).
 *
 * **Granularidade física — mesma resolução de `RegistrarEntradaEstoque`/
 * `CriarReservaEstoque`**: modo Quantitativo nunca aceita `$unidade`;
 * Lote/Serializado sempre exigem uma `UnidadeEstoque` JÁ EXISTENTE
 * (Saída nunca cria uma unidade nova, diferente da Entrada).
 *
 * **Concorrência — mesmo total order já usado por `CriarReservaEstoque`**:
 * o recurso físico (`LocalEstoque` ou `UnidadeEstoque`, conforme o modo)
 * é travado SEMPRE PRIMEIRO, antes de qualquer SUM de saldo — depois,
 * se a Saída consome uma Reserva, `ReservaEstoque` é travada EM SEGUIDA
 * (mesma ordem: recurso físico antes do recurso lógico que o consome).
 */
class RegistrarSaidaEstoque
{
    public function execute(
        Material $material,
        LocalEstoque $local,
        float $quantidade,
        \DateTimeInterface $ocorridoEm,
        User $usuarioRegistro,
        ?ReservaEstoque $reserva = null,
        ?ItemSuprimento $pacote = null,
        ?FrenteTrabalho $frenteInformada = null,
        ?UnidadeEstoque $unidade = null,
        ?User $retiradoPor = null,
        ?string $retiradoPorExterno = null,
        ?string $observacao = null,
    ): MovimentacaoEstoque {
        return DB::transaction(function () use (
            $material, $local, $quantidade, $ocorridoEm, $usuarioRegistro,
            $reserva, $pacote, $frenteInformada, $unidade, $retiradoPor, $retiradoPorExterno, $observacao
        ) {
            $this->garantirQuantidadePositiva($quantidade);

            $dataSaida = Carbon::parse($ocorridoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataSaida);

            $this->garantirMaterialAtivo($material);
            $this->garantirLocalAtivo($local);

            $retiradoPorExternoNormalizado = $this->normalizarRetiradoPorExterno($retiradoPorExterno);
            $this->garantirRetiradoPorValido($local, $retiradoPor, $retiradoPorExternoNormalizado);

            if ($frenteInformada && $frenteInformada->obra_id !== $local->obra_id) {
                throw new SaidaEstoqueInvalidaException('Esta Frente de Trabalho não pertence à mesma obra deste Local de Estoque.');
            }

            if ($pacote && $pacote->obra_id !== $local->obra_id) {
                throw new SaidaEstoqueInvalidaException('Este Pacote de Compra não pertence à mesma obra deste Local de Estoque.');
            }

            // Recurso físico travado SEMPRE PRIMEIRO — mesmo total order de CriarReservaEstoque.
            if ($unidade) {
                $unidadeTravada = UnidadeEstoque::whereKey($unidade->id)->lockForUpdate()->firstOrFail();
            } else {
                LocalEstoque::whereKey($local->id)->lockForUpdate()->firstOrFail();
                $unidadeTravada = null;
            }

            $this->garantirUnidadeCompativel($material, $local, $unidadeTravada);
            $this->garantirQuantidadeSerialUnitaria($material, $quantidade);

            $itemSuprimentoFinal = $pacote;

            if ($reserva) {
                // Reserva (recurso lógico) travada EM SEGUIDA, depois do recurso físico.
                $reservaTravada = ReservaEstoque::whereKey($reserva->id)->lockForUpdate()->firstOrFail();

                $this->garantirReservaCompativel($reservaTravada, $material, $local, $unidadeTravada);

                if ($pacote && $pacote->id !== $reservaTravada->item_suprimento_id) {
                    throw new SaidaEstoqueInvalidaException('O Pacote informado diverge do Pacote da Reserva selecionada.');
                }

                $itemSuprimentoFinal = ItemSuprimento::find($reservaTravada->item_suprimento_id);

                $saldoPendente = SaldoReserva::saldoPendenteConsumo($reservaTravada);
                if ($quantidade > $saldoPendente + 0.0005) {
                    throw new SaidaEstoqueInvalidaException(
                        "Esta Reserva já tem apenas {$saldoPendente} pendente de consumo — não é possível retirar {$quantidade} vinculado a ela."
                    );
                }
            }

            // Ciclo 20.5.CORREÇÃO: saldo físico da unidade é sempre
            // ESCOPADO ao Local desta saída (nunca o total global da
            // unidade) — fecha o Achado C1, uma bobina/lote pode ter
            // saldo em mais de um Local ao mesmo tempo.
            $saldoFisico = $unidadeTravada
                ? SaldoEstoque::porUnidadeLocal($unidadeTravada, $local)
                : SaldoEstoque::porMaterialLocal($material, $local);

            if ($quantidade > $saldoFisico + 0.0005) {
                throw new SaldoFisicoInsuficienteException(
                    "Não há saldo físico suficiente para esta saída ({$saldoFisico} disponível, {$quantidade} informado).",
                    $saldoFisico,
                    $quantidade
                );
            }

            return MovimentacaoEstoque::create([
                'obra_id' => $local->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Saida,
                'material_id' => $material->id,
                'local_estoque_id' => $local->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'reserva_estoque_id' => $reserva?->id,
                'item_suprimento_id' => $itemSuprimentoFinal?->id,
                'frente_trabalho_id' => $frenteInformada?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataSaida,
                'registrado_por' => $usuarioRegistro->id,
                'retirado_por' => $retiradoPor?->id,
                'retirado_por_externo' => $retiradoPorExternoNormalizado,
                'observacao' => $observacao,
            ]);
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new SaidaEstoqueInvalidaException('A quantidade da saída precisa ser maior que zero.');
        }
    }

    private function garantirDataNaoFutura(Carbon $data): void
    {
        if ($data->gt(Carbon::today())) {
            throw new SaidaEstoqueInvalidaException('A data da saída não pode estar no futuro.');
        }
    }

    private function garantirMaterialAtivo(Material $material): void
    {
        if (! $material->ativo) {
            throw new SaidaEstoqueInvalidaException('Este Material está inativo e não pode ter nova saída registrada.');
        }
    }

    private function garantirLocalAtivo(LocalEstoque $local): void
    {
        if (! $local->ativo) {
            throw new SaidaEstoqueInvalidaException('Este Local de Estoque está inativo e não pode ter nova saída registrada.');
        }

        if ($local->tipo === TipoLocalEstoque::Terceiro) {
            throw new SaidaEstoqueInvalidaException(
                'Este Local é de custódia de Terceiro — retirada para campo/Frente só é permitida a partir de Locais próprios da obra (Ciclo 20.5).'
            );
        }
    }

    /**
     * Ciclo 20.3.CORREÇÃO — string vazia/só espaço em branco conta como
     * ausência (nunca um retirante externo "fantasma"). Nunca cria
     * cadastro de trabalhador/terceiro — só normaliza o texto livre já
     * recebido.
     */
    private function normalizarRetiradoPorExterno(?string $retiradoPorExterno): ?string
    {
        if ($retiradoPorExterno === null) {
            return null;
        }

        $normalizado = trim($retiradoPorExterno);

        return $normalizado === '' ? null : $normalizado;
    }

    /**
     * Ciclo 20.3.CORREÇÃO — fecha o Achado C1 (cross-tenant) e a Decisão
     * D2 (anonimato) da auditoria adversarial: toda Saída precisa de
     * EXATAMENTE UM retirante. `$retiradoPorExterno` já chega aqui
     * normalizado (trim aplicado, vazio já virou null) — nunca reavaliado
     * cru.
     */
    private function garantirRetiradoPorValido(LocalEstoque $local, ?User $retiradoPor, ?string $retiradoPorExternoNormalizado): void
    {
        if ($retiradoPor && $retiradoPorExternoNormalizado) {
            throw new SaidaEstoqueInvalidaException(
                'Informe apenas um: usuário do sistema OU nome de pessoa externa — não os dois ao mesmo tempo.'
            );
        }

        if (! $retiradoPor && ! $retiradoPorExternoNormalizado) {
            throw new SaidaEstoqueInvalidaException(
                'Informe quem retirou o material: selecione um usuário do sistema ou informe o nome de uma pessoa externa.'
            );
        }

        if ($retiradoPor) {
            if ($retiradoPor->tenant_id !== $local->tenant_id) {
                throw new SaidaEstoqueInvalidaException('O usuário retirante não pertence a este tenant.');
            }

            if (! $retiradoPor->temAcessoAObra($local->obra_id)) {
                throw new SaidaEstoqueInvalidaException('O usuário retirante não está vinculado a esta obra.');
            }
        }
    }

    /**
     * Mesma resolução de granularidade física de CriarReservaEstoque —
     * mas a Saída NUNCA cria uma UnidadeEstoque nova (diferente da
     * Entrada): a unidade precisa já existir.
     */
    private function garantirUnidadeCompativel(Material $material, LocalEstoque $local, ?UnidadeEstoque $unidade): void
    {
        if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Quantitativo) {
            if ($unidade) {
                throw new SaidaEstoqueInvalidaException('Este Material é Quantitativo — não aceita saída por lote/serial específico.');
            }

            return;
        }

        if (! $unidade) {
            throw new SaidaEstoqueInvalidaException('Este Material exige selecionar um lote/bobina ou serial específico para dar saída.');
        }

        if ($unidade->material_id !== $material->id) {
            throw new SaidaEstoqueInvalidaException('A unidade selecionada não pertence a este Material.');
        }

        // Ciclo 20.5.CORREÇÃO: presença física é sempre derivada do ledger
        // (nunca mais de `local_estoque_id`, que virou só "local de
        // criação/origem" — ver App\Support\Estoque\SaldoEstoque::porUnidadeLocal()).
        // Só dispara quando a unidade TEM saldo em OUTRO Local (relocação
        // genuína, ex.: remetida a um Terceiro) — uma unidade sem saldo
        // em NENHUM Local cai no guard de saldo físico insuficiente, mais
        // específico, em vez de soar como "está em outro lugar".
        if (SaldoEstoque::porUnidadeLocal($unidade, $local) <= 0.0005 && SaldoEstoque::porUnidade($unidade) > 0.0005) {
            throw new SaidaEstoqueInvalidaException('A unidade selecionada não tem saldo físico neste Local de Estoque — parte ou todo o saldo está em outro Local.');
        }
    }

    /**
     * Item 27: não permitir retirar 0.5 de um Material Serializado — a
     * quantidade precisa ser exatamente 1 (mesma regra explícita já
     * usada por RegistrarEntradaEstoque/CriarReservaEstoque, nunca só
     * confiada ao guard geral de saldo físico).
     */
    private function garantirQuantidadeSerialUnitaria(Material $material, float $quantidade): void
    {
        if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Serializado && abs($quantidade - 1.0) > 0.0005) {
            throw new SaidaEstoqueInvalidaException('Material serializado exige quantidade igual a 1 por saída — retire um serial por vez.');
        }
    }

    /**
     * Itens 28/29/30/31: uma Saída vinculada a uma Reserva precisa usar
     * exatamente o mesmo Material/Local/Unidade(quando fixada)/obra da
     * Reserva — nunca reinterpreta pra qual recurso físico a Reserva
     * apontava. Reserva precisa estar Ativa (Liberada nunca é
     * consumível). "Reserva quantitativa não fixa lote" (item 28) já é
     * coberto pela condição `$reserva->unidade_estoque_id` — só
     * Lote/Serializado fixam uma unidade na criação da Reserva
     * (CriarReservaEstoque::garantirUnidadeCompativel).
     */
    private function garantirReservaCompativel(ReservaEstoque $reserva, Material $material, LocalEstoque $local, ?UnidadeEstoque $unidade): void
    {
        if (! $reserva->estaAtiva()) {
            throw new SaidaEstoqueInvalidaException('Esta Reserva não está mais Ativa — não é possível vincular uma Saída a ela.');
        }

        if ($reserva->material_id !== $material->id) {
            throw new SaidaEstoqueInvalidaException('Esta Reserva é de outro Material.');
        }

        if ($reserva->local_estoque_id !== $local->id) {
            throw new SaidaEstoqueInvalidaException('Esta Reserva está fixada em outro Local de Estoque.');
        }

        if ($reserva->unidade_estoque_id && (! $unidade || $unidade->id !== $reserva->unidade_estoque_id)) {
            throw new SaidaEstoqueInvalidaException('Esta Reserva está fixada num lote/bobina/serial específico — a Saída precisa usar a mesma unidade.');
        }

        if ($reserva->obra_id !== $local->obra_id) {
            throw new SaidaEstoqueInvalidaException('Esta Reserva não pertence à mesma obra deste Local de Estoque.');
        }
    }
}
