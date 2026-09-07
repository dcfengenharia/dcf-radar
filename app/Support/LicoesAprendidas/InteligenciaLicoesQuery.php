<?php

namespace App\Support\LicoesAprendidas;

use App\DTOs\LicoesAprendidas\ItemDistribuicaoLicoes;
use App\DTOs\LicoesAprendidas\ItemEvolucaoLicoes;
use App\DTOs\LicoesAprendidas\ItemMaterialCrossObra;
use App\DTOs\LicoesAprendidas\ItemProvenienciaLicoes;
use App\DTOs\LicoesAprendidas\ResumoInteligenciaLicoes;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Models\CandidatoLicaoAprendida;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaVinculo;
use App\Models\Material;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Ciclo 23, Etapa 23.5.A (Decisão 9) — read-model ÚNICO da "Inteligência
 * Corporativa de Lições Aprendidas". Centraliza TODAS as agregações desta
 * etapa — a Blade nunca monta estatística sozinha, só lê o
 * `ResumoInteligenciaLicoes` já pronto.
 *
 * Escopo fixo e único: `LicaoAprendida::status = Publicada` (Decisão 1/2 —
 * candidato pendente NUNCA entra aqui, governança de candidato continua
 * 100% na aba "Revisão da Obra" da 23.3). Isolamento de tenant é
 * automático via `BelongsToTenant` — nenhuma query aqui usa `DB::table()`
 * cru (que bypassaria o global scope); toda agregação parte de
 * `LicaoAprendida::query()`/`LicaoAprendidaVinculo::query()`, preservando
 * o scope mesmo com `join()`/`selectRaw()`.
 *
 * Sempre agregação SQL (`groupBy`/`selectRaw`/`havingRaw`) — nunca
 * `LicaoAprendida::all()` carregado em PHP pra somar em loop (Decisão 9).
 * Cada método é uma query fixa, independente do volume de linhas —
 * validado por `tests/Feature/InteligenciaLicoesQueryTest.php` com
 * 100/1.000/10.000 lições (Decisão 12).
 */
final class InteligenciaLicoesQuery
{
    public static function resumo(): ResumoInteligenciaLicoes
    {
        return new ResumoInteligenciaLicoes(
            totalLicoesPublicadas: self::totalPublicadas(),
            totalObrasComLicaoPublicada: self::totalObrasComPublicacao(),
            totalBoasPraticasPublicadas: self::totalBoasPraticas(),
            distribuicaoPorArea: self::distribuicaoPorArea(),
            distribuicaoPorDisciplina: self::distribuicaoPorDisciplina(),
            distribuicaoPorTipo: self::distribuicaoPorTipo(),
            distribuicaoPorCriticidade: self::distribuicaoPorCriticidade(),
            evolucaoTemporal: self::evolucaoTemporal(),
            materiaisCrossObra: self::materiaisCrossObra(),
            proveniencia: self::proveniencia(),
        );
    }

    /** Só o total — usado no cabeçalho ("X lições publicadas"). */
    public static function totalPublicadas(): int
    {
        return self::baseQuery()->count();
    }

    /** `COUNT(DISTINCT obra_origem_id)` — "Y obras contribuíram" (Decisão 5). */
    public static function totalObrasComPublicacao(): int
    {
        return (int) self::baseQuery()
            ->distinct()
            ->count('obra_origem_id');
    }

    /** "Z boas práticas" — nunca confundido com o total geral (Decisão 5). */
    public static function totalBoasPraticas(): int
    {
        return self::baseQuery()
            ->where('tipo', TipoLicaoAprendida::BoaPratica->value)
            ->count();
    }

    /**
     * Distribuição por Área Funcional (Decisão 3 — nunca "incidência"/
     * "recorrência", só "distribuição da memória registrada").
     *
     * @return Collection<int, ItemDistribuicaoLicoes>
     */
    public static function distribuicaoPorArea(): Collection
    {
        // `toBase()` — sem isso, `get()` do Eloquent Builder hidrata Model e
        // aplica o cast de `area_funcional` (enum) na coluna crua
        // selecionada, quebrando o `AreaFuncionalLicao::from()` abaixo (que
        // espera a string bruta do banco, não a instância já convertida).
        return self::baseQuery()
            ->toBase()
            ->selectRaw('area_funcional, count(*) as quantidade')
            ->groupBy('area_funcional')
            ->get()
            ->map(fn ($linha) => new ItemDistribuicaoLicoes(
                chave: $linha->area_funcional,
                rotulo: AreaFuncionalLicao::from($linha->area_funcional)->label(),
                quantidade: (int) $linha->quantidade,
            ))
            ->sortByDesc('quantidade')
            ->values();
    }

