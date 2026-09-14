<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home Executiva (Ciclo 25, Fechamento) — cursor mínimo de "última vez
 * que este usuário abriu a Home DESTA obra", pra "O que mudou" deixar de
 * usar uma janela fixa de 7 dias e passar a ser "desde sua última
 * visita" de verdade. Fresh-read confirmou que não existe hoje nenhum
 * `last_login_at`/log de acesso por usuário+obra reaproveitável — esta é
 * a infraestrutura mínima necessária, criada só depois de confirmar essa
 * ausência (Seção 3 do pedido de fechamento).
 *
 * `ultimo_acesso_em` é sempre o timestamp de UM acesso completo/renderizado
 * com sucesso — nunca de um re-render interno do Livewire (o cursor só é
 * lido/gravado em `mount()`, que só roda em navegação real, nunca em
 * atualização parcial via `wire:model`/polling).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ultimo_acesso_home_por_usuario_obra', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('ultimo_acesso_em');
            $table->timestamps();

            $table->unique(['obra_id', 'user_id'], 'uahpuo_obra_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ultimo_acesso_home_por_usuario_obra');
    }
};
