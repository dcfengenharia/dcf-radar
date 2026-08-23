<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ciclo 17, A.9.5 — Fotografia P: torna explícita a vigência temporal de
     * uma versão de ProgramacaoSemanal. Antes desta coluna, "qual versão
     * valia num instante histórico X" só era dedutível combinando `versao`
     * (mais alta) com `congelada_em` (crescente) sob a suposição de que
     * `App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal` nunca
     * cria uma revisão de uma versão ainda Aberta (garantido só pela
     * aplicação, nunca pelo banco) — decisão do usuário: tornar essa
     * garantia uma coluna real, não uma dedução sobre dados existentes.
     *
     * `superseded_at` fica `NULL` enquanto a versão é a mais recente da
     * semana; é carimbado (`now()`, mesma transação) quando uma revisão
     * dela é criada — nunca em nenhum outro momento (fechar/reabrir uma
     * programação não mexe aqui, só "ser substituída por uma revisão"
     * mexe). Nullable, sem backfill: versões já revisadas ANTES desta
     * migration ficam com `superseded_at = NULL` pra sempre — mesmo
     * princípio de todas as fotografias do Ciclo 17 ("confiança começa só
     * a partir desta fase", nunca inventar um instante histórico que
     * ninguém registrou de verdade). Ver `ProgramacaoSemanal::vigenteEm()`.
     */
    public function up(): void
    {
        Schema::table('programacoes_semanais', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->after('congelada_em');
        });
    }

    public function down(): void
    {
        Schema::table('programacoes_semanais', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
