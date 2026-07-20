<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_desvios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_curva_id')->constrained('report_curvas')->cascadeOnDelete();

            // O próprio pacote da curva (linha "nível pai") ou um filho
            // imediato dele — nunca mais fundo que isso.
            $table->foreignUlid('pacote_trabalho_id')->constrained('pacotes_trabalho')->restrictOnDelete();

            $table->boolean('eh_nivel_pai')->default(false);

            $table->string('titulo_exibicao');

            // peso = HH linha de base desta linha / HH linha de base do pai.
            // 1.0 pra linha do nível pai (peso de si mesma).
            $table->decimal('peso', 6, 4);

            // %previsto e %real são o avanço PRÓPRIO da linha (HH até a data
            // de status dividido pelo HH total DA PRÓPRIA linha) — não
            // relativos ao total do pai. Ver ReportGerador pra explicação
            // completa da fórmula, verificada contra a planilha real.
            $table->decimal('percentual_previsto', 5, 2);
            $table->decimal('percentual_real', 5, 2);

            // %desvio = %real - %previsto (negativo = atrasado).
            $table->decimal('percentual_desvio', 5, 2);

            // %impacto = %desvio * peso.
            $table->decimal('percentual_impacto', 6, 2);

            $table->unsignedInteger('ordem')->default(0);

            $table->timestamps();

            $table->index(['report_curva_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_desvios');
    }
};
