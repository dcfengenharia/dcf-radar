<?php

namespace App\Actions\Estoque;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\TipoLocalEstoque;
use App\Exceptions\ReservaEstoqueInvalidaException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Models\DestinacaoPlanejadaMaterial;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\ReservaEstoque;
use App\Models\UnidadeEstoque;
use App\Models\User;
use App\Support\Estoque\SaldoEstoque;
use App\Support\Estoque\SaldoReserva;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.2 — único ponto de escrita de ReservaEstoque.
 * NUNCA cria MovimentacaoEstoque (Seção 15/21) — saldo físico
 * permanece inalterado, só o saldo DISPONÍVEL (App\Support\Estoque\
 * SaldoReserva) reduz.
 *
 * **Granularidade física, decidida pelo modo de rastreabilidade do
 * Material** (Seção 16/28/29, mesma resolução já usada por
 * App\Actions\Estoque\RegistrarEntradaEstoque): Quantitativo reserva
 * Material+Local (unidade_estoque_id null); Lote/Serializado reservam
 * uma UnidadeEstoque específica (bobina/serial) — nunca fração de um
 * modo pro outro.
 *
 * **Concorrência (Seção 20)**: como não existe uma linha física única
 * representando "saldo de Material X no Local Y" pra Quantitativo, o
 * recurso travado é o próprio `LocalEstoque` (decisão desta etapa,
 * documentada — mesmo custo aceito em outras partes do projeto:
 * serializa reservas de MATERIAIS DIFERENTES no mesmo Local
 * desnecessariamente, mas nunca permite over-reserva do par real
 * Material+Local, que é o que importa). Pra Lote/Serializado, trava a
 * própria `UnidadeEstoque` — granularidade fina, mesmo padrão exato já
 * usado por RegistrarEntradaEstoque (RecebimentoPedido::lockForUpdate()
 * antes de somar).
 *
 * **Serial não duplica reserva "por regra especial"**: quantidade de
 * uma UnidadeEstoque serializada é sempre 1 (regra de
 * RegistrarEntradaEstoque) — uma vez que exista 1 reserva Ativa de
 * quantidade 1 contra ela, o saldo disponível vira 0 e uma segunda
 * tentativa já falha pelo guard geral de over-reserva, sem precisar de
 * nenhuma checagem duplicada.
 *
 * **Ciclo 20, Etapa 20.2.CORREÇÃO (fecha o Achado B1)**: `$pacote`
 * (`ItemSuprimento`) passou a ser parâmetro OBRIGATÓRIO — decisão do
 * usuário após a auditoria adversarial: toda Reserva sabe "para qual
 * Pacote", mesmo sem Destinação (`$destinacao=null` continua
 * representando "Pacote conhecido, Frente ainda não detalhada", nunca
 * uma Frente fake). Quando `$destinacao` é informada, ela precisa
 * pertencer ao MESMO Pacote (`item_suprimento_id`) — não só ao mesmo
 * Material — checagem nova em `garantirDestinacaoCompativel()`.
 */
