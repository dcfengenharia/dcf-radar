<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.9 — evidência histórica e imutável do
     * aceite/assinatura de recebimento de UM `GrdDestinatario` (todos os
     * itens que aquele destinatário recebeu naquela GRD — mesma unidade
     * já usada pelo Comprovante de Entrega, 18.5.8). NÃO é assinatura
     * digital ICP-Brasil, nem tem valor jurídico qualificado — é uma
     * assinatura manuscrita capturada em tela (ou um aceite simples sem
     * assinatura) com evidência de quem/quando registrou.
     *
     * Cardinalidade — decisão explícita do usuário (18.5.9): NO MÁXIMO 1
     * aceite ATIVO por `grd_destinatario_id` a qualquer momento, mas o
     * histórico pode acumular vários (os invalidados nunca são apagados
     * nem alterados em conteúdo). Correção de erro é SEMPRE um evento
     * novo (invalidar o atual + registrar outro), nunca um update
     * destrutivo do aceite errado.
     *
     * **`ativo_unico_destinatario` — garantia ESTRUTURAL no banco, não só
     * `exists()` na aplicação** (instrução explícita do usuário: "não
     * confie em unique envolvendo coluna nullable sem analisar a
     * semântica de NULL do MySQL"). Coluna `STORED GENERATED`:
     * `CASE WHEN invalidado_em IS NULL THEN grd_destinatario_id ELSE NULL END`.
     * MySQL trata cada NULL como DISTINTO em um índice UNIQUE (múltiplos
     * NULL convivem livremente) — então quantos aceites INVALIDADOS
     * quiser por destinatário (todos geram NULL nesta coluna), mas o
     * SEGUNDO INSERT com `invalidado_em IS NULL` pro MESMO destinatário
     * colide de verdade (both geram o mesmo `grd_destinatario_id` não-nulo)
     * e é REJEITADO pelo próprio banco — inclusive sob concorrência
     * genuína (2 processos inserindo ao mesmo tempo), sem depender de
     * nenhum lock de aplicação. **Verificado empiricamente** antes desta
     * migration: 1ª inserção ativa OK, 2ª inserção ativa pro mesmo
     * destinatário -> `ERROR 1062 Duplicate entry`; 2 inserções
     * invalidadas pro mesmo destinatário -> ambas OK, coexistindo.
     *
     * `grd_destinatario_id` é `restrictOnDelete()` — mesma lição de
     * proteção de evidência histórica já usada em toda a árvore GED
     * (Fotografia O, `grd_recolhimentos.grd_distribuicao_id`, etc.): um
     * aceite já registrado nunca pode desaparecer como efeito colateral
     * de apagar o destinatário que ele documenta.
     *
     * `token` — identificador público ALEATÓRIO e persistido pro QR Code
     * de verificação (`App\Http\Controllers\GrdVerificacaoPublicaController`).
     * Deliberadamente NÃO uma signed/temporary URL do Laravel: um QR
     * impresso e arquivado fisicamente precisa continuar verificável
     * mesmo anos depois, e uma signed URL fica permanentemente inválida
     * se `APP_KEY` rotacionar — o mesmo risco não existe pra um token
     * persistido comparado por igualdade simples no banco. O token
     * identifica O REGISTRO (a linha histórica específica), nunca "o
     * destinatário" — um aceite invalidado continua resolvendo pelo seu
     * próprio token antigo, mostrando "Registro invalidado" (nunca 404).
     *
     * `assinatura_hash` (SHA-256) — checksum de INTEGRIDADE do arquivo,
     * nunca assinatura digital/certificado. Detecta alteração física dos
     * bytes do PNG depois de gravado; não confere validade jurídica
     * nenhuma.
     */
    public function up(): void
    {
        Schema::create('grd_aceites_entrega', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('grd_destinatario_id')
                ->constrained('grd_destinatarios')
                ->restrictOnDelete();
            $table->string('nome_recebedor_snapshot');
            $table->string('empresa_snapshot')->nullable();
            $table->string('setor_snapshot')->nullable();
            $table->string('tipo_aceite');
            $table->string('assinatura_path')->nullable();
            $table->string('assinatura_hash', 64)->nullable();
            $table->string('token', 48)->unique();
            $table->foreignUlid('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ocorrido_em');
            $table->text('observacao')->nullable();
            $table->timestamp('invalidado_em')->nullable();
            $table->foreignUlid('invalidado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo_invalidacao')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'grd_destinatario_id'], 'grd_aceites_entrega_tenant_dest_idx');
        });

        // storedAs() do Laravel não cobre CASE/coluna-a-partir-de-outra-coluna
        // de forma portável entre grammars — adicionada via SQL cru,
        // documentado e verificado empiricamente acima. Mesma convenção já
        // aceita no projeto pra construções que a fluent API não cobre
        // (ex.: whereRaw/selectRaw em várias queries GRD/Health Check).
        DB::statement(
            'ALTER TABLE grd_aceites_entrega '
            . 'ADD COLUMN ativo_unico_destinatario CHAR(26) '
            . 'GENERATED ALWAYS AS (CASE WHEN invalidado_em IS NULL THEN grd_destinatario_id ELSE NULL END) STORED, '
            . 'ADD UNIQUE KEY grd_aceites_entrega_ativo_unico (ativo_unico_destinatario)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('grd_aceites_entrega');
    }
};
