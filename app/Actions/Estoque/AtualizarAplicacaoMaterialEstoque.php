<?php

namespace App\Actions\Estoque;

use App\Exceptions\AplicacaoConciliacaoFechadaException;
use App\Exceptions\AplicacaoConciliacaoInvalidaException;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\FrenteTrabalho;
use App\Models\ItemSuprimento;
use App\Models\MovimentacaoEstoque;
use App\Models\User;
use App\Support\Estoque\PoliticaConciliacaoAplicacao;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 20, Etapa 20.4 — corrige Frente/Pacote/quantidade/data/observação
 * de uma Aplicação JÁ CRIADA, enquanto a Saída dona ainda não estiver
 * 100% conciliada (decisão do usuário, Seção 12). Mesmo total order de
 * lock de `RegistrarAplicacaoMaterialEstoque` — a Saída é travada
 * PRIMEIRO, e o guard de "já fechada" é avaliado sobre o estado ANTES
 * desta edição (excluindo o valor antigo desta própria linha do SUM,
 * pra permitir reduzir a quantidade de uma linha sem falso-positivo de
 * "já fechada").
 *
 * Identidade (`movimentacao_estoque_id`/`tenant_id`/`obra_id`/
 * `registrado_por`) nunca é reescrita aqui — só
 * `frente_trabalho_id`/`item_suprimento_id`/`quantidade`/`aplicado_em`/
 * `observacao`. `atualizado_por` é sempre gravado.
 */
class AtualizarAplicacaoMaterialEstoque
{
    public function execute(
        AplicacaoMaterialEstoque $aplicacao,
        FrenteTrabalho $frente,
        float $quantidade,
        \DateTimeInterface $aplicadoEm,
        User $usuarioAtualizacao,
        ?ItemSuprimento $pacote = null,
        ?string $observacao = null,
    ): AplicacaoMaterialEstoque {
        return DB::transaction(function () use ($aplicacao, $frente, $quantidade, $aplicadoEm, $usuarioAtualizacao, $pacote, $observacao) {
            $this->garantirQuantidadePositiva($quantidade);

            $saidaTravada = MovimentacaoEstoque::whereKey($aplicacao->movimentacao_estoque_id)->lockForUpdate()->firstOrFail();

            // "Já fechada" avaliado sobre o estado REAL antes desta edição
            // (SUM incluindo o valor ATUAL/antigo desta própria linha,
            // ainda persistido) — se já bate 100%, a conciliação já
            // fechou de verdade e nenhuma edição é permitida, nem mesmo
            // pra "corrigir" um erro (decisão do usuário).
            if (PoliticaConciliacaoAplicacao::saidaEstaFechada($saidaTravada)) {
                throw new AplicacaoConciliacaoFechadaException('Esta Saída já está 100% conciliada — as aplicações dela estão congeladas.');
            }

            $this->garantirFrenteValida($frente, $saidaTravada);

            $dataAplicacao = Carbon::parse($aplicadoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataAplicacao);
            $this->garantirDataNaoAnteriorASaida($dataAplicacao, $saidaTravada);

            $pacoteFinal = $this->resolverPacote($saidaTravada, $pacote);

            $pendenteExcluindoEsta = PoliticaConciliacaoAplicacao::pendente($saidaTravada, excluirAplicacaoId: $aplicacao->id);
            if ($quantidade > $pendenteExcluindoEsta + 0.0005) {
                throw new AplicacaoConciliacaoInvalidaException(
                    "O saldo pendente desta Saída (excluindo esta linha) é {$pendenteExcluindoEsta} — não é possível alterar para {$quantidade}."
                );
            }

            $aplicacao->update([
                'frente_trabalho_id' => $frente->id,
                'item_suprimento_id' => $pacoteFinal?->id,
                'quantidade' => $quantidade,
                'aplicado_em' => $dataAplicacao,
                'atualizado_por' => $usuarioAtualizacao->id,
                'observacao' => $observacao,
            ]);

            return $aplicacao->fresh();
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new AplicacaoConciliacaoInvalidaException('A quantidade da aplicação precisa ser maior que zero.');
        }
    }

    private function garantirFrenteValida(FrenteTrabalho $frente, MovimentacaoEstoque $saida): void
    {
        if ($frente->trashed()) {
            throw new AplicacaoConciliacaoInvalidaException('Esta Frente de Trabalho está indisponível — selecione uma Frente ativa.');
        }

        if ($frente->obra_id !== $saida->obra_id) {
            throw new AplicacaoConciliacaoInvalidaException('Esta Frente de Trabalho não pertence à mesma obra da Saída.');
        }
    }

    private function garantirDataNaoFutura(Carbon $data): void
    {
        if ($data->gt(Carbon::today())) {
            throw new AplicacaoConciliacaoInvalidaException('A data da aplicação não pode estar no futuro.');
        }
    }

    private function garantirDataNaoAnteriorASaida(Carbon $dataAplicacao, MovimentacaoEstoque $saida): void
    {
        if ($dataAplicacao->lt($saida->ocorrido_em)) {
            throw new AplicacaoConciliacaoInvalidaException('A data da aplicação não pode ser anterior à data da Saída física.');
        }
    }

    private function resolverPacote(MovimentacaoEstoque $saida, ?ItemSuprimento $pacote): ?ItemSuprimento
    {
        if ($saida->item_suprimento_id) {
            if ($pacote && $pacote->id !== $saida->item_suprimento_id) {
                throw new AplicacaoConciliacaoInvalidaException('O Pacote informado diverge do Pacote canônico desta Saída.');
            }

            return $saida->pacote;
        }

        if ($pacote && $pacote->obra_id !== $saida->obra_id) {
            throw new AplicacaoConciliacaoInvalidaException('Este Pacote não pertence à mesma obra da Saída.');
        }

        return $pacote;
    }
}
