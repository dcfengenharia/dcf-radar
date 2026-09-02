<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 21, Etapa 21.4 — coluna aditiva pra suportar COOLDOWN de e-mail
 * imediato (Seção 6/11 do pedido). Deliberadamente NA PRÓPRIA
 * `situacao_ocorrencias` (nunca "adicionar campo a domínio operacional",
 * Seção 27 — esta tabela já É infraestrutura de comunicação, criada na
 * 21.3 especificamente pra isso, nunca uma tabela de negócio como
 * Atividade/Material/PedidoCompra). Guardar aqui (em vez de derivar do
 * ledger por canal, Seção 12) é o que permite o check de cooldown ler 1
 * única linha já em memória durante `processarSituacao()`, sem nenhuma
 * query/JSON-extraction adicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('situacao_ocorrencias', function (Blueprint $table) {
            $table->timestamp('ultimo_email_em')->nullable()->after('resolvida_em');
        });
    }

    public function down(): void
    {
        Schema::table('situacao_ocorrencias', function (Blueprint $table) {
            $table->dropColumn('ultimo_email_em');
        });
    }
};
