<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.4 — cabeçalho da Requisição de Compra (RC): filha
 * de EXATAMENTE 1 `ItemSuprimento`/Pacote de Compra (nunca cruza
 * Pacotes — decisão do usuário, sem precedente legado que exigisse
 * diferente), 1 Pacote pode ter N RCs. Schema espelha `requisicoes_planejamento`
 * (19.2)/`grds` (18.5.1) — mesmo fato documental Rascunho→Emitida, mesma
 * numeração via lock em linha estável (Work), mesmo UNIQUE(obra_id,
 * numero) como defesa final.
 *
 * **Decisão do usuário (19.4, investigação fresh)**: o mecanismo legado
 * de `ItemSuprimento` (fluxo_suprimento_id/etapas()/status via
 * SuprimentoScheduler) permanece 100% INTACTO — RC é um domínio NOVO e
 * PARALELO, nunca sincroniza com ele. `item_suprimento_id` é
 * `restrictOnDelete()` — mesma lição de evidência histórica já aplicada
 * em toda a árvore GED/Take Off/RP: uma RC nunca fica órfã por um Pacote
 * apagado (o Observer do Pacote também passa a bloquear exclusão quando
 * há qualquer RC, ver `App\Observers\ItemSuprimentoObserver`).
 *
 * `fluxo_suprimento_id` é só REFERÊNCIA de template (`restrictOnDelete()`,
 * nullable) — a RC reaproveita SOMENTE o cadastro de
 * `FluxoSuprimento`/`EtapaFluxoSuprimento` como template; a instância
 * real das etapas vive em `requisicao_compra_etapas`, congelada na
 * emissão (nunca reaproveita `ItemSuprimentoEtapa`, que já pertence
 * semanticamente ao mecanismo legado do Pacote). `fluxo_nome_snapshot`
 * preserva o nome do fluxo mesmo que o cadastro de template seja
 * renomeado/desativado depois.
 *
 * `status`: Rascunho|Emitida|Concluida (`App\Enums\StatusRequisicaoCompra`).
 * Rascunho→Emitida é ação humana explícita (`EmitirRequisicaoCompra`,
 * mesmo padrão de `EmitirRequisicaoPlanejamento`/`EmitirGrd`).
 * Emitida→Concluida é DERIVADA da progressão real das etapas (nunca um
 * botão arbitrário desconectado da realidade) — transicionada
 * automaticamente pela mesma Action que registra a conclusão da última
 * etapa (`RegistrarConclusaoEtapaRequisicaoCompra`).
 *
 * SoftDeletes: Rascunho livre pra excluir; Emitida/Concluida bloqueadas
 * por Observer (`App\Observers\RequisicaoCompraObserver`, mesmo padrão
 * de `GrdObserver`/`RequisicaoPlanejamentoObserver`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicoes_compra', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->foreignUlid('item_suprimento_id');
            $table->foreign('item_suprimento_id', 'req_compra_pacote_fk')
                ->references('id')->on('itens_suprimento')
                ->restrictOnDelete();

            $table->unsignedInteger('numero')->nullable();
            $table->string('status')->default('rascunho');

            $table->foreignUlid('fluxo_suprimento_id')->nullable();
            $table->foreign('fluxo_suprimento_id', 'req_compra_fluxo_fk')
                ->references('id')->on('fluxos_suprimento')
                ->restrictOnDelete();
            $table->string('fluxo_nome_snapshot')->nullable();

            $table->text('observacao')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('emitida_em')->nullable();
            $table->foreignUlid('emitida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluida_em')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['obra_id', 'numero'], 'req_compra_obra_numero_unique');
            $table->index(['tenant_id', 'item_suprimento_id'], 'req_compra_tenant_pacote_idx');
            $table->index(['tenant_id', 'obra_id', 'status'], 'req_compra_tenant_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicoes_compra');
    }
};
