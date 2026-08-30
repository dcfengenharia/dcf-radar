<?php

namespace App\Actions\Estoque;

use App\Enums\DirecaoRemessaIndustrializacao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\RemessaIndustrializacaoInvalidaException;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\RemessaIndustrializacao;
use App\Models\UnidadeEstoque;
use App\Models\User;
use App\Support\Estoque\SaldoEstoque;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.5 — evento FÍSICO de transferência de matéria-prima
 * entre o Local próprio da obra e o Local Terceiro de uma Ordem
 * (Opção A de custódia, confirmada pelo usuário). Constrói a Saida e a
 * Entrada correlatas DIRETAMENTE aqui — nunca delega pra
 * `RegistrarSaidaEstoque`/`RegistrarEntradaEstoque`, que agora
 * REJEITAM propositalmente qualquer operação envolvendo um Local
 * Terceiro (são fluxos de uso distintos: retirada pra campo e entrada
 * via Pedido/Recebimento, nenhum dos dois é este).
 *
 * `direcao=Envio`: Saida no Local próprio + Entrada no Local Terceiro.
 * `direcao=RetornoSobra`: o inverso — matéria-prima nunca consumida
 * volta fisicamente à obra (Seção 22).
 *
 * As duas pontas SEMPRE nascem na MESMA transação, correlacionadas por
 * esta própria linha de `RemessaIndustrializacao` (nunca 2 operações
 * desconectadas — requisito explícito do usuário).
 *
 * **Ciclo 20.5.CORREÇÃO — fecha o Achado C1 (bobina/lote parcialmente
 * remetida deixava o restante inacessível na origem)**: `UnidadeEstoque`
 * é uma identidade física ÚNICA (nunca duplicada/dividida em novas
 * linhas) que pode ter saldo em MAIS DE UM Local ao mesmo tempo — esta
 * Action NUNCA mais atualiza `UnidadeEstoque.local_estoque_id` (que virou
 * só "local de criação/origem", imutável e informativo). "Onde esta
 * unidade está e quanto tem em cada Local" é sempre derivado do ledger
 * via `App\Support\Estoque\SaldoEstoque::porUnidadeLocal()` — a Saida
 * (origem) + Entrada (destino) já registram isso corretamente por si só,
 * sem precisar de nenhum ponteiro de "localização atual".
 */
