<?php

namespace App\Exports;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Imports\MaterialImporter;
use App\Models\FamiliaMaterial;
use App\Models\UnidadeMedida;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Template oficial de importação do Catálogo Mestre de Materiais —
 * mesmo padrão multi-aba de `RelatorioRestricoesExport` (WithMultipleSheets
 * + classes anônimas FromArray/WithHeadings/WithTitle).
 *
 * ABA 1: nome vem de `MaterialImporter::SHEET_NOME` — ÚNICA autoridade do
 * nome da aba, NUNCA um literal duplicado aqui (BUG TARGETED corrigido:
 * esta classe gerava `'Materiais'` enquanto o importador procurava
 * `'MATERIAIS'` — `PhpSpreadsheet::getSheetByName()` é case-sensitive, e
 * o próprio template oficial nunca era aceito pelo importador). Colunas
 * preenchíveis (com 1 linha de exemplo, nunca dado real). ABA 2/3:
 * valores VÁLIDOS do tenant do usuário que está baixando o modelo
 * (Unidades/Famílias ativas) — apoio de digitação, nunca uma validação
 * de dropdown do Excel nesta primeira versão. ABA 4: instruções em
 * texto — nunca expõe ID interno, sempre identificador humano
 * (código/nome).
 */
class MaterialImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            $this->folhaMateriais(),
            $this->folhaUnidades(),
            $this->folhaFamilias(),
            $this->folhaInstrucoes(),
        ];
    }

    private function folhaMateriais()
    {
        return $this->folha(
            MaterialImporter::SHEET_NOME,
            ['Código', 'Descrição', 'Unidade', 'Família', 'Modo de Rastreabilidade'],
            [
                ['EX-001', 'Exemplo — Tubo ASTM A106 6"', 'UN', '', 'quantitativo'],
            ]
        );
    }

    private function folhaUnidades()
    {
        $linhas = UnidadeMedida::where('ativo', true)
            ->orderBy('codigo')
            ->get()
            ->map(fn (UnidadeMedida $u) => [$u->codigo, $u->nome])
            ->all();

        return $this->folha('Unidades válidas', ['Código', 'Nome'], $linhas);
    }

    private function folhaFamilias()
    {
        $linhas = FamiliaMaterial::where('ativo', true)
            ->orderBy('nome')
            ->get()
            ->map(fn (FamiliaMaterial $f) => [$f->codigo ?? '', $f->nome])
            ->all();

        return $this->folha('Famílias válidas', ['Código', 'Nome'], $linhas);
    }

    private function folhaInstrucoes()
    {
        $modosAceitos = collect(ModoRastreabilidadeMaterial::cases())
            ->map(fn (ModoRastreabilidadeMaterial $m) => "{$m->value} ({$m->label()})")
            ->implode(', ');

        $linhas = [
            ['Código', 'Sim', 'Identificador único do Material no catálogo do seu tenant. Não pode repetir um código já cadastrado (mesmo um Material inativo/excluído).'],
            ['Descrição', 'Sim', 'Texto livre descrevendo o Material.'],
            ['Unidade', 'Sim', 'Use exatamente o CÓDIGO de uma Unidade de Medida já cadastrada (veja a aba "Unidades válidas"). Uma unidade inexistente rejeita a linha inteira — nunca é criada automaticamente.'],
            ['Família', 'Não', 'Use exatamente o NOME de uma Família já cadastrada (veja a aba "Famílias válidas"). Se preenchida e não encontrada, a linha é rejeitada. Deixe em branco se este Material não tem Família.'],
            ['Modo de Rastreabilidade', 'Não', "Valores aceitos: {$modosAceitos}. Se deixado em branco, assume \"quantitativo\"."],
            ['Código já existente no catálogo', '—', 'Nunca é sobrescrito — a linha é marcada como conflito e ignorada na importação.'],
            ['Código duplicado dentro do arquivo', '—', 'Códigos repetidos na mesma planilha são rejeitados — corrija antes de reimportar.'],
            ['Unidade/Família ainda não cadastrada', '—', 'Nunca é criada automaticamente pela importação. Cadastre antes em Estoque → Unidades de Medida / Famílias de Materiais, ou corrija o texto na planilha.'],
        ];

        return $this->folha('Instruções', ['Campo', 'Obrigatório?', 'Como preencher'], $linhas);
    }

    private function folha(string $titulo, array $cabecalho, array $linhas)
    {
        return new class ($titulo, $cabecalho, $linhas) implements FromArray, WithHeadings, WithTitle {
            public function __construct(
                private readonly string $titulo,
                private readonly array $cabecalho,
                private readonly array $linhas,
            ) {
            }

            public function array(): array
            {
                return $this->linhas;
            }

            public function headings(): array
            {
                return $this->cabecalho;
            }

            public function title(): string
            {
                return $this->titulo;
            }
        };
    }
}
