<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 5) — contexto operacional OPCIONAL de
 * uma reaplicação. Decisão de desenho (Opção A do pedido — reaplicação +
 * tabela de contextos própria, nunca `entidade_tipo`/`entidade_id`
 * singular na própria linha de `licao_aprendida_reaplicacoes`):
 *
 * A cardinalidade corporativa da reaplicação é sempre Lição×Obra (1
 * linha), mas o REGISTRO pode ter nascido de mais de um ponto de
 * contexto real (ex.: usuário registra a partir do popup de uma
 * Atividade específica no Lookahead — 1 contexto — mas nada impede uma
 * 2ª chamada complementar registrando também o Material que motivou a
 * mesma reaplicação). Uma coluna singular na própria reaplicação
 * impediria isso silenciosamente — daí a tabela filha, mesmo padrão já
 * aprovado e em produção para `licao_aprendida_vinculos` (Ciclo 23.1):
 * `entidade_tipo`/`entidade_id` como referência SOLTA e tipada (nunca
 * FQCN vindo de request — sempre validada contra a allowlist fechada de
 * `App\Enums\TipoEntidadeVinculoLicao` via `App\Support\LicoesAprendidas\
 * VinculoLicaoResolver`), `titulo_snapshot` congelado no momento da
 * criação.
 *
 * Nesta etapa, cada Action que registra uma reaplicação passa no máximo
 * 1 contexto (o CTA que disparou o registro — Atividade no Lookahead,
 * Material no Estoque, nenhum na Biblioteca) — mas o SCHEMA não impõe
 * esse limite de propósito, pra não repetir o mesmo problema que a
 * coluna singular teria.
 *
 * `reaplicacao_id` é `cascadeOnDelete()` — diferente das FKs de
 * evidência histórica do projeto: aqui o contexto é dado FILHO da
 * própria reaplicação (nunca uma referência externa apontando PRA ela),
 * e como a reaplicação nunca é de fato excluída (bloqueio estrutural no
 * Observer), o comportamento de cascade nunca chega a ser exercitado em
 * produção — só defesa coerente caso um bypass direto de SQL algum dia
 * remova a linha pai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licao_aprendida_reaplicacao_contextos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('reaplicacao_id')->constrained('licao_aprendida_reaplicacoes')->cascadeOnDelete();

            $table->string('entidade_tipo');
            $table->string('entidade_id');
            $table->string('titulo_snapshot');

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['reaplicacao_id', 'entidade_tipo', 'entidade_id'], 'licao_reaplicacao_ctx_reap_entidade_unique');
            $table->index(['entidade_tipo', 'entidade_id'], 'licao_reaplicacao_ctx_entidade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licao_aprendida_reaplicacao_contextos');
    }
};
