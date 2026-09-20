<?php

namespace App\Actions\Estoque;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\EntradaEstoqueInvalidaException;
use App\Exceptions\OperacaoEstoqueDuplicadaException;
use App\Exceptions\SaldoRecebimentoInsuficienteException;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCompra;
use App\Models\RecebimentoPedido;
use App\Models\UnidadeEstoque;
use App\Models\User;
use App\Support\Estoque\ResolverMaterialDaCadeia;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.1 — registra UM evento append-only de entrada em
 * estoque, a partir de um RecebimentoPedido já existente (Ciclo 19).
 * RecebimentoPedido continua significando "chegou fisicamente";
 * MovimentacaoEstoque(Entrada) passa a significar "foi incorporado ao
 * controle físico do estoque" — os dois fatos são sempre independentes
 * (entrada parcial é o caso normal, Seção 16 da investigação).
 *
 * **Cardinalidade**: 1 RecebimentoPedido pode gerar N MovimentacaoEstoque
 * (ex.: 1000m recebidos viram 2 bobinas de 500m em locais/lotes
 * distintos) — nunca um cabeçalho de "evento de entrada" intermediário
 * (mesmo padrão headless já usado em grd_recolhimentos/recebimentos_pedido).
 *
 * **Concorrência**: RecebimentoPedido::lockForUpdate() é adquirido ANTES
 * de somar as entradas já incorporadas — mesmo mecanismo de toda a
 * cadeia de saldo do Ciclo 19.
 *
 * **Material obrigatório (Seção 23 da investigação, CRÍTICO)**: se a
 * cadeia RecebimentoPedido->...->ItemTakeOff não resolver um
 * ItemTakeOff.material_id, a entrada é BLOQUEADA com mensagem didática
 * — nunca inventa/infere um Material, nunca altera o histórico
 * comercial já existente.
 *
 * **Disponibilidade (Seção 18)**: como não existe domínio de
 * qualidade/inspeção/quarentena nesta fase, toda entrada é considerada
 * fisicamente disponível no instante em que é registrada — simplificação
 * explícita desta etapa, NUNCA chamada de "aprovado pela qualidade".
 *
 * **Auditoria Pré-Produção A2.1, Seções 5-9 (idempotência)**:
 * `$operationId` é OPCIONAL — a UI gera um ULID UMA VEZ quando a
 * intenção de dar entrada nasce (não a cada render/retry) e reenvia o
 * MESMO valor em qualquer reenvio/retry daquela intenção. Retry com o
 * MESMO `operation_id` E o mesmo recebimento/local/quantidade retorna a
 * `MovimentacaoEstoque` já criada, sem duplicar (idempotente); com
 * QUALQUER desses 3 campos diferente, lança
 * `OperacaoEstoqueDuplicadaException` (conflito — nunca sobrescreve
 * silenciosamente). NUNCA deduplicamos por combinação de campos de
 * negócio (Seção 6) — duas entradas idênticas SEM operation_id, ou com
 * operation_ids DIFERENTES, continuam sendo 2 fatos legítimos e
 * distintos. A garantia real contra corrida é o `UNIQUE(tenant_id,
 * operation_id)` do banco (Seção 8) — a checagem abaixo é só um atalho
 * de performance pro caminho feliz, nunca a defesa em si.
 */
