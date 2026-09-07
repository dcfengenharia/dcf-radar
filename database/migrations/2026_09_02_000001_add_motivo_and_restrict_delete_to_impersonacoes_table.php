<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pré-produção, Etapa 2 (seção 10/11) — fecha 2 lacunas da trilha de
     * auditoria de acesso privilegiado ("Entrar como"), a MESMA
     * infraestrutura já existente (`Impersonacao`/`ImpersonationContext`),
     * nunca um segundo sistema:
     *
     * 1. `motivo` (nullable, texto livre) — o princípio-alvo desta etapa é
     *    "acesso excepcional → identidade conhecida → motivo → timestamp →
     *    tenant/obra → ação auditável". Até aqui a tabela já capturava
     *    identidade/timestamp/tenant/IP, mas nunca o motivo. Opcional (não
     *    bloqueia o suporte em uma emergência) — a UI passa a pedir, sem
     *    impedir tecnicamente quem deixar em branco.
     *
     * 2. `admin_user_id` de `cascadeOnDelete()` pra `restrictOnDelete()` —
     *    achado real da investigação: como `admin_user_id` tinha
     *    `cascadeOnDelete()`, excluir de verdade (forceDelete/hard delete)
     *    um admin da plataforma apagaria junto TODO o histórico de
     *    impersonation dele — o próprio registro que prova "quem acessou
     *    o quê" desapareceria com quem o gerou. Mesma lição já aplicada em
     *    Fotografia O (Ciclo 17, A.9.3.CORREÇÃO): evidência histórica nunca
     *    usa cascade. `tenant_id` permanece cascadeOnDelete — fora de
     *    escopo desta correção (não existe hoje nenhum mecanismo de
     *    exclusão de tenant, ver auditoria da Etapa 1, seção 13).
     */
    public function up(): void
    {
        Schema::table('impersonacoes', function (Blueprint $table) {
            $table->text('motivo')->nullable()->after('tenant_id');
        });

        Schema::table('impersonacoes', function (Blueprint $table) {
            $table->dropForeign('impersonacoes_admin_user_id_foreign');
            $table->foreign('admin_user_id', 'impersonacoes_admin_user_id_foreign')
                ->references('id')->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('impersonacoes', function (Blueprint $table) {
            $table->dropForeign('impersonacoes_admin_user_id_foreign');
            $table->foreign('admin_user_id', 'impersonacoes_admin_user_id_foreign')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });

        Schema::table('impersonacoes', function (Blueprint $table) {
            $table->dropColumn('motivo');
        });
    }
};
