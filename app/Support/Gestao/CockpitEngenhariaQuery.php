<?php

namespace App\Support\Gestao;

use App\DTOs\Engenharia\AtividadeProntidaoDocumental;
use App\DTOs\Engenharia\FatoEngenharia;
use App\DTOs\Gestao\Cockpit\CockpitEngenharia;
use App\DTOs\Gestao\Cockpit\CockpitProntidaoEngenhariaHorizonte;
use App\Enums\EstadoProntidaoEngenharia;
use App\Enums\SeveridadeSituacao;
use App\Enums\TipoSituacaoGerencial;
use App\Models\Work;
use App\DTOs\Engenharia\InteligenciaEngenharia;
use App\Support\Engenharia\InteligenciaEngenhariaQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.2 — read model do Cockpit de Engenharia. Compõe,
 * NUNCA reimplementa: `InteligenciaEngenhariaQuery::porObra()` (Ciclo
 * 22.1, fonte de prontidão documental/GRD/industrialização/suprimentos)
 * e `SituacoesGerenciaisQuery::porObra()` (Ciclo 21.2, fonte do fato
 * "documento bloqueante" JÁ existente e do cruzamento com
 * "MaterialCritico" pra "dupla restrição", Seção 21). Nenhum dos dois é
 * alterado; nenhuma fórmula duplicada — só leitura + composição de
 * apresentação (buckets, ordenação, resumo em memória).
 *
 * **Horizonte único mais largo, nunca 3 chamadas** (mesmo princípio já
 * documentado em `CockpitObraQuery`, Ciclo 21.5, Seção 6/7 do pedido
 * 22.2): `InteligenciaEngenhariaQuery::porObra()` é chamada com o maior
 * horizonte solicitado (56 dias, salvo horizonte principal menor
 * explicitamente pedido) UMA VEZ; os buckets de 14/28/56 dias são
 * filtrados EM MEMÓRIA sobre esse mesmo resultado.
 */
class CockpitEngenhariaQuery
{
    private const HORIZONTES_SEMANAS = [2 => 14, 4 => 28, 8 => 56];

    private const MAX_MATRIZ_EXECUTIVA = 50;

    public static function resumo(Work $obra, int $horizontePrincipalDias = 28): CockpitEngenharia
    {
        $referencia = Carbon::today();
        $horizonteMaisLargo = max($horizontePrincipalDias, 56);

        $inteligencia = InteligenciaEngenhariaQuery::porObra($obra, $horizonteMaisLargo, $referencia);
        $situacoesGerenciais = SituacoesGerenciaisQuery::porObra($obra, $horizontePrincipalDias);

        $prontidaoNoHorizontePrincipal = $inteligencia->prontidaoDocumental
            ->filter(fn (AtividadeProntidaoDocumental $a) => $a->diasParaInicio !== null && $a->diasParaInicio <= $horizontePrincipalDias);

        $atividadesComMaterialCritico = $situacoesGerenciais
            ->filter(fn ($s) => $s->tipo === TipoSituacaoGerencial::MaterialCritico)
            ->pluck('entidadeId')
            ->unique()
            ->all();

        $documentoBloqueanteSituacoes = $situacoesGerenciais
            ->filter(fn ($s) => $s->tipo === TipoSituacaoGerencial::DocumentoBloqueante);

        return new CockpitEngenharia(
            obraId: $obra->id,
            horizonteDias: $horizontePrincipalDias,
            resumoExecutivo: self::montarResumoExecutivo($prontidaoNoHorizontePrincipal, $documentoBloqueanteSituacoes, $inteligencia),
            acaoPrioritaria: self::montarAcaoPrioritaria($documentoBloqueanteSituacoes, $inteligencia, $atividadesComMaterialCritico),
            prontidaoPorHorizonte: self::montarProntidaoPorHorizonte($inteligencia->prontidaoDocumental, $referencia),
            matrizAtividades: $prontidaoNoHorizontePrincipal->sortBy('diasParaInicio')->take(self::MAX_MATRIZ_EXECUTIVA)->values(),
            matrizTotalAtividades: $prontidaoNoHorizontePrincipal->count(),
            grdAguardandoAceite: $inteligencia->grdAguardandoAceite,
            copiasObsoletasPendentes: $inteligencia->copiasObsoletasPendentes,
            industrializacaoComMudancaRevisao: $inteligencia->industrializacaoComMudancaRevisao,
            suprimentoBloqueadoPorDocumento: $inteligencia->suprimentoBloqueadoPorDocumento,
            informacaoInsuficiente: $prontidaoNoHorizontePrincipal->filter(
                fn (AtividadeProntidaoDocumental $a) => $a->estado === EstadoProntidaoEngenharia::InformacaoInsuficiente
            )->values(),
            gaps: [
                '"Distribuição física pendente" não existe como estado próprio no domínio — emissão de GRD já É o evento'
                    . ' de entrega (Ciclo 18.5.1); só "aguardando aceite" e "cópia obsoleta pendente de recolhimento" são fatos reais.',
                'Filtro por Disciplina não incluído nesta etapa — a relação `Atividade::documentosEngenharia()` não carrega'
                    . ' disciplina em lote sem custo adicional de eager-load por documento; documentado, não forçado (Seção 23).',
            ],
        );
    }

