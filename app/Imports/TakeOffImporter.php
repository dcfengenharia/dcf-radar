<?php

namespace App\Imports;

use App\Enums\OrigemItemTakeOff;
use App\Models\Disciplina;
use App\Models\FamiliaMaterial;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\UnidadeMedida;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — importador de itens de Take Off,
 * mesmo padrão em duas fases de `App\Imports\DocumentoEngenhariaImporter`
 * (lerLinhas → sem tocar banco; analisar → prévia; aplicar → grava,
 * chamado de dentro de transacaoSegura() no componente Livewire).
 *
 * DIFERENÇA da 19.1 original: opera sempre sobre UMA `ListaEngenharia`
 * já criada/selecionada pelo usuário (nunca resolve/cria Documento,
 * Revisão OU Lista) — a planilha não tem mais coluna "Tipo" (o tipo é
 * da lista inteira, implícito, nunca por linha). Reconciliação
 * (novo/atualizado) é escopada estritamente por `lista_engenharia_id`:
 * importar em LM-002 nunca toca/altera itens de LM-001, mesmo que
 * tenham o mesmo código (identidades de listas diferentes nunca colidem
 * — unique é `(lista_engenharia_id, codigo)`).
 *
 * Aba fixa "TAKEOFF", colunas A-G: Código, Descrição, Unidade, Família,
 * Disciplina, Quantidade, Observações.
 */
class TakeOffImporter
{
    private const SHEET_NOME = 'TAKEOFF';

    public function lerLinhas(string $caminhoArquivo): array
    {
        $reader = new Xlsx();
        $reader->setLoadSheetsOnly([self::SHEET_NOME]);
        $spreadsheet = $reader->load($caminhoArquivo);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NOME);

        if (!$sheet) {
            throw new RuntimeException('A planilha não tem uma aba chamada "TAKEOFF" — confira se é o arquivo certo.');
        }

        $linhas = [];
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $descricao = trim((string) $sheet->getCell("B{$row}")->getValue());

            if ($descricao === '') {
                continue;
            }

