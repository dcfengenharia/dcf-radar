<?php

namespace Tests\Feature;

use App\Exports\CentralProntidaoExport;
use App\Exports\RestricoesExport;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Excel as ExcelWriterType;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A1, EXP-01 — prova FIM A FIM (não só do
 * sanitizador isolado, ver tests/Unit/PrevineInjecaoDeFormulaExcelTest.php):
 * um payload malicioso digitado por usuário (ex.: descrição de Restrição)
 * gera um arquivo .xlsx REAL cujo Excel/LibreOffice NUNCA interpreta como
 * fórmula executável — inspeciona o binário real gerado pelo
 * Maatwebsite/PhpSpreadsheet, não uma simulação.
 */
class ExcelFormulaInjectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{spreadsheet: \PhpOffice\PhpSpreadsheet\Spreadsheet, path: string}
     */
    private function gerarXlsxReal(object $export): array
    {
        $bytes = Excel::raw($export, ExcelWriterType::XLSX);

        $path = tempnam(sys_get_temp_dir(), 'exp01_').'.xlsx';
        file_put_contents($path, $bytes);

        return ['spreadsheet' => IOFactory::load($path), 'path' => $path];
    }

    /**
     * @dataProvider payloadsPerigosos
     */
    public function test_descricao_de_restricao_com_payload_malicioso_nunca_vira_formula_no_xlsx_real(string $payload): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'descricao' => $payload,
            'responsavel_externo' => $payload,
        ]);

        $export = new RestricoesExport(collect([$restricao]));

        ['spreadsheet' => $spreadsheet, 'path' => $path] = $this->gerarXlsxReal($export);

        $sheet = $spreadsheet->getActiveSheet();
        // Cabeçalho na linha 1; dados na linha 2. Coluna B = Descrição.
        $celulaDescricao = $sheet->getCell('B2');
        $celulaResponsavel = $sheet->getCell('E2');

        $this->assertNotSame(DataType::TYPE_FORMULA, $celulaDescricao->getDataType(), "payload '{$payload}' virou fórmula na coluna Descrição");
        $this->assertSame($payload, $celulaDescricao->getValue());

        $this->assertNotSame(DataType::TYPE_FORMULA, $celulaResponsavel->getDataType(), "payload '{$payload}' virou fórmula na coluna Responsável");

        @unlink($path);
    }

    public static function payloadsPerigosos(): array
    {
        return [
            'formula HYPERLINK' => ['=HYPERLINK("http://evil.example","clique")'],
            'formula SUM com +' => ['+SUM(A1:A10)'],
            'formula com hifen' => ['-2+3+cmd|\'/C calc\'!A0'],
            'formula com arroba' => ['@SUM(1,9)'],
        ];
    }

    public function test_descricao_normal_de_restricao_permanece_intacta_no_xlsx_real(): void
    {
        $tenant = Tenant::factory()->create();
        Work::factory()->create(['tenant_id' => $tenant->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'descricao' => 'Aguardando liberação do projeto estrutural - Torre A',
        ]);

        $export = new RestricoesExport(collect([$restricao]));
        ['spreadsheet' => $spreadsheet, 'path' => $path] = $this->gerarXlsxReal($export);

        $celula = $spreadsheet->getActiveSheet()->getCell('B2');
        $this->assertSame(DataType::TYPE_STRING, $celula->getDataType());
        $this->assertSame('Aguardando liberação do projeto estrutural - Torre A', $celula->getValue());

        @unlink($path);
    }

    /**
     * Cobertura do export multi-aba (WithMultipleSheets) — garante que o
     * value binder também protege as ABAS INTERNAS (classes anônimas), não
     * só exports de aba única.
     */
    public function test_export_multi_aba_tambem_protege_descricao_de_restricao_bloqueante(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = \App\Models\Atividade::factory()->create([
            'tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'fora_do_cronograma' => false,
        ]);
        Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
            'descricao' => '=cmd|\'/C calc\'!A0',
        ]);

        $views = (new \App\Support\CentralProntidao\CentralProntidaoQuery())->paraObra($obra);
        $dados = [
            'obra' => $obra,
            'geradoEm' => now(),
            'horizonteLabel' => '30 dias',
            'filtros' => ['pacote' => null, 'disciplina' => null, 'frente' => null, 'responsavel' => null, 'busca' => null],
            'resumo' => ['pronta' => 0, 'atencao' => 0, 'nao_pronta' => 1, 'concluida' => 0, 'total' => 1],
            'views' => $views,
        ];

        $export = new CentralProntidaoExport($dados);
        $sheets = $export->sheets();
        $folhaRestrBloq = $sheets[2]; // 'Restrições Bloqueantes'

        $bytes = Excel::raw($folhaRestrBloq, ExcelWriterType::XLSX);
        $path = tempnam(sys_get_temp_dir(), 'exp01_multi_').'.xlsx';
        file_put_contents($path, $bytes);
        $spreadsheet = IOFactory::load($path);

        $celula = $spreadsheet->getActiveSheet()->getCell('B2');
        $this->assertNotSame(DataType::TYPE_FORMULA, $celula->getDataType());
        $this->assertSame('=cmd|\'/C calc\'!A0', $celula->getValue());

        @unlink($path);
    }
}