    /** @return array{atividades_bloqueadas:int, atividades_parciais:int, atividades_informacao_insuficiente:int, documentos_bloqueantes:int, grds_aguardando_aceite:int, copias_obsoletas_pendentes:int, industrializacao_com_mudanca:int, suprimento_bloqueado:int} */
    private static function montarResumoExecutivo(Collection $prontidao, Collection $documentoBloqueanteSituacoes, InteligenciaEngenharia $inteligencia): array
    {
        return [
            'atividades_bloqueadas' => $prontidao->filter(fn ($a) => $a->estado === EstadoProntidaoEngenharia::Bloqueada)->count(),
            'atividades_parciais' => $prontidao->filter(fn ($a) => $a->estado === EstadoProntidaoEngenharia::Parcial)->count(),
            'atividades_informacao_insuficiente' => $prontidao->filter(fn ($a) => $a->estado === EstadoProntidaoEngenharia::InformacaoInsuficiente)->count(),
            'documentos_bloqueantes' => $documentoBloqueanteSituacoes->pluck('entidadeId')->unique()->count(),
            'grds_aguardando_aceite' => $inteligencia->grdAguardandoAceite->count(),
            'copias_obsoletas_pendentes' => $inteligencia->copiasObsoletasPendentes->count(),
            'industrializacao_com_mudanca' => $inteligencia->industrializacaoComMudancaRevisao->count(),
            'suprimento_bloqueado' => $inteligencia->suprimentoBloqueadoPorDocumento->count(),
        ];
    }

    /**
     * Bloco "O que precisa da minha ação?" (Seção 8) — une, SEM
     * recalcular nenhuma delas, as situações `DocumentoBloqueante` já
     * derivadas pelo Ciclo 21.2 com os fatos GRD/Industrialização/
     * Suprimentos da 22.1 — todos já têm a MESMA forma (severidade,
     * diasParaRelevante, descricao, deepLink), nunca uma segunda
     * prioridade inventada. Ordenação: impacto temporal real primeiro
     * (menor diasParaRelevante), depois fatos sem data (GRD/
     * industrialização/suprimentos, que não têm urgência temporal
     * própria) por severidade.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private static function montarAcaoPrioritaria(Collection $documentoBloqueanteSituacoes, InteligenciaEngenharia $inteligencia, array $atividadesComMaterialCritico): Collection
    {
        $linhas = collect();

        foreach ($documentoBloqueanteSituacoes as $s) {
            $atividadeId = $s->contexto['atividade_id'] ?? null;
            $linhas->push([
                'tipo' => 'documento_bloqueante',
                'descricao' => $s->descricao,
                'severidade' => $s->severidade,
                'diasParaRelevante' => $s->diasParaRelevante,
                'deepLink' => $s->deepLink,
                'duplaRestricao' => $atividadeId !== null && in_array($atividadeId, $atividadesComMaterialCritico, true),
            ]);
        }

        /** @var Collection<int, FatoEngenharia> $demaisFatos */
        $demaisFatos = $inteligencia->grdAguardandoAceite
            ->merge($inteligencia->copiasObsoletasPendentes)
            ->merge($inteligencia->industrializacaoComMudancaRevisao)
            ->merge($inteligencia->suprimentoBloqueadoPorDocumento);

        foreach ($demaisFatos as $fato) {
            $linhas->push([
                'tipo' => $fato->tipo,
                'descricao' => $fato->descricao,
                'severidade' => $fato->severidade,
                'diasParaRelevante' => $fato->diasParaRelevante,
                'deepLink' => $fato->deepLink,
                'duplaRestricao' => $fato->atividadeId !== null && in_array($fato->atividadeId, $atividadesComMaterialCritico, true),
            ]);
        }

        return $linhas->sortBy([
            fn ($l) => $l['diasParaRelevante'] ?? PHP_INT_MAX,
            fn ($l) => -$l['severidade']->peso(),
        ])->values();
    }

    /** @return array<int, CockpitProntidaoEngenhariaHorizonte> */
    private static function montarProntidaoPorHorizonte(Collection $prontidaoDocumental, Carbon $referencia): array
    {
        $resultado = [];

        foreach (self::HORIZONTES_SEMANAS as $semanas => $dias) {
            $linhas = $prontidaoDocumental->filter(
                fn (AtividadeProntidaoDocumental $a) => $a->diasParaInicio !== null && $a->diasParaInicio <= $dias
            );

            $total = $linhas->count();
            $liberadas = $linhas->filter(fn ($a) => $a->estado === EstadoProntidaoEngenharia::Liberada)->count();
            $parciais = $linhas->filter(fn ($a) => $a->estado === EstadoProntidaoEngenharia::Parcial)->count();
            $bloqueadas = $linhas->filter(fn ($a) => $a->estado === EstadoProntidaoEngenharia::Bloqueada)->count();
            $infoInsuficiente = $linhas->filter(fn ($a) => $a->estado === EstadoProntidaoEngenharia::InformacaoInsuficiente)->count();

            $avaliaveis = $total - $infoInsuficiente;

            $resultado[$semanas] = new CockpitProntidaoEngenhariaHorizonte(
                horizonteDias: $dias,
                horizonteSemanas: $semanas,
                total: $total,
                liberadas: $liberadas,
                parciais: $parciais,
                bloqueadas: $bloqueadas,
                informacaoInsuficiente: $infoInsuficiente,
                percentualLiberadoAvaliavel: $avaliaveis > 0 ? round(($liberadas / $avaliaveis) * 100, 1) : null,
            );
        }

        return $resultado;
    }
}
