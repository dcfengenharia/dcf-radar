<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.5.B — evidência explícita de que uma lição
 * Publicada foi conscientemente REAPLICADA em outra obra. Unidade
 * corporativa fechada (Decisão/Seção 3 do pedido): **Lição × Obra**,
 * nunca por Atividade/uso individual — 30 atividades da mesma obra
 * usando a mesma recomendação continuam sendo, pra inteligência
 * corporativa, "uma lição reaplicada em uma obra".
 *
 * `UNIQUE(tenant_id, licao_aprendida_id, obra_id)` é a garantia
 * ESTRUTURAL (nunca só `firstOrCreate()`/checagem em PHP) — dupla
 * submissão/double-click é resolvida pelo próprio banco
 * (`App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao` captura
 * `QueryException` 1062, mesmo idioma já usado em
 * `VincularEntidadeALicao`/`PlanoAcao::transformarEmRestricoes()`).
 *
 * `licao_aprendida_id`/`obra_id` são `restrictOnDelete()` — mesma lição
 * de evidência histórica repetida em toda a árvore GED/GRD/Estoque deste
 * projeto: uma reaplicação registrada nunca pode desaparecer só porque a
 * lição foi arquivada (arquivamento é soft-delete + transição de status,
 * nunca `forceDelete()`) ou a obra foi removida.
 *
 * Identidade imutável após criada (Seção 6): tenant/lição/obra/quem
 * registrou (`created_by_id`, via `HasAuthorship`)/quando registrou
 * (`created_at` nativo — nunca um campo `registrado_em` duplicado, já
 * que o registro é sempre "agora", sem suporte a backdating nesta
 * etapa). `observacao_inicial` faz parte dessa MESMA identidade
 * imutável — nunca editável depois de criada; se a equipe quiser
 * complementar a nota depois, isso é o papel de uma NOVA avaliação
 * (append-only, tabela irmã), nunca uma edição da observação original.
 * Bloqueio estrutural de update/delete em
 * `App\Observers\LicaoAprendidaReaplicacaoObserver`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licao_aprendida_reaplicacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('licao_aprendida_id')->constrained('licoes_aprendidas')->restrictOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->restrictOnDelete();

            $table->text('observacao_inicial')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'licao_aprendida_id', 'obra_id'], 'licao_reaplicacoes_tenant_licao_obra_unique');
            $table->index(['tenant_id', 'obra_id'], 'licao_reaplicacoes_tenant_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licao_aprendida_reaplicacoes');
    }
};
