<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A migration original criou `notifiable_id` como bigint (padrão do
     * stub do Laravel), mas todo model do projeto usa ULID como chave
     * primária (HasUlids) — gravar um ULID nessa coluna trunca o dado e
     * quebra o canal 'database' de qualquer notificação (silenciosamente,
     * via job de fila que falha). Tabela `notifications` está vazia
     * (confirmado antes desta migration), sem risco de perda de dado.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_type_notifiable_id_index');
            $table->dropColumn('notifiable_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->ulid('notifiable_id')->after('notifiable_type');
            $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable_type_notifiable_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_type_notifiable_id_index');
            $table->dropColumn('notifiable_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('notifiable_id')->after('notifiable_type');
            $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable_type_notifiable_id_index');
        });
    }
};
