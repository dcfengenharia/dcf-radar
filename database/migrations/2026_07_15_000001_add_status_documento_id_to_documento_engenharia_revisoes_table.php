<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documento_engenharia_revisoes', function (Blueprint $table) {
            $table->foreignUlid('status_documento_id')
                ->nullable()
                ->after('documento_engenharia_id')
                ->constrained('status_documentos_engenharia')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documento_engenharia_revisoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_documento_id');
        });
    }
};