class CriarReservaEstoque
{
    public function execute(
        ItemSuprimento $pacote,
        Material $material,
        LocalEstoque $local,
        float $quantidade,
        ?UnidadeEstoque $unidade = null,
        ?DestinacaoPlanejadaMaterial $destinacao = null,
        ?User $usuario = null,
        ?string $observacao = null,
    ): ReservaEstoque {
        return DB::transaction(function () use ($pacote, $material, $local, $quantidade, $unidade, $destinacao, $usuario, $observacao) {
            $this->garantirQuantidadePositiva($quantidade);
            $this->garantirMaterialAtivo($material);
            $this->garantirLocalAtivo($local);
            $this->garantirPacoteCompativel($pacote, $local);
            $this->garantirUnidadeCompativel($material, $local, $unidade);
            $this->garantirQuantidadeSerialUnitaria($material, $quantidade);
            $this->garantirDestinacaoCompativel($destinacao, $pacote, $material, $local);

            if ($unidade) {
                $unidadeTravada = UnidadeEstoque::whereKey($unidade->id)->lockForUpdate()->firstOrFail();
                // Ciclo 20.5.CORREÇÃO: nunca reservar mais do que existe
                // FISICAMENTE nesta Local (fecha o Achado C1) — combinado
                // com o disponível GLOBAL já existente (reservas contra
                // a mesma unidade em qualquer Local, comportamento
                // intocado desde a 20.2). O menor dos dois é sempre a
                // cota real disponível.
                $disponivel = min(
                    SaldoReserva::disponivelPorUnidade($unidadeTravada),
                    SaldoEstoque::porUnidadeLocal($unidadeTravada, $local)
                );
            } else {
                LocalEstoque::whereKey($local->id)->lockForUpdate()->firstOrFail();
                $disponivel = SaldoReserva::disponivelPorMaterialLocal($material, $local);
            }

            if ($quantidade > $disponivel + 0.0005) {
                throw new SaldoFisicoInsuficienteException(
                    "Não há saldo físico disponível suficiente para reservar ({$disponivel} disponível, {$quantidade} informado).",
                    $disponivel,
                    $quantidade
                );
            }

            return ReservaEstoque::create([
                'obra_id' => $local->obra_id,
                'item_suprimento_id' => $pacote->id,
                'material_id' => $material->id,
                'local_estoque_id' => $local->id,
                'unidade_estoque_id' => $unidade?->id,
                'destinacao_planejada_material_id' => $destinacao?->id,
                'quantidade' => $quantidade,
                'status' => \App\Enums\StatusReservaEstoque::Ativa->value,
                'created_by_id' => $usuario?->id,
                'observacao' => $observacao,
            ]);
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new ReservaEstoqueInvalidaException('A quantidade da reserva precisa ser maior que zero.');
        }
    }

    private function garantirMaterialAtivo(Material $material): void
    {
        if (! $material->ativo) {
            throw new ReservaEstoqueInvalidaException('Este Material está inativo e não pode receber nova reserva.');
        }
    }

    private function garantirLocalAtivo(LocalEstoque $local): void
    {
        if (! $local->ativo) {
            throw new ReservaEstoqueInvalidaException('Este Local de Estoque está inativo e não pode receber nova reserva.');
        }

        if ($local->tipo === TipoLocalEstoque::Terceiro) {
            throw new ReservaEstoqueInvalidaException(
                'Este Local é de custódia de Terceiro — Reserva pra demanda de campo só é permitida em Locais próprios da obra (Ciclo 20.5).'
            );
        }
    }

    /**
     * Ciclo 20.2.CORREÇÃO — cross-obra bloqueado estruturalmente: o
     * Pacote (obra-scoped via ItemSuprimento.obra_id) precisa pertencer
     * à MESMA obra do Local físico onde a reserva está sendo feita.
     */
    private function garantirPacoteCompativel(ItemSuprimento $pacote, LocalEstoque $local): void
    {
        if ($pacote->obra_id !== $local->obra_id) {
            throw new ReservaEstoqueInvalidaException('Este Pacote de Compra não pertence à mesma obra deste Local de Estoque.');
        }
    }

    /**
     * Seção 16/29: modo Quantitativo nunca aceita UnidadeEstoque; modo
     * Lote/Serializado sempre exige uma UnidadeEstoque já existente do
     * MESMO Material e MESMO Local. Serializado adicionalmente exige
     * quantidade == 1 (mesma regra de RegistrarEntradaEstoque).
     */
    private function garantirUnidadeCompativel(Material $material, LocalEstoque $local, ?UnidadeEstoque $unidade): void
    {
        if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Quantitativo) {
            if ($unidade) {
                throw new ReservaEstoqueInvalidaException('Este Material é Quantitativo — não aceita reserva por lote/serial específico.');
            }

            return;
        }

        if (! $unidade) {
            throw new ReservaEstoqueInvalidaException('Este Material exige selecionar um lote/bobina ou serial específico para reservar.');
        }

        if ($unidade->material_id !== $material->id) {
            throw new ReservaEstoqueInvalidaException('A unidade selecionada não pertence a este Material.');
        }

        // Ciclo 20.5.CORREÇÃO: presença física é sempre derivada do ledger
        // (nunca mais de `local_estoque_id`, que virou só "local de
        // criação/origem" — uma bobina/lote pode ter saldo em mais de um
        // Local ao mesmo tempo após uma remessa parcial pra Terceiro).
        // Só dispara quando a unidade TEM saldo em OUTRO Local (relocação
        // genuína) — sem saldo em NENHUM Local cai no guard de saldo
        // disponível insuficiente, mais específico.
        if (SaldoEstoque::porUnidadeLocal($unidade, $local) <= 0.0005 && SaldoEstoque::porUnidade($unidade) > 0.0005) {
            throw new ReservaEstoqueInvalidaException('A unidade selecionada não tem saldo físico neste Local de Estoque — parte ou todo o saldo está em outro Local.');
        }
    }

    /**
     * Seção 29: "não permitir reservar 0.5 unidade" — quantidade de uma
     * reserva sobre Material Serializado precisa ser EXATAMENTE 1 (mesma
     * regra explícita já usada por RegistrarEntradaEstoque, nunca só
     * confiada ao guard geral de over-reserva — 0.5 nunca ultrapassaria
     * o disponível de 1, então precisa de checagem própria, diferente da
     * duplicidade de reserva, que aí sim é só consequência natural do
     * saldo disponível chegar a zero).
     */
    private function garantirQuantidadeSerialUnitaria(Material $material, float $quantidade): void
    {
        if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Serializado && abs($quantidade - 1.0) > 0.0005) {
            throw new ReservaEstoqueInvalidaException('Material serializado exige quantidade igual a 1 por reserva — reserve um serial por vez.');
        }
    }

    /**
     * Seção 25/31 (20.2) + Achado B1 fechado (20.2.CORREÇÃO): reserva
     * sem Destinação é permitida (destinacao=null) — quando presente,
     * precisa ser do MESMO Pacote E do MESMO Material (nunca reservar
     * Material X contra uma Destinação de Material Y, nem misturar
     * Pacotes diferentes) e da MESMA obra do Local (cross-obra bloqueado
     * estruturalmente, nunca só por permissão do usuário).
     */
    private function garantirDestinacaoCompativel(?DestinacaoPlanejadaMaterial $destinacao, ItemSuprimento $pacote, Material $material, LocalEstoque $local): void
    {
        if (! $destinacao) {
            return;
        }

        if ($destinacao->item_suprimento_id !== $pacote->id) {
            throw new ReservaEstoqueInvalidaException('A Destinação Planejada selecionada é de outro Pacote de Compra.');
        }

        if ($destinacao->material_id !== $material->id) {
            throw new ReservaEstoqueInvalidaException('A Destinação Planejada selecionada é de outro Material.');
        }

        if ($destinacao->obra_id !== $local->obra_id) {
            throw new ReservaEstoqueInvalidaException('A Destinação Planejada selecionada não pertence à mesma obra deste Local de Estoque.');
        }
    }
}
