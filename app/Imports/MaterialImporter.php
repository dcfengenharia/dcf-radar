<?php

namespace App\Imports;

use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\OrigemCadastroMaterial;
use App\Models\FamiliaMaterial;
use App\Models\Material;
use App\Models\UnidadeMedida;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * Importador do Catálogo Mestre de Materiais — mesmo padrão em duas fases
 * (lerLinhas → analisar → aplicar) já usado por `TakeOffImporter`/
 * `DocumentoEngenhariaImporter`, mas com semântica DELIBERADAMENTE
 * DIFERENTE em 2 pontos, por instrução explícita do produto:
 *
 * 1. NUNCA cria Unidade de Medida ou Família de Materiais ausente — uma
 *    unidade/família não encontrada no cadastro mestre do tenant vira
 *    erro de linha ("não encontrada"), nunca uma criação automática
 *    (diferente de `TakeOffImporter::resolverOuCriarUnidade/Familia()`,
 *    que auto-cria — comportamento certo pra Take Off, errado pro
 *    catálogo mestre normalizado de Material).
 * 2. NUNCA faz upsert — um código já existente no catálogo (mesmo
 *    soft-deletado, já que a unique constraint não distingue) é
 *    marcado como CONFLITO e ignorado, nunca atualizado silenciosamente.
 *
 * Aba fixa "Materiais", colunas A-E: Código, Descrição, Unidade,
 * Família, Modo de Rastreabilidade.
 *
 * BUG TARGETED (corrigido): `SHEET_NOME` era `'MATERIAIS'` (caixa alta),
 * mas `App\Exports\MaterialImportTemplateExport` sempre gerou a aba como
 * `'Materiais'` (Título) — `PhpSpreadsheet\Spreadsheet::getSheetByName()`
 * é case-sensitive, então o PRÓPRIO template oficial gerado pelo botão
 * "Baixar modelo" nunca era aceito pelo importador ("A planilha não tem
 * uma aba chamada MATERIAIS"). Reproduzido byte-a-byte gerando o arquivo
 * real via `MaterialImportTemplateExport` e comparando os bytes do nome
 * da aba contra esta constante. `SHEET_NOME` é agora PUBLIC e é a ÚNICA
 * autoridade do nome da aba — `MaterialImportTemplateExport` referencia
 * esta constante diretamente, eliminando qualquer chance de as duas
 * strings divergirem de novo no futuro.
 */
class MaterialImporter
{
    public const SHEET_NOME = 'Materiais';

    public function lerLinhas(string $caminhoArquivo): array
    {
        $reader = new Xlsx();
        $reader->setLoadSheetsOnly([self::SHEET_NOME]);
        $spreadsheet = $reader->load($caminhoArquivo);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NOME);

        if (! $sheet) {
            throw new RuntimeException('A planilha não tem uma aba chamada "' . self::SHEET_NOME . '" — confira se é o arquivo baixado pelo botão "Baixar modelo".');
        }

        $linhas = [];
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $codigo = trim((string) $sheet->getCell("A{$row}")->getValue());
            $descricao = trim((string) $sheet->getCell("B{$row}")->getValue());

            if ($codigo === '' && $descricao === '') {
                continue;
            }

            $linhas[] = [
                'linha' => $row,
                'codigo' => $codigo,
                'descricao' => $descricao,
                'unidade' => trim((string) $sheet->getCell("C{$row}")->getValue()),
                'familia' => trim((string) $sheet->getCell("D{$row}")->getValue()) ?: null,
                'modo_rastreabilidade' => trim((string) $sheet->getCell("E{$row}")->getValue()) ?: null,
            ];
        }

        return $linhas;
    }

    /**
     * Monta a prévia sem gravar nada. Cada linha vira `status`
     * 'valida'|'invalida'|'conflito':
     * - 'invalida': campo obrigatório ausente, Unidade/Família não
     *   encontrada, modo de rastreabilidade inválido, ou código
     *   duplicado dentro do PRÓPRIO arquivo.
     * - 'conflito': código já existe no catálogo do tenant (mesmo
     *   soft-deletado) — nunca sobrescrito.
     * - 'valida': pronta para `aplicar()`.
     */
    public function analisar(array $linhas): array
    {
        $codigosVistosNoArquivo = [];
        $linhasAnalisadas = [];
        $resumo = [
            'validas' => 0,
            'invalidas' => 0,
            'conflitos' => 0,
            'duplicadas_no_arquivo' => 0,
        ];

        $unidadesPorCodigo = UnidadeMedida::all()->keyBy(fn (UnidadeMedida $u) => mb_strtolower($u->codigo));
        $familiasPorNome = FamiliaMaterial::all()->keyBy(fn (FamiliaMaterial $f) => mb_strtolower($f->nome));
        $codigosExistentes = Material::withTrashed()->pluck('codigo')
            ->map(fn ($c) => mb_strtolower($c))
            ->flip();

        foreach ($linhas as $linha) {
            $erros = [];

            if ($linha['codigo'] === '') {
                $erros[] = 'Código é obrigatório.';
            }

            if ($linha['descricao'] === '') {
                $erros[] = 'Descrição é obrigatória.';
            }

            $unidade = null;
            if ($linha['unidade'] === '') {
                $erros[] = 'Unidade de Medida é obrigatória.';
            } else {
                $unidade = $unidadesPorCodigo->get(mb_strtolower($linha['unidade']));
                if (! $unidade) {
                    $erros[] = "Unidade \"{$linha['unidade']}\" não encontrada no cadastro mestre. Cadastre a Unidade antes de importar ou corrija o código na planilha.";
                }
            }

            $familia = null;
            if ($linha['familia']) {
                $familia = $familiasPorNome->get(mb_strtolower($linha['familia']));
                if (! $familia) {
                    $erros[] = "Família \"{$linha['familia']}\" não encontrada no cadastro mestre. Cadastre a Família antes de importar, corrija o nome, ou deixe em branco (Família é opcional).";
                }
            }

            $modo = ModoRastreabilidadeMaterial::Quantitativo;
            if ($linha['modo_rastreabilidade']) {
                $modoResolvido = $this->resolverModo($linha['modo_rastreabilidade']);
                if (! $modoResolvido) {
                    $erros[] = "Modo de rastreabilidade \"{$linha['modo_rastreabilidade']}\" inválido — valores aceitos: " .
                        collect(ModoRastreabilidadeMaterial::cases())->map(fn ($m) => $m->value)->implode(', ') . '.';
                    $modo = null;
                } else {
                    $modo = $modoResolvido;
                }
            }

            $codigoLower = mb_strtolower($linha['codigo']);
            $duplicadaNoArquivo = $linha['codigo'] !== '' && isset($codigosVistosNoArquivo[$codigoLower]);

            if ($duplicadaNoArquivo) {
                $erros[] = "Código \"{$linha['codigo']}\" aparece mais de uma vez nesta planilha.";
            } elseif ($linha['codigo'] !== '') {
                $codigosVistosNoArquivo[$codigoLower] = true;
            }

            $conflito = ! $duplicadaNoArquivo && $linha['codigo'] !== '' && $codigosExistentes->has($codigoLower);
            if ($conflito) {
                $erros[] = "Já existe um Material com o código \"{$linha['codigo']}\" no catálogo — a importação nunca sobrescreve; corrija ou remova esta linha.";
            }

            $status = 'valida';
            if ($conflito) {
                $status = 'conflito';
                $resumo['conflitos']++;
            } elseif ($duplicadaNoArquivo || ! empty($erros)) {
                $status = 'invalida';
                $resumo['invalidas']++;
                if ($duplicadaNoArquivo) {
                    $resumo['duplicadas_no_arquivo']++;
                }
            } else {
                $resumo['validas']++;
            }

            $linhasAnalisadas[] = [
                'linha' => $linha['linha'],
                'codigo' => $linha['codigo'],
                'descricao' => $linha['descricao'],
                'unidade_id' => $unidade?->id,
                'unidade_label' => $linha['unidade'],
                'familia_id' => $familia?->id,
                'familia_label' => $linha['familia'],
                'modo_rastreabilidade' => $modo?->value,
                'status' => $status,
                'erros' => $erros,
            ];
        }

        return [
            'total_linhas' => count($linhas),
            'resumo' => $resumo,
            'linhas' => $linhasAnalisadas,
        ];
    }

    private function resolverModo(string $valor): ?ModoRastreabilidadeMaterial
    {
        foreach (ModoRastreabilidadeMaterial::cases() as $caso) {
            if (mb_strtolower($caso->value) === mb_strtolower($valor) || mb_strtolower($caso->label()) === mb_strtolower($valor)) {
                return $caso;
            }
        }

        return null;
    }

    /**
     * Grava só as linhas classificadas 'valida' — chamar dentro de
     * `transacaoSegura()`/`DB::transaction()` no componente Livewire
     * (tudo ou nada). `unidade_medida_id`/`familia_material_id` já foram
     * resolvidos em `analisar()`, nunca recriados aqui. Defesa em
     * profundidade: mesmo que o chamador esqueça de filtrar por status
     * antes de passar aqui, uma linha 'invalida'/'conflito' é sempre
     * ignorada — este método nunca grava nada fora do que `analisar()`
     * já aprovou.
     */
    public function aplicar(array $linhas): array
    {
        $criados = 0;

        foreach ($linhas as $linha) {
            if (($linha['status'] ?? null) !== 'valida') {
                continue;
            }

            Material::create([
                'codigo' => $linha['codigo'],
                'descricao' => $linha['descricao'],
                'unidade_medida_id' => $linha['unidade_id'],
                'familia_material_id' => $linha['familia_id'],
                'modo_rastreabilidade' => $linha['modo_rastreabilidade'],
                'origem_cadastro' => OrigemCadastroMaterial::Catalogo->value,
                'ativo' => true,
            ]);
            $criados++;
        }

        return ['criados' => $criados];
    }
}