            $linhas[] = [
                'linha' => $row,
                'codigo' => trim((string) $sheet->getCell("A{$row}")->getValue()) ?: null,
                'descricao' => $descricao,
                'unidade' => trim((string) $sheet->getCell("C{$row}")->getValue()) ?: null,
                'familia' => trim((string) $sheet->getCell("D{$row}")->getValue()) ?: null,
                'disciplina' => trim((string) $sheet->getCell("E{$row}")->getValue()) ?: null,
                'quantidade' => $this->lerQuantidade($sheet->getCell("F{$row}")),
                'observacoes' => trim((string) $sheet->getCell("G{$row}")->getValue()) ?: null,
            ];
        }

        return $linhas;
    }

    /**
     * Tolera separador decimal brasileiro (vírgula) além do formato
     * numérico nativo do Excel. Valor não numérico/vazio vira null —
     * nunca lança exceção (não pode travar a prévia por causa de uma
     * célula suja); a linha é sinalizada como aviso e ignorada em
     * analisar()/aplicar().
     */
    private function lerQuantidade(Cell $cell): ?float
    {
        $valor = $cell->getValue();

        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            return (float) $valor;
        }

        $normalizado = str_replace(['.', ','], ['', '.'], trim((string) $valor));

        return is_numeric($normalizado) ? (float) $normalizado : null;
    }

    /**
     * Monta a prévia sem gravar nada: deduplica por código dentro do
     * próprio arquivo (a última linha prevalece — mesmo critério do
     * importador de LD), classifica novo/atualizado contra os itens JÁ
     * existentes DESTA lista, e junta os avisos de dado inválido/
     * catálogo novo. Quantidade <= 0 (zero ou negativa) é sempre
     * ignorada — Take Off nunca entra com quantidade não-positiva pelo
     * caminho de importação normal (seção 14 da correção 19.1).
     */
    public function analisar(array $linhas, ListaEngenharia $lista): array
    {
        $porCodigo = [];
        $codigosDuplicados = [];
        $avisos = [];
        $ignoradas = 0;

        foreach ($linhas as $linha) {
            if ($linha['quantidade'] === null) {
                $avisos[] = "Linha {$linha['linha']}: quantidade ausente ou inválida — linha ignorada.";
                $ignoradas++;
                continue;
            }

            if ($linha['quantidade'] <= 0) {
                $avisos[] = "Linha {$linha['linha']}: quantidade deve ser maior que zero (recebido {$linha['quantidade']}) — linha ignorada.";
                $ignoradas++;
                continue;
            }

            $chave = $linha['codigo'] ?? "__sem_codigo_{$linha['linha']}__";

            if (isset($porCodigo[$chave]) && $linha['codigo']) {
                $codigosDuplicados[$linha['codigo']] = true;
            }

            $porCodigo[$chave] = $linha;
        }

        $linhasDeduped = array_values($porCodigo);

        foreach ($codigosDuplicados as $codigoDuplicado => $_) {
            $avisos[] = "Código \"{$codigoDuplicado}\" aparece mais de uma vez na planilha — a última linha prevalece.";
        }

        $codigos = array_filter(array_column($linhasDeduped, 'codigo'));
        $existentes = ItemTakeOff::where('lista_engenharia_id', $lista->id)
            ->whereIn('codigo', $codigos)
            ->get()
            ->keyBy('codigo');

        $unidadesConhecidas = [];
        foreach (UnidadeMedida::all() as $u) {
            $unidadesConhecidas[mb_strtolower($u->codigo)] = true;
            $unidadesConhecidas[mb_strtolower($u->nome)] = true;
        }
        $familiasConhecidas = FamiliaMaterial::all()->keyBy(fn(FamiliaMaterial $f) => mb_strtolower($f->nome));
        $disciplinasConhecidas = Disciplina::all()->keyBy(fn(Disciplina $d) => mb_strtolower($d->nome));

        $novos = 0;
        $atualizados = 0;

        foreach ($linhasDeduped as $linha) {
            $existente = $linha['codigo'] ? $existentes->get($linha['codigo']) : null;
            $existente ? $atualizados++ : $novos++;

            if ($linha['unidade'] && !isset($unidadesConhecidas[mb_strtolower($linha['unidade'])])) {
                $avisos[] = "Unidade \"{$linha['unidade']}\" (linha {$linha['linha']}) não existe ainda — será criada automaticamente.";
            }
            if ($linha['familia'] && !$familiasConhecidas->has(mb_strtolower($linha['familia']))) {
                $avisos[] = "Família \"{$linha['familia']}\" (linha {$linha['linha']}) não existe ainda — será criada automaticamente.";
            }
            if ($linha['disciplina'] && !$disciplinasConhecidas->has(mb_strtolower($linha['disciplina']))) {
                $avisos[] = "Disciplina \"{$linha['disciplina']}\" (linha {$linha['linha']}) não existe ainda — será criada automaticamente.";
            }
        }

        return [
            'total_linhas' => count($linhas),
            'ignoradas' => $ignoradas,
            'novos' => $novos,
            'atualizados' => $atualizados,
            'avisos' => $avisos,
            'linhas' => $linhasDeduped,
        ];
    }

    /**
     * Grava de fato — chamar dentro de transacaoSegura() no componente
     * Livewire (tenant_id carimbado automaticamente por BelongsToTenant).
     * Escopado estritamente por `$lista->id`: nunca toca item de
     * nenhuma outra lista, mesmo que o código coincida.
     */
    public function aplicar(array $linhasDeduped, ListaEngenharia $lista, ?string $usuarioId): array
    {
        $novos = 0;
        $atualizados = 0;

        foreach ($linhasDeduped as $linha) {
            $unidadeId = $this->resolverOuCriarUnidade($linha['unidade']);
            $familiaId = $this->resolverOuCriarFamilia($linha['familia']);
            $disciplinaId = $this->resolverOuCriarDisciplina($linha['disciplina']);

            $existente = $linha['codigo']
                ? ItemTakeOff::where('lista_engenharia_id', $lista->id)->where('codigo', $linha['codigo'])->first()
                : null;

            $dados = [
                'descricao' => $linha['descricao'],
                'unidade_medida_id' => $unidadeId,
                'familia_material_id' => $familiaId,
                'disciplina_id' => $disciplinaId,
                'quantidade' => $linha['quantidade'],
                'observacoes' => $linha['observacoes'],
            ];

            if ($existente) {
                $existente->update($dados);
                $atualizados++;
            } else {
                ItemTakeOff::create($dados + [
                    'lista_engenharia_id' => $lista->id,
                    'codigo' => $linha['codigo'],
                    'origem' => OrigemItemTakeOff::Importado->value,
                    'created_by_id' => $usuarioId,
                ]);
                $novos++;
            }
        }

        return [
            'novos' => $novos,
            'atualizados' => $atualizados,
        ];
    }

    /**
     * `unidades_medida.codigo` é obrigatório (D2) — uma planilha de Take
     * Off normalmente traz a sigla curta ("KG", "UN", "M") na coluna de
     * unidade, não um nome longo, então o valor da célula vira `codigo`
     * E `nome` ao criar automaticamente. Normalização (trim+maiúsculo)
     * acontece no próprio model (`UnidadeMedida::setCodigoAttribute()`),
     * nunca duplicada aqui. Casamento tenta código OU nome
     * (case-insensitive), pra reconhecer tanto "KG" quanto "Quilograma"
     * se o catálogo já tiver sido cadastrado manualmente com nome longo.
     */
    private function resolverOuCriarUnidade(?string $valor): ?string
    {
        if (!$valor) {
            return null;
        }

        $existente = UnidadeMedida::whereRaw('LOWER(codigo) = ? OR LOWER(nome) = ?', [mb_strtolower($valor), mb_strtolower($valor)])->first();

        return $existente?->id ?? UnidadeMedida::create(['codigo' => $valor, 'nome' => $valor])->id;
    }

    /**
     * Ciclo 19, Etapa 19.1.HARDENING — agora que `familias_material` tem
     * `unique(tenant_id, nome)` de verdade, duas importações concorrentes
     * resolvendo a mesma família nova pela primeira vez podem colidir
     * (corrida clássica check-then-act). Mesmo padrão já usado em
     * `PlanoAcao::transformarEmRestricoes()`: tenta criar, e se colidir
     * (MySQL 1062), busca de novo — nunca deixa a exceção de banco crua
     * subir pro usuário.
     */
    private function resolverOuCriarFamilia(?string $nome): ?string
    {
        if (!$nome) {
            return null;
        }

        $existente = FamiliaMaterial::whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])->first();

        if ($existente) {
            return $existente->id;
        }

        try {
            return FamiliaMaterial::create(['nome' => $nome])->id;
        } catch (\Illuminate\Database\QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return FamiliaMaterial::whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])->first()?->id;
            }
            throw $e;
        }
    }

    private function resolverOuCriarDisciplina(?string $nome): ?string
    {
        if (!$nome) {
            return null;
        }

        $existente = Disciplina::whereRaw('LOWER(nome) = ?', [mb_strtolower($nome)])->first();

        return $existente?->id ?? Disciplina::create(['nome' => $nome])->id;
    }
}
