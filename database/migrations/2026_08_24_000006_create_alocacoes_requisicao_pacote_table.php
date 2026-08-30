<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.3 — alocação quantitativa de um
 * `RequisicaoPlanejamentoItem` (só de RP `Emitida`, validado na Action,
 * nunca no schema) a um `ItemSuprimento`/Pacote de Compra. `ItemSuprimento`
 * NÃO virou uma tabela nova — evolui conceitualmente pra "Pacote de
 * Compra" preservando PK/ULID/vínculos existentes (decisão do produto,
 * confirmada pela investigação: hoje já não tem quantidade/unidade
 * própria, já é N:N com Atividade/Documento — é estruturalmente um
 * coordenador de workflow, não uma linha de material).
 *
 * As duas FKs são `restrictOnDelete()` — mesma lição de evidência
 * histórica já aplicada em toda a árvore GED/Take Off/RP do projeto:
 * uma alocação nunca deve ficar órfã por um DELETE físico silencioso de
 * nenhum dos dois lados. `unique(requisicao_planejamento_item_id,
 * item_suprimento_id)` — no máximo 1 linha por par RPItem×Pacote (mesmo
 * padrão de `requisicao_planejamento_itens`: editar SUBSTITUI o valor,
 * nunca soma uma segunda linha — elimina "contar a própria linha duas
 * vezes" por construção).
 *
 * Sem SoftDeletes (decisão desta etapa, registrada no CLAUDE.md): antes
 * de existir Requisição de Compra (19.4+), uma alocação é só um estado
 * de planejamento/coordenação — remover fisicamente o vínculo é
 * aceitável, mesmo padrão de `RequisicaoPlanejamentoItem` (que também
 * não tem SoftDeletes própria).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alocacoes_requisicao_pacote', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            // Nomes de constraint explícitos e curtos — o nome automático do
            // Laravel pra esta coluna estoura os 64 chars do MySQL (mesma
            // classe de problema já documentada no projeto, ex.:
            // cronograma_importacao_health_checks).
            $table->foreignUlid('requisicao_planejamento_item_id');
            $table->foreign('requisicao_planejamento_item_id', 'alocacao_rp_item_fk')
                ->references('id')->on('requisicao_planejamento_itens')
                ->restrictOnDelete();

            $table->foreignUlid('item_suprimento_id');
            $table->foreign('item_suprimento_id', 'alocacao_pacote_fk')
                ->references('id')->on('itens_suprimento')
                ->restrictOnDelete();

            $table->decimal('quantidade_alocada', 14, 3);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['requisicao_planejamento_item_id', 'item_suprimento_id'], 'alocacao_rp_item_pacote_unique');
            $table->index(['tenant_id', 'item_suprimento_id'], 'alocacao_tenant_pacote_idx');
            $table->index(['tenant_id', 'requisicao_planejamento_item_id'], 'alocacao_tenant_rp_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alocacoes_requisicao_pacote');
    }
};
