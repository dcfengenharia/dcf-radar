<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 18, Etapa 18.5.1 — cadastro de destinatário de GRD (pessoa,
     * equipe, setor, local físico, fiscalização, cliente, subcontratada).
     * Obra-scoped (decisão de produto 18.5.0/18.5.1 — não tenant-wide),
     * mesmo nível de simplicidade de Fornecedor/EquipeResponsavel: sem
     * polimorfismo, `user_id` nullable cobre o caso "é um usuário do
     * sistema", os campos de texto cobrem qualquer outro caso (mesmo
     * precedente de Restricao.responsavel_id + responsavel_externo).
     *
     * SoftDeletes: desativação sem perder histórico (mesmo padrão de
     * Fornecedor/EquipeResponsavel) — GrdDestinatario congela nome/
     * empresa/setor no momento da emissão, então soft-deletar um
     * Destinatario nunca corrompe uma GRD já emitida.
     */
    public function up(): void
    {
        Schema::create('destinatarios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nome');
            $table->string('empresa')->nullable();
            $table->string('setor')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'obra_id'], 'destinatarios_tenant_obra_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinatarios');
    }
};
