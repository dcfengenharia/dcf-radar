<?php

namespace Tests\Unit;

use App\Exports\Concerns\PrevineInjecaoDeFormulaExcel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A1, EXP-01 — prova unitária do sanitizador
 * central contra injeção de fórmula Excel/CSV. Testado diretamente contra
 * um Cell real do PhpSpreadsheet (não um mock), gravando o valor e
 * inspecionando o DataType e o conteúdo textual efetivamente persistido.
 */
class PrevineInjecaoDeFormulaExcelTest extends TestCase
{
    private function bindValueEmCelulaReal($value): \PhpOffice\PhpSpreadsheet\Cell\Cell
    {
        $binder = new class {
            use PrevineInjecaoDeFormulaExcel;
        };

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $cell = $sheet->getCell('A1');

        $binder->bindValue($cell, $value);

        return $cell;
    }

    /**
     * @dataProvider payloadsPerigosos
     */
    public function test_payload_perigoso_nunca_vira_formula(string $payload): void
    {
        $cell = $this->bindValueEmCelulaReal($payload);

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        // PhpSpreadsheet\Cell\DataType::checkString() normaliza \r/\r\n para
        // \n em QUALQUER string de célula (comportamento nativo documentado,
        // nada a ver com o sanitizador) — a garantia de segurança real é o
        // DataType acima, nunca o byte exato de quebra de linha.
        $this->assertSame(str_replace(["\r\n", "\r"], "\n", $payload), $cell->getValue());
    }

    public static function payloadsPerigosos(): array
    {
        return [
            'formula HYPERLINK' => ['=HYPERLINK("http://evil.example","clique")'],
            'formula SUM com +' => ['+SUM(1+9)*79'],
            'formula com hifen' => ['-1+1+cmd|\' /C calc\'!A0'],
            'formula com arroba' => ['@SUM(1,9)'],
            'tab seguido de igual' => ["\t=1+1"],
            'retorno de carro seguido de igual' => ["\r=1+1"],
        ];
    }

    /**
     * @dataProvider payloadsNormais
     */
    public function test_valor_normal_nunca_e_alterado($payload, string $tipoEsperado): void
    {
        $cell = $this->bindValueEmCelulaReal($payload);

        $this->assertSame($tipoEsperado, $cell->getDataType());
    }

    public static function payloadsNormais(): array
    {
        return [
            'texto comum' => ['Concretagem da Fundação', DataType::TYPE_STRING],
            'texto com hifen no meio' => ['Item pré-fabricado', DataType::TYPE_STRING],
            'string vazia' => ['', DataType::TYPE_STRING],
            'numero inteiro' => [42, DataType::TYPE_NUMERIC],
            'numero float' => [3.14, DataType::TYPE_NUMERIC],
            'numero negativo real (nunca string)' => [-5, DataType::TYPE_NUMERIC],
            'booleano' => [true, DataType::TYPE_BOOL],
            'null' => [null, DataType::TYPE_NULL],
        ];
    }

    public function test_texto_normal_comecando_com_hifen_como_string_nunca_vira_formula(): void
    {
        // Uma STRING (não um número real) que começa com "-" precisa ser
        // protegida igual às demais — nunca deixada como fórmula.
        $cell = $this->bindValueEmCelulaReal('-Fulano de Tal');

        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('-Fulano de Tal', $cell->getValue());
    }

    public function test_data_hora_legitima_nunca_e_afetada(): void
    {
        $data = new \DateTimeImmutable('2026-01-15 10:00:00');
        $cell = $this->bindValueEmCelulaReal($data);

        // DateTimeInterface é convertido pro formato de data padrão do
        // DefaultValueBinder — nunca passa pelo caminho de sanitização
        // (só afeta strings), então o comportamento nativo do
        // Maatwebsite/PhpSpreadsheet é preservado intocado.
        $this->assertSame('2026-01-15 10:00:00', $cell->getValue());
    }
}
