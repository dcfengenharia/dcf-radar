<?php

namespace App\Exceptions;

/**
 * Melhoria "Posto Operacional" — hardening de `App\Actions\Estoque\CriarMaterial`
 * (achado do relatório de fechamento: `exists:unidades_medida,id`/
 * `exists:familias_material,id` no `$this->validate()` de cada chamador
 * consultam a tabela crua, sem scope de tenant — um payload manipulado
 * com um ID de OUTRO tenant passaria essa validação). Lançada pela
 * própria Action quando `unidade_medida_id`/`familia_material_id` não
 * resolvem (via `::find()`, já tenant-scoped por `BelongsToTenant`) no
 * tenant corrente — nunca confia só na validação de formulário do
 * Livewire.
 */
class MaterialInvalidoException extends \RuntimeException
{
}
