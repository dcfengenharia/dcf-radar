<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 19, Etapa 19.4 — instância PRÓPRIA do fluxo de compra aplicada a
 * UMA RC (decisão do usuário: NUNCA reaproveita `itens_suprimento_etapas`,
 * que já pertence semanticamente ao mecanismo legado do Pacote — 1
 * Pacote pode ter N RCs, cada uma com seu próprio progresso
 * independente, e o mecanismo legado é 1:1 por Pacote).
 *
 * `etapa_fluxo_suprimento_id` é `restrictOnDelete()`, nullable — só
 * referência de rastreabilidade ao template; os campos `*_snapshot`/
 * `prazo_dias_snapshot` são a fonte real após a emissão (nunca relê o
 * template ao vivo depois).
 *
 * `data_prevista`: calculada via `App\Support\DiasUteisCalculator`
 * (convenção única e não-ambígua do projeto — dias ÚTEIS, nunca
 * corridos) em cadeia a partir da data de emissão da RC, somando
 * `prazo_dias_snapshot` de cada etapa em sequência (mesmo princípio de
 * encadeamento retroativo/progressivo já usado por
 * `App\Services\SuprimentoScheduler`). Congelada na emissão, nunca
 * recalculada depois — mesma filosofia "fotografia" de todo o projeto.
 *
 * `data_realizada` (nullable): só a EVENTOS de conclusão de etapa
 * (`App\Actions\Suprimentos\RegistrarConclusaoEtapaRequisicaoCompra`)
 * pertence essa escrita — nunca ao scheduler/importação de cronograma.
 * Sem série "Tendência" (diferente de `ItemSuprimentoEtapaData`): RC é
 * um processo discreto e formal, não uma curva viva ligada à importação
 * de avanço — 2 datas (`data_prevista`/`data_realizada`) bastam, sem
 * precisar de uma tabela filha própria por série.
 *
 * Status por etapa é SEMPRE derivado (pendente/atrasada/concluída),
 * nunca coluna própria — mesma filosofia de todo o domínio (ver
 * `App\Models\RequisicaoCompraEtapa::status()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisicao_compra_etapas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();

            $table->foreignUlid('requisicao_compra_id');
            $table->foreign('requisicao_compra_id', 'req_compra_etapa_rc_fk')
                ->references('id')->on('requisicoes_compra')
                ->cascadeOnDelete();

            $table->foreignUlid('etapa_fluxo_suprimento_id')->nullable();
            $table->foreign('etapa_fluxo_suprimento_id', 'req_compra_etapa_template_fk')
                ->references('id')->on('etapas_fluxo_suprimento')
                ->restrictOnDelete();

            $table->unsignedInteger('ordem');
            $table->string('nome_snapshot');
            $table->unsignedInteger('prazo_dias_snapshot');
            $table->date('data_prevista');
            $table->date('data_realizada')->nullable();
            $table->foreignUlid('realizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->unique(['requisicao_compra_id', 'ordem'], 'req_compra_etapa_rc_ordem_unique');
            $table->index(['tenant_id', 'requisicao_compra_id'], 'req_compra_etapa_tenant_rc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisicao_compra_etapas');
    }
};
