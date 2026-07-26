<?php

namespace App\Actions\ProgramacaoSemanal;

use App\Models\ProgramacaoSemanalItem;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lança o HH Realizado de um item de Programação Semanal — o "realizado"
 * é sempre digitado pelo usuário aqui, nunca confundido com o HH previsto
 * congelado (`horas_previstas_congeladas`, gravado uma vez em
 * RegistrarComprometimentoSemanal e nunca alterado por esta Action) nem
 * com `AvancoPeriodo` serie=Realizado (vem só de reimportação do XML,
 * escopo do projeto inteiro — não é isto).
 *
 * `hh_realizado = null` (campo limpo pelo usuário) reseta
 * `realizado_por`/`realizado_em` junto — mesma filosofia de
 * `AtividadeItemProntidao.concluido_por`/`concluido_em` (CLAUDE.md):
 * nunca deixar um autor "fantasma" apontando pra um lançamento já
 * desfeito.
 */
class SalvarRealizadoProgramacaoSemanalItem
{
    public function execute(ProgramacaoSemanalItem $item, ?float $hhRealizado, ?string $userId): ProgramacaoSemanalItem
    {
        if ($item->programacaoSemanal->estaFechada()) {
            throw new RuntimeException('Esta programação está fechada; não é possível lançar HH realizado.');
        }

        if ($hhRealizado !== null) {
            if ($hhRealizado < 0) {
                throw new InvalidArgumentException('HH realizado não pode ser negativo.');
            }

            $hhTotal = (float) ($item->atividade->work_horas ?? 0);
            if ($hhTotal > 0 && $hhRealizado > $hhTotal) {
                throw new InvalidArgumentException(
                    'HH realizado não pode ser maior que o HH total da atividade ('
                        . number_format($hhTotal, 2, ',', '.') . ' HH).'
                );
            }
        }

        $item->update([
            'hh_realizado' => $hhRealizado,
            'realizado_por' => $hhRealizado !== null ? $userId : null,
            'realizado_em' => $hhRealizado !== null ? now() : null,
        ]);

        return $item;
    }
}
