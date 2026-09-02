<?php

namespace App\Support\CentralProntidao;

use App\Enums\StatusItemSuprimento;
use App\Enums\StatusPlanoAcao;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\ItemProntidao;
use App\Models\ItemSuprimento;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\Work;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Central de Prontidão (Ciclo 15, Etapa B.1) — camada de LEITURA/
 * CONSOLIDAÇÃO sobre dado já existente, cruzando Atividade/Restricao/
 * checklist/PlanoAcao/Suprimentos/Engenharia. NÃO cria nenhuma fonte nova
 * de prontidão (Ciclo 14, princípio 10): `pronta` de cada
 * AtividadeProntidaoView vem sempre de `Atividade::scopeProntas()`,
 * consultado em UMA única query em lote — nunca reimplementado aqui (ver
 * `paraObra()`). PlanoAcao/Suprimento/Engenharia entram só como CONTEXTO
 * — nunca alteram `pronta` (Ciclo 14, princípios 5/6; ver
 * StatusOperacionalProntidao/montarView()).
 *
 * Toda consulta é em LOTE (eager-load + whereIn + agregação em memória),
 * nunca por atividade (Ciclo 14, princípio 12) — o número de queries não
 * cresce com a quantidade de atividades retornadas.
 */
class CentralProntidaoQuery
{
    private const STATUS_RESTRICAO_ABERTA = [
        StatusRestricao::Aberta->value,
        StatusRestricao::EmTratamento->value,
        StatusRestricao::AguardandoTerceiros->value,
    ];

    private const STATUS_SUPRIMENTO_ALERTA = [
        StatusItemSuprimento::EmRisco,
        StatusItemSuprimento::Atrasado,
    ];

    /**
     * Filtros de apresentação (Ciclo 15, Etapa B.2) — todos opcionais e
     * aditivos, aplicados como `WHERE` simples na query base em
     * `carregarAtividades()`. Nenhum deles participa da decisão de
     * `pronta`: reduzem apenas QUAIS atividades entram no conjunto
     * avaliado, nunca COMO uma atividade já incluída é classificada — a
     * fonte canônica (`scopeProntas()`) continua rodando exatamente do
     * mesmo jeito sobre o subconjunto já filtrado (Ciclo 14, princípio 2).
     *
     * @return Collection<int, AtividadeProntidaoView>
     */
    public function paraObra(
        Work $obra,
        ?CarbonInterface $horizonteAte = null,
        ?string $pacoteTrabalhoId = null,
        ?string $disciplinaId = null,
        ?string $frenteTrabalhoId = null,
        ?string $responsavelId = null,
        ?string $busca = null,
    ): Collection {
        $atividades = $this->carregarAtividades(
            $obra,
            $horizonteAte,
            $pacoteTrabalhoId,
            $disciplinaId,
            $frenteTrabalhoId,
            $responsavelId,
            $busca,
        );

        if ($atividades->isEmpty()) {
            return collect();
        }

        $atividadeIds = $atividades->pluck('id');

        // Fonte canônica de prontidão — Atividade::scopeProntas(), 1 query
        // em lote sobre o conjunto já filtrado. Nunca reimplementada aqui
        // (Ciclo 14, princípio 2).
        $idsProntas = Atividade::query()
            ->where('obra_id', $obra->id)
            ->whereIn('id', $atividadeIds)
            ->prontas()
            ->pluck('id')
            ->all();

        $checklistTotalObra = ItemProntidao::where('obra_id', $obra->id)->count();
        $itensChecklistObra = ItemProntidao::where('obra_id', $obra->id)->get(['id', 'nome']);

        $conclusoesPorAtividade = AtividadeItemProntidao::whereIn('atividade_id', $atividadeIds)
            ->where('concluido', true)
            ->get(['atividade_id', 'item_prontidao_id'])
            ->groupBy('atividade_id');

        $planoAcoesPorUid = $this->indexarPlanoAcoesPorUid($obra);

        return $atividades
            ->map(fn (Atividade $atividade) => $this->montarView(
                $atividade,
                in_array($atividade->id, $idsProntas, true),
                $checklistTotalObra,
                $itensChecklistObra,
                $conclusoesPorAtividade->get($atividade->id, collect()),
                $planoAcoesPorUid[$atividade->external_uid] ?? collect(),
            ))
            ->values();
    }

