<?php

namespace App\Support\LicoesAprendidas;

use App\DTOs\LicoesAprendidas\SugestaoLicaoContextual;
use App\Enums\MotivoCorrespondenciaLicao;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Models\AlocacaoRequisicaoPacote;
use App\Models\Atividade;
use App\Models\LicaoAprendida;
use App\Models\Material;
use App\Models\Work;
use Illuminate\Support\Collection;

/**
 * Ciclo 23, Etapa 23.4 — fecha o ciclo "conhecimento publicado → contexto
 * operacional futuro → conhecimento apresentado à equipe". Read-model
 * ÚNICO — a UI nunca monta regra de correspondência sozinha.
 *
 * Determinístico, sem IA, sem similaridade textual, sem score arbitrário
 * (Seção 1 do pedido). Só 2 relações corporativas comprovadamente
 * estáveis entre obras (Seção 5 — fresh-read de `TipoEntidadeVinculoLicao::
 * obraDeterministica()`/`Disciplina`/`Material`, ambos os únicos
 * conceitos SEM `obra_id` próprio no domínio):
 * - **MesmoMaterial**: `Material.id` é tenant-wide desde o Ciclo 20.1 —
 *   o mesmo registro é usado por qualquer obra do tenant.
 * - **MesmaDisciplina**: `Disciplina.id` é tenant-wide (CLAUDE.md,
 *   Etapa 2A/2B) — mesma convenção.
 * Fornecedor/DocumentoEngenharia/Atividade são sempre obra-specific
 * (`obraDeterministica() === true`) — NUNCA comparados diretamente
 * entre obras (decisão confirmada com o usuário, Etapa 23.4).
 *
 * Consulta sempre estrutural no banco (`whereHas`/`whereIn`), nunca
 * `LicaoAprendida::all()->filter()` (Seção 39). Só `StatusLicaoAprendida::
 * Publicada`, sempre `obra_origem_id != obraAtual.id` (Seções 13/14/41).
 *
 * Segurança (Seção 23): o DTO retornado (`SugestaoLicaoContextual`)
 * reproduz exatamente o recorte de campos já seguro e em produção desde
 * a 23.1/23.2 no detalhe da biblioteca corporativa — nunca
 * `observacoes_internas`, nunca a entidade operacional original, nunca
 * deep-link operacional. A pré-condição de autorização (`LicaoAprendidaPolicy::
 * viewAny()`) é responsabilidade do CHAMADOR (mesmo padrão de toda
 * Policy no projeto) — este serviço nunca resolve `Auth::user()` sozinho.
 */
class LicoesContextuaisQuery
{
    /**
     * @param  Collection<int, string>  $materialIds
     * @return Collection<string, Collection<int, SugestaoLicaoContextual>> chave = material_id
     */
    public static function porMateriais(Work $obraAtual, Collection $materialIds): Collection
    {
        $materialIds = $materialIds->filter()->unique()->values();
        if ($materialIds->isEmpty()) {
            return collect();
        }

        $licoes = self::buscarLicoesPublicadasDeOutrasObras($obraAtual, $materialIds, collect());
        $materiais = Material::whereIn('id', $materialIds)->get()->keyBy('id');

        return $materialIds->mapWithKeys(function (string $materialId) use ($licoes, $materiais) {
            $sugestoes = $licoes
                ->filter(fn (LicaoAprendida $licao) => in_array($materialId, self::materiaisVinculados($licao), true))
                ->map(fn (LicaoAprendida $licao) => self::montarSugestao($licao, [
                    ['motivo' => MotivoCorrespondenciaLicao::MesmoMaterial, 'contexto' => self::tituloMaterial($materiais->get($materialId))],
                ]))
                ->sort(fn ($a, $b) => $a->chaveOrdenacao() <=> $b->chaveOrdenacao())
                ->values();

            return [$materialId => $sugestoes];
        });
    }

    /** Conveniência de 1 Material — nunca reimplementa a regra, só delega. */
    public static function porMaterial(Work $obraAtual, Material $material): Collection
    {
        return self::porMateriais($obraAtual, collect([$material->id]))->get($material->id, collect());
    }

