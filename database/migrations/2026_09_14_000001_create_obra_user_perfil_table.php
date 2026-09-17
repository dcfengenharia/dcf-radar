<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 2B — CORE RBAC CONTEXTUAL PROFISSIONAL (Seções 3-6 do pedido).
 *
 * Separa MEMBRESIA (`obra_user` — "usuário pertence/tem acesso à obra",
 * intocado nesta migration) de ATRIBUIÇÃO DE PERFIL ("quais perfis ele
 * possui nessa obra") — hoje `obra_user.perfil_id` só suporta 1 perfil
 * por (obra, usuário), por ser uma coluna simples na própria pivot de
 * membresia. Esta tabela nova permite N perfis por (obra, usuário),
 * sem alterar `obra_user` — a UNIÃO das capacidades de todos os perfis
 * do usuário naquela obra é a permissão efetiva (Seção 7/8: GRANT por
 * qualquer perfil, nunca DENY explícito).
 *
 * `tenant_id` é redundante com `work_id.tenant_id` mas incluído
 * explicitamente — mesmo padrão já usado em toda tabela de associação
 * do projeto (ex.: `alocacoes_requisicao_pacote`, `item_suprimento_atividades`)
 * — permite escopar/isolar por tenant sem precisar de join, e mantém
 * `TenantIsolationTest` simples de escrever.
 *
 * `perfil_id` usa `cascadeOnDelete()` (não `nullOnDelete()` como
 * `obra_user.perfil_id`) — uma linha AQUI É a própria associação; se o
 * Perfil for de fato apagado (só possível quando "em uso" é falso, ver
 * `⚡perfis-acesso.blade.php::excluirPerfil()`, atualizado nesta fase
 * pra também checar esta tabela), a associação correspondente deixa de
 * fazer sentido e é removida junto — nunca uma "associação órfã" com
 * perfil_id apontando pra um Perfil inexistente.
 *
 * `unique(work_id, user_id, perfil_id)` — o MESMO perfil nunca é
 * atribuído duas vezes ao mesmo usuário na mesma obra (nunca inflaria
 * a união de permissões, mas evita lixo/ambiguidade). Perfis
 * DIFERENTES pro mesmo par são livres (é exatamente o que esta tabela
 * existe pra permitir).
 *
 * Seção 6 do pedido — `obra_user.perfil_id` NÃO é removido/alterado
 * nesta migration. Continua existindo como espelho de exibição/
 * compatibilidade pra UI legada de "1 perfil por membro" — nunca mais
 * lido pelo resolver central (`App\Models\Concerns\HasObraPapel`), que
 * passa a consultar esta tabela nova como fonte primária, com um
 * FALLBACK de leitura pro valor legado (união, nunca substituição) —
 * ver docblock de `HasObraPapel::perfisIdsNaObra()` pra autoridade
 * completa. Isso garante compatibilidade mesmo com código/teste que
 * ainda escreve só na coluna legada sem passar pela nova ponte
 * (`App\Support\AtribuicaoPerfilObra`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('obra_user_perfil', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('perfil_id')->constrained('perfis')->cascadeOnDelete();
            $table->timestamps();

            // Nomes explícitos e curtos — o nome automático do Laravel pra
            // esta tabela (já com nome composto longo) estouraria os 64
            // caracteres do MySQL nos índices/constraints (mesma classe de
            // problema já documentada repetidamente no projeto).
            $table->unique(['work_id', 'user_id', 'perfil_id'], 'obra_user_perfil_unico');
            $table->index(['work_id', 'user_id'], 'obra_user_perfil_membro_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('obra_user_perfil');
    }
};