    /**
     * Query base + eager-load em lote. `fora_do_cronograma = false` (Ciclo
     * 14, princípio de arquitetura já validado no Ciclo 12/13) e filtro de
     * horizonte via WHERE SQL direto (nunca em memória — diferente do
     * Lookahead, que filtra em PHP; decisão deliberada do Ciclo 14,
     * seção G, sem alterar o Lookahead).
     *
     * `itensSuprimento.documentosEngenharia` reaproveita o eager-load
     * `latestRevisao.statusDocumento` já embutido na PRÓPRIA definição de
     * `ItemSuprimento::documentosEngenharia()` — não duplicado aqui.
     * `itensSuprimento.atividades` é necessário pra `ItemSuprimento::
     * necessidade()` (chamado em montarView()) não disparar lazy loading
     * (Model::preventLazyLoading() está ativo fora de produção).
     *
     * **Etapa 21.7.CORREÇÃO (Achado C)**: o select limitado precisa
     * incluir TODA coluna que `ItemSuprimento::necessidade()` lê —
     * `inicio_planejado` E `fora_do_cronograma` (usada por
     * `reject(fn($a) => $a->fora_do_cronograma)` pra excluir atividade
     * arquivada do cálculo). Faltando `fora_do_cronograma` no select, o
     * atributo chega como `null` (falsy) e o `reject()` nunca rejeita
     * nada — uma atividade arquivada mais cedo passava a antecipar
     * incorretamente a necessidade do Pacote. `id` continua presente
     * (chave da relação BelongsToMany, sem ele a hidratação da pivot
     * quebra).
     */
    private function carregarAtividades(
        Work $obra,
        ?CarbonInterface $horizonteAte,
        ?string $pacoteTrabalhoId = null,
        ?string $disciplinaId = null,
        ?string $frenteTrabalhoId = null,
        ?string $responsavelId = null,
        ?string $busca = null,
    ): Collection {
        $query = Atividade::query()
            ->where('obra_id', $obra->id)
            ->where('fora_do_cronograma', false);

        if ($horizonteAte !== null) {
            $query->where('inicio_planejado', '<=', $horizonteAte);
        }

        $query
            ->when($pacoteTrabalhoId, fn ($q) => $q->where('pacote_trabalho_id', $pacoteTrabalhoId))
            ->when($disciplinaId, fn ($q) => $q->where('disciplina_id', $disciplinaId))
            ->when($frenteTrabalhoId, fn ($q) => $q->where('frente_trabalho_id', $frenteTrabalhoId))
            ->when($responsavelId, fn ($q) => $q->where('responsavel_id', $responsavelId))
            ->when($busca, fn ($q) => $q->where(function ($sub) use ($busca) {
                $sub->where('nome', 'like', "%{$busca}%")
                    ->orWhere('codigo_cronograma', 'like', "%{$busca}%");
            }));

        return $query
            ->with([
                'restricoes' => fn ($q) => $q->whereIn('status', self::STATUS_RESTRICAO_ABERTA)
                    ->with('responsavel:id,first_name,last_name'),
                'pacoteTrabalho:id,nome',
                'disciplina:id,nome',
                'frenteTrabalho:id,nome',
                'responsavel:id,first_name,last_name',
                'itensSuprimento.atividades:id,inicio_planejado,fora_do_cronograma',
                'itensSuprimento.documentosEngenharia',
                // Ciclo 18, Etapa 18.4 — vínculo DIRETO Atividade<->Documento
                // (Ciclo 18.1, pivô documento_engenharia_atividades), escopado
                // só a esta Central (nunca eager-loaded globalmente em
                // Atividade — Lookahead/importador não tocados). SoftDeletes
                // de DocumentoEngenharia já exclui documento soft-deletado
                // automaticamente (global scope da relação), sem código extra.
                'documentosEngenharia.latestRevisao.ultimaLiberacao',
                'documentosEngenharia.latestRevisao.statusDocumento',
            ])
            ->get();
    }