    /**
     * Distribuição por Disciplina — `disciplina_id` é opcional em
     * `LicaoAprendida` (nem toda lição tem uma disciplina técnica
     * associada), então uma linha `null` vira "Sem disciplina informada"
     * — nunca omitida silenciosamente do total.
     *
     * @return Collection<int, ItemDistribuicaoLicoes>
     */
    public static function distribuicaoPorDisciplina(): Collection
    {
        return self::baseQuery()
            ->leftJoin('disciplinas', 'disciplinas.id', '=', 'licoes_aprendidas.disciplina_id')
            ->selectRaw('licoes_aprendidas.disciplina_id as disciplina_id, disciplinas.nome as nome, count(*) as quantidade')
            ->groupBy('licoes_aprendidas.disciplina_id', 'disciplinas.nome')
            ->get()
            ->map(fn ($linha) => new ItemDistribuicaoLicoes(
                chave: (string) ($linha->disciplina_id ?? ''),
                rotulo: $linha->nome ?? 'Sem disciplina informada',
                quantidade: (int) $linha->quantidade,
            ))
            ->sortByDesc('quantidade')
            ->values();
    }

    /**
     * @return Collection<int, ItemDistribuicaoLicoes>
     */
    public static function distribuicaoPorTipo(): Collection
    {
        return self::baseQuery()
            ->toBase()
            ->selectRaw('tipo, count(*) as quantidade')
            ->groupBy('tipo')
            ->get()
            ->map(fn ($linha) => new ItemDistribuicaoLicoes(
                chave: $linha->tipo,
                rotulo: TipoLicaoAprendida::from($linha->tipo)->label(),
                quantidade: (int) $linha->quantidade,
            ))
            ->sortByDesc('quantidade')
            ->values();
    }

    /**
     * @return Collection<int, ItemDistribuicaoLicoes>
     */
    public static function distribuicaoPorCriticidade(): Collection
    {
        return self::baseQuery()
            ->toBase()
            ->selectRaw('criticidade, count(*) as quantidade')
            ->groupBy('criticidade')
            ->get()
            ->map(fn ($linha) => new ItemDistribuicaoLicoes(
                chave: $linha->criticidade,
                rotulo: CriticidadeLicao::from($linha->criticidade)->label(),
                quantidade: (int) $linha->quantidade,
            ))
            ->sortByDesc(fn (ItemDistribuicaoLicoes $item) => CriticidadeLicao::from($item->chave)->peso())
            ->values();
    }

    /**
     * Evolução temporal das PUBLICAÇÕES (`publicado_em`, nunca
     * `created_at`/`data_ocorrencia`) — "quando a memória corporativa
     * cresceu", agrupado por mês. Toda `LicaoAprendida` com
     * `status=Publicada` sempre tem `publicado_em` preenchido
     * (`PublicarLicaoAprendida` carimba no momento da transição) — o
     * `whereNotNull` é defensivo, não um filtro que descarta dado real.
     *
     * @return Collection<int, ItemEvolucaoLicoes>
     */
    public static function evolucaoTemporal(): Collection
    {
        return self::baseQuery()
            ->whereNotNull('publicado_em')
            ->selectRaw("DATE_FORMAT(publicado_em, '%Y-%m') as periodo, count(*) as quantidade")
            ->groupBy('periodo')
            ->orderBy('periodo')
            ->get()
            ->map(fn ($linha) => new ItemEvolucaoLicoes(
                periodo: $linha->periodo,
                quantidade: (int) $linha->quantidade,
            ));
    }

