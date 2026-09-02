<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 21, Etapa 21.4 — generalização EXATA de `grd_alerta_entregas`
 * (Ciclo 18.5.6) pro domínio de Situações Gerenciais: ledger mínimo de
 * idempotência POR CANAL EXTERNO (só `mail` nesta etapa — Seção 7, nunca
 * WhatsApp/SMS/Slack/Teams). A PRIMARY KEY de `notifications.id`
 * (UUIDv5 determinístico, `SincronizarSituacoesGerenciais::
 * enviarComIdempotencia()`) já protege o canal `database` — mas um
 * retry do job de fila do canal `mail` (Laravel despacha 1
 * `SendQueuedNotifications` POR canal) pode, em tese, reenviar um
 * e-mail já entregue com sucesso (Seção 13).
 *
 * `evento_usuario_id` é o MESMO id usado como `notifications.id` — a
 * identidade do evento nunca é redefinida aqui, só ganha a dimensão
 * `canal`. Mesma garantia REAL já documentada e auditada em
 * `App\Notifications\Channels\GrdLedgerMailChannel` — at-least-once com
 * deduplicação best-effort, nunca exactly-once (residual conhecido e
 * aceito: processo morre ENTRE o SMTP aceitar o e-mail e o INSERT deste
 * ledger — não resolvido por nada existente no projeto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('situacao_comunicacao_entregas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('evento_usuario_id');
            $table->string('canal');
            $table->timestamp('enviado_em');
            $table->timestamp('created_at')->nullable();

            $table->unique(['evento_usuario_id', 'canal'], 'situacao_com_entregas_evento_canal_unique');
            $table->index(['tenant_id', 'obra_id'], 'situacao_com_entregas_tenant_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('situacao_comunicacao_entregas');
    }
};
