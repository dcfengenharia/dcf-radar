<?php

namespace App\Actions\Estoque;

use App\Enums\ModalidadeEntregaProduto;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\EntregaProdutoIndustrializadoInvalidaException;
use App\Models\EntregaProdutoIndustrializado;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\ProducaoIndustrializada;
use App\Models\ProdutoIndustrializado;
use App\Models\UnidadeEstoque;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.5 — evento de ENTREGA: o produto sai do Local
 * Terceiro rumo a um destino (decisão do usuário confirmada, Opção A):
 * SEMPRE gera uma `Saida` técnica no Local Terceiro + uma `Entrada`
 * técnica no Local próprio da obra ("a Entrada é técnica/patrimonial,
 * não uma afirmação de que o material foi fisicamente estocado no
 * almoxarifado"). Quando `modalidade=EntregaDiretaCampo`, uma 3ª
 * Movimentação real reaproveita `App\Actions\Estoque\
 * RegistrarSaidaEstoque` de verdade — 100% do fluxo já existente
 * (retirado_por/Frente/Pacote, e posterior Aplicação/Conciliação
 * ficam disponíveis de graça).
 *
 * Guard de saldo: `quantidade <= produzido - entregue` do Produto,
 * ambos SEMPRE somados de `producoes_industrializadas`/
 * `entregas_produto_industrializado` sob lock do próprio
 * `ProdutoIndustrializado` (recurso lógico compartilhado por todas as
 * entregas concorrentes do mesmo produto).
 */
class RegistrarEntregaProdutoIndustrializado
{
    public function execute(
        ProdutoIndustrializado $produto,
        float $quantidade,
        ModalidadeEntregaProduto $modalidade,
        LocalEstoque $localDestino,
        \DateTimeInterface $ocorridoEm,
        User $usuario,
        ?UnidadeEstoque $unidade = null,
        ?FrenteTrabalho $frenteCampo = null,
        ?ItemSuprimento $pacoteCampo = null,
        ?User $retiradoPor = null,
        ?string $retiradoPorExterno = null,
        ?string $observacao = null,
    ): EntregaProdutoIndustrializado {
        return DB::transaction(function () use (
            $produto, $quantidade, $modalidade, $localDestino, $ocorridoEm, $usuario,
            $unidade, $frenteCampo, $pacoteCampo, $retiradoPor, $retiradoPorExterno, $observacao
        ) {
            $this->garantirQuantidadePositiva($quantidade);

            $produtoTravado = ProdutoIndustrializado::whereKey($produto->id)->lockForUpdate()->firstOrFail();

            $ordem = OrdemIndustrializacao::findOrFail($produtoTravado->ordem_industrializacao_id);
            if (! $ordem->estaEmitida()) {
                throw new EntregaProdutoIndustrializadoInvalidaException('Só é possível registrar entrega em uma Ordem Emitida.');
            }

            if ($localDestino->obra_id !== $ordem->obra_id) {
                throw new EntregaProdutoIndustrializadoInvalidaException('Este Local de destino não pertence à mesma obra da Ordem.');
            }

            if ($localDestino->tipo?->value === 'terceiro') {
                throw new EntregaProdutoIndustrializadoInvalidaException('Selecione um Local PRÓPRIO da obra como destino — não outro Local de custódia de Terceiro.');
            }

            if ($modalidade === ModalidadeEntregaProduto::EntregaDiretaCampo) {
                $this->garantirDadosEntregaDiretaCampo($frenteCampo, $retiradoPor, $retiradoPorExterno);
            }

            $dataEntrega = Carbon::parse($ocorridoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataEntrega);

            $produzido = (float) ProducaoIndustrializada::where('produto_industrializado_id', $produtoTravado->id)->sum('quantidade');
            $entregue = (float) EntregaProdutoIndustrializado::where('produto_industrializado_id', $produtoTravado->id)->sum('quantidade');
            $saldoPronto = round($produzido - $entregue, 3);

            if ($quantidade > $saldoPronto + 0.0005) {
                throw new EntregaProdutoIndustrializadoInvalidaException(
                    "Só há {$saldoPronto} pronto aguardando destino no terceiro — não é possível entregar {$quantidade}."
                );
            }

            $localTerceiro = LocalEstoque::findOrFail($ordem->local_terceiro_id);

            $movimentacaoSaidaTerceiro = MovimentacaoEstoque::create([
                'obra_id' => $ordem->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Saida,
                'material_id' => $produtoTravado->material_id,
                'local_estoque_id' => $localTerceiro->id,
                'unidade_estoque_id' => $unidade?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataEntrega,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            $movimentacaoEntradaDestino = MovimentacaoEstoque::create([
                'obra_id' => $ordem->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Entrada,
                'material_id' => $produtoTravado->material_id,
                'local_estoque_id' => $localDestino->id,
                'unidade_estoque_id' => $unidade?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataEntrega,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            $movimentacaoSaidaCampo = null;

            if ($modalidade === ModalidadeEntregaProduto::EntregaDiretaCampo) {
                $material = Material::findOrFail($produtoTravado->material_id);

                $movimentacaoSaidaCampo = app(RegistrarSaidaEstoque::class)->execute(
                    $material,
                    $localDestino,
                    $quantidade,
                    $dataEntrega,
                    $usuario,
                    reserva: null,
                    pacote: $pacoteCampo,
                    frenteInformada: $frenteCampo,
                    unidade: $unidade,
                    retiradoPor: $retiradoPor,
                    retiradoPorExterno: $retiradoPorExterno,
                    observacao: $observacao,
                );
            }

            return EntregaProdutoIndustrializado::create([
                'obra_id' => $ordem->obra_id,
                'produto_industrializado_id' => $produtoTravado->id,
                'quantidade' => $quantidade,
                'unidade_estoque_id' => $unidade?->id,
                'modalidade' => $modalidade,
                'movimentacao_saida_terceiro_id' => $movimentacaoSaidaTerceiro->id,
                'movimentacao_entrada_destino_id' => $movimentacaoEntradaDestino->id,
                'movimentacao_saida_campo_id' => $movimentacaoSaidaCampo?->id,
                'frente_trabalho_id' => $frenteCampo?->id,
                'retirado_por' => $retiradoPor?->id,
                'retirado_por_externo' => $retiradoPorExterno,
                'ocorrido_em' => $dataEntrega,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new EntregaProdutoIndustrializadoInvalidaException('A quantidade entregue precisa ser maior que zero.');
        }
    }

    private function garantirDataNaoFutura(Carbon $data): void
    {
        if ($data->gt(Carbon::today())) {
            throw new EntregaProdutoIndustrializadoInvalidaException('A data da entrega não pode estar no futuro.');
        }
    }

    private function garantirDadosEntregaDiretaCampo(?FrenteTrabalho $frenteCampo, ?User $retiradoPor, ?string $retiradoPorExterno): void
    {
        if (! $frenteCampo) {
            throw new EntregaProdutoIndustrializadoInvalidaException('Entrega direta ao campo exige informar a Frente de Trabalho.');
        }

        if (($retiradoPor && $retiradoPorExterno) || (! $retiradoPor && ! $retiradoPorExterno)) {
            throw new EntregaProdutoIndustrializadoInvalidaException('Informe exatamente um: usuário do sistema OU nome de pessoa externa que retirou o material.');
        }
    }
}
