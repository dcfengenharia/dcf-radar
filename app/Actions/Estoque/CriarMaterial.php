<?php

namespace App\Actions\Estoque;

use App\Enums\OrigemCadastroMaterial;
use App\Exceptions\MaterialInvalidoException;
use App\Models\FamiliaMaterial;
use App\Models\Material;
use App\Models\UnidadeMedida;

/**
 * Melhoria "Posto Operacional" — único ponto de escrita de CRIAÇÃO de
 * Material Mestre. Extraído do que antes era um `Material::create()`
 * inline em `⚡estoque.blade.php::salvarMaterial()` pra evitar duplicar
 * a mesma lógica na 2ª superfície de criação (inline, pelo popup do
 * Plano Semanal — `⚡plano-semanal.blade.php::salvarNovoMaterialInline()`).
 *
 * Cobre só CRIAÇÃO — edição de Material continua exclusiva de
 * `⚡estoque.blade.php::salvarMaterial()` (branch de `update()`), fora do
 * escopo desta melhoria.
 *
 * Validação de FORMATO de campo (obrigatoriedade/tamanho) continua
 * responsabilidade do `$this->validate()` de cada chamador — mesmo
 * padrão de toda Action deste projeto que nunca reimplementa regra de
 * formulário do Livewire. `tenant_id` é sempre auto-carimbado por
 * `BelongsToTenant` (nunca aceito como parâmetro aqui) — nenhum
 * chamador pode injetar tenant/obra por payload.
 *
 * **Hardening (fechamento pós-relatório)**: `unidade_medida_id`/
 * `familia_material_id` são SEMPRE revalidados AQUI, na própria Action —
 * nunca confiados só ao `exists:unidades_medida,id`/`exists:
 * familias_material,id` de cada `$this->validate()` de chamador, que
 * consulta a tabela CRUA (`DB::table()`) sem nenhum scope de tenant.
 * `UnidadeMedida::find()`/`FamiliaMaterial::find()` já são tenant-scoped
 * por `BelongsToTenant` — um ID de OUTRO tenant simplesmente não é
 * encontrado, e a Action lança `MaterialInvalidoException` ANTES de
 * qualquer escrita. Colocar a garantia aqui (em vez de duplicá-la em
 * cada Blade) garante que TODO chamador atual (Estoque, Plano Semanal)
 * e qualquer chamador futuro recebem exatamente a mesma proteção, sem
 * precisar lembrar de reimplementá-la.
 */
class CriarMaterial
{
    public function execute(
        string $codigo,
        string $descricao,
        string $unidadeMedidaId,
        ?string $familiaMaterialId,
        string $modoRastreabilidade,
        OrigemCadastroMaterial $origemCadastro,
    ): Material {
        $unidade = UnidadeMedida::find($unidadeMedidaId);
        if (! $unidade) {
            throw new MaterialInvalidoException('A unidade de medida selecionada não existe ou não pertence a este tenant.');
        }

        if ($familiaMaterialId !== null && ! FamiliaMaterial::find($familiaMaterialId)) {
            throw new MaterialInvalidoException('A família selecionada não existe ou não pertence a este tenant.');
        }

        return Material::create([
            'codigo' => $codigo,
            'descricao' => $descricao,
            'unidade_medida_id' => $unidade->id,
            'familia_material_id' => $familiaMaterialId,
            'modo_rastreabilidade' => $modoRastreabilidade,
            'origem_cadastro' => $origemCadastro->value,
            'ativo' => true,
        ]);
    }
}
