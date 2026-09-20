<?php

namespace App\Actions\Estoque;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\OperacaoEstoqueDuplicadaException;
use App\Exceptions\ProducaoIndustrializadaInvalidaException;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\ProducaoIndustrializada;
use App\Models\ProdutoIndustrializado;
use App\Models\UnidadeEstoque;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.5 — evento de FABRICAÇÃO: o produto passa a
 * existir fisicamente, em custódia do Local Terceiro da Ordem. Cria
 * uma `MovimentacaoEstoque::Entrada` técnica ali, sobre o
 * `material_id` do `ProdutoIndustrializado` — reaproveita 100% do
 * ledger existente, nunca um saldo paralelo.
 *
 * **Serial/Lote — mesma resolução de `RegistrarEntradaEstoque`**, mas
 * NUNCA reaproveita `recebimento_pedido_id` (esta unidade nasce de uma
 * fabricação, nunca de um Pedido — o campo fica `null`, já nullable
 * desde 20.1).
 *
 * **Sem limite de over-produção nesta fase** (decisão de implementação
 * documentada na migration) — a UI só exibe a divergência entre
 * previsto e produzido, nunca bloqueia.
 *
 * **Auditoria Pré-Produção A2.2, Seções 3-6 (idempotência, fecha o risco
 * histórico de estoque fantasma por retry de produção)**: `$operationId`
 * OPCIONAL, mesmo contrato de `RegistrarEntradaEstoque` (A2.1) — o
 * pré-check e o catch de corrida real ficam FORA da transação porque
 * `resolverOuCriarUnidade()` pode criar uma `UnidadeEstoque` de lote
 * especulativa antes do INSERT final. Reproduzido empiricamente ANTES
 * desta correção em `tests/Feature/Auditoria/IndustrializacaoReproducaoRiscoTest.php`.
 */