class RegistrarEntradaEstoque
{
    public function execute(
        RecebimentoPedido $recebimento,
        LocalEstoque $local,
        float $quantidade,
        \DateTimeInterface $ocorridoEm,
        User $usuario,
        ?string $codigoLote = null,
        ?string $serialUnico = null,
        ?string $identificadorLogistico = null,
        ?string $observacao = null,
        ?string $operationId = null,
    ): MovimentacaoEstoque {
        // Auditoria Pré-Produção A2.1, Seção 9 — checagem de pré-existência
        // FORA da transação (atalho de performance no caminho feliz, nunca
        // a garantia real). Fazer isso DENTRO do DB::transaction() e
        // devolver o registro existente num `catch` local, sem relançar,
        // deixaria COMMITAR qualquer escrita especulativa já feita antes
        // do INSERT que colidiu (ex.: uma UnidadeEstoque de lote nova
        // criada por resolverUnidadeEstoque()) — por isso o try/catch de
        // corrida real fica FORA da transação: uma QueryException não
        // capturada dentro do closure sempre desfaz TUDO que a tentativa
        // perdedora já tinha feito, antes de tentarmos devolver o fato
        // que a tentativa VENCEDORA já criou.
        if ($operationId !== null) {
            $existente = MovimentacaoEstoque::where('operation_id', $operationId)->first();
            if ($existente) {
                return $this->validarOuRetornarExistente($existente, $recebimento, $local, $quantidade);
            }
        }

        try {
            return DB::transaction(function () use (
                $recebimento, $local, $quantidade, $ocorridoEm, $usuario,
                $codigoLote, $serialUnico, $identificadorLogistico, $observacao, $operationId
            ) {
            if ($quantidade <= 0) {
                throw new EntradaEstoqueInvalidaException('A quantidade da entrada precisa ser maior que zero.');
            }

            $dataEntrada = Carbon::parse($ocorridoEm)->startOfDay();
            // Auditoria Pré-Produção A2.1, Seção 2 — corrigido definitivamente:
            // Carbon::today() usava o fuso padrão da app (UTC), aceitando
            // incorretamente uma data que ainda É futura pro usuário
            // brasileiro das 21h00 às 23h59 (horário de Brasília).
            // RelogioNegocio::dataEstaNoFuturo() compara por DATA DE
            // NEGÓCIO (America/Sao_Paulo), nunca por instante UTC absoluto.
            // A dívida de convenção de teste (Carbon::setTestNow() com
            // meia-noite UTC implícita) foi corrigida na própria fixture
            // dos testes afetados — ver EstoqueFundacaoTest.php.
            if (\App\Support\Tempo\RelogioNegocio::dataEstaNoFuturo($dataEntrada)) {
                throw new EntradaEstoqueInvalidaException('A data da entrada não pode estar no futuro.');
            }

            $recebimentoTravado = RecebimentoPedido::whereKey($recebimento->id)->lockForUpdate()->firstOrFail();

            $itemTakeOff = ResolverMaterialDaCadeia::itemTakeOff($recebimentoTravado);
            if (! $itemTakeOff || ! $itemTakeOff->material_id) {
                throw new EntradaEstoqueInvalidaException(
                    'Associe este item a um Material/SKU antes de incorporá-lo ao estoque.'
                );
            }

            $material = Material::find($itemTakeOff->material_id);
            if (! $material) {
                throw new EntradaEstoqueInvalidaException('O Material associado a este item não foi encontrado.');
            }
            if (! $material->ativo) {
                throw new EntradaEstoqueInvalidaException('Este Material está inativo e não pode receber novas entradas em estoque.');
            }

            if (! $local->ativo) {
                throw new EntradaEstoqueInvalidaException('Este Local de Estoque está inativo e não pode receber novas entradas.');
            }

            if ($local->tipo === TipoLocalEstoque::Terceiro) {
                throw new EntradaEstoqueInvalidaException(
                    'Este Local é de custódia de Terceiro — entrada a partir de Recebimento de Pedido só é permitida em Locais próprios da obra.'
                );
            }

            $pedidoCompraItem = $recebimentoTravado->pedidoCompraItem;
            $pedido = $pedidoCompraItem ? PedidoCompra::find($pedidoCompraItem->pedido_compra_id) : null;
            if (! $pedido || $pedido->obra_id !== $local->obra_id) {
                throw new EntradaEstoqueInvalidaException(
                    'O Local de Estoque selecionado não pertence à mesma obra deste recebimento.'
                );
            }

            $jaIncorporado = (float) MovimentacaoEstoque::where('recebimento_pedido_id', $recebimentoTravado->id)->sum('quantidade');
            $recebido = (float) $recebimentoTravado->quantidade_recebida;

            if ($jaIncorporado + $quantidade > $recebido + 0.0005) {
                $saldoDisponivel = round($recebido - $jaIncorporado, 3);
                throw new SaldoRecebimentoInsuficienteException(
                    "Este recebimento não tem mais saldo suficiente pra dar entrada ({$saldoDisponivel} disponível, {$quantidade} informado).",
                    $saldoDisponivel,
                    $quantidade
                );
            }

            $unidade = $this->resolverUnidadeEstoque(
                $material, $local, $quantidade, $recebimentoTravado, $usuario,
                $codigoLote, $serialUnico, $identificadorLogistico
            );

            return MovimentacaoEstoque::create([
                'operation_id' => $operationId,
                'obra_id' => $local->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Entrada,
                'material_id' => $material->id,
                'local_estoque_id' => $local->id,
                'unidade_estoque_id' => $unidade?->id,
                'recebimento_pedido_id' => $recebimentoTravado->id,
                'item_take_off_id' => $itemTakeOff->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataEntrada,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);
            });
        } catch (QueryException $e) {
            // Corrida real: outra requisição com o MESMO operation_id já
            // commitou entre nossa checagem de pré-existência (topo do
            // método) e o INSERT desta transação — que já foi revertida
            // por inteiro (inclusive qualquer UnidadeEstoque de lote nova
            // que resolverUnidadeEstoque() tivesse criado especulativamente).
            // A garantia real contra corrida é sempre o UNIQUE(tenant_id,
            // operation_id) do banco, nunca o exists() de cima.
            if ($operationId !== null && ($e->errorInfo[1] ?? null) === 1062) {
                $existente = MovimentacaoEstoque::where('operation_id', $operationId)->first();
                if ($existente) {
                    return $this->validarOuRetornarExistente($existente, $recebimento, $local, $quantidade);
                }
            }

            throw $e;
        }
    }

    /**
     * Auditoria Pré-Produção A2.1, Seção 9 — retry da MESMA intenção
     * (mesmo recebimento/local/quantidade) retorna o fato já existente,
     * sem duplicar; qualquer um desses 3 campos divergente é tratado
     * como conflito (payload semanticamente diferente reaproveitando o
     * mesmo operation_id), nunca sobrescrito silenciosamente.
     */
    private function validarOuRetornarExistente(
        MovimentacaoEstoque $existente,
        RecebimentoPedido $recebimento,
        LocalEstoque $local,
        float $quantidade,
    ): MovimentacaoEstoque {
        $mesmoRecebimento = $existente->recebimento_pedido_id === $recebimento->id;
        $mesmoLocal = $existente->local_estoque_id === $local->id;
        $mesmaQuantidade = abs((float) $existente->quantidade - $quantidade) <= 0.0005;

        if (! $mesmoRecebimento || ! $mesmoLocal || ! $mesmaQuantidade) {
            throw new OperacaoEstoqueDuplicadaException();
        }

        return $existente;
    }

    private function resolverUnidadeEstoque(
        Material $material,
        LocalEstoque $local,
        float $quantidade,
        RecebimentoPedido $recebimento,
        User $usuario,
        ?string $codigoLote,
        ?string $serialUnico,
        ?string $identificadorLogistico,
    ): ?UnidadeEstoque {
        return match ($material->modo_rastreabilidade) {
            ModoRastreabilidadeMaterial::Quantitativo => null,

            ModoRastreabilidadeMaterial::Lote => $this->resolverUnidadeLote(
                $material, $local, $recebimento, $usuario, $codigoLote, $identificadorLogistico
            ),

            ModoRastreabilidadeMaterial::Serializado => $this->criarUnidadeSerial(
                $material, $local, $quantidade, $recebimento, $usuario, $serialUnico, $identificadorLogistico
            ),
        };
    }

    private function resolverUnidadeLote(
        Material $material,
        LocalEstoque $local,
        RecebimentoPedido $recebimento,
        User $usuario,
        ?string $codigoLote,
        ?string $identificadorLogistico,
    ): UnidadeEstoque {
        if (! $codigoLote) {
            throw new EntradaEstoqueInvalidaException('Este Material exige informar o lote/bobina para dar entrada.');
        }

        $existente = UnidadeEstoque::where('material_id', $material->id)
            ->where('codigo_lote', $codigoLote)
            ->first();

        if ($existente) {
            if ($existente->local_estoque_id !== $local->id) {
                throw new EntradaEstoqueInvalidaException(
                    'Este lote/bobina já está registrado em outro Local de Estoque.'
                );
            }

            return $existente;
        }

        return UnidadeEstoque::create([
            'material_id' => $material->id,
            'local_estoque_id' => $local->id,
            'codigo_lote' => $codigoLote,
            'identificador_logistico' => $identificadorLogistico,
            'recebimento_pedido_id' => $recebimento->id,
            'created_by_id' => $usuario->id,
        ]);
    }

    private function criarUnidadeSerial(
        Material $material,
        LocalEstoque $local,
        float $quantidade,
        RecebimentoPedido $recebimento,
        User $usuario,
        ?string $serialUnico,
        ?string $identificadorLogistico,
    ): UnidadeEstoque {
        if (! $serialUnico) {
            throw new EntradaEstoqueInvalidaException('Este Material exige informar o serial para dar entrada.');
        }

        if (abs($quantidade - 1.0) > 0.0005) {
            throw new EntradaEstoqueInvalidaException('Material serializado exige quantidade igual a 1 por entrada — registre um serial por vez.');
        }

        try {
            return UnidadeEstoque::create([
                'material_id' => $material->id,
                'local_estoque_id' => $local->id,
                'serial_unico' => $serialUnico,
                'identificador_logistico' => $identificadorLogistico,
                'recebimento_pedido_id' => $recebimento->id,
                'created_by_id' => $usuario->id,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new EntradaEstoqueInvalidaException('Este serial já está cadastrado no estoque.');
            }

            throw $e;
        }
    }
}
