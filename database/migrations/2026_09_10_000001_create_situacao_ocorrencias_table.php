<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo 21, Etapa 21.3 — decisão arquitetural B (ver CLAUDE.md, seção
 * "Central de Notificações e Estado de Comunicação"): a SITUAÇÃO em si
 * continua 100% derivada (`App\Support\Gestao\SituacoesGerenciaisQuery`,
 * intocada) — esta tabela NUNCA é a fonte da verdade operacional, é só o
 * CICLO DE VIDA da ocorrência (fenômeno detectado → ativo → resolvido →
 * eventualmente reaberto), necessário pra responder "isto já foi visto
 * antes?"/"quando resolveu?"/"quantas vezes reapareceu?" sem precisar de
 * uma segunda fonte de "último estado conhecido" nem de reprocessar
 * histórico. Mesmo padrão já provado em produção por
 * `App\Support\SincronizarRestricaoCadeiaSuprimento` (Ciclo 19.7) pra
 * `Restricao.origem_cadeia_suprimento_id`: 1 linha por fenômeno,
 * `status` alterna Ativa/Resolvida, NUNCA duas linhas pro mesmo fenômeno.
 *
 * `chave_logica` é `App\DTOs\Gestao\SituacaoGerencial::$chaveLogica` —
 * já prefixada pelo tipo (`"material_critico:{...}"`), então
 * `unique(tenant_id, chave_logica)` já é suficiente como identidade
 * global do fenômeno, sem precisar compor com `tipo`/`obra_id`.
 *
 * `episodio` (nunca "vezes_reaberta" como coluna própria — derivado por
 * `episodio - 1`): incrementado só quando uma ocorrência RESOLVIDA volta
 * a ser detectada — é o que resolve a Seção 4 do pedido ("fenômeno ≠
 * ocorrência ≠ comunicação"): a IDENTIDADE do fenômeno (`chave_logica`)
 * nunca muda entre episódios, mas cada episódio tem sua PRÓPRIA janela
 * de comunicação (ver `severidade_peso_comunicado`/idempotência em
 * `SincronizarSituacoesGerenciais`).
 *
 * `severidade_peso_comunicado`: o MAIOR peso de severidade já comunicado
 * dentro do episódio ATUAL — usado só pra decidir se uma escalada de
 * severidade justifica uma NOVA comunicação (Seção 15), nunca pra exibir
 * ao usuário (isso é `severidade_atual`, sempre o valor mais recente
 * derivado, atualizado a cada sincronização mesmo sem gerar comunicação
 * — ex.: uma DESESCALADA atualiza `severidade_atual` sem tocar
 * `severidade_peso_comunicado` nem gerar spam, Seção 16/Teste G).
 *
 * `descricao_atual`/`contexto_atual`: espelham o estado mais recente da
 * derivação — usados só pra exibição/depuração de "por que isto
 * continua ativo", NUNCA confundidos com o SNAPSHOT congelado de uma
 * comunicação específica (esse vive em `notifications.data`, imutável
 * por natureza da tabela nativa do Laravel — Seção 14).
 *
 * Índices desenhados a partir das consultas reais (Seção 26, nunca "pode
 * ajudar"): `unique(tenant_id, chave_logica)` é a identidade; `(obra_id,
 * status)` é a única consulta de ciclo de vida que
 * `SincronizarSituacoesGerenciais` realmente faz ("quais ocorrências
 * desta obra estão Ativas, pra decidir quais resolver").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('situacao_ocorrencias', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->string('tipo');
            $table->string('chave_logica');
            $table->string('status')->default('ativa');
            $table->unsignedInteger('episodio')->default(1);

            $table->string('severidade_atual');
            $table->unsignedTinyInteger('severidade_peso_comunicado');

            $table->string('entidade_tipo');
            $table->string('entidade_id');

            $table->text('descricao_atual');
            $table->json('contexto_atual')->nullable();

            $table->timestamp('primeira_deteccao_em');
            $table->timestamp('ultima_deteccao_em');
            $table->timestamp('resolvida_em')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'chave_logica'], 'situacao_ocorrencias_tenant_chave_unique');
            $table->index(['obra_id', 'status'], 'situacao_ocorrencias_obra_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('situacao_ocorrencias');
    }
};
