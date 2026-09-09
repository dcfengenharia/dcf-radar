<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Melhoria "Posto Operacional" — proveniência do CADASTRO do Material
 * Mestre (`App\Enums\OrigemCadastroMaterial`). Fresh-read confirmou que
 * nenhum mecanismo existente (HasAuthorship só grava `created_by_id`;
 * não há Policy/Action dedicada; `AtividadeNecessidadeMaterial.origem`
 * já resolve a proveniência da NECESSIDADE, nunca do Material em si)
 * respondia "onde/como este cadastro mestre nasceu" — mesma lacuna que
 * `App\Enums\OrigemItemTakeOff` já fecha pra `itens_take_off`, cujo
 * padrão (`string('origem')->default('manual')`) é reaproveitado aqui
 * literalmente.
 *
 * `default('catalogo')` cobre TODO o histórico existente sem backfill —
 * todo Material já cadastrado até hoje só nasceu pela tela de Estoque
 * (`⚡estoque.blade.php::salvarMaterial()`), então "Catálogo" é
 * factualmente correto pra ele, nunca um valor arbitrário.
 *
 * Deliberadamente SEM nenhuma FK nova (nem obra_id, nem atividade_id)
 * — decisão explícita do pedido ("preferência: não colocar FKs
 * operacionais desnecessárias no Material Mestre apenas para
 * auditoria"): Material continua 100% tenant-wide, nunca obra-specific.
 * "Quem"/"quando" já são cobertos de graça por `created_by_id`
 * (HasAuthorship) + `created_at`; "qual obra/atividade motivou a
 * criação" fica implicitamente recuperável, quando a necessidade
 * chega a ser de fato salva, via a própria `AtividadeNecessidadeMaterial`
 * criada logo em seguida (que já carrega `obra_id`/`atividade_id` e
 * `origem=operacional`) — nunca duplicado aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materiais', function (Blueprint $table) {
            $table->string('origem_cadastro')->default('catalogo')->after('modo_rastreabilidade');
        });
    }

    public function down(): void
    {
        Schema::table('materiais', function (Blueprint $table) {
            $table->dropColumn('origem_cadastro');
        });
    }
};
