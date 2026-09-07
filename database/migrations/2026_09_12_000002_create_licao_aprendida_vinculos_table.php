<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.1 — vínculos contextuais da lição com fatos
 * operacionais reais. Investigado antes de codificar: o projeto NUNCA
 * usa relacionamento polimórfico Eloquent (`morphTo`/`morphMany`, zero
 * ocorrência em `app/Models`) — o idioma já consolidado pra "isto pode
 * apontar pra um de vários tipos de entidade" é referência SOLTA e
 * tipada, sem FK física (`entidade_tipo` string + `entidade_id` ULID),
 * já usado em `inconsistencias_avanco`/`situacao_ocorrencias`. Seguido
 * aqui sem inventar um padrão novo.
 *
 * `entidade_tipo` NUNCA aceita valor arbitrário do request — sempre
 * validado contra a allowlist fechada de `App\Enums\
 * TipoEntidadeVinculoLicao` em `App\Support\LicoesAprendidas\
 * VinculoLicaoResolver`, nunca uma string solta vinda do formulário.
 *
 * `titulo_snapshot` é o mínimo necessário pra inteligibilidade histórica
 * (Seção 16 do pedido) — congelado no momento da criação do vínculo via
 * `VinculoLicaoResolver::tituloParaSnapshot()`, nunca recalculado depois
 * (se o título da Atividade/Restrição mudar depois, a lição continua
 * mostrando o que era verdade quando o vínculo foi criado).
 *
 * `licao_aprendida_id` é `restrictOnDelete()` (Etapa 23.2, corrigido —
 * era `cascadeOnDelete()` na versão original da 23.1, nunca chegou a ir
 * pra produção): a Etapa 23.2 precisou de uma coluna `STORED GENERATED`
 * derivada de `licao_aprendida_id` (`origem_unica_da_licao`, ver
 * `2026_09_13_000001_...`) — o InnoDB do MySQL 8 proíbe estruturalmente
 * (`ER_CANNOT_ADD_FOREIGN`, confirmado empiricamente) uma coluna gerada
 * `STORED` que depende de uma coluna com FK `ON DELETE CASCADE`/
 * `SET NULL` (mesma classe de restrição documentada pela Microsoft/MySQL
 * pra "generated columns that refer to a foreign key column"). `RESTRICT`
 * é seguro na prática: `LicaoAprendida` usa `SoftDeletes`, e nenhum ponto
 * do domínio chama `forceDelete()` — o vínculo nunca fica órfão porque a
 * exclusão real (não-soft) de uma lição simplesmente nunca acontece hoje.
 * Mesmo padrão RESTRICT já usado em `grd_aceites_entrega.
 * grd_destinatario_id` pela MESMA razão estrutural.
 *
 * `unique(licao_aprendida_id, entidade_tipo, entidade_id)` — nunca 2
 * linhas pro mesmo par lição+entidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licao_aprendida_vinculos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('licao_aprendida_id')->constrained('licoes_aprendidas')->restrictOnDelete();

            $table->string('entidade_tipo');
            $table->string('entidade_id');
            $table->string('titulo_snapshot');

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['licao_aprendida_id', 'entidade_tipo', 'entidade_id'],
                'licao_vinculos_licao_entidade_unique'
            );
            $table->index(['entidade_tipo', 'entidade_id'], 'licao_vinculos_entidade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licao_aprendida_vinculos');
    }
};