class RegistrarRemessaIndustrializacao
{
    public function execute(
        OrdemIndustrializacao $ordem,
        Material $material,
        float $quantidade,
        DirecaoRemessaIndustrializacao $direcao,
        LocalEstoque $localProprio,
        \DateTimeInterface $ocorridoEm,
        User $usuario,
        ?UnidadeEstoque $unidade = null,
        ?string $observacao = null,
    ): RemessaIndustrializacao {
        return DB::transaction(function () use (
            $ordem, $material, $quantidade, $direcao, $localProprio, $ocorridoEm, $usuario, $unidade, $observacao
        ) {
            $this->garantirQuantidadePositiva($quantidade);

            $ordemTravada = OrdemIndustrializacao::whereKey($ordem->id)->lockForUpdate()->firstOrFail();
            $this->garantirOrdemEmitida($ordemTravada);

            if ($localProprio->obra_id !== $ordemTravada->obra_id) {
                throw new RemessaIndustrializacaoInvalidaException('Este Local próprio não pertence à mesma obra da Ordem.');
            }

            if ($localProprio->tipo?->value === 'terceiro') {
                throw new RemessaIndustrializacaoInvalidaException('Selecione um Local PRÓPRIO da obra — não outro Local de custódia de Terceiro.');
            }

            $localTerceiro = LocalEstoque::findOrFail($ordemTravada->local_terceiro_id);

            [$localOrigem, $localDestino] = $direcao === DirecaoRemessaIndustrializacao::Envio
                ? [$localProprio, $localTerceiro]
                : [$localTerceiro, $localProprio];

            $dataRemessa = Carbon::parse($ocorridoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataRemessa);

            // Trava os 2 Locais em ordem determinística (por id) antes de
            // qualquer SUM — evita deadlock entre remessas concorrentes
            // que envolvam os mesmos 2 Locais em direções opostas.
            LocalEstoque::whereIn('id', [$localOrigem->id, $localDestino->id])->orderBy('id')->lockForUpdate()->get();

            $unidadeTravada = $unidade ? UnidadeEstoque::whereKey($unidade->id)->lockForUpdate()->firstOrFail() : null;
            $this->garantirUnidadeCompativel($material, $localOrigem, $unidadeTravada);

            // Ciclo 20.5.CORREÇÃO: saldo da unidade é sempre ESCOPADO ao
            // Local de ORIGEM desta remessa (nunca o total global da
            // unidade) — uma remessa parcial anterior pode já ter deixado
            // parte do saldo em outro Local.
            $saldoOrigem = $unidadeTravada
                ? SaldoEstoque::porUnidadeLocal($unidadeTravada, $localOrigem)
                : SaldoEstoque::porMaterialLocal($material, $localOrigem);

            if ($quantidade > $saldoOrigem + 0.0005) {
                throw new RemessaIndustrializacaoInvalidaException(
                    "Saldo insuficiente em {$localOrigem->nome} ({$saldoOrigem} disponível, {$quantidade} informado)."
                );
            }

            $saida = MovimentacaoEstoque::create([
                'obra_id' => $ordemTravada->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Saida,
                'material_id' => $material->id,
                'local_estoque_id' => $localOrigem->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataRemessa,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            $entrada = MovimentacaoEstoque::create([
                'obra_id' => $ordemTravada->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Entrada,
                'material_id' => $material->id,
                'local_estoque_id' => $localDestino->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataRemessa,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            // Ciclo 20.5.CORREÇÃO: `UnidadeEstoque.local_estoque_id`
            // NUNCA é mais atualizado aqui (fecha o Achado C1) — a Saida+
            // Entrada acima já são suficientes pro ledger responder
            // corretamente "quanto desta unidade tem em cada Local"
            // (App\Support\Estoque\SaldoEstoque::porUnidadeLocal()),
            // mesmo quando uma remessa é PARCIAL e a mesma identidade
            // física passa a ter saldo simultâneo em origem e destino.

            return RemessaIndustrializacao::create([
                'obra_id' => $ordemTravada->obra_id,
                'ordem_industrializacao_id' => $ordemTravada->id,
                'material_id' => $material->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'direcao' => $direcao,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataRemessa,
                'movimentacao_saida_id' => $saida->id,
                'movimentacao_entrada_id' => $entrada->id,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new RemessaIndustrializacaoInvalidaException('A quantidade da remessa precisa ser maior que zero.');
        }
    }

    private function garantirOrdemEmitida(OrdemIndustrializacao $ordem): void
    {
        if (! $ordem->estaEmitida()) {
            throw new RemessaIndustrializacaoInvalidaException('Só é possível registrar remessas em uma Ordem Emitida.');
        }
    }

    private function garantirDataNaoFutura(Carbon $data): void
    {
        if ($data->gt(Carbon::today())) {
            throw new RemessaIndustrializacaoInvalidaException('A data da remessa não pode estar no futuro.');
        }
    }

    private function garantirUnidadeCompativel(Material $material, LocalEstoque $localOrigem, ?UnidadeEstoque $unidade): void
    {
        if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Quantitativo) {
            if ($unidade) {
                throw new RemessaIndustrializacaoInvalidaException('Este Material é Quantitativo — não aceita remessa por lote/serial específico.');
            }

            return;
        }

        if (! $unidade) {
            throw new RemessaIndustrializacaoInvalidaException('Este Material exige selecionar um lote/bobina ou serial específico.');
        }

        if ($unidade->material_id !== $material->id) {
            throw new RemessaIndustrializacaoInvalidaException('A unidade selecionada não pertence a este Material.');
        }

        // Ciclo 20.5.CORREÇÃO: presença física é sempre derivada do ledger
        // (nunca mais de `local_estoque_id`, que virou só "local de
        // criação/origem") — uma unidade pode ter saldo em mais de um
        // Local ao mesmo tempo após uma remessa parcial anterior. Só
        // dispara quando a unidade TEM saldo em OUTRO Local (relocação
        // genuína) — sem saldo em NENHUM Local cai no guard de saldo
        // insuficiente, mais específico.
        if (SaldoEstoque::porUnidadeLocal($unidade, $localOrigem) <= 0.0005 && SaldoEstoque::porUnidade($unidade) > 0.0005) {
            throw new RemessaIndustrializacaoInvalidaException('A unidade selecionada não tem saldo físico no Local de origem desta remessa — parte ou todo o saldo está em outro Local.');
        }
    }
}
