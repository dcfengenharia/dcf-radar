<?php

namespace App\Exports\Concerns;

use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Auditoria Pré-Produção A1, EXP-01 — sanitizador central e reutilizável
 * contra injeção de fórmula Excel/CSV (OWASP CSV Injection): qualquer
 * célula de TEXTO cujo primeiro caractere seja um dos gatilhos que o
 * Excel (ou uma reabertura como CSV/planilha por outra ferramenta) pode
 * interpretar como início de fórmula — `=`, `+`, `-`, `@`, tab ou
 * retorno de carro — é gravada com o tipo explícito TYPE_STRING, nunca
 * deixada pro PhpSpreadsheet detectar automaticamente como fórmula
 * (`Maatwebsite\Excel\DefaultValueBinder`/
 * `PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder::dataTypeForValue()`
 * tratam qualquer string começando com `=` como TYPE_FORMULA).
 *
 * Números, datas e o restante do texto (inclusive strings vazias e
 * qualquer string cujo primeiro caractere não seja um dos gatilhos)
 * passam intocados pelo binder padrão do Maatwebsite — nenhum valor
 * legítimo é alterado.
 *
 * Uso: a classe de export (ou cada sheet-export individual, no caso de
 * `WithMultipleSheets` — o Maatwebsite troca o value binder ativo por
 * SHEET, nunca pelo container) declara
 * `implements \Maatwebsite\Excel\Concerns\WithCustomValueBinder` e usa
 * esta trait.
 *
 * @see WithCustomValueBinder
 */
trait PrevineInjecaoDeFormulaExcel
{
    private const GATILHOS_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::GATILHOS_FORMULA, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder())->bindValue($cell, $value);
    }
}