    /**
     * Reverse lookup de PlanoAcao por `external_uid` — SEM query por
     * atividade (Ciclo 14, princípio 12). 1 query pra TODAS as ações
     * Abertas da obra, explodindo `uids_referencia` em memória — mesmo
     * espírito de `⚡plano-acao.blade.php::foraDoCronogramaPorUid()`
     * (Ciclo 11). Escopado por `obra_id` ANTES de indexar por uid — nunca
     * um lookup global, já que `external_uid` só é único dentro da obra
     * (Ciclo 14, seção L).
     *
     * @return array<string, Collection<int, PlanoAcao>>
     */
    private function indexarPlanoAcoesPorUid(Work $obra): array
    {
        $acoesAbertas = PlanoAcao::where('obra_id', $obra->id)
            ->where('status', StatusPlanoAcao::Aberta->value)
            ->with(['importacaoOrigem.healthCheck', 'ultimaReconciliacao'])
            ->get();

        $indice = [];
        foreach ($acoesAbertas as $acao) {
            foreach ($acao->uids_referencia ?? [] as $uid) {
                $indice[$uid] ??= collect();
                $indice[$uid]->push($acao);
            }
        }

        return $indice;
    }

    private function montarView(
        Atividade $atividade,
        bool $pronta,
        int $checklistTotalObra,
        Collection $itensChecklistObra,
        Collection $conclusoesDaAtividade,
        Collection $planoAcoesDaAtividade,
    ): AtividadeProntidaoView {
        [$restricoesBloqueantes, $restricoesNaoBloqueantes] = $this->separarRestricoes($atividade->restricoes);

        $idsItensConcluidos = $conclusoesDaAtividade->pluck('item_prontidao_id')->all();
        $checklistConcluido = count($idsItensConcluidos);
        $checklistPendentes = $checklistTotalObra > 0
            ? $itensChecklistObra
                ->reject(fn (ItemProntidao $item) => in_array($item->id, $idsItensConcluidos, true))
                ->pluck('nome')
                ->values()
                ->all()
            : [];

        // Deduplicação DEFENSIVA por id — nunca confiar que $planoAcoesDaAtividade
        // já chega sem repetição. Causa conhecida: uids_referencia com valor
        // repetido dentro da MESMA PlanoAcao faz indexarPlanoAcoesPorUid()
        // empurrar a mesma instância mais de uma vez pro mesmo uid (Ciclo 15,
        // auditoria pós-B.1) — mas a checagem aqui protege contra QUALQUER
        // causa futura de duplicidade no índice, não só essa. Duas PlanoAcões
        // DISTINTAS que referenciam o mesmo external_uid continuam intactas
        // (unique() por id nunca colapsa objetos diferentes).
        $planoAcoesResumo = $planoAcoesDaAtividade
            ->unique('id')
            ->map(fn (PlanoAcao $acao) => new PlanoAcaoResumo(
                id: $acao->id,
                titulo: $acao->titulo,
                regraId: $acao->regra_id,
                severidade: $acao->severidadeDaRegra(),
                resultadoUltimaReconciliacao: $acao->ultimaReconciliacao?->resultado,
            ))
            ->values()
            ->all();

        $suprimentosAlerta = $atividade->itensSuprimento
            ->filter(fn (ItemSuprimento $item) => in_array($item->status, self::STATUS_SUPRIMENTO_ALERTA, true))
            ->map(fn (ItemSuprimento $item) => new SuprimentoAlerta(
                itemId: $item->id,
                nome: $item->nome,
                status: $item->status,
                necessidade: $item->necessidade(),
            ))
            ->values()
            ->all();

        $engenhariaAlerta = $atividade->itensSuprimento
            ->flatMap(fn (ItemSuprimento $item) => $item->documentosEngenharia)
            ->unique('id')
            ->filter(fn ($documento) => $documento->estaAtrasado() || ! $documento->estaEmitido())
            ->map(fn ($documento) => new EngenhariaAlerta(
                documentoId: $documento->id,
                codigo: $documento->codigo,
                atrasado: $documento->estaAtrasado(),
                emitido: $documento->estaEmitido(),
            ))
            ->values()
            ->all();

        // Ciclo 18, Etapa 18.4 — documentos vinculados DIRETAMENTE (Ciclo
        // 18.1) e não liberados para construção. Fonte canônica ÚNICA:
        // DocumentoEngenharia::estaLiberadoParaConstrucao()/motivoLiberacao()
        // — nunca status/texto/data inferidos aqui (regra explícita da 18.4).
        $documentosBloqueantes = $atividade->documentosEngenharia
            // Defesa em profundidade (Ciclo 18.4): o pivô já é validado
            // mesma-obra no momento da criação (vincularAtividade() em
            // ⚡documentos-engenharia.blade.php), mas nunca confiar só nisso
            // pra dado legado/corrompido — um Documento de outra obra
            // (mesmo tenant) nunca pode bloquear a Atividade.
            ->filter(fn ($documento) => $documento->obra_id === $atividade->obra_id)
            ->reject(fn ($documento) => $documento->estaLiberadoParaConstrucao())
            ->map(fn ($documento) => new DocumentoEngenhariaBloqueio(
                documentoId: $documento->id,
                codigo: $documento->codigo,
                descricao: $documento->descricao,
                revisaoVigente: $documento->revisaoVigente()?->revisao,
                statusDocumental: $documento->statusAtual()?->nome,
                motivo: $documento->motivoLiberacao(),
            ))
            ->values()
            ->all();

        $concluida = $atividade->concluido_em !== null;

        // Ciclo 18, Etapa 18.4.CORREÇÃO — `$pronta` (parâmetro, sempre vindo
        // de `Atividade::scopeProntas()`) JÁ considera GED bloqueante desde
        // esta correção — o ramo especial que a 18.4 adicionava aqui
        // (`$documentosBloqueantes !== [] => NaoPronta`) virou redundante e
        // foi removido: `! $pronta` já cobre GED, restrição bloqueante e
        // checklist pendente com a MESMA regra usada por Plano Semanal/
        // Lookahead/`estaPronta()`. `documentosBloqueantes` continua sendo
        // calculado e exposto no DTO (Concluida também, ver `$concluida`
        // abaixo) — é usado pra EXPLICAR o motivo na Lista/detalhe/export,
        // nunca mais pra decidir `statusOperacional` por conta própria.
        $statusOperacional = match (true) {
            $concluida => StatusOperacionalProntidao::Concluida,
            ! $pronta => StatusOperacionalProntidao::NaoPronta,
            $this->temAlertaContextual($restricoesNaoBloqueantes, $planoAcoesResumo, $suprimentosAlerta, $engenhariaAlerta)
                => StatusOperacionalProntidao::Atencao,
            default => StatusOperacionalProntidao::Pronta,
        };

        return new AtividadeProntidaoView(
            atividadeId: $atividade->id,
            externalUid: $atividade->external_uid,
            codigoCronograma: $atividade->codigo_cronograma,
            nome: $atividade->nome,
            inicioPlanejado: $atividade->inicio_planejado,
            pacoteNome: $atividade->pacoteTrabalho?->nome,
            disciplinaNome: $atividade->disciplina?->nome,
            frenteNome: $atividade->frenteTrabalho?->nome,
            responsavelNome: $this->nomeResponsavelAtividade($atividade),
            statusOperacional: $statusOperacional,
            pronta: $pronta,
            restricoesBloqueantes: $restricoesBloqueantes,
            restricoesNaoBloqueantes: $restricoesNaoBloqueantes,
            checklistTotal: $checklistTotalObra,
            checklistConcluido: $checklistConcluido,
            checklistPendentes: $checklistPendentes,
            planoAcoesAbertas: $planoAcoesResumo,
            suprimentos: $suprimentosAlerta,
            engenharia: $engenhariaAlerta,
            documentosBloqueantes: $documentosBloqueantes,
            resumoMotivos: $this->montarResumoMotivos(
                $statusOperacional,
                $restricoesBloqueantes,
                $checklistTotalObra,
                $checklistConcluido,
                $restricoesNaoBloqueantes,
                $planoAcoesResumo,
                $suprimentosAlerta,
                $engenhariaAlerta,
                $documentosBloqueantes,
            ),
        );
    }

