<?php

namespace App\Support\LicoesAprendidas;

use App\Enums\StatusRestricao;
use App\Enums\TipoCandidatoLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoMovimentacaoEstoque;
use App\Models\AplicacaoMaterialEstoque;
use App\Models\CandidatoLicaoAprendida;
use App\Models\FrenteTrabalho;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCompra;
use App\Models\Restricao;
use App\Models\Work;
use App\Support\Estoque\DesviosAplicacao;
use App\Support\Estoque\PoliticaConciliacaoAplicacao;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Ciclo 23, Etapa 23.3 — único ponto que gera candidatos a lição
 * aprendida. Recebe SEMPRE uma obra explícita (Seção 19/37 — nunca
 * varre o tenant inteiro, nunca é chamado de middleware/layout/navbar).
 *
 * Um candidato NUNCA é criado automaticamente como `LicaoAprendida` —
 * este serviço só INSERE linhas em `licao_aprendida_candidatos`
 * (Pendente), nunca cria/altera nada em Restricao/PedidoCompra/
 * AplicacaoMaterialEstoque/LicaoAprendida.
 *
 * Idempotência (Seção 12): pré-carrega as `chave_logica` já existentes
 * da obra numa única query, e só tenta `create()` pra chaves realmente
 * novas — gerar 1x/2x/10x produz a mesma quantidade de linhas. Corrida
 * concorrente (Seção 13): a garantia REAL é o índice único
 * `UNIQUE(tenant_id, obra_id, chave_logica)` — o pré-carregamento é só
 * uma otimização, `criarSeNovo()` sempre captura `QueryException` 1062
 * como sinal de idempotência, nunca um erro (mesmo idioma já usado em
 * `AlertaDistribuicaoGrd`/`PlanoAcao::transformarEmRestricoes()`).
 */
class GerarCandidatosLicoesObra
{
    /** Regra A (Seção 2 da decisão aprovada) — duração mínima em dias, evidência factual, não classificação de gravidade. */
    private const LIMIAR_DIAS_RESTRICAO = 1;

    /**
     * Regra C (Etapa 23.3.CORREÇÃO) — mesma tolerância de ponto flutuante
     * já usada por `PoliticaConciliacaoAplicacao::saidaEstaFechada()`
     * (0.0005, documentada lá). Duplicada aqui só como literal de
     * comparação — nunca uma segunda regra: o valor somado
     * (`totalAplicadoEmLote()`) e o critério (`>=`) são exatamente os
     * mesmos da fonte autoritativa, só evitando 1 query por Saída dentro
     * do loop (o método público de instância já é O(1) por Saída, mas
     * aqui já temos os dados batch-carregados).
     */
    private const TOLERANCIA_CONCILIACAO = 0.0005;

    public function execute(Work $obra): int
    {
        $chavesExistentes = CandidatoLicaoAprendida::where('obra_id', $obra->id)
            ->pluck('chave_logica')
            ->flip();

        $criados = 0;
        $criados += $this->gerarRestricoesRelevantes($obra, $chavesExistentes);
        $criados += $this->gerarPedidosAtrasoFinal($obra, $chavesExistentes);
        $criados += $this->gerarAplicacoesDesvio($obra, $chavesExistentes);

        return $criados;
    }

    /**
     * Regra A — Restrição bloqueante resolvida com duração >= 1 dia
     * (`resolvida_em - aberta_em`). Ambas as datas são colunas diretas,
     * sempre presentes quando `status = Resolvida` — snapshot congela
     * exatamente as duas datas + a duração usada pra satisfazer a regra
     * (Seção 2 do pedido aprovado).
     */
    private function gerarRestricoesRelevantes(Work $obra, Collection $chavesExistentes): int
    {
        $restricoes = Restricao::query()
            ->where('bloqueante', true)
            ->where('status', StatusRestricao::Resolvida->value)
            ->whereNotNull('aberta_em')
            ->whereNotNull('resolvida_em')
            ->whereHas('atividade', fn ($q) => $q->where('obra_id', $obra->id))
            ->with('atividade')
            ->get();

        $criados = 0;

        foreach ($restricoes as $restricao) {
            $dias = (int) $restricao->aberta_em->diffInDays($restricao->resolvida_em);
            if ($dias < self::LIMIAR_DIAS_RESTRICAO) {
                continue;
            }

            $chave = "restricao_relevante:{$restricao->id}";
            if ($chavesExistentes->has($chave)) {
                continue;
            }

            $criado = $this->criarSeNovo(
                obra: $obra,
                tipo: TipoCandidatoLicaoAprendida::RestricaoRelevante,
                chaveLogica: $chave,
                entidadeTipo: TipoEntidadeVinculoLicao::Restricao,
                entidadeId: $restricao->id,
                titulo: 'Restrição bloqueante resolvida após '.$dias.' dia'.($dias > 1 ? 's' : ''),
                descricao: "Esta restrição bloqueante permaneceu aberta por {$dias} dia".($dias > 1 ? 's' : '').' antes de ser resolvida.',
                dadosSnapshot: [
                    'aberta_em' => $restricao->aberta_em->toDateTimeString(),
                    'resolvida_em' => $restricao->resolvida_em->toDateTimeString(),
                    'dias' => $dias,
                    'descricao_restricao' => (string) $restricao->descricao,
                ],
            );

            if ($criado) {
                $chavesExistentes->put($chave, true);
                $criados++;
            }
        }

        return $criados;
    }

