<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.6 — ledger mínimo de idempotência POR CANAL
     * EXTERNO (mail/whatsapp) dos alertas de distribuição GRD. A auditoria
     * da 18.5.5.HARDENING confirmou que a PRIMARY KEY de `notifications.id`
     * (UUIDv5 determinístico, ver App\Support\Grd\AlertaDistribuicaoGrd::
     * idAlerta()) protege estruturalmente o canal `database` — mas canais
     * externos (mail via SMTP, WhatsApp via Z-API) são side effects que a
     * PK de `notifications` não cobre: um retry do job de fila do canal
     * `mail` (Laravel despacha 1 SendQueuedNotifications POR canal) pode,
     * em tese, reenviar um e-mail já entregue com sucesso.
     *
     * `evento_usuario_id` é o MESMO UUIDv5 usado como `notifications.id` —
     * a identidade do evento (tipo+obra+documento+revisão+usuário)
     * continua sendo UMA SÓ, nunca duplicada/redefinida aqui — este ledger
     * só acrescenta a dimensão `canal`, que a PK de `notifications` não
     * carrega (um mesmo evento+usuário pode ter várias linhas aqui, 1 por
     * canal externo usado).
     *
     * Escrito SEMPRE DEPOIS de uma tentativa de envio SEM exceção (nunca
     * antes) — ver App\Notifications\Channels\GrdLedgerMailChannel/
     * GrdLedgerZApiChannel: um retry após falha genuína (send() lançou)
     * nunca encontra a linha, então tenta de novo normalmente; um retry
     * após sucesso genuíno encontra a linha e pula. Trade-off documentado
     * no próprio código dos 2 channels: não protege contra o caso raro de
     * "enviou com sucesso, mas o processo morreu antes de gravar a linha"
     * — o mesmo residual que qualquer sistema at-least-once carrega.
     */
    public function up(): void
    {
        Schema::create('grd_alerta_entregas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tipo_alerta');
            $table->foreignUlid('documento_engenharia_id')->constrained('documentos_engenharia')->cascadeOnDelete();
            $table->foreignUlid('revisao_id')->constrained('documento_engenharia_revisoes')->cascadeOnDelete();
            $table->uuid('evento_usuario_id');
            $table->string('canal');
            $table->timestamp('enviado_em');
            $table->timestamp('created_at')->nullable();

            $table->unique(['evento_usuario_id', 'canal'], 'grd_alerta_entregas_evento_canal_unique');
            $table->index(['tenant_id', 'obra_id'], 'grd_alerta_entregas_tenant_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grd_alerta_entregas');
    }
};