    /**
     * @return array{0: RestricaoResumo[], 1: RestricaoResumo[]}
     */
    private function separarRestricoes(Collection $restricoes): array
    {
        $bloqueantes = [];
        $naoBloqueantes = [];

        foreach ($restricoes as $restricao) {
            $resumo = new RestricaoResumo(
                id: $restricao->id,
                descricao: $restricao->descricao,
                prazoLimite: $restricao->prazo_limite,
                responsavel: $this->nomeResponsavel($restricao),
                origem: $this->origemDaRestricao($restricao),
            );

            if ($restricao->bloqueante) {
                $bloqueantes[] = $resumo;
            } else {
                $naoBloqueantes[] = $resumo;
            }
        }

        return [$bloqueantes, $naoBloqueantes];
    }

    private function nomeResponsavel(Restricao $restricao): ?string
    {
        if ($restricao->responsavel) {
            return trim($restricao->responsavel->first_name . ' ' . $restricao->responsavel->last_name);
        }

        return $restricao->responsavel_externo;
    }

    /**
     * Nome do responsável DA ATIVIDADE (Ciclo 15, Etapa B.4) — diferente de
     * `nomeResponsavel()` acima (que resolve o responsável de uma
     * Restricao, com fallback pra texto livre `responsavel_externo`).
     * `Atividade::responsavel()` é sempre um usuário interno cadastrado
     * (nunca texto livre), então não há fallback equivalente aqui. `null`
     * quando a atividade não tem responsável atribuído.
     */
    private function nomeResponsavelAtividade(Atividade $atividade): ?string
    {
        if (! $atividade->responsavel) {
            return null;
        }

        return trim($atividade->responsavel->first_name . ' ' . $atividade->responsavel->last_name);
    }

