<?php

namespace App\Imports;

use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\StatusDocumento;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Importador da "Lista de Documentos" (Engenharia) — planilha real do
 * usuário tem uma aba fixa chamada "LD" com colunas A-E: Disciplina,
 * Código, Revisão, Título do Documento, Status do Documento (as colunas
 * F-H — Status da Emissão, Data Emissão Prevista, Data Emissão Real —
 * vêm sempre vazias na planilha real hoje, mas o layout já reserva G e H
 * pra elas; G e H são lidas quando preenchidas, F continua ignorada por
 * falta de definição de uso).
 *
 * A aba é um REGISTRO DE ESTADO ATUAL (não um log de revisões): cada
 * linha traz a revisão/status vigente do documento, não seu histórico.
 * Por isso a reconciliação sempre ATUALIZA o documento (descrição/
 * disciplina) e só ACRESCENTA uma nova revisão quando o texto da
 * revisão muda em relação à última já registrada — nunca edita/apaga
 * revisão existente (mesma filosofia de AtividadeSnapshot). Status
 * agora é da emissão (revisão), não mais do documento — só é gravado
 * quando uma revisão nova é de fato criada.
 */
class DocumentoEngenhariaImporter
{
    private const SHEET_NOME = 'LD';

    /**
     * Lê a aba LD do arquivo, sem tocar no banco. Lança RuntimeException
     * se a aba não existir (aviso claro pro usuário na prévia).
     */
    public function lerLinhas(string $caminhoArquivo): array
    {
        $reader = new Xlsx();
        $reader->setLoadSheetsOnly([self::SHEET_NOME]);
        $spreadsheet = $reader->load($caminhoArquivo);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NOME);

        if (!$sheet) {
            throw new RuntimeException('A planilha não tem uma aba chamada "LD" — confira se é o arquivo certo.');
        }

        $linhas = [];
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $codigo = trim((string) $sheet->getCell("B{$row}")->getValue());

            if ($codigo === '') {
                continue;
            }

