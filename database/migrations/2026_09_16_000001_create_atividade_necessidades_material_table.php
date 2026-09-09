<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Melhoria "Posto Operacional" — arquitetura B (híbrida) aprovada pelo
 * usuário: `AtividadeNecessidadeMaterial` é a fonte autoritativa da
 * pergunta "quanto desta matéria-prima/material esta atividade
 * necessita?" — NUNCA participa da soma da cadeia já existente
 * (ItemTakeOff → RequisicaoPlanejamentoItem → AlocacaoRequisicaoPacote →
 * RequisicaoCompra/Pedido → Recebimento), que continua intocada.
 *
 * **Duas origens, mutuamente exclusivas, garantidas em 2 camadas**
 * (Action, mais abaixo, E constraint de banco, aqui): `origem=take_off`
 * exige `item_take_off_id` preenchido e `material_id` SEMPRE nulo (o
 * Material é sempre derivado via `$necessidade->itemTakeOff->material_id`,
 * nunca copiado — fecha o único vetor real de divergência que esta
 * tabela poderia introduzir, já que `ItemTakeOff.material_id` pode ser
 * reassociado enquanto não travado por uso downstream, ver
 * `App\Support\Estoque\PoliticaAssociacaoMaterial`); `origem=operacional`
 * exige o inverso (`material_id` preenchido, `item_take_off_id` nulo) —
 * necessidade declarada em campo, sem base documental de Engenharia.
 *
 * **Unidade — ambiguidade fechada explicitamente** (achado do fresh-read:
 * `ItemTakeOff.unidade_medida_id` pode divergir da unidade canônica do
 * Material associado, nunca validado/convertido hoje): esta tabela
 * SEMPRE grava a própria `unidade_medida_id` em que `quantidade_necessaria`
 * foi declarada — nunca inferida silenciosamente depois. Para
 * `origem=take_off`, o valor de autoridade é a unidade do próprio
 * ItemTakeOff (congelada no momento da distribuição, nunca relida ao
 * vivo depois — mesma filosofia de "snapshot no momento", já usada em
 * toda a árvore GRD/Pedido/RC deste projeto); para `origem=operacional`,
 * o valor de autoridade é a unidade canônica do Material selecionado.
 * A comparação "esta unidade bate com a unidade que o Estoque usa pra
 * este Material" (`Material.unidade_medida_id`) é feita em TEMPO DE
 * LEITURA pela query de cobertura — nunca aqui, nunca convertida — se
 * divergir, a cobertura simplesmente não é calculada (estado
 * `UnidadeIncompativel`).
 *
 * **Distribuição do TakeOff — mesma disciplina já validada em
 * `DestinacaoPlanejadaMaterial`/`AtualizarDestinacaoPlanejada` (Ciclo
 * 20.2), só trocando Frente por Atividade e a origem da quantidade
 * formal (Alocação → ItemTakeOff diretamente, decisão do usuário: a
 * Produção precisa enxergar a necessidade por atividade ANTES mesmo de
 * Suprimentos ter processado RP/RC/Pedido daquele Pacote)**:
 * `saldo_a_distribuir(item_take_off_id) = ItemTakeOff.quantidade -
 * SUM(quantidade_necessaria WHERE item_take_off_id = X)`, nunca
 * persistido, sempre recalculado por `App\Support\Estoque\
 * ConciliacaoNecessidadeAtividade`. Sobre-distribuição é BLOQUEADA
 * (nunca só alertada), mesma postura de todos os precedentes análogos
 * deste domínio. Se o TakeOff for reduzido depois de já distribuído, o
 * saldo fica honestamente NEGATIVO (nunca truncado/corrigido
 * automaticamente) — mesmo comportamento já aceito e documentado em
 * `ConciliacaoTakeOff`/19.2.CORREÇÃO.
 *
 * **`origem=operacional` exige `justificativa` OBRIGATÓRIA** (NOT NULL
 * só quando esta origem é usada — garantido na Action, não no schema,
 * já que a coluna também serve de `observacao` livre e opcional pro
 * caso `take_off`) — decisão do usuário: toda necessidade sem lastro
 * documental precisa de uma razão registrada, mas sem exigir nenhuma
 * aprovação formal nesta rodada.
 *
 * FKs de identidade (`item_take_off_id`/`material_id`) são
 * `restrictOnDelete()` — mesma lição de evidência histórica de sempre
 * (nunca perder o registro de necessidade por trás de uma exclusão em
 * cascata). `atividade_id` é `cascadeOnDelete()` — mesmo precedente já
 * usado em `documento_engenharia_atividades`/`item_suprimento_atividades`
 * (vínculo estrutural vivo; Atividade nunca é hard-deleted em produção).
 *
 * **Unique via semântica de NULL do MySQL, mesmo mecanismo já usado em
 * toda a árvore Estoque/GRD deste projeto**: `unique(atividade_id,
 * item_take_off_id)` só impede duplicidade quando `item_take_off_id` NÃO
 * é nulo (cada linha `operacional`, com `item_take_off_id=NULL`, conta
 * como um valor DISTINTO pro MySQL — nunca colide entre si nesse
 * índice); `unique(atividade_id, material_id)` faz o inverso, protegendo
 * só as linhas `operacional`. As duas convivem sem se atrapalhar.
 *
 * **CHECK constraint** (MySQL 8.0.32 do ambiente, suporta CHECK real
 * desde 8.0.16 — primeira vez usada neste projeto, decisão explícita do
 * usuário: "aplicar constraint/check no banco, se suportado") — reforça
 * a 2ª camada da invariante de origem, redundante com a validação da
 * Action, mas garantindo que NENHUM escritor (nem futuro, nem um bypass
 * acidental) consiga gravar um estado inconsistente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atividade_necessidades_material', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('obra_id')->constrained('works')->cascadeOnDelete();

            $table->foreignUlid('atividade_id')->constrained('atividades')->cascadeOnDelete();

            $table->string('origem');

            $table->foreignUlid('item_take_off_id')->nullable();
            $table->foreign('item_take_off_id', 'anm_item_take_off_fk')
                ->references('id')->on('itens_take_off')
                ->restrictOnDelete();

            $table->foreignUlid('material_id')->nullable();
            $table->foreign('material_id', 'anm_material_fk')
                ->references('id')->on('materiais')
                ->restrictOnDelete();

            $table->foreignUlid('unidade_medida_id')->constrained('unidades_medida')->restrictOnDelete();

            $table->decimal('quantidade_necessaria', 14, 3);
            $table->text('observacao')->nullable();

            $table->foreignUlid('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['atividade_id', 'item_take_off_id'], 'anm_atividade_item_take_off_unique');
            $table->unique(['atividade_id', 'material_id'], 'anm_atividade_material_unique');
            $table->index(['tenant_id', 'atividade_id'], 'anm_tenant_atividade_idx');
            $table->index(['obra_id', 'material_id'], 'anm_obra_material_idx');
            $table->index(['tenant_id', 'item_take_off_id'], 'anm_tenant_item_take_off_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE atividade_necessidades_material
            ADD CONSTRAINT anm_origem_check CHECK (
                (origem = 'take_off' AND item_take_off_id IS NOT NULL AND material_id IS NULL)
                OR
                (origem = 'operacional' AND item_take_off_id IS NULL AND material_id IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atividade_necessidades_material');
    }
};