class RegistrarProducaoIndustrializada
{
    public function execute(
        ProdutoIndustrializado $produto,
        float $quantidade,
        \DateTimeInterface $ocorridoEm,
        User $usuario,
        ?string $codigoLote = null,
        ?string $serialUnico = null,
        ?string $identificadorLogistico = null,
        ?string $observacao = null,
        ?string $operationId = null,
    ): ProducaoIndustrializada {
        if ($operationId !== null) {
            $existente = ProducaoIndustrializada::where('operation_id', $operationId)->first();
            if ($existente) {
                return $this->validarOuRetornarExistente($existente, $produto, $quantidade);
            }
        }

        try {
            return DB::transaction(function () use ($produto, $quantidade, $ocorridoEm, $usuario, $codigoLote, $serialUnico, $identificadorLogistico, $observacao, $operationId) {
            $this->garantirQuantidadePositiva($quantidade);

            $ordem = OrdemIndustrializacao::findOrFail($produto->ordem_industrializacao_id);
            if (! $ordem->estaEmitida()) {
                throw new ProducaoIndustrializadaInvalidaException('Só é possível registrar produção em uma Ordem Emitida.');
            }

            $dataProducao = Carbon::parse($ocorridoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataProducao);

            $localTerceiro = LocalEstoque::findOrFail($ordem->local_terceiro_id);
            $material = Material::findOrFail($produto->material_id);

            $unidade = $this->resolverOuCriarUnidade($material, $localTerceiro, $quantidade, $usuario, $codigoLote, $serialUnico, $identificadorLogistico);

            $movimentacaoEntrada = MovimentacaoEstoque::create([
                'obra_id' => $ordem->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Entrada,
                'material_id' => $material->id,
                'local_estoque_id' => $localTerceiro->id,
                'unidade_estoque_id' => $unidade?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataProducao,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            return ProducaoIndustrializada::create([
                'operation_id' => $operationId,
                'obra_id' => $ordem->obra_id,
                'produto_industrializado_id' => $produto->id,
                'quantidade' => $quantidade,
                'unidade_estoque_id' => $unidade?->id,
                'ocorrido_em' => $dataProducao,
                'movimentacao_entrada_id' => $movimentacaoEntrada->id,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);
            });
        } catch (QueryException $e) {
            if ($operationId !== null && ($e->errorInfo[1] ?? null) === 1062) {
                $existente = ProducaoIndustrializada::where('operation_id', $operationId)->first();
                if ($existente) {
                    return $this->validarOuRetornarExistente($existente, $produto, $quantidade);
                }
            }

            throw $e;
        }
    }

    /**
     * Auditoria Pré-Produção A2.2, Seção 5 — retry da MESMA intenção
     * (mesmo Produto/quantidade) retorna o fato já existente, sem
     * duplicar; quantidade divergente vira conflito.
     */
    private function validarOuRetornarExistente(ProducaoIndustrializada $existente, ProdutoIndustrializado $produto, float $quantidade): ProducaoIndustrializada
    {
        $mesmoProduto = $existente->produto_industrializado_id === $produto->id;
        $mesmaQuantidade = abs((float) $existente->quantidade - $quantidade) <= 0.0005;

        if (! $mesmoProduto || ! $mesmaQuantidade) {
            throw new OperacaoEstoqueDuplicadaException();
        }

        return $existente;
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new ProducaoIndustrializadaInvalidaException('A quantidade produzida precisa ser maior que zero.');
        }
    }

    private function garantirDataNaoFutura(Carbon $data): void
    {
        if ($data->gt(Carbon::today())) {
            throw new ProducaoIndustrializadaInvalidaException('A data da produção não pode estar no futuro.');
        }
    }

    private function resolverOuCriarUnidade(
        Material $material,
        LocalEstoque $local,
        float $quantidade,
        User $usuario,
        ?string $codigoLote,
        ?string $serialUnico,
        ?string $identificadorLogistico,
    ): ?UnidadeEstoque {
        return match ($material->modo_rastreabilidade) {
            ModoRastreabilidadeMaterial::Quantitativo => null,
            ModoRastreabilidadeMaterial::Lote => $this->resolverUnidadeLote($material, $local, $usuario, $codigoLote, $identificadorLogistico),
            ModoRastreabilidadeMaterial::Serializado => $this->criarUnidadeSerial($material, $local, $quantidade, $usuario, $serialUnico, $identificadorLogistico),
        };
    }

    private function resolverUnidadeLote(Material $material, LocalEstoque $local, User $usuario, ?string $codigoLote, ?string $identificadorLogistico): UnidadeEstoque
    {
        if (! $codigoLote) {
            throw new ProducaoIndustrializadaInvalidaException('Este Material exige informar o lote para registrar a produção.');
        }

        $existente = UnidadeEstoque::where('material_id', $material->id)->where('codigo_lote', $codigoLote)->first();

        if ($existente) {
            if ($existente->local_estoque_id !== $local->id) {
                throw new ProducaoIndustrializadaInvalidaException('Este lote já está registrado em outro Local de Estoque.');
            }

            return $existente;
        }

        return UnidadeEstoque::create([
            'material_id' => $material->id,
            'local_estoque_id' => $local->id,
            'codigo_lote' => $codigoLote,
            'identificador_logistico' => $identificadorLogistico,
            'created_by_id' => $usuario->id,
        ]);
    }

    private function criarUnidadeSerial(Material $material, LocalEstoque $local, float $quantidade, User $usuario, ?string $serialUnico, ?string $identificadorLogistico): UnidadeEstoque
    {
        if (! $serialUnico) {
            throw new ProducaoIndustrializadaInvalidaException('Este Material exige informar o serial para registrar a produção.');
        }

        if (abs($quantidade - 1.0) > 0.0005) {
            throw new ProducaoIndustrializadaInvalidaException('Material serializado exige quantidade igual a 1 por evento de produção — registre uma peça por vez.');
        }

        try {
            return UnidadeEstoque::create([
                'material_id' => $material->id,
                'local_estoque_id' => $local->id,
                'serial_unico' => $serialUnico,
                'identificador_logistico' => $identificadorLogistico,
                'created_by_id' => $usuario->id,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new ProducaoIndustrializadaInvalidaException('Este serial já está cadastrado.');
            }

            throw $e;
        }
    }
}
