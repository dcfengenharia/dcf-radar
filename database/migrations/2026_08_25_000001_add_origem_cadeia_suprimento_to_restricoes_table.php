<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.7 — identidade estrutural DEDICADA da Restrição
 * automática originada da cadeia formal (RP→Pacote→RC→Pedido→Recebimento),
 * decisão explícita do usuário (AskUserQuestion) após investigação: NUNCA
 * reaproveitar `origem_suprimento_item_id` — esse campo já pertence
 * EXCLUSIVAMENTE ao mecanismo legado (`App\Support\SincronizarRestricaoSuprimento`,
 * baseado em `ItemSuprimentoEtapa`/`SuprimentoScheduler`), e nada no
 * domínio impede um Pacote de ter os dois mecanismos ativos ao mesmo
 * tempo — reaproveitar o mesmo campo faria os dois sincronizadores
 * disputarem a mesma linha (um resolve, o outro reabre, thrashing).
 *
 * `origem_cadeia_suprimento_id` — nullable, FK pra `itens_suprimento`,
 * `nullOnDelete()` (mesma política de `origem_suprimento_item_id` —
 * confirmada via `SHOW CREATE TABLE restricoes` antes desta migration:
 * `restricoes_origem_suprimento_item_id_foreign ... ON DELETE SET NULL`).
 *
 * **Identidade lógica = Atividade + Pacote** (decisão explícita do
 * usuário): `UNIQUE(tenant_id, origem_cadeia_suprimento_id, atividade_id)`
 * — mesmo padrão EXATO já usado por `origem_plano_acao_id`
 * (`restricoes_origem_plano_acao_atividade_unique`, Ciclo 11). Garante
 * estruturalmente, a nível de banco, no máximo 1 linha (histórica —
 * NUNCA soft-deletada por este mecanismo, ver `App\Support\
 * SincronizarRestricaoCadeiaSuprimento`) por par — o sincronizador
 * SEMPRE reabre a mesma linha (`status: Resolvida -> Aberta`), nunca
 * cria uma segunda pro mesmo par, mesmo mecanismo de reabertura já usado
 * pelo legado (`SincronizarRestricaoSuprimento::abrirOuAtualizar()`).
 * MySQL nunca colide `NULL` consigo mesmo num índice UNIQUE — Restrições
 * manuais e as de outras origens (`origem_cadeia_suprimento_id = null`
 * sempre) continuam livres pra coexistir na mesma Atividade, exatamente
 * como já documentado pro par `origem_suprimento_item_id`/
 * `origem_plano_acao_id`.
 *
 * Nome de constraint curto e explícito (`restricao_origem_cadeia_sup_unique`)
 * — o nome automático do Laravel pra essa combinação de colunas estoura
 * os 64 caracteres do MySQL (mesma classe de problema já documentada
 * repetidamente neste projeto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->foreignUlid('origem_cadeia_suprimento_id')->nullable();
            $table->foreign('origem_cadeia_suprimento_id', 'restricao_origem_cadeia_sup_fk')
                ->references('id')->on('itens_suprimento')
                ->nullOnDelete();

            $table->unique(['tenant_id', 'origem_cadeia_suprimento_id', 'atividade_id'], 'restricao_origem_cadeia_sup_unique');
        });
    }

    public function down(): void
    {
        Schema::table('restricoes', function (Blueprint $table) {
            $table->dropUnique('restricao_origem_cadeia_sup_unique');
            $table->dropForeign('restricao_origem_cadeia_sup_fk');
            $table->dropColumn('origem_cadeia_suprimento_id');
        });
    }
};