    /**
     * Batch-safe (Seção 38 — testado com 10/100 atividades, custo fixo).
     * Resolve, pra cada Atividade, os Materiais corporativos que ela
     * toca via a MESMA cadeia determinística já em produção desde o
     * Ciclo 21.1 (`Atividade->itensSuprimento` [N:N real] →
     * `AlocacaoRequisicaoPacote` → `RequisicaoPlanejamentoItem` →
     * `ItemTakeOff.material_id` — nunca WBS/descrição/heurística) e a
     * `disciplina_id` própria da Atividade (coluna direta, tenant-wide).
     *
     * @param  Collection<int, Atividade>  $atividades
     * @return Collection<string, Collection<int, SugestaoLicaoContextual>> chave = atividade_id
     */
    public static function porAtividades(Work $obraAtual, Collection $atividades): Collection
    {
        if ($atividades->isEmpty()) {
            return collect();
        }

        $materialIdsPorAtividade = self::materialIdsPorAtividade($atividades);
        $todosMaterialIds = $materialIdsPorAtividade->flatten()->filter()->unique()->values();
        $disciplinaIds = $atividades->pluck('disciplina_id')->filter()->unique()->values();

        $licoes = self::buscarLicoesPublicadasDeOutrasObras($obraAtual, $todosMaterialIds, $disciplinaIds);
        $materiais = Material::whereIn('id', $todosMaterialIds)->get()->keyBy('id');

        return $atividades->mapWithKeys(function (Atividade $atividade) use ($licoes, $materiais, $materialIdsPorAtividade) {
            $materialIdsDaAtividade = $materialIdsPorAtividade->get($atividade->id, collect());

            $sugestoes = $licoes
                ->map(function (LicaoAprendida $licao) use ($materialIdsDaAtividade, $materiais, $atividade) {
                    $motivos = [];

                    $materiaisVinculados = self::materiaisVinculados($licao);
                    foreach ($materialIdsDaAtividade as $materialId) {
                        if (in_array($materialId, $materiaisVinculados, true)) {
                            $motivos[] = [
                                'motivo' => MotivoCorrespondenciaLicao::MesmoMaterial,
                                'contexto' => self::tituloMaterial($materiais->get($materialId)),
                            ];
                        }
                    }

                    if ($atividade->disciplina_id && $licao->disciplina_id === $atividade->disciplina_id) {
                        $motivos[] = [
                            'motivo' => MotivoCorrespondenciaLicao::MesmaDisciplina,
                            'contexto' => $licao->disciplina?->nome,
                        ];
                    }

                    return $motivos === [] ? null : self::montarSugestao($licao, $motivos);
                })
                ->filter()
                ->sort(fn ($a, $b) => $a->chaveOrdenacao() <=> $b->chaveOrdenacao())
                ->values();

            return [$atividade->id => $sugestoes];
        });
    }

    /** Conveniência de 1 Atividade — mesmo padrão já usado pela Curva S (Ciclo 21), nunca lista inteira. */
    public static function porAtividade(Work $obraAtual, Atividade $atividade): Collection
    {
        return self::porAtividades($obraAtual, collect([$atividade]))->get($atividade->id, collect());
    }

    /**
     * Única query real de `LicaoAprendida` deste serviço — sempre
     * estrutural (`whereHas`/`whereIn`), sempre `Publicada`, sempre
     * excluindo a obra atual (Seções 13/14/39/41).
     *
     * @param  Collection<int, string>  $materialIds
     * @param  Collection<int, string>  $disciplinaIds
     * @return Collection<int, LicaoAprendida>
     */
    private static function buscarLicoesPublicadasDeOutrasObras(Work $obraAtual, Collection $materialIds, Collection $disciplinaIds): Collection
    {
        if ($materialIds->isEmpty() && $disciplinaIds->isEmpty()) {
            return collect();
        }

        return LicaoAprendida::query()
            ->where('status', StatusLicaoAprendida::Publicada->value)
            ->where('obra_origem_id', '!=', $obraAtual->id)
            ->where(function ($query) use ($materialIds, $disciplinaIds) {
                $query->when(
                    $materialIds->isNotEmpty(),
                    fn ($q) => $q->orWhereHas('vinculos', fn ($vq) => $vq
                        ->where('entidade_tipo', TipoEntidadeVinculoLicao::Material->value)
                        ->whereIn('entidade_id', $materialIds->all()))
                );
                $query->when(
                    $disciplinaIds->isNotEmpty(),
                    fn ($q) => $q->orWhereIn('disciplina_id', $disciplinaIds->all())
                );
            })
            ->with([
                'obraOrigem:id,name',
                'disciplina:id,nome',
                'vinculos' => fn ($q) => $q
                    ->where('entidade_tipo', TipoEntidadeVinculoLicao::Material->value)
                    ->when($materialIds->isNotEmpty(), fn ($q2) => $q2->whereIn('entidade_id', $materialIds->all())),
            ])
            ->orderByDesc('publicado_em')
            ->get();
    }

