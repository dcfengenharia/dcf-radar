<?php

namespace App\Actions\Estoque;

use App\Enums\TipoMovimentacaoEstoque;
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
 * Ciclo 20, Etapa 20.4 — cria UMA linha de Aplicação (parcela de uma
 * Saída física conciliada contra uma Frente real, opcionalmente também
 * contra um Pacote). `MovimentacaoEstoque`/`ReservaEstoque`/
 * `DestinacaoPlanejadaMaterial` NUNCA são alterados por esta Action —
 * só leitura + insert em `aplicacoes_material_estoque`.
 *
 * **Total order de lock** (mesmo já usado por `RegistrarSaidaEstoque`/
 * `CriarReservaEstoque`): a `MovimentacaoEstoque` (Saída) é travada
 * SEMPRE PRIMEIRO, antes de qualquer SUM de pendência — duas tentativas
 * concorrentes de aplicar contra a MESMA Saída serializam nesse lock,
 * nunca formalizam mais que `quantidade` da Saída.
 *
 * **Pacote (Seções 17/18/19, decisão do usuário)**: quando a Saída já
 * tem `item_suprimento_id` fixo, um `$pacote` explicitamente diferente
 * é bloqueado (nunca aponta silenciosamente pra outro Pacote); `$pacote`
 * omitido nesse caso HERDA automaticamente o da Saída (é o Pacote
 * canônico dela). Quando a Saída não tem Pacote (null), `$pacote` é
 * 100% livre por linha — inclusive linhas diferentes da mesma Saída
 * podem apontar pra Pacotes diferentes.
 */
class RegistrarAplicacaoMaterialEstoque
{
    public function execute(
        MovimentacaoEstoque $saida,
        FrenteTrabalho $frente,
        float $quantidade,
        \DateTimeInterface $aplicadoEm,
        User $usuarioRegistro,
        ?ItemSuprimento $pacote = null,
        ?string $observacao = null,
    ): AplicacaoMaterialEstoque {
        return DB::transaction(function () use ($saida, $frente, $quantidade, $aplicadoEm, $usuarioRegistro, $pacote, $observacao) {
            $this->garantirQuantidadePositiva($quantidade);

            // Saída travada SEMPRE PRIMEIRO — mesmo total order de RegistrarSaidaEstoque.
            $saidaTravada = MovimentacaoEstoque::whereKey($saida->id)->lockForUpdate()->firstOrFail();

            $this->garantirEhSaida($saidaTravada);
            $this->garantirFrenteValida($frente, $saidaTravada);

            $dataAplicacao = Carbon::parse($aplicadoEm)->startOfDay();
            $this->garantirDataNaoFutura($dataAplicacao);
            $this->garantirDataNaoAnteriorASaida($dataAplicacao, $saidaTravada);

            if (PoliticaConciliacaoAplicacao::saidaEstaFechada($saidaTravada)) {
                throw new AplicacaoConciliacaoFechadaException('Esta Saída já está 100% conciliada — não é possível adicionar novas aplicações a ela.');
            }

            $pacoteFinal = $this->resolverPacote($saidaTravada, $pacote);

            $pendente = PoliticaConciliacaoAplicacao::pendente($saidaTravada);
            if ($quantidade > $pendente + 0.0005) {
                throw new AplicacaoConciliacaoInvalidaException(
                    "Esta Saída tem apenas {$pendente} pendente de conciliação — não é possível aplicar {$quantidade}."
                );
            }

            return AplicacaoMaterialEstoque::create([
                'obra_id' => $saidaTravada->obra_id,
                'movimentacao_estoque_id' => $saidaTravada->id,
                'frente_trabalho_id' => $frente->id,
                'item_suprimento_id' => $pacoteFinal?->id,
                'quantidade' => $quantidade,
                'aplicado_em' => $dataAplicacao,
                'registrado_por' => $usuarioRegistro->id,
                'observacao' => $observacao,
            ]);
        });
    }

    private function garantirQuantidadePositiva(float $quantidade): void
    {
        if ($quantidade <= 0) {
            throw new AplicacaoConciliacaoInvalidaException('A quantidade da aplicação precisa ser maior que zero.');
        }
    }

    private function garantirEhSaida(MovimentacaoEstoque $saida): void
    {
        if ($saida->tipo !== TipoMovimentacaoEstoque::Saida) {
            throw new AplicacaoConciliacaoInvalidaException('Só é possível conciliar uma Movimentação de Estoque do tipo Saída.');
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

    /**
     * Item 36 do pedido — logicamente inválido aplicar material antes de
     * ele ter deixado o estoque. Bloqueado sem exceção (nenhum caso
     * legítimo de retroatividade encontrado na investigação: a Saída
     * sempre é o fato físico anterior, `ocorrido_em` já é uma data, não
     * um timestamp, então uma Aplicação no MESMO dia da Saída é sempre
     * permitida — só uma Aplicação em dia estritamente ANTERIOR é
     * inválida).
     */
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
