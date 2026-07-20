<?php

namespace Database\Factories;

use App\Enums\StatusReport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReportFactory extends Factory
{
    /**
     * cronograma_importacao_id não tem valor padrão aqui de propósito —
     * assim como em todos os outros testes deste projeto que envolvem
     * CronogramaImportacao, cabe ao teste criar a importação explicitamente
     * (via CronogramaImportacao::create([...])) e passá-la, já que os
     * dados de HH (AvancoPeriodo) precisam estar amarrados à MESMA
     * importação usada aqui.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'obra_id' => Work::factory(),
            'periodo_referencia' => now()->startOfWeek(),
            'status' => StatusReport::Rascunho->value,
            'criado_por' => User::factory(),
        ];
    }

    public function emitido(): static
    {
        return $this->state(fn () => [
            'status' => StatusReport::Emitido->value,
            'emitido_em' => now(),
        ]);
    }
}