    /** @return array<int, string> */
    private static function materiaisVinculados(LicaoAprendida $licao): array
    {
        return $licao->vinculos->pluck('entidade_id')->all();
    }

    private static function montarSugestao(LicaoAprendida $licao, array $motivos): SugestaoLicaoContextual
    {
        return new SugestaoLicaoContextual(
            licaoId: $licao->id,
            titulo: $licao->titulo,
            situacaoObservada: $licao->situacao_observada,
            recomendacaoFutura: $licao->recomendacao_futura,
            tipo: $licao->tipo,
            criticidade: $licao->criticidade,
            areaFuncional: $licao->area_funcional,
            disciplinaNome: $licao->disciplina?->nome,
            obraOrigemNome: $licao->obraOrigem?->name,
            publicadoEm: $licao->publicado_em,
            motivos: $motivos,
        );
    }

    private static function tituloMaterial(?Material $material): ?string
    {
        return $material ? VinculoLicaoResolver::tituloParaSnapshot(TipoEntidadeVinculoLicao::Material, $material) : null;
    }

    /**
     * Mesma cadeia determinística de `App\Support\Gestao\
     * CoberturaMaterialAtividadeQuery` (Ciclo 21.1) — reimplementada aqui
     * de forma isolada e mais enxuta (só o CONJUNTO de material_ids por
     * atividade, sem cobertura/déficit) pra nunca acoplar 23.4 a uma
     * classe gerencial já crítica e testada por outro domínio. Sempre em
     * lote (`whereIn` pelos IDs recebidos — nunca depende do estado de
     * eager-load já carregado na Collection do chamador, que pode ser uma
     * `Illuminate\Support\Collection` comum, sem `loadMissing()`): no
     * máximo 3 queries fixas, nunca 1 por Atividade.
     *
     * @param  Collection<int, Atividade>  $atividades
     * @return Collection<string, Collection<int, string>> chave = atividade_id, valor = material_ids únicos
     */
    private static function materialIdsPorAtividade(Collection $atividades): Collection
    {
        $pacoteIdsPorAtividade = Atividade::query()
            ->whereIn('id', $atividades->pluck('id'))
            ->with('itensSuprimento:id')
            ->get()
            ->mapWithKeys(fn (Atividade $a) => [$a->id => $a->itensSuprimento->pluck('id')]);

        $pacoteIds = $pacoteIdsPorAtividade->flatten()->unique()->values();
        if ($pacoteIds->isEmpty()) {
            return $atividades->mapWithKeys(fn (Atividade $a) => [$a->id => collect()]);
        }

        $materiaisPorPacote = AlocacaoRequisicaoPacote::query()
            ->whereIn('item_suprimento_id', $pacoteIds)
            ->with('requisicaoItem.itemTakeOff:id,material_id')
            ->get()
            ->map(fn (AlocacaoRequisicaoPacote $a) => [
                'item_suprimento_id' => $a->item_suprimento_id,
                'material_id' => $a->requisicaoItem?->itemTakeOff?->material_id,
            ])
            ->filter(fn (array $p) => $p['material_id'] !== null)
            ->groupBy('item_suprimento_id')
            ->map(fn (Collection $grupo) => $grupo->pluck('material_id')->unique()->values());

        return $atividades->mapWithKeys(function (Atividade $atividade) use ($pacoteIdsPorAtividade, $materiaisPorPacote) {
            $ids = $pacoteIdsPorAtividade->get($atividade->id, collect())
                ->flatMap(fn (string $pacoteId) => $materiaisPorPacote->get($pacoteId, collect()))
                ->unique()
                ->values();

            return [$atividade->id => $ids];
        });
    }
}
