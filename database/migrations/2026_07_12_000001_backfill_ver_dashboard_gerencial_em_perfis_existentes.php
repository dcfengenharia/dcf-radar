<?php

use App\Models\Perfil;
use App\Models\PerfilPermissao;
use Illuminate\Database\Migrations\Migration;

/**
 * Nova funcionalidade "dashboard.gerencial" adicionada ao catálogo —
 * `Perfil::seedPadrao()` já concede "ver" nela pra tenants criados a
 * partir de agora (mesmo loop universal de "ver" que cobre toda
 * funcionalidade), mas perfis que já existiam antes desta migration não
 * ganham isso retroativamente sozinhos. Preserva o mesmo princípio já
 * usado nas outras migrations de backfill desta feature: "ver" é
 * universal, ninguém deveria perder acesso a uma tela nova por causa da
 * ordem de deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Perfil::withTrashed()->get(['id', 'tenant_id'])->each(function (Perfil $perfil) {
            PerfilPermissao::firstOrCreate([
                'perfil_id' => $perfil->id,
                'funcionalidade' => 'dashboard.gerencial',
                'acao' => 'ver',
            ], [
                'tenant_id' => $perfil->tenant_id,
            ]);
        });
    }

    public function down(): void
    {
        PerfilPermissao::where('funcionalidade', 'dashboard.gerencial')->delete();
    }
};
