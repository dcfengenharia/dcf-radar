<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela normalizada (não JSON), espelhando avanco_periodos — mesma
        // filosofia usada em todo o resto do schema pra série temporal.
        Schema::create('report_curva_datapoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_curva_id')->constrained('report_curvas')->cascadeOnDelete();

            // Reaproveita os mesmos valores de GranularidadePeriodo/SerieAvanco
            // já usados em avanco_periodos — não são conceitos novos.
            $table->string('granularidade');
            $table->string('serie');

            $table->date('periodo_inicio');

            // Já com eventual ajuste manual (CurvaAjuste) aplicado — o
            // "horas_exibir" que CurvaAvanco::calcular() já resolve.
            $table->decimal('horas', 12, 2);
            $table->decimal('percentual_acumulado', 5, 2)->default(0);

            $table->timestamps();

            $table->index(
                ['report_curva_id', 'granularidade', 'serie', 'periodo_inicio'],
                'report_curva_datapoints_busca_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_curva_datapoints');
    }
};