            $linhas[] = [
                'linha' => $row,
                'disciplina' => trim((string) $sheet->getCell("A{$row}")->getValue()) ?: null,
                'codigo' => $codigo,
                'revisao' => trim((string) $sheet->getCell("C{$row}")->getValue()) ?: null,
                'titulo' => trim((string) $sheet->getCell("D{$row}")->getValue()) ?: null,
                'status' => trim((string) $sheet->getCell("E{$row}")->getValue()) ?: null,
                'data_prevista' => $this->lerData($sheet->getCell("G{$row}")),
                'data_real' => $this->lerData($sheet->getCell("H{$row}")),
            ];
        }

        return $linhas;
    }

    /**
     * Lê uma célula de data com tolerância a dois formatos: data "de
     * verdade" do Excel (serial numérico com formatação de data) ou
     * texto livre parseável (ex.: usuário digitou "01/07/2026" numa
     * célula sem formatação). Célula vazia ou não reconhecível como
     * data vira null — nunca lança exceção (não pode travar a prévia
     * por causa de uma célula suja).
     */
    private function lerData(Cell $cell): ?string
    {
        $valor = $cell->getValue();

        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor) && ExcelDate::isDateTime($cell)) {
            try {
                return ExcelDate::excelToDateTimeObject($valor)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $valor)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Monta a prévia sem gravar nada: deduplica por código (a última
     * linha da planilha prevalece), classifica novo/atualizado, conta
     * quantas revisões novas seriam criadas e junta os avisos.
     */
    public function analisar(array $linhas, string $obraId): array
    {
        $porCodigo = [];
        $codigosDuplicados = [];

        foreach ($linhas as $linha) {
            if (isset($porCodigo[$linha['codigo']])) {
                $codigosDuplicados[$linha['codigo']] = true;
            }
            $porCodigo[$linha['codigo']] = $linha;
        }

        $linhasDeduped = array_values($porCodigo);
        $codigos = array_column($linhasDeduped, 'codigo');

        $existentes = DocumentoEngenharia::where('obra_id', $obraId)
            ->whereIn('codigo', $codigos)
            ->with('revisoes:id,documento_engenharia_id,revisao')
            ->withCount('reprogramacoes')
            ->get()
            ->keyBy('codigo');

        $disciplinasConhecidas = Disciplina::all()->keyBy(fn(Disciplina $d) => mb_strtolower($d->nome));
        $statusConhecidos = StatusDocumento::where('obra_id', $obraId)->get();

        $novos = 0;
        $atualizados = 0;
        $novasRevisoes = 0;
        $avisos = [];

        foreach ($codigosDuplicados as $codigoDuplicado => $_) {
            $avisos[] = "Código \"{$codigoDuplicado}\" aparece mais de uma vez na planilha — a última linha prevalece.";
        }

        foreach ($linhasDeduped as $linha) {
            $documento = $existentes->get($linha['codigo']);

            if (!$documento) {
                $novos++;
            } else {
                $atualizados++;
            }

            if ($linha['revisao']) {
                $revisaoAtual = $documento?->revisoes->first()?->revisao;
                if ($revisaoAtual !== $linha['revisao']) {
                    $novasRevisoes++;
                }
            }

            if ($linha['disciplina'] && !$disciplinasConhecidas->has(mb_strtolower($linha['disciplina']))) {
                $avisos[] = "Disciplina \"{$linha['disciplina']}\" (linha {$linha['linha']}) não existe ainda — será criada automaticamente.";
            }

            if ($linha['status'] && !$this->encontrarStatus($statusConhecidos, $linha['status'])) {
                $avisos[] = "Status \"{$linha['status']}\" (linha {$linha['linha']}, código {$linha['codigo']}) não encontrado no cadastro desta obra — documento fica sem status.";
            }

            if ($linha['data_prevista'] && $documento && ($documento->revisoes->isNotEmpty() || $documento->reprogramacoes_count > 0)) {
                $avisos[] = "Data de previsão da linha {$linha['linha']} (código {$linha['codigo']}) será ignorada — o documento já tem emissão ou reprogramação registrada.";
            }
        }

        return [
            'total_linhas' => count($linhas),
            'novos' => $novos,
            'atualizados' => $atualizados,
            'novas_revisoes' => $novasRevisoes,
            'avisos' => $avisos,
            'linhas' => $linhasDeduped,
        ];
    }

    /**
     * Grava de fato — chamar dentro de transacaoSegura() no componente
     * Livewire (o tenant_id é carimbado automaticamente pelo trait
     * BelongsToTenant, nunca definido à mão aqui).
     */
    public function aplicar(array $linhasDeduped, string $obraId, ?string $usuarioId): array
    {
        $novos = 0;
        $atualizados = 0;
        $novasRevisoes = 0;
        $statusConhecidos = StatusDocumento::where('obra_id', $obraId)->get();

        foreach ($linhasDeduped as $linha) {
            $disciplinaId = null;
            if ($linha['disciplina']) {
                $disciplina = Disciplina::whereRaw('LOWER(nome) = ?', [mb_strtolower($linha['disciplina'])])->first();
                if (!$disciplina) {
                    $disciplina = Disciplina::create(['nome' => $linha['disciplina']]);
                }
                $disciplinaId = $disciplina->id;
            }

            $statusId = $linha['status'] ? $this->encontrarStatus($statusConhecidos, $linha['status'])?->id : null;

            $documento = DocumentoEngenharia::where('obra_id', $obraId)
                ->where('codigo', $linha['codigo'])
                ->withCount('reprogramacoes')
                ->first();

            $dadosDocumento = [
                'descricao' => $linha['titulo'] ?? $linha['codigo'],
                'disciplina_id' => $disciplinaId,
            ];

            if (!$documento) {
                $documento = DocumentoEngenharia::create(
                    $dadosDocumento + [
                        'obra_id' => $obraId,
                        'codigo' => $linha['codigo'],
                        'data_planejada' => $linha['data_prevista'],
                    ]
                );
                $novos++;
            } else {
                // Nunca reescreve uma previsão que já foi reprogramada ou que
                // já teve emissão — a planilha só alimenta a estimativa
                // inicial, o que acontece depois é histórico imutável.
                if ($linha['data_prevista'] && $documento->revisoes()->doesntExist() && $documento->reprogramacoes_count === 0) {
                    $dadosDocumento['data_planejada'] = $linha['data_prevista'];
                }

                $documento->update($dadosDocumento);
                $atualizados++;
            }

            if ($linha['revisao']) {
                $revisaoAtual = $documento->revisoes()->first()?->revisao;
                if ($revisaoAtual !== $linha['revisao']) {
                    $documento->revisoes()->create([
                        'revisao' => $linha['revisao'],
                        'data_emissao' => $linha['data_real'],
                        'descricao' => 'Importado via planilha',
                        'status_documento_id' => $statusId,
                        'criado_por_id' => $usuarioId,
                    ]);
                    $novasRevisoes++;
                }
            }
        }

        return [
            'novos' => $novos,
            'atualizados' => $atualizados,
            'novas_revisoes' => $novasRevisoes,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, StatusDocumento> $status
     */
    private function encontrarStatus($status, string $valorPlanilha): ?StatusDocumento
    {
        $valor = mb_strtolower(trim($valorPlanilha));

        return $status->first(
            fn(StatusDocumento $s) => ($s->codigo && mb_strtolower($s->codigo) === $valor) || mb_strtolower($s->nome) === $valor
        );
    }
}