    /**
     * Regra B — Pedido de Compra Emitido, concluído (`Completa`), com
     * `diasAtrasoFinal() !== null` (que já implica >= 1 dia, ver
     * `PedidoCompra::diasAtrasoFinal()`). Usa exclusivamente
     * `data_prevista_entrega` e `dataEntregaCompleta()` — nunca
     * `necessidade()`/`diasAtrasoAtual()`.
     */
    private function gerarPedidosAtrasoFinal(Work $obra, Collection $chavesExistentes): int
    {
        $pedidos = PedidoCompra::query()
            ->where('obra_id', $obra->id)
            ->where('status', \App\Enums\StatusPedidoCompra::Emitido->value)
            ->with(['itens.recebimentos', 'fornecedor'])
            ->get();

        $criados = 0;

        foreach ($pedidos as $pedido) {
            $dias = $pedido->diasAtrasoFinal();
            if ($dias === null) {
                continue;
            }

            $chave = "pedido_atraso_final:{$pedido->id}";
            if ($chavesExistentes->has($chave)) {
                continue;
            }

            $conclusao = $pedido->dataEntregaCompleta();
            $fornecedorNome = $pedido->fornecedor_nome_snapshot ?: $pedido->fornecedor?->nome ?: 'Fornecedor';

            $criado = $this->criarSeNovo(
                obra: $obra,
                tipo: TipoCandidatoLicaoAprendida::PedidoAtrasoFinal,
                chaveLogica: $chave,
                entidadeTipo: TipoEntidadeVinculoLicao::PedidoCompra,
                entidadeId: $pedido->id,
                titulo: "Pedido #{$pedido->numero}: recebimento concluído com {$dias} dia".($dias > 1 ? 's' : '').' de atraso',
                descricao: "O recebimento completo ocorreu {$dias} dia".($dias > 1 ? 's' : '').' após a data prevista de entrega.',
                dadosSnapshot: [
                    'numero_pedido' => $pedido->numero,
                    'fornecedor_nome' => $fornecedorNome,
                    'data_prevista_entrega' => $pedido->data_prevista_entrega?->toDateString(),
                    'data_entrega_completa' => $conclusao?->toDateString(),
                    'dias_atraso' => $dias,
                ],
            );

            if ($criado) {
                $chavesExistentes->put($chave, true);
                $criados++;
            }
        }

        return $criados;
    }

