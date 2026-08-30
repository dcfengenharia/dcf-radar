<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 20, Etapa 20.3 — campos aditivos pra Saída física de estoque
 * (`App\Enums\TipoMovimentacaoEstoque::Saida`). `MovimentacaoEstoque`
 * continua sendo a ÚNICA entidade de fato físico (Entrada e Saída) —
 * nenhuma `SaidaEstoque` paralela foi criada (investigação, Seção 4).
 *
 * **Cardinalidade Saída→Reserva (decisão do usuário)**: FK simples
 * `reserva_estoque_id` nullable — cada `MovimentacaoEstoque` de Saída
 * consome NO MÁXIMO uma `ReservaEstoque`. Uma retirada que precise
 * consumir várias Reservas gera N linhas de Saída dentro da MESMA
 * transação (mesmo espírito headless já usado em
 * grd_recolhimentos/movimentacoes de Entrada) — nenhuma entidade de
 * cabeçalho/agrupamento nesta fase. `restrictOnDelete()` — evidência
 * histórica, mesma política de toda FK do projeto.
 *
 * **`item_suprimento_id` nullable (decisão do usuário)**: quando a Saída
 * consome uma Reserva, é sempre DERIVADO dela (nunca pode divergir —
 * validado em `App\Actions\Estoque\RegistrarSaidaEstoque`); sem Reserva,
 * é opcional — ausência nunca bloqueia uma saída emergencial e significa
 * "demanda ainda não conciliada", nunca "sem rastreabilidade definitiva"
 * (a 20.4 poderá completar essa referência sem jamais reescrever esta
 * linha, que é append-only). `restrictOnDelete()`.
 *
 * **`frente_trabalho_id` nullable**: "Frente informada na retirada" —
 * destino informado NAQUELE INSTANTE pelo operador, nunca a aplicação
 * final conciliada (isso é 20.4). Pode divergir livremente da Frente da
 * Destinação/Reserva original (Seção 32 — nunca bloqueado).
 * `restrictOnDelete()`.
 *
 * **`retirado_por`/`retirado_por_externo` (decisão do usuário)**: mesmo
 * par já usado em `restricoes.responsavel_id`/`responsavel_externo` —
 * cobre tanto usuário cadastrado quanto pessoa de campo sem login.
 * `registrado_por` (já existente, Entrada) continua significando
 * exclusivamente "quem lançou no sistema" — nunca reaproveitado como
 * retirante. Sem `autorizado_por` nesta fase (decisão do usuário — só
 * introduzir se houver requisito próprio de autorização formal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->foreignUlid('reserva_estoque_id')->nullable()->after('unidade_estoque_id');
            $table->foreignUlid('item_suprimento_id')->nullable()->after('item_take_off_id');
            $table->foreignUlid('frente_trabalho_id')->nullable()->after('item_suprimento_id');
            $table->foreignUlid('retirado_por')->nullable()->after('registrado_por');
            $table->string('retirado_por_externo')->nullable()->after('retirado_por');
        });

        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->foreign('reserva_estoque_id', 'movimentacoes_estoque_reserva_fk')
                ->references('id')->on('reservas_estoque')
                ->restrictOnDelete();

            $table->foreign('item_suprimento_id', 'movimentacoes_estoque_pacote_fk')
                ->references('id')->on('itens_suprimento')
                ->restrictOnDelete();

            $table->foreign('frente_trabalho_id', 'movimentacoes_estoque_frente_fk')
                ->references('id')->on('frentes_trabalho')
                ->restrictOnDelete();

            $table->foreign('retirado_por', 'movimentacoes_estoque_retirado_fk')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['tenant_id', 'reserva_estoque_id'], 'movimentacoes_estoque_reserva_idx');
            $table->index(['tenant_id', 'item_suprimento_id'], 'movimentacoes_estoque_pacote_idx');
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->dropForeign('movimentacoes_estoque_reserva_fk');
            $table->dropForeign('movimentacoes_estoque_pacote_fk');
            $table->dropForeign('movimentacoes_estoque_frente_fk');
            $table->dropForeign('movimentacoes_estoque_retirado_fk');
            $table->dropIndex('movimentacoes_estoque_reserva_idx');
            $table->dropIndex('movimentacoes_estoque_pacote_idx');
            $table->dropColumn([
                'reserva_estoque_id',
                'item_suprimento_id',
                'frente_trabalho_id',
                'retirado_por',
                'retirado_por_externo',
            ]);
        });
    }
};
