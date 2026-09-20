<?php

namespace App\Actions\Estoque;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\TipoLocalEstoque;
use App\Enums\TipoMovimentacaoEstoque;
use App\Exceptions\OperacaoEstoqueDuplicadaException;
use App\Exceptions\SaldoFisicoInsuficienteException;
use App\Exceptions\TransferenciaEstoqueInvalidaException;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\TransferenciaEstoque;
use App\Models\UnidadeEstoque;
use App\Models\User;
use App\Support\Estoque\SaldoEstoque;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.6 — registra UMA Transferência física de material
 * entre dois Locais PRÓPRIOS da mesma obra (nunca envolve Local
 * Terceiro — esse fluxo já é coberto por
 * `App\Actions\Estoque\RegistrarRemessaIndustrializacao`/Ordem de
 * Industrialização, Ciclo 20.5; ver docblock da migration).
 *
 * **Transferência ≠ Saída para campo** (item 2 do pedido): muda
 * localização/custódia dentro do estoque rastreado, nunca é aplicação —
 * por isso NUNCA seta `frente_trabalho_id`/`item_suprimento_id`/
 * `reserva_estoque_id` nas `MovimentacaoEstoque` que cria, e NUNCA cria
 * `App\Models\AplicacaoMaterialEstoque`.
 *
 * **Atomicidade (item 3)**: Saida (origem) + Entrada (destino) +
 * `TransferenciaEstoque` (identidade de operação correlacionando as
 * duas) nascem SEMPRE na mesma transação — nunca meia-transferência.
 *
 * **`UnidadeEstoque.local_estoque_id` NUNCA é atualizado aqui** (mesma
 * correção já aplicada em `RegistrarRemessaIndustrializacao`,
 * 20.5.CORREÇÃO) — "onde esta unidade está e quanto tem em cada Local"
 * é sempre derivado do ledger via `SaldoEstoque::porUnidadeLocal()`. Uma
 * bobina/lote transferida PARCIALMENTE nunca vira uma segunda
 * identidade — a mesma `UnidadeEstoque` passa a ter saldo simultâneo em
 * origem e destino, exatamente como já acontece com Remessa.
 *
 * **Reservas (item 12, decisão do usuário — Opção B)**: o saldo da
 * origem é validado SEMPRE contra o FÍSICO total, nunca contra o
 * disponível não-reservado (`App\Support\Estoque\SaldoReserva`) — mesma
 * filosofia já documentada em `RegistrarSaidaEstoque` pra saída de
 * emergência/desvio de frente. Uma `ReservaEstoque` ativa na origem
 * NUNCA é tocada/reduzida/liberada automaticamente por esta ação — fica
 * "descoberta" (o déficit já é derivável via `SaldoReserva`/
 * `CoberturaReservas`, mesmo mecanismo da 20.4), e esta Action nunca
 * escolhe sozinha qual Reserva foi prejudicada.
 *
 * **Concorrência/deadlock (itens 18/19)**: os 2 Locais são travados em
 * ordem DETERMINÍSTICA por `id` (nunca pela ordem origem/destino
 * informada pelo chamador) — mesmo mecanismo já usado por
 * `RegistrarRemessaIndustrializacao`. Isso é o que garante que duas
 * transferências concorrentes e opostas (A→B e B→A) nunca causem
 * deadlock: as duas travam os mesmos 2 Locais na MESMA ordem.
 *
 * **Auditoria Pré-Produção A2.1, Seções 5-9 (idempotência)**:
 * `$operationId` é OPCIONAL — a Transferência JÁ É a "identidade de
 * operação" da dupla Saída+Entrada que cria (docblock da migration), por
 * isso o `operation_id` vive na PRÓPRIA `TransferenciaEstoque` (nunca
 * nas 2 `MovimentacaoEstoque` internas, que continuam sem operation_id
 * próprio). Retry com o mesmo operation_id + mesmo material/origem/
 * destino/quantidade retorna a Transferência já criada; qualquer um
 * desses campos divergente vira `OperacaoEstoqueDuplicadaException`.
 * Garantia real é o `UNIQUE(tenant_id, operation_id)` do banco.
 */
