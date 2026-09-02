<?php

namespace App\DTOs\Gestao\Cockpit;

use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.5 — read model ÚNICO do Cockpit Executivo da Obra.
 * `readonly`/`final`, montado inteiramente por
 * `App\Support\Gestao\CockpitObraQuery::resumo()` — o Livewire/Blade
 * NUNCA calcula nada, só formata o que já chega pronto aqui (Seção 2 do
 * pedido: `fatos operacionais → Queries/Services de Gestão → DTO → Livewire
 * → Blade`, nunca `Blade → loops → Models → regra`).
 *
 * **Blocos que são `Collection<SituacaoGerencial>` (riscos/acoesHoje/
 * suprimentosCronograma/engenharia/inventarioAguardandoDecisao) reaproveitam
 * o MESMO DTO já validado desde a 21.2** — nunca uma segunda representação
 * paralela da mesma situação (todo campo que a Seção 5/6 do pedido pede já
 * existe em `App\DTOs\Gestao\SituacaoGerencial`: tipo/severidade/descrição/
 * motivo/quantidade/dataRelevante/destinatariosPerfis/deepLink/contexto).
 *
 * **Blocos que são arrays/Collections simples (fornecedores, industrialização,
 * pipeline, estoque) reaproveitam as Collections de array JÁ retornadas
 * pelos serviços das Etapas 21.1/21.2** (`ResumoFornecedorQuery`,
 * `ResumoIndustrializacaoQuery`, `PipelineMaterialQuery`) — nunca
 * envelopadas numa 2ª camada de DTO só por uniformidade (Seção 4: "não
 * retornar arrays gigantes ANÔNIMOS" — esses já são pequenos e
 * estruturados, mesma convenção do resto da Etapa 20/21).
 */
final class CockpitObra
{
    /**
     * @param  array<string, int|float|null>  $panorama
     * @param  Collection<int, \App\DTOs\Gestao\SituacaoGerencial>  $riscos
     * @param  Collection<int, \App\DTOs\Gestao\SituacaoGerencial>  $acoesHoje
     * @param  array<string, CockpitProntidaoHorizonte>  $prontidao  chaves '2'/'4'/'8'
     * @param  Collection<int, CockpitAtividadeLinha>  $matrizAtividades
     * @param  int  $pipelineTotalMateriais  total de materiais relevantes à obra (antes do corte de exibição)
     * @param  Collection<int, array>  $pipelineMateriais  top N (por déficit/necessidade) — cada
     *   item é a linha CRUA de `PipelineMaterialQuery::porMateriais()` + `material_codigo`/`material_descricao`.
     *   **Nunca somado entre materiais** (unidades incompatíveis — kg+m+un) — sempre 1 linha por Material.
     * @param  Collection<int, array{situacao: \App\DTOs\Gestao\SituacaoGerencial, folga_dias: ?int, pacote_nome: ?string}>  $suprimentosCronograma
     * @param  Collection<string, array>  $fornecedores  chave = fornecedor_id, valor = row de ResumoFornecedorQuery + 'nome'
     * @param  array<string, Collection<int, \App\DTOs\Gestao\SituacaoGerencial>>  $estoque  chaves: reservas_descobertas/materiais_sem_destinacao/saidas_sem_conciliacao/desvios_aplicacao/materiais_parados
     * @param  Collection<string, array>  $industrializacao  chave = ordem_id, valor = row de ResumoIndustrializacaoQuery + metadados
     * @param  Collection<int, \App\DTOs\Gestao\SituacaoGerencial>  $engenharia
     * @param  array{em_contagem: int, aguardando_decisao: Collection<int, \App\DTOs\Gestao\SituacaoGerencial>}  $inventario
     * @param  string[]  $gaps
     */
    public function __construct(
        public readonly string $obraId,
        public readonly int $horizontePrincipalDias,
        public readonly array $panorama,
        public readonly Collection $riscos,
        public readonly Collection $acoesHoje,
        public readonly int $totalInformativas,
        public readonly array $prontidao,
        public readonly Collection $matrizAtividades,
        public readonly int $pipelineTotalMateriais,
        public readonly Collection $pipelineMateriais,
        public readonly Collection $suprimentosCronograma,
        public readonly Collection $fornecedores,
        public readonly array $estoque,
        public readonly Collection $industrializacao,
        public readonly Collection $engenharia,
        public readonly array $inventario,
        public readonly array $gaps,
    ) {
    }
}
