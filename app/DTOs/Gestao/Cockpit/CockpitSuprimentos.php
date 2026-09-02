<?php

namespace App\DTOs\Gestao\Cockpit;

use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.6 — read model do Cockpit de Suprimentos e
 * Abastecimento. Mesma filosofia de `CockpitObra` (21.5): `readonly`/
 * `final`, montado inteiramente por `App\Support\Gestao\
 * CockpitSuprimentosQuery::resumo()`, NUNCA calcula regra de negócio —
 * cada bloco é composição de serviços já existentes/testados (Etapas
 * 21.1/21.2, Ciclo 19), incluindo REUSO DIRETO de métodos `public`
 * de `App\Support\Gestao\CockpitObraQuery` (Seção 4 do pedido:
 * "compartilhe queries inferiores, não copie código").
 */
final class CockpitSuprimentos
{
    /**
     * @param  array<string, int>  $panorama  Seção 24 — contagens objetivas, nunca score
     * @param  Collection<int, \App\DTOs\Gestao\SituacaoGerencial>  $necessidadesCriticas
     * @param  Collection<int, array>  $funilAbastecimento  linhas cruas de `PipelineMaterialQuery`, nunca somadas entre materiais
     * @param  Collection<int, array>  $comprasPendentes  saldo por estágio da cadeia, por Material
     * @param  Collection<int, array>  $pedidosCriticos
     * @param  array{previsto_hoje: Collection, proximos_dias: Collection, vencidos: Collection, parciais: Collection, sem_destinacao: Collection}  $recebimentos
     * @param  Collection<string, array>  $fornecedores  enriquecido com folga mínima + materiais de atividades próximas
     * @param  Collection<int, CockpitAtividadeLinha>  $coberturaFutura  só atividades com prontidão material != Coberto
     * @param  array<string, Collection>  $estoque
     * @param  Collection<string, array>  $terceiros
     * @param  Collection<int, array>  $chegaTardeDemais  folga negativa, Seção 19
     * @param  string[]  $gaps
     */
    public function __construct(
        public readonly string $obraId,
        public readonly int $horizontePrincipalDias,
        public readonly array $panorama,
        public readonly Collection $necessidadesCriticas,
        public readonly Collection $funilAbastecimento,
        public readonly int $funilTotalMateriais,
        public readonly Collection $comprasPendentes,
        public readonly Collection $pedidosCriticos,
        public readonly array $recebimentos,
        public readonly Collection $fornecedores,
        public readonly Collection $coberturaFutura,
        public readonly array $estoque,
        public readonly Collection $terceiros,
        public readonly Collection $chegaTardeDemais,
        public readonly array $gaps,
    ) {
    }
}
