<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.2 — ReservaEstoque: comprometimento FÍSICO de parte
 * do saldo (App\Support\Estoque\SaldoEstoque, já existente e intocado)
 * com uma finalidade. NUNCA é MovimentacaoEstoque — reservar não move
 * saldo físico, só reduz saldo DISPONÍVEL (Seção 21, testado).
 *
 * Granularidade física — mesma tripla denormalizada já usada em
 * MovimentacaoEstoque (Seção 16 da investigação: uma única estrutura
 * cobre os 3 modos de rastreabilidade, nunca 3 schemas separados):
 * `material_id` + `local_estoque_id` sempre presentes;
 * `unidade_estoque_id` nullable — null pro modo Quantitativo (saldo por
 * Material+Local), preenchido pros modos Lote/Serializado (saldo por
 * bobina/serial específico), exatamente como
 * App\Actions\Estoque\RegistrarEntradaEstoque já resolve na entrada.
 *
 * `destinacao_planejada_material_id` é NULLABLE de propósito (Seção 25):
 * uma Reserva pode existir comprometendo estoque físico pra um
 * Pacote/Material em geral, ANTES de o Planejamento detalhar qual
 * Frente recebe o quê — nunca inventamos uma Frente "A definir" fake
 * (Seção 26) só pra satisfazer NOT NULL aqui. Quando presente, é só
 * RÓTULO/finalidade (nunca teto de reserva — o teto de reserva é sempre
 * físico, Material+Local/Unidade, independente de Destinação).
 *
 * `material_id`/`local_estoque_id`/`unidade_estoque_id`/
 * `destinacao_planejada_material_id` são `restrictOnDelete()` —
 * evidência histórica (mesma política de sempre). `obra_id` denormalizado
 * a partir de `local_estoque_id.obra_id` (mesmo padrão de
 * MovimentacaoEstoque.obra_id).
 *
 * `status` (App\Enums\StatusReservaEstoque) + `liberado_em`/
 * `liberado_por`/`motivo_liberacao`: liberação é transição ÚNICA e
 * irreversível registrada NA PRÓPRIA linha (mesmo padrão simples de
 * GrdAceiteEntrega.invalidado_em/invalidado_por/motivo_invalidacao,
 * Ciclo 18) — nunca DELETE (Seção 22: "não usar delete como
 * liberação"), preservando histórico sem precisar de uma tabela de
 * eventos separada pra uma transição que só acontece 1 vez na vida do
 * registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservas_estoque', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->foreignUlid('material_id');
            $table->foreign('material_id', 'reservas_estoque_material_fk')
                ->references('id')->on('materiais')
                ->restrictOnDelete();

            $table->foreignUlid('local_estoque_id');
            $table->foreign('local_estoque_id', 'reservas_estoque_local_fk')
                ->references('id')->on('locais_estoque')
                ->restrictOnDelete();

            $table->foreignUlid('unidade_estoque_id')->nullable();
            $table->foreign('unidade_estoque_id', 'reservas_estoque_unidade_fk')
                ->references('id')->on('unidades_estoque')
                ->restrictOnDelete();

            $table->foreignUlid('destinacao_planejada_material_id')->nullable();
            $table->foreign('destinacao_planejada_material_id', 'reservas_estoque_destinacao_fk')
                ->references('id')->on('destinacoes_planejadas_material')
                ->restrictOnDelete();

            $table->decimal('quantidade', 14, 3);
            $table->string('status')->default('ativa');

            $table->timestamp('liberado_em')->nullable();
            $table->foreignUlid('liberado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_liberacao')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'material_id', 'local_estoque_id', 'status'], 'reservas_estoque_mat_local_status_idx');
            $table->index(['tenant_id', 'unidade_estoque_id', 'status'], 'reservas_estoque_unidade_status_idx');
            $table->index(['tenant_id', 'destinacao_planejada_material_id'], 'reservas_estoque_destinacao_idx');
            $table->index(['tenant_id', 'obra_id'], 'reservas_estoque_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas_estoque');
    }
};
