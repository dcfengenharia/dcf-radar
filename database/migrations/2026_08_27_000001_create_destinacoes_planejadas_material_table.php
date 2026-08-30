<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.2 — DestinacaoPlanejadaMaterial: quanto da demanda
 * FORMAL de um Material dentro de um Pacote de Compra está planejado
 * para cada FrenteTrabalho. Camada lógica, nunca física — não move
 * saldo, não referencia LocalEstoque/UnidadeEstoque.
 *
 * Origem quantitativa formal (investigação, Seção 5): SUM de
 * `alocacoes_requisicao_pacote.quantidade_alocada` cujo
 * `requisicaoItem.itemTakeOff.material_id` bate com `material_id` desta
 * linha e `item_suprimento_id` bate com o Pacote — nunca uma quantidade
 * nova e independente da cadeia formal (App\Support\Estoque\
 * ConciliacaoDestinacao é quem calcula isso, sempre em lote/derivado,
 * nunca persistido aqui).
 *
 * `frente_trabalho_id` é NOT NULL de propósito — uma Destinação, por
 * definição, aponta pra algum lugar (Seção 26: nunca criar Frente
 * "A definir" fake). A quantidade AINDA NÃO destinada a nenhuma Frente
 * (Seção 13) é sempre a diferença DERIVADA entre o formal e a soma das
 * linhas aqui — nunca uma linha materializada com frente_trabalho_id
 * nulo (evita a armadilha clássica do MySQL de múltiplos NULL "iguais"
 * dentro de um unique, já documentada e evitada em outras tabelas do
 * projeto, ex. inconsistencias_avanco.entidade_id).
 *
 * `unique(tenant_id, item_suprimento_id, material_id, frente_trabalho_id)`
 * — no máximo 1 linha por trinca Pacote×Material×Frente; editar
 * SUBSTITUI o valor (nunca soma uma segunda linha), mesmo padrão de
 * requisicao_planejamento_itens/alocacoes_requisicao_pacote.
 *
 * `item_suprimento_id`/`material_id`/`frente_trabalho_id` são
 * `restrictOnDelete()` — evidência histórica, nunca cascade (mesma
 * política de toda FK do projeto que aponta pra um fato de negócio já
 * registrado). `obra_id` é DENORMALIZADO a partir do Pacote (mesmo
 * padrão de MovimentacaoEstoque.obra_id) — evita join só pra escopar
 * consultas/permissões por obra.
 *
 * Sem SoftDeletes: uma Destinação ainda não referenciada por nenhuma
 * Reserva pode ser excluída/ajustada livremente em UPDATE/DELETE normal
 * (Seção 11) — a proteção contra desaparecer com Reserva vinculada é
 * `App\Observers\DestinacaoPlanejadaMaterialObserver` (guard de domínio,
 * não SoftDeletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destinacoes_planejadas_material', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->foreignUlid('item_suprimento_id');
            $table->foreign('item_suprimento_id', 'destinacoes_planejadas_pacote_fk')
                ->references('id')->on('itens_suprimento')
                ->restrictOnDelete();

            $table->foreignUlid('material_id');
            $table->foreign('material_id', 'destinacoes_planejadas_material_fk')
                ->references('id')->on('materiais')
                ->restrictOnDelete();

            $table->foreignUlid('frente_trabalho_id');
            $table->foreign('frente_trabalho_id', 'destinacoes_planejadas_frente_fk')
                ->references('id')->on('frentes_trabalho')
                ->restrictOnDelete();

            $table->decimal('quantidade_planejada', 14, 3);

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'item_suprimento_id', 'material_id', 'frente_trabalho_id'],
                'destinacoes_planejadas_identidade_unique'
            );
            $table->index(['tenant_id', 'item_suprimento_id', 'material_id'], 'destinacoes_planejadas_formal_idx');
            $table->index(['tenant_id', 'frente_trabalho_id'], 'destinacoes_planejadas_frente_idx');
            $table->index(['tenant_id', 'obra_id'], 'destinacoes_planejadas_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinacoes_planejadas_material');
    }
};
