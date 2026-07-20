<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ->change() exigiria doctrine/dbal (não instalado neste projeto),
     * por isso a alteração de nullable é feita via SQL bruto — mesmo
     * padrão já usado na migration de documentos_engenharia.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE documento_engenharia_revisoes MODIFY data_emissao DATE NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documento_engenharia_revisoes MODIFY data_emissao DATE NOT NULL');
    }
};
