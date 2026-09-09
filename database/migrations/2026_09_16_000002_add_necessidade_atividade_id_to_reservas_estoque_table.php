<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Melhoria "Posto Operacional" — Seção 7 (aprovada nesta rodada, Seção 8
 * ADIADA pro equivalente em AplicacaoMaterialEstoque): coluna nova,
 * nullable, PURO RÓTULO de finalidade — EXATO mesmo papel que
 * `destinacao_planejada_material_id` já tem desde o Ciclo 20.2 ("quando
 * presente, é só rótulo, nunca teto — o teto de Reserva é sempre
 * físico"). Reservar sem esse rótulo continua sendo um estado válido
 * (reserva genérica); reservar COM o rótulo permite ao popup do Plano
 * Semanal mostrar "quanto está reservado especificamente pra esta
 * necessidade desta atividade".
 *
 * NÃO altera nenhuma regra existente de Reserva (autoridade física
 * continua sendo `App\Support\Estoque\SaldoEstoque`/`SaldoReserva`,
 * intocados) — é 100% aditivo, mesmo espírito de quando
 * `destinacao_planejada_material_id` foi adicionado em cima de
 * `item_suprimento_id` (obrigatório) na 20.2.
 *
 * `restrictOnDelete()` — mesma lição de evidência histórica de sempre:
 * uma Reserva já rotulada nunca pode ficar órfã silenciosamente por
 * trás da exclusão da necessidade que ela cumpre (e
 * `AtividadeNecessidadeMaterial` de qualquer forma nunca é
 * hard-deletada enquanto tiver Reserva vinculada — ver
 * `App\Actions\Estoque\AtualizarNecessidadeMaterialAtividade::remover()`).
 *
 * `App\Observers\ReservaEstoqueObserver::updating()` já bloqueia
 * INCONDICIONALMENTE qualquer `save()`/`update()` de instância — a nova
 * coluna já está automaticamente coberta por essa imutabilidade total,
 * nenhuma mudança de código foi necessária no Observer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas_estoque', function (Blueprint $table) {
            $table->foreignUlid('necessidade_atividade_id')->nullable()->after('destinacao_planejada_material_id');
            $table->foreign('necessidade_atividade_id', 'reservas_estoque_necessidade_fk')
                ->references('id')->on('atividade_necessidades_material')
                ->restrictOnDelete();

            $table->index(['tenant_id', 'necessidade_atividade_id'], 'reservas_estoque_necessidade_idx');
        });
    }

    public function down(): void
    {
        Schema::table('reservas_estoque', function (Blueprint $table) {
            $table->dropForeign('reservas_estoque_necessidade_fk');
            $table->dropIndex('reservas_estoque_necessidade_idx');
            $table->dropColumn('necessidade_atividade_id');
        });
    }
};