    /**
     * Origem DERIVADA a partir dos 2 campos já existentes — nunca um
     * campo/coluna nova (Ciclo 15, seção "Restrições" do prompt). Os dois
     * campos são mutuamente exclusivos por construção em todo o código de
     * produção existente (SincronizarRestricaoSuprimento nunca seta
     * origem_plano_acao_id, PlanoAcao::transformarEmRestricoes() nunca
     * seta origem_suprimento_item_id) — checagem sequencial simples.
     */
    private function origemDaRestricao(Restricao $restricao): OrigemRestricaoProntidao
    {
        if ($restricao->origem_suprimento_item_id !== null) {
            return OrigemRestricaoProntidao::Suprimento;
        }

        if ($restricao->origem_plano_acao_id !== null) {
            return OrigemRestricaoProntidao::PlanoAcao;
        }

        return OrigemRestricaoProntidao::Manual;
    }

    /**
     * @param  RestricaoResumo[]  $restricoesNaoBloqueantes
     * @param  PlanoAcaoResumo[]  $planoAcoes
     * @param  SuprimentoAlerta[]  $suprimentos
     * @param  EngenhariaAlerta[]  $engenharia
     */
    private function temAlertaContextual(array $restricoesNaoBloqueantes, array $planoAcoes, array $suprimentos, array $engenharia): bool
    {
        return $restricoesNaoBloqueantes !== [] || $planoAcoes !== [] || $suprimentos !== [] || $engenharia !== [];
    }

    /**
     * @param  RestricaoResumo[]  $restricoesBloqueantes
     * @param  RestricaoResumo[]  $restricoesNaoBloqueantes
     * @param  PlanoAcaoResumo[]  $planoAcoes
     * @param  SuprimentoAlerta[]  $suprimentos
     * @param  EngenhariaAlerta[]  $engenharia
     * @param  DocumentoEngenhariaBloqueio[]  $documentosBloqueantes
     * @return string[]
     */
    private function montarResumoMotivos(
        StatusOperacionalProntidao $status,
        array $restricoesBloqueantes,
        int $checklistTotal,
        int $checklistConcluido,
        array $restricoesNaoBloqueantes,
        array $planoAcoes,
        array $suprimentos,
        array $engenharia,
        array $documentosBloqueantes,
    ): array {
        if ($status === StatusOperacionalProntidao::Concluida || $status === StatusOperacionalProntidao::Pronta) {
            return [];
        }

        $motivos = [];

        if ($restricoesBloqueantes !== []) {
            $motivos[] = 'Restrição bloqueante (' . count($restricoesBloqueantes) . ')';
        }

        if ($documentosBloqueantes !== []) {
            $motivos[] = 'Documento de engenharia não liberado (' . count($documentosBloqueantes) . ')';
        }

        if ($checklistTotal > 0 && $checklistConcluido < $checklistTotal) {
            $motivos[] = "Checklist pendente ({$checklistConcluido}/{$checklistTotal})";
        }

        if ($restricoesNaoBloqueantes !== []) {
            $motivos[] = 'Restrição não-bloqueante (' . count($restricoesNaoBloqueantes) . ')';
        }

        if ($planoAcoes !== []) {
            $motivos[] = 'Plano de Ação aberto (' . count($planoAcoes) . ')';
        }

        if ($suprimentos !== []) {
            $motivos[] = 'Suprimento em risco (' . count($suprimentos) . ')';
        }

        if ($engenharia !== []) {
            $motivos[] = 'Engenharia atrasada (' . count($engenharia) . ')';
        }

        return $motivos;
    }
}
