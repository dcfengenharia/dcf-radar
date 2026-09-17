<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 2C, Seção 4-8 — Convite multiperfil: um Convite passa a poder
 * carregar 1..N Perfis, não mais só o `perfil_id` singular legado.
 *
 * `convites.perfil_id` NÃO é removido nem alterado — continua sendo
 * gravado (como o primeiro perfil selecionado) pra qualquer leitura
 * legada que ainda dependa dele, e é o único dado disponível pra
 * convites criados ANTES desta migração (Seção 5: "convites legados
 * continuam válidos" — nenhum backfill necessário, o fallback vive em
 * `App\Support\Perfis\AtribuicaoPerfilConvite::perfisValidosParaAceite()`).
 *
 * `convite_id` cascade (linha filha de um Convite, nunca sobrevive
 * sozinha à exclusão do pai — mesmo padrão do resto do domínio de
 * Convite, que já cascade pelo tenant/obra). `perfil_id` restrict —
 * mesma defesa em profundidade já usada em toda referência a `Perfil`
 * no projeto (a checagem real de "em uso" continua sendo a aplicação,
 * em `⚡perfis-acesso.blade.php::excluirPerfil()`, estendida nesta fase
 * pra também considerar esta tabela).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convite_perfis', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('convite_id')->constrained('convites')->cascadeOnDelete();
            $table->foreignUlid('perfil_id')->constrained('perfis')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['convite_id', 'perfil_id'], 'convite_perfis_convite_perfil_unique');
            $table->index(['tenant_id', 'convite_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convite_perfis');
    }
};
