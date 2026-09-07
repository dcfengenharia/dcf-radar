<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 23, Etapa 23.5.B (Seção 10-14) — avaliações da reaplicação são
 * **append-only**, nunca uma avaliação única congelada (decisão
 * explícita do pedido, alterando o desenho original do fresh-read): uma
 * reaplicação pode receber N avaliações ao longo do tempo (ex.:
 * "parcial" em 20/09, "positivo" em 15/10) — a mais recente NUNCA apaga
 * nem altera a anterior, ambas continuam navegáveis no histórico.
 *
 * `resultado` é o enum fechado `App\Enums\ResultadoAvaliacaoReaplicacao`
 * (Positivo|Parcial|Negativo|NaoAplicavel) — **nunca** um valor
 * "AguardandoAvaliacao" gravado aqui (Seção 11): ausência de QUALQUER
 * linha nesta tabela pra uma reaplicação já É "aguardando avaliação",
 * um estado puramente derivado, nunca persistido.
 *
 * `reaplicacao_id` é `restrictOnDelete()` (evidência histórica, mesmo
 * idioma repetido em todo o projeto) — ainda que a reaplicação nunca
 * seja de fato excluída (bloqueio estrutural no Observer da própria
 * reaplicação), a avaliação nunca deveria desaparecer por cascade.
 *
 * `avaliado_por_id`/`avaliado_em` são os nomes de domínio explícitos
 * pedidos (Seção 13) — sempre carimbados pela própria
 * `App\Actions\LicoesAprendidas\AvaliarReaplicacaoLicao` no momento do
 * registro (nunca editáveis pelo usuário, sem suporte a backdating
 * nesta etapa — equivalentes a `created_at` na prática, mas mantidos
 * como campos de domínio nomeados pra bater com a linguagem do pedido
 * e sobreviver a uma eventual extensão futura). "Resultado atual" (ver
 * `LicaoAprendidaReaplicacao::resultadoAtual()`) usa `created_at`/`id`
 * como critério de desempate determinístico — nunca `avaliado_em` — pra
 * seguir a MESMA convenção já estabelecida no projeto inteiro
 * ("ordem operacional é sempre a ordem de REGISTRO, nunca a data
 * informada", `GrdDistribuicao::estado()`/`PedidoCompraItem::
 * dataConclusaoRecebimento()`), mesmo não havendo backdating hoje.
 *
 * Bloqueio estrutural de update/delete em
 * `App\Observers\LicaoAprendidaReaplicacaoAvaliacaoObserver` — correção
 * é sempre uma NOVA avaliação, nunca editar/apagar a anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licao_aprendida_reaplicacao_avaliacoes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('reaplicacao_id')->constrained('licao_aprendida_reaplicacoes')->restrictOnDelete();

            $table->string('resultado');
            $table->foreignUlid('avaliado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('avaliado_em');
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'reaplicacao_id'], 'licao_reaplicacao_aval_tenant_reap_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licao_aprendida_reaplicacao_avaliacoes');
    }
};
