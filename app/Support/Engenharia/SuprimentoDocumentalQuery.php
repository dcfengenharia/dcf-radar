<?php

namespace App\Support\Engenharia;

use App\DTOs\Engenharia\FatoEngenharia;
use App\Enums\SeveridadeSituacao;
use App\Models\DocumentoEngenharia;
use App\Models\ItemSuprimento;
use App\Models\Work;
use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.1 — Engenharia × Suprimentos (Seção 18). Relação
 * DETERMINÍSTICA já existente e já em produção desde o Ciclo 18.4 —
 * `ItemSuprimento::documentosEngenharia()` (N:N via pivot real
 * `item_suprimento_documentos`, mesma relação já consumida por
 * `CentralProntidaoQuery::montarView()` pro `engenhariaAlerta`). Nenhum
 * casamento textual/por código — só a FK do pivô. Diferente da cadeia
 * `ItemTakeOff → Lista → Revisão → Documento` (Ciclo 19.1), que
 * estabelece a ORIGEM documental de um item de Take Off — aqui a
 * pergunta é a inversa: "este Pacote de Compra depende de algum
 * Documento ainda não liberado?", e a resposta determinística já existe
 * sem precisar atravessar TakeOff/Material nenhum.
 */
class SuprimentoDocumentalQuery
{
    /** @return Collection<int, FatoEngenharia> */
    public static function pacotesBloqueadosPorDocumento(Work $obra): Collection
    {
        $pacotes = ItemSuprimento::query()
            ->where('obra_id', $obra->id)
            ->whereHas('documentosEngenharia', fn ($q) => $q->naoLiberados())
            ->with([
                'documentosEngenharia' => fn ($q) => $q->naoLiberados(),
                // Ciclo 22, Etapa 22.4.CORREÇÃO — Achado B da auditoria 22.4:
                // `contexto['motivo_liberacao'] => $doc->motivoLiberacao()`
                // (linha abaixo) chama `DocumentoEngenharia::revisaoVigente()`
                // internamente — sem este eager-load, cai no fallback
                // `$this->latestRevisao()->first()` (query nova a cada
                // chamada, nunca cacheada) e, em seguida,
                // `DocumentoEngenhariaRevisao::estaLiberadaParaConstrucao()`
                // lê `$this->ultimaLiberacao` como PROPRIEDADE — em um model
                // recém-buscado sem essa relação carregada, isso aciona o
                // guard de `Model::preventLazyLoading()` e lança
                // `LazyLoadingViolationException` fora de produção (e um N+1
                // silencioso em produção). Mesma classe de bug já corrigida
                // em `CentralProntidaoQuery` (Ciclo 21.7.CORREÇÃO, Achado C)
                // e em `SituacoesGerenciaisQuery::documentoBloqueante()`
                // (Ciclo 22.2) — mesma correção: estender o eager-load pra
                // cobrir toda a cadeia que `motivoLiberacao()` de fato lê.
                'documentosEngenharia.latestRevisao.ultimaLiberacao',
            ])
            ->get();

        return $pacotes
            ->flatMap(fn (ItemSuprimento $pacote) => $pacote->documentosEngenharia
                ->map(fn (DocumentoEngenharia $doc) => [$pacote, $doc]))
            ->map(function (array $par) use ($obra) {
                [$pacote, $doc] = $par;

                return new FatoEngenharia(
                    tipo: 'suprimento_bloqueado_por_documento',
                    severidade: SeveridadeSituacao::Atencao,
                    obraId: $obra->id,
                    entidadeTipo: 'ItemSuprimento',
                    entidadeId: $pacote->id,
                    descricao: "Pacote de Compra {$pacote->nome} depende do documento {$doc->codigo},"
                        . ' ainda não liberado para construção.',
                    dataRelevante: null,
                    diasParaRelevante: null,
                    atividadeId: null,
                    destinatariosPerfis: [['slug' => 'suprimentos.mapa', 'acao' => 'ver'], ['slug' => 'engenharia.pacotes', 'acao' => 'ver']],
                    deepLink: ['rota' => 'engenharia.pacotes', 'parametros' => ['documento' => $doc->id]],
                    contexto: ['pacote_id' => $pacote->id, 'documento_id' => $doc->id, 'motivo_liberacao' => $doc->motivoLiberacao()],
                );
            })
            ->values();
    }
}
