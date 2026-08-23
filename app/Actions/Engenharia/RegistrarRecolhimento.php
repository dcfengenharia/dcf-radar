<?php

namespace App\Actions\Engenharia;

use App\Enums\ResultadoRecolhimento;
use App\Exceptions\GrdRecolhimentoInvalidoException;
use App\Models\Grd;
use App\Models\GrdDistribuicao;
use App\Models\GrdItem;
use App\Models\GrdRecolhimento;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 18, Etapa 18.5.1 (18.5.1.HARDENING) — registra UM evento
 * append-only de recolhimento sobre uma GrdDistribuicao. Nunca edita nem
 * apaga um evento anterior (histórico completo sempre preservado — ver
 * App\Models\GrdRecolhimento, `UPDATED_AT = null` — append-only por
 * contrato de domínio: só `RegistrarRecolhimento::execute()` cria linhas
 * nesta tabela, confirmado por grep — nenhum outro Action/serviço escreve
 * em `grd_recolhimentos`).
 *
 * `resultado = NaoLocalizado` NUNCA reduz a quantidade pendente (regra de
 * domínio central — a soma de `quantidadeRecolhida()` só considera
 * eventos `Recolhido`, ver App\Models\GrdDistribuicao::quantidadeRecolhida()).
 * Ainda assim, CADA evento `NaoLocalizado` individual é limitado à
 * quantidade PENDENTE no instante da tentativa (`quantidade <=
 * quantidadePendente()`, nunca a soma dos eventos) — não faz sentido
 * registrar "não localizei 3 cópias" quando só 2 ainda estão em campo.
 * Múltiplas tentativas com a MESMA quantidade são permitidas em datas
 * diferentes (ex.: `NaoLocalizado 2` em 10/08 e de novo em 12/08, ambas
 * válidas com pendente=2 o tempo todo) — é histórico de tentativas, nunca
 * soma física.
 *
 * `resultado = Recolhido` é limitado: a soma acumulada de eventos
 * Recolhido nunca pode superar `grd_distribuicoes.quantidade` (a
 * quantidade ENTREGUE).
 *
 * **Concorrência**: `GrdDistribuicao::whereKey(...)->lockForUpdate()` é
 * adquirido ANTES de qualquer cálculo de `quantidadeRecolhida()`/
 * `quantidadePendente()`, pros dois resultados (Recolhido e
 * NaoLocalizado) — uma segunda chamada concorrente sobre a MESMA
 * distribuição bloqueia até a primeira transação commitar; `SELECT ...
 * FOR UPDATE` sempre lê o dado mais recente já commitado (nunca o
 * snapshot da transação corrente), então o cálculo de recolhida/pendente
 * feito logo depois do lock sempre reflete o efeito real de qualquer
 * chamada anterior já commitada — duas tentativas simultâneas de
 * `Recolhido 1` sobre uma distribuição com pendente=1 nunca passam as
 * duas: a segunda vê `quantidadeRecolhida()` já atualizada pela primeira
 * e é rejeitada.
 */
class RegistrarRecolhimento
{
    public function execute(
        GrdDistribuicao $distribuicao,
        ResultadoRecolhimento $resultado,
        int $quantidade,
        User $usuario,
        ?string $observacao = null,
        ?\DateTimeInterface $ocorridoEm = null
    ): GrdRecolhimento {
        return DB::transaction(function () use ($distribuicao, $resultado, $quantidade, $usuario, $observacao, $ocorridoEm) {
            if ($quantidade < 1) {
                throw new \InvalidArgumentException('Quantidade precisa ser maior ou igual a 1.');
            }

            $distribuicaoAtual = GrdDistribuicao::whereKey($distribuicao->id)->lockForUpdate()->firstOrFail();

            $item = GrdItem::whereKey($distribuicaoAtual->grd_item_id)->firstOrFail();
            $grd = Grd::whereKey($item->grd_id)->firstOrFail();

            if (! $grd->estaEmitida()) {
                throw new GrdRecolhimentoInvalidoException('Só é possível registrar recolhimento numa GRD Emitida.');
            }

            if ($resultado === ResultadoRecolhimento::Recolhido) {
                $jaRecolhido = $distribuicaoAtual->quantidadeRecolhida();
                if ($jaRecolhido + $quantidade > $distribuicaoAtual->quantidadeEntregue()) {
                    throw new GrdRecolhimentoInvalidoException(
                        'A quantidade recolhida acumulada não pode superar a quantidade entregue.'
                    );
                }
            } else {
                $pendenteAgora = $distribuicaoAtual->quantidadePendente();
                if ($quantidade > $pendenteAgora) {
                    throw new GrdRecolhimentoInvalidoException(
                        'A quantidade informada como não localizada não pode superar a quantidade pendente no momento da tentativa.'
                    );
                }
            }

            return GrdRecolhimento::create([
                'grd_distribuicao_id' => $distribuicaoAtual->id,
                'resultado' => $resultado,
                'quantidade' => $quantidade,
                'ocorrido_em' => $ocorridoEm ?? now(),
                'registrado_por' => $usuario->id,
                'observacao' => $observacao,
            ]);
        });
    }
}
