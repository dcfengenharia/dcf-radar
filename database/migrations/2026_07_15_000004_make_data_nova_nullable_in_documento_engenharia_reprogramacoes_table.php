<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ->change() exigiria doctrine/dbal (não instalado neste projeto), por
     * isso a alteração de nullable é feita via SQL bruto — mesmo padrão já
     * usado nas migrations de documentos_engenharia/documento_engenharia_
     * revisoes. Permite registrar reprogramação quando o usuário limpa a
     * data de previsão (volta pra null), não só quando troca por outra data.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE documento_engenharia_reprogramacoes MODIFY data_nova DATE NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documento_engenharia_reprogramacoes MODIFY data_nova DATE NOT NULL');
    }
};