    /**
     * Materiais presentes em lições publicadas de 2+ obras distintas
     * (Decisão 7) — `HAVING COUNT(DISTINCT obra_origem_id) >= 2` é o
     * ÚNICO critério de inclusão, calculado inteiramente em SQL. Uma
     * lição vinculada ao mesmo Material nunca é contada 2x mesmo se
     * tiver outros vínculos (Decisão 13) — `COUNT(DISTINCT
     * licao_aprendida_id)` garante isso por construção, independente da
     * unicidade já imposta por `licao_vinculos_licao_entidade_unique`.
     *
     * @return Collection<int, ItemMaterialCrossObra>
     */
    public static function materiaisCrossObra(): Collection
    {
        $linhas = LicaoAprendidaVinculo::query()
            ->join('licoes_aprendidas', 'licoes_aprendidas.id', '=', 'licao_aprendida_vinculos.licao_aprendida_id')
            ->where('licao_aprendida_vinculos.entidade_tipo', TipoEntidadeVinculoLicao::Material->value)
            ->where('licoes_aprendidas.status', StatusLicaoAprendida::Publicada->value)
            ->selectRaw(
                'licao_aprendida_vinculos.entidade_id as material_id, '
                .'count(distinct licao_aprendida_vinculos.licao_aprendida_id) as quantidade_licoes, '
                .'count(distinct licoes_aprendidas.obra_origem_id) as quantidade_obras, '
                .'sum(case when licoes_aprendidas.tipo = ? then 1 else 0 end) as quantidade_boas_praticas',
                [TipoLicaoAprendida::BoaPratica->value]
            )
            ->groupBy('licao_aprendida_vinculos.entidade_id')
            ->havingRaw('count(distinct licoes_aprendidas.obra_origem_id) >= 2')
            ->get();

        if ($linhas->isEmpty()) {
            return collect();
        }

        $materiais = Material::whereIn('id', $linhas->pluck('material_id'))->get()->keyBy('id');

        return $linhas
            ->map(function ($linha) use ($materiais) {
                $material = $materiais->get($linha->material_id);

                return new ItemMaterialCrossObra(
                    materialId: $linha->material_id,
                    titulo: $material
                        ? VinculoLicaoResolver::tituloParaSnapshot(TipoEntidadeVinculoLicao::Material, $material)
                        : 'Material removido',
                    quantidadeLicoes: (int) $linha->quantidade_licoes,
                    quantidadeObras: (int) $linha->quantidade_obras,
                    quantidadeBoasPraticas: (int) $linha->quantidade_boas_praticas,
                );
            })
            ->sortByDesc('quantidadeLicoes')
            ->values();
    }

    /**
     * Proveniência (Decisão 15) — reconstrução determinística das 3
     * categorias já provadas mutuamente exclusivas:
     *
     * - `candidato_convertido`: existe `CandidatoLicaoAprendida.
     *   licao_aprendida_id` apontando pra esta lição (único jeito real de
     *   provar que ela nasceu de `ConverterCandidatoEmLicao`).
     * - `captura_contextual`: NÃO é candidato convertido, mas tem um
     *   vínculo com `e_origem=true` (só `CriarLicaoComOrigem` cria esse
     *   vínculo — chamada tanto direto quanto por dentro da conversão de
     *   candidato, por isso o candidato precisa ser checado PRIMEIRO,
     *   nunca em paralelo).
     * - `manual`: nem uma coisa nem outra — nasceu do formulário simples
     *   (`CriarLicaoAprendida`, que nunca cria nenhum vínculo).
     *
     * A ordem de checagem (candidato → e_origem → manual) é o que torna
     * as 3 categorias mutuamente exclusivas — nunca uma soma que
     * ultrapassa o total de lições publicadas (validado em teste).
     *
     * @return Collection<int, ItemProvenienciaLicoes>
     */
    public static function proveniencia(): Collection
    {
        $idsConvertidosDeCandidato = CandidatoLicaoAprendida::query()
            ->whereNotNull('licao_aprendida_id')
            ->select('licao_aprendida_id');

        $candidatoConvertido = self::baseQuery()
            ->whereIn('id', $idsConvertidosDeCandidato)
            ->count();

        $capturaContextual = self::baseQuery()
            ->whereNotIn('id', $idsConvertidosDeCandidato)
            ->whereHas('vinculos', fn (Builder $q) => $q->where('e_origem', true))
            ->count();

        $manual = self::baseQuery()
            ->whereNotIn('id', $idsConvertidosDeCandidato)
            ->whereDoesntHave('vinculos', fn (Builder $q) => $q->where('e_origem', true))
            ->count();

        return collect([
            new ItemProvenienciaLicoes('candidato_convertido', 'Candidato Convertido', $candidatoConvertido),
            new ItemProvenienciaLicoes('captura_contextual', 'Captura Contextual', $capturaContextual),
            new ItemProvenienciaLicoes('manual', 'Manual', $manual),
        ]);
    }

    /** Único ponto de escopo — sempre `Publicada` (Decisão 1/2), nunca outro status. */
    private static function baseQuery(): Builder
    {
        return LicaoAprendida::query()->where('status', StatusLicaoAprendida::Publicada->value);
    }
}
