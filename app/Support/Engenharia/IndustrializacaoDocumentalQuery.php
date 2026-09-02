<?php

namespace App\Support\Engenharia;

use App\DTOs\Engenharia\FatoEngenharia;
use App\Enums\SeveridadeSituacao;
use App\Models\ProdutoIndustrializado;
use App\Models\Work;
use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.1 — Engenharia × Industrialização (Seção 17).
 * `ProdutoIndustrializado::documento_engenharia_revisao_id` congela a
 * revisão EXATA usada na fabricação (Ciclo 20.5) — comparamos essa FK
 * congelada contra `DocumentoEngenharia::revisaoVigente()` (Ciclo 18.3)
 * e expomos a divergência como FATO puro, nunca como invalidação
 * automática: "Produto associado à revisão R1; revisão atual R2" —
 * decisão explícita do pedido (Seção 17: "não conclua automaticamente
 * que produto fabricado na revisão anterior está inválido... isso requer
 * regra de Engenharia/Qualidade que pode não existir. Se não existir,
 * apenas exponha... como fato, não erro"). Confirmado por fresh-read:
 * essa regra de Qualidade NÃO existe no domínio — por isso o fato aqui
 * é sempre neutro.
 */
class IndustrializacaoDocumentalQuery
{
    /** @return Collection<int, FatoEngenharia> */
    public static function comMudancaDeRevisao(Work $obra): Collection
    {
        $produtos = ProdutoIndustrializado::query()
            ->whereHas('ordem', fn ($q) => $q->where('obra_id', $obra->id))
            ->whereNotNull('documento_engenharia_revisao_id')
            ->with(['documentoRevisao.documento.latestRevisao', 'ordem:id,numero,obra_id'])
            ->get();

        return $produtos
            ->filter(function (ProdutoIndustrializado $produto) {
                $revisaoCongelada = $produto->documentoRevisao;
                $revisaoVigenteAtual = $revisaoCongelada?->documento?->latestRevisao;

                return $revisaoCongelada && $revisaoVigenteAtual && $revisaoCongelada->id !== $revisaoVigenteAtual->id;
            })
            ->map(function (ProdutoIndustrializado $produto) use ($obra) {
                $revisaoCongelada = $produto->documentoRevisao;
                $documento = $revisaoCongelada->documento;
                $revisaoVigente = $documento->latestRevisao;
                $ordem = $produto->ordem;

                return new FatoEngenharia(
                    tipo: 'industrializacao_com_mudanca_de_revisao',
                    severidade: SeveridadeSituacao::Informativa,
                    obraId: $obra->id,
                    entidadeTipo: 'ProdutoIndustrializado',
                    entidadeId: $produto->id,
                    descricao: "Produto {$produto->nome} (Ordem {$ordem->numero}) foi fabricado com base na revisão"
                        . " {$revisaoCongelada->revisao} do documento {$documento->codigo}; a revisão vigente hoje é"
                        . " {$revisaoVigente->revisao}.",
                    dataRelevante: null,
                    diasParaRelevante: null,
                    atividadeId: null,
                    destinatariosPerfis: [['slug' => 'engenharia.pacotes', 'acao' => 'ver']],
                    deepLink: ['rota' => 'engenharia.pacotes', 'parametros' => ['documento' => $documento->id]],
                    contexto: [
                        'ordem_industrializacao_id' => $ordem->id,
                        'revisao_congelada' => $revisaoCongelada->revisao,
                        'revisao_vigente' => $revisaoVigente->revisao,
                    ],
                );
            })
            ->values();
    }
}
