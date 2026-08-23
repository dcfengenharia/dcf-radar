<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.3 — histórico append-only de liberação/revogação
     * para construção de uma DocumentoEngenhariaRevisao.
     *
     * Decisão de modelagem (documentada em detalhe no relatório da Etapa
     * 18.3): investigação confirmou que uma DocumentoEngenhariaRevisao
     * NUNCA é editada depois de criada (nenhum fluxo de "editarRevisao()"
     * existe hoje — cada correção sempre nasce como revisão nova). Um
     * simples par de colunas `liberada_para_construcao`/`_em`/`_por`
     * DIRETO na revisão perderia evidência no primeiro ciclo
     * liberar→revogar→liberar de novo (só sobraria o último estado). Por
     * isso a fonte de verdade é este log — a revisão NUNCA guarda um
     * campo `liberada_para_construcao` próprio; ela sempre deriva do
     * ÚLTIMO evento aqui (`DocumentoEngenhariaRevisao::estaLiberadaParaConstrucao()`,
     * via relação ofMany — mesmo padrão de `DocumentoEngenharia::
     * latestRevisao()`). Nome curto de propósito (mesma razão já
     * documentada em outras tabelas do projeto): "revisao_liberacoes" +
     * sufixo automático de FK/índice do Laravel poderia se aproximar do
     * limite de 64 caracteres do MySQL — por isso os nomes de constraint
     * abaixo são explícitos.
     */
    public function up(): void
    {
        Schema::create('revisao_liberacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('revisao_id')->constrained('documento_engenharia_revisoes')->cascadeOnDelete();
            $table->boolean('liberada_para_construcao');
            $table->foreignUlid('alterado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ocorrido_em');
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revisao_id'], 'revlib_tenant_revisao_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisao_liberacoes');
    }
};