class RegistrarTransferenciaEstoque
{
    public function execute(
        Material $material,
        LocalEstoque $localOrigem,
        LocalEstoque $localDestino,
        float $quantidade,
        \DateTimeInterface $ocorridoEm,
        User $usuario,
        ?UnidadeEstoque $unidade = null,
        ?string $observacao = null,
        ?string $operationId = null,
    ): TransferenciaEstoque {
        // Ver RegistrarEntradaEstoque::execute() pro raciocínio completo.
        // Aqui é ainda mais crítico: o pré-check e o catch de corrida real
        // ficam FORA da transação porque esta Action já cria 2
        // MovimentacaoEstoque especulativas ANTES do INSERT final que
        // carrega o operation_id (TransferenciaEstoque) — só uma exceção
        // NÃO capturada dentro do closure desfaz as duas junto, evitando
        // uma Saída+Entrada órfãs (sem TransferenciaEstoque correlata) se
        // a tentativa perdedora de uma corrida fosse apenas engolida
        // internamente.
        if ($operationId !== null) {
            $existente = TransferenciaEstoque::where('operation_id', $operationId)->first();
            if ($existente) {
                return $this->validarOuRetornarExistente($existente, $material, $localOrigem, $localDestino, $quantidade);
            }
        }

        try {
            return DB::transaction(function () use (
                $material, $localOrigem, $localDestino, $quantidade, $ocorridoEm, $usuario, $unidade, $observacao, $operationId
            ) {
            $this->garantirQuantidadePositiva($quantidade);

            $dataTransferencia = Carbon::parse($ocorridoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataTransferencia);

            if ($localOrigem->id === $localDestino->id) {
                throw new TransferenciaEstoqueInvalidaException('O Local de origem precisa ser diferente do Local de destino.');
            }

            if ($localOrigem->obra_id !== $localDestino->obra_id) {
                throw new TransferenciaEstoqueInvalidaException('Os dois Locais precisam pertencer à mesma obra.');
            }

            if (! $material->ativo) {
                throw new TransferenciaEstoqueInvalidaException('Este Material está inativo e não pode ter nova transferência registrada.');
            }

            $this->garantirLocalProprioEAtivo($localOrigem, 'origem');
            $this->garantirLocalProprioEAtivo($localDestino, 'destino');

            // Trava os 2 Locais em ordem determinística (por id) ANTES de
            // qualquer SUM — evita deadlock entre transferências
            // concorrentes envolvendo os mesmos 2 Locais em direções
            // opostas (Seção 19).
            LocalEstoque::whereIn('id', [$localOrigem->id, $localDestino->id])->orderBy('id')->lockForUpdate()->get();

            $unidadeTravada = $unidade ? UnidadeEstoque::whereKey($unidade->id)->lockForUpdate()->firstOrFail() : null;
            $this->garantirUnidadeCompativel($material, $localOrigem, $unidadeTravada);
            $this->garantirQuantidadeSerialUnitaria($material, $quantidade);

            $saldoOrigem = $unidadeTravada
                ? SaldoEstoque::porUnidadeLocal($unidadeTravada, $localOrigem)
                : SaldoEstoque::porMaterialLocal($material, $localOrigem);

            if ($quantidade > $saldoOrigem + 0.0005) {
                throw new SaldoFisicoInsuficienteException(
                    "Não há saldo físico suficiente no Local de origem para esta transferência ({$saldoOrigem} disponível, {$quantidade} informado).",
                    $saldoOrigem,
                    $quantidade
                );
            }

            $saida = MovimentacaoEstoque::create([
                'obra_id' => $localOrigem->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Saida,
                'material_id' => $material->id,
                'local_estoque_id' => $localOrigem->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataTransferencia,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            $entrada = MovimentacaoEstoque::create([
                'obra_id' => $localOrigem->obra_id,
                'tipo' => TipoMovimentacaoEstoque::Entrada,
                'material_id' => $material->id,
                'local_estoque_id' => $localDestino->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataTransferencia,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);

            return TransferenciaEstoque::create([
                'operation_id' => $operationId,
                'obra_id' => $localOrigem->obra_id,
                'material_id' => $material->id,
                'unidade_estoque_id' => $unidadeTravada?->id,
                'local_origem_id' => $localOrigem->id,
                'local_destino_id' => $localDestino->id,
                'quantidade' => $quantidade,
                'ocorrido_em' => $dataTransferencia,
                'movimentacao_saida_id' => $saida->id,
                'movimentacao_entrada_id' => $entrada->id,
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);
            });
        } catch (QueryException $e) {
            // Corrida real — mesma lógica de RegistrarEntradaEstoque: a
            // garantia é o UNIQUE(tenant_id, operation_id) de
            // transferencias_estoque, nunca o exists() de cima. A
            // transação inteira (incluindo as 2 MovimentacaoEstoque
            // especulativas) já foi revertida antes de chegarmos aqui.
            if ($operationId !== null && ($e->errorInfo[1] ?? null) === 1062) {
                $existente = TransferenciaEstoque::where('operation_id', $operationId)->first();
                if ($existente) {
                    return $this->validarOuRetornarExistente($existente, $material, $localOrigem, $localDestino, $quantidade);
                }
            }

            throw $e;
        }
    }

    /**
     * Auditoria Pré-Produção A2.1, Seção 9 — mesmo contrato de
     * RegistrarEntradaEstoque::validarOuRetornarExistente(), aplicado
     * aos 4 campos que identificam a intenção de uma Transferência.
     */
    private function validarOuRetornarExistente(
        TransferenciaEstoque $existente,
        Material $material,
        LocalEstoque $localOrigem,
        LocalEstoque $localDestino,
        float $quantidade,
    ): TransferenciaEstoque {
        $mesmoMaterial = $existente->material_id === $material->id;
        $mesmaOrigem = $existente->local_origem_id === $localOrigem->id;
        $mesmoDestino = $existente->local_destino_id === $localDestino->id;
        $mesmaQuantidade = abs((float) $existente->quantidade - $quantidade) <= 0.0005;

        if (! $mesmoMaterial || ! $mesmaOrigem || ! $mesmoDestino || ! $mesmaQuantidade) {
            throw new OperacaoEstoqueDuplicadaException();
        }

        return $existente;
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new TransferenciaEstoqueInvalidaException('A quantidade da transferência precisa ser maior que zero.');
        }
    }

    private function garantirDataNaoFutura(Carbon $data): void
    {
        // Auditoria Pré-Produção A2.1, Seção 2 — corrigido definitivamente
        // com data de negócio (America/Sao_Paulo), nunca instante UTC
        // absoluto. Ver RegistrarEntradaEstoque::execute() pro achado
        // original completo.
        if (\App\Support\Tempo\RelogioNegocio::dataEstaNoFuturo($data)) {
            throw new TransferenciaEstoqueInvalidaException('A data da transferência não pode estar no futuro.');
        }
    }

    /**
     * Item 14 (decisão do usuário): Transferência genérica NUNCA
     * envolve Local tipo Terceiro nesta etapa — mesmo guard já usado
     * por `RegistrarEntradaEstoque`/`RegistrarSaidaEstoque`/
     * `CriarReservaEstoque`.
     */
    private function garantirLocalProprioEAtivo(LocalEstoque $local, string $papel): void
    {
        if (! $local->ativo) {
            throw new TransferenciaEstoqueInvalidaException("O Local de {$papel} está inativo e não pode participar de uma nova transferência.");
        }

        if ($local->tipo === TipoLocalEstoque::Terceiro) {
            throw new TransferenciaEstoqueInvalidaException(
                "O Local de {$papel} é de custódia de Terceiro — Transferência genérica só é permitida entre Locais PRÓPRIOS da obra (o fluxo Próprio↔Terceiro é feito via Ordem de Industrialização)."
            );
        }
    }

    /**
     * Mesma resolução de granularidade física já usada em
     * `RegistrarSaidaEstoque`/`CriarReservaEstoque`/
     * `RegistrarRemessaIndustrializacao` — presença física é sempre
     * derivada do ledger (`SaldoEstoque::porUnidadeLocal()`), nunca de
     * `UnidadeEstoque.local_estoque_id`. Só dispara "não está neste
     * Local" quando a unidade TEM saldo em OUTRO Local (relocação
     * genuína) — sem saldo em NENHUM Local cai no guard de saldo físico
     * insuficiente, mais específico (mesmo refinamento da 20.5.CORREÇÃO).
     */
    private function garantirUnidadeCompativel(Material $material, LocalEstoque $localOrigem, ?UnidadeEstoque $unidade): void
    {
        if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Quantitativo) {
            if ($unidade) {
                throw new TransferenciaEstoqueInvalidaException('Este Material é Quantitativo — não aceita transferência por lote/serial específico.');
            }

            return;
        }

        if (! $unidade) {
            throw new TransferenciaEstoqueInvalidaException('Este Material exige selecionar um lote/bobina ou serial específico para transferir.');
        }

        if ($unidade->material_id !== $material->id) {
            throw new TransferenciaEstoqueInvalidaException('A unidade selecionada não pertence a este Material.');
        }

        if (SaldoEstoque::porUnidadeLocal($unidade, $localOrigem) <= 0.0005 && SaldoEstoque::porUnidade($unidade) > 0.0005) {
            throw new TransferenciaEstoqueInvalidaException('A unidade selecionada não tem saldo físico no Local de origem desta transferência — parte ou todo o saldo está em outro Local.');
        }
    }

    /**
     * Item 9: não permitir transferir 0.5 de um Material Serializado —
     * a quantidade precisa ser exatamente 1 (mesma regra já usada em
     * todo o domínio de Estoque).
     */
    private function garantirQuantidadeSerialUnitaria(Material $material, float $quantidade): void
    {
        if ($material->modo_rastreabilidade === ModoRastreabilidadeMaterial::Serializado && abs($quantidade - 1.0) > 0.0005) {
            throw new TransferenciaEstoqueInvalidaException('Material serializado exige quantidade igual a 1 por transferência — transfira um serial por vez.');
        }
    }
}
