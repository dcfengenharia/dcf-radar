<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Mapa dos 3 valores antigos do enum StatusDocumentoEngenharia pros
     * status reais que serão semeados por obra na migração de dados —
     * preserva o "conclusivo" equivalente ao antigo Concluido.
     */
    private array $mapaStatusAntigo = [
        'em_elaboracao' => ['nome' => 'Em Elaboração', 'ordem' => 1, 'conclusivo' => false],
        'aprovado' => ['nome' => 'Aprovado', 'ordem' => 2, 'conclusivo' => false],
        'concluido' => ['nome' => 'Concluído', 'ordem' => 3, 'conclusivo' => true],
    ];

    public function up(): void
    {
        Schema::table('documentos_engenharia', function (Blueprint $table) {
            // obra_id direto — antes só existia transitivamente via
            // pacote_engenharia_id, que agora vira opcional; sem uma coluna
            // própria não haveria como saber de qual obra é um documento
            // sem pacote nem status atribuído.
            $table->foreignUlid('obra_id')->nullable()->after('pacote_engenharia_id')->constrained('works')->cascadeOnDelete();
            $table->string('codigo')->nullable()->after('obra_id');
            $table->string('descricao')->nullable()->after('codigo');
            $table->foreignUlid('disciplina_id')->nullable()->after('descricao')->constrained('disciplinas')->nullOnDelete();
            $table->foreignUlid('status_documento_id')->nullable()->after('status')->constrained('status_documentos_engenharia')->nullOnDelete();
            $table->softDeletes();
        });

        $this->backfillObraDescricaoEStatus();

        Schema::table('documentos_engenharia', function (Blueprint $table) {
            $table->dropColumn(['nome', 'status']);
        });

        // pacote_engenharia_id vira opcional — apagar um Pacote não deve
        // mais apagar em cascata os documentos, só desvincular. obra_id
        // vira obrigatório (todo documento existente já foi backfillado
        // a partir do pacote). ->change() exigiria doctrine/dbal (não
        // instalado neste projeto), por isso as duas alterações de
        // nullability são feitas via SQL bruto.
        DB::statement('ALTER TABLE documentos_engenharia MODIFY pacote_engenharia_id CHAR(26) NULL');
        DB::statement('ALTER TABLE documentos_engenharia MODIFY obra_id CHAR(26) NOT NULL');

        Schema::table('documentos_engenharia', function (Blueprint $table) {
            $table->dropForeign(['pacote_engenharia_id']);
            $table->foreign('pacote_engenharia_id')->references('id')->on('pacotes_engenharia')->nullOnDelete();

            $table->index(['tenant_id', 'obra_id']);
        });
    }

    private function backfillObraDescricaoEStatus(): void
    {
        $statusIdPorObraNome = [];

        foreach (DB::table('documentos_engenharia')->get() as $documento) {
            $obraId = DB::table('pacotes_engenharia')->where('id', $documento->pacote_engenharia_id)->value('obra_id');

            $dados = ['descricao' => $documento->nome, 'obra_id' => $obraId];

            if ($obraId) {
                $infoStatus = $this->mapaStatusAntigo[$documento->status] ?? $this->mapaStatusAntigo['em_elaboracao'];
                $chave = $obraId . '|' . $infoStatus['nome'];

                if (!isset($statusIdPorObraNome[$chave])) {
                    $statusIdPorObraNome[$chave] = $this->obterOuCriarStatus($obraId, $documento->tenant_id, $infoStatus['nome']);
                }

                $dados['status_documento_id'] = $statusIdPorObraNome[$chave];
            }

            DB::table('documentos_engenharia')->where('id', $documento->id)->update($dados);
        }
    }

    private function obterOuCriarStatus(string $obraId, string $tenantId, string $nome): string
    {
        $existente = DB::table('status_documentos_engenharia')->where('obra_id', $obraId)->where('nome', $nome)->value('id');
        if ($existente) {
            return $existente;
        }

        // Primeira vez que vemos essa obra: semeia os 3 status padrão de uma vez.
        foreach ($this->mapaStatusAntigo as $info) {
            $jaExiste = DB::table('status_documentos_engenharia')->where('obra_id', $obraId)->where('nome', $info['nome'])->exists();
            if ($jaExiste) {
                continue;
            }

            DB::table('status_documentos_engenharia')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'obra_id' => $obraId,
                'nome' => $info['nome'],
                'ordem' => $info['ordem'],
                'conclusivo' => $info['conclusivo'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return DB::table('status_documentos_engenharia')->where('obra_id', $obraId)->where('nome', $nome)->value('id');
    }

    public function down(): void
    {
        Schema::table('documentos_engenharia', function (Blueprint $table) {
            $table->string('nome')->nullable();
            $table->string('status')->default('em_elaboracao');
        });

        DB::table('documentos_engenharia')->update(['nome' => DB::raw('descricao')]);

        Schema::table('documentos_engenharia', function (Blueprint $table) {
            $table->dropForeign(['status_documento_id']);
            $table->dropForeign(['disciplina_id']);
            $table->dropForeign(['obra_id']);
            $table->dropColumn(['obra_id', 'codigo', 'descricao', 'disciplina_id', 'status_documento_id', 'deleted_at']);

            $table->dropForeign(['pacote_engenharia_id']);
        });

        DB::statement('ALTER TABLE documentos_engenharia MODIFY pacote_engenharia_id CHAR(26) NOT NULL');

        Schema::table('documentos_engenharia', function (Blueprint $table) {
            $table->foreign('pacote_engenharia_id')->references('id')->on('pacotes_engenharia')->cascadeOnDelete();
        });
    }
};