    /**
     * Regra C — Aplicação de material numa Frente diferente da Frente
     * planejada da Reserva de origem. Reutiliza EXCLUSIVAMENTE
     * `DesviosAplicacao::porSaidasEmLote()` (semântica autoritativa já
     * existente, Ciclo 20.4) pra decidir "tem frente planejada" e "qual
     * é a frente planejada" — nunca uma segunda definição de desvio; só
     * itera as linhas de `AplicacaoMaterialEstoque` já carregadas em
     * lote pra identificar QUAIS linhas individuais divergem (mesma
     * condição que a classe já usa internamente pra agregar).
     */
    private function gerarAplicacoesDesvio(Work $obra, Collection $chavesExistentes): int
    {
        $saidas = MovimentacaoEstoque::query()
            ->where('obra_id', $obra->id)
            ->where('tipo', TipoMovimentacaoEstoque::Saida->value)
            ->whereNotNull('reserva_estoque_id')
            ->get();

        if ($saidas->isEmpty()) {
            return 0;
        }

        $desviosPorSaida = DesviosAplicacao::porSaidasEmLote($saidas);
        if ($desviosPorSaida->isEmpty()) {
            return 0;
        }

        $saidaIds = $saidas->pluck('id')->all();
        $aplicacoesPorSaida = AplicacaoMaterialEstoque::whereIn('movimentacao_estoque_id', $saidaIds)
            ->get()
            ->groupBy('movimentacao_estoque_id');
        $totalAplicadoPorSaida = PoliticaConciliacaoAplicacao::totalAplicadoEmLote($saidaIds);

        $frenteIds = $saidas->pluck('id')
            ->map(fn ($id) => $desviosPorSaida->get($id)['frente_planejada_id'] ?? null)
            ->merge($aplicacoesPorSaida->flatten()->pluck('frente_trabalho_id'))
            ->filter()
            ->unique()
            ->values();
        $frentesPorId = FrenteTrabalho::withTrashed()->whereIn('id', $frenteIds)->get()->keyBy('id');

        $criados = 0;

        foreach ($saidas as $saida) {
            $desvio = $desviosPorSaida->get($saida->id);
            if (! $desvio || ! $desvio['tem_frente_planejada']) {
                continue;
            }

            // Auditoria de historicidade (23.3.CORREÇÃO): a Destinação
            // Planejada/Reserva já são 100% imutáveis desde a criação
            // (Observers dedicados, Ciclo 20.2), mas
            // AplicacaoMaterialEstoque.frente_trabalho_id/quantidade
            // continuam editáveis (AtualizarAplicacaoMaterialEstoque)
            // ENQUANTO a Saída não estiver "fechada"
            // (PoliticaConciliacaoAplicacao::saidaEstaFechada() —
            // AplicacaoMaterialEstoqueObserver só bloqueia TODA alteração
            // depois disso). Gerar um candidato antes desse ponto
            // descreveria um fato que ainda pode mudar sem nenhuma
            // reabertura de episódio — nunca a fotografia imutável que a
            // Regra C promete. Só considerar Saídas já fechadas torna a
            // cadeia inteira (destinação → reserva → aplicação)
            // provavelmente estável no momento da geração.
            $totalAplicado = $totalAplicadoPorSaida->get($saida->id, 0.0);
            if ($totalAplicado < (float) $saida->quantidade - self::TOLERANCIA_CONCILIACAO) {
                continue;
            }

            $aplicacoes = $aplicacoesPorSaida->get($saida->id, collect());

            foreach ($aplicacoes as $aplicacao) {
                if ($aplicacao->frente_trabalho_id === $desvio['frente_planejada_id']) {
                    continue;
                }

                $chave = "aplicacao_desvio_destinacao:{$aplicacao->id}";
                if ($chavesExistentes->has($chave)) {
                    continue;
                }

                $frentePlanejada = $frentesPorId->get($desvio['frente_planejada_id']);
                $frenteAplicada = $frentesPorId->get($aplicacao->frente_trabalho_id);

                $criado = $this->criarSeNovo(
                    obra: $obra,
                    tipo: TipoCandidatoLicaoAprendida::AplicacaoDesvioDestinacao,
                    chaveLogica: $chave,
                    entidadeTipo: TipoEntidadeVinculoLicao::AplicacaoMaterialEstoque,
                    entidadeId: $aplicacao->id,
                    titulo: 'Aplicação de material divergente da Frente planejada',
                    descricao: "Este material foi aplicado na Frente '".($frenteAplicada?->nome ?? '—')."', mas a Reserva de origem estava planejada para a Frente '".($frentePlanejada?->nome ?? '—')."'.",
                    dadosSnapshot: [
                        'aplicado_em' => $aplicacao->aplicado_em?->toDateString(),
                        'quantidade' => (float) $aplicacao->quantidade,
                        'frente_planejada_id' => $desvio['frente_planejada_id'],
                        'frente_planejada_nome' => $frentePlanejada?->nome,
                        'frente_aplicada_id' => $aplicacao->frente_trabalho_id,
                        'frente_aplicada_nome' => $frenteAplicada?->nome,
                    ],
                );

                if ($criado) {
                    $chavesExistentes->put($chave, true);
                    $criados++;
                }
            }
        }

        return $criados;
    }

    private function criarSeNovo(
        Work $obra,
        TipoCandidatoLicaoAprendida $tipo,
        string $chaveLogica,
        TipoEntidadeVinculoLicao $entidadeTipo,
        string $entidadeId,
        string $titulo,
        string $descricao,
        array $dadosSnapshot,
    ): bool {
        try {
            CandidatoLicaoAprendida::create([
                'obra_id' => $obra->id,
                'tipo' => $tipo->value,
                'chave_logica' => $chaveLogica,
                'status' => \App\Enums\StatusCandidatoLicaoAprendida::Pendente->value,
                'entidade_tipo' => $entidadeTipo->value,
                'entidade_id' => $entidadeId,
                'titulo' => $titulo,
                'descricao' => $descricao,
                'dados_snapshot' => $dadosSnapshot,
                'gerado_em' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }
    }
}
