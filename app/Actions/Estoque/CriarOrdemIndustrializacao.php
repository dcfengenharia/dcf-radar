<?php

namespace App\Actions\Estoque;

use App\Enums\TipoLocalEstoque;
use App\Exceptions\OrdemIndustrializacaoInvalidaException;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.5 — cria uma OrdemIndustrializacao em Rascunho.
 * `localTerceiro` precisa já existir (tipo=Terceiro, mesmo Fornecedor) —
 * esta Action nunca cria o Local sozinha (separa "cadastro de custódia"
 * de "abrir um processo com esse fornecedor").
 */
class CriarOrdemIndustrializacao
{
    public function execute(
        Work $obra,
        Fornecedor $fornecedor,
        LocalEstoque $localTerceiro,
        User $usuario,
        ?ItemSuprimento $pacote = null,
        ?string $observacao = null,
    ): OrdemIndustrializacao {
        return DB::transaction(function () use ($obra, $fornecedor, $localTerceiro, $usuario, $pacote, $observacao) {
            if ($fornecedor->obra_id !== $obra->id) {
                throw new OrdemIndustrializacaoInvalidaException('Este Fornecedor não pertence a esta obra.');
            }

            if ($localTerceiro->obra_id !== $obra->id) {
                throw new OrdemIndustrializacaoInvalidaException('Este Local de custódia não pertence a esta obra.');
            }

            if ($localTerceiro->tipo !== TipoLocalEstoque::Terceiro) {
                throw new OrdemIndustrializacaoInvalidaException('O Local de custódia precisa ser do tipo Terceiro.');
            }

            if ($localTerceiro->fornecedor_id !== $fornecedor->id) {
                throw new OrdemIndustrializacaoInvalidaException('Este Local de custódia pertence a outro Fornecedor.');
            }

            if ($pacote && $pacote->obra_id !== $obra->id) {
                throw new OrdemIndustrializacaoInvalidaException('Este Pacote de Compra não pertence a esta obra.');
            }

            return OrdemIndustrializacao::create([
                'obra_id' => $obra->id,
                'fornecedor_id' => $fornecedor->id,
                'local_terceiro_id' => $localTerceiro->id,
                'item_suprimento_id' => $pacote?->id,
                'status' => 'rascunho',
                'observacao' => $observacao,
                'created_by_id' => $usuario->id,
            ]);
        });
    }
}
