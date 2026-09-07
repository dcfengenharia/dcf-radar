<?php

namespace Tests\Feature;

use App\Actions\ExportUserData;
use App\Models\Atividade;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pré-produção, Etapa 2.2 (achado B2, incluindo os 2 casos equivalentes
 * encontrados pelo grep da seção 18) — fixture adversarial: usuário A
 * registra uma operação cujo conteúdo carrega dado pessoal de uma
 * TERCEIRA pessoa (B); exportar A nunca pode trazer o nome/assinatura/
 * texto livre de B, mesmo aparecendo na mesma linha que A registrou. O
 * FATO da ação de A (que registrou, quando, em qual contexto) continua
 * presente — só os campos de identidade de B são removidos.
 */
class ExportUserDataMinimizacaoTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::factory()->create();
    }

    // ---- GRD aceite: registrado_por = A (titular), recebedor físico = B (terceiro) ----

    public function test_grd_aceite_nunca_expoe_nome_ou_assinatura_do_recebedor_terceiro(): void
    {
        $tenant = $this->tenant();
        $a = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $grd = DB::table('grds')->insertGetId([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'status' => 'emitida', 'criado_por' => $a->id, 'created_at' => now(), 'updated_at' => now(),
        ], 'id');
        // insertGetId não devolve ULID de string corretamente em todo driver — resolve pela linha.
        $grdId = DB::table('grds')->where('criado_por', $a->id)->value('id');

        $destinatarioId = (string) Str::ulid();
        DB::table('destinatarios')->insert([
            'id' => $destinatarioId, 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'nome' => 'Fulano de Tal', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $grdDestinatarioId = (string) Str::ulid();
        DB::table('grd_destinatarios')->insert([
            'id' => $grdDestinatarioId, 'tenant_id' => $tenant->id, 'grd_id' => $grdId,
            'destinatario_id' => $destinatarioId, 'nome_snapshot' => 'Fulano de Tal',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('grd_aceites_entrega')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'grd_destinatario_id' => $grdDestinatarioId,
            'nome_recebedor_snapshot' => 'Joao Terceiro Recebedor',
            'empresa_snapshot' => 'Empreiteira XPTO',
            'setor_snapshot' => 'Campo',
            'tipo_aceite' => 'sem_assinatura',
            'assinatura_path' => 'privado/assinaturas/algum-arquivo.png',
            'assinatura_hash' => hash('sha256', 'conteudo-fake'),
            'token' => Str::random(48),
            'registrado_por' => $a->id,
            'ocorrido_em' => now(),
            'created_at' => now(),
        ]);

        $export = app(ExportUserData::class)->gerar($a);
        $linha = $export['grd_aceites_registrados'][0];

        // O fato da ação de A é preservado.
        $this->assertSame($a->id, $linha['registrado_por']);
        $this->assertSame($grdDestinatarioId, $linha['grd_destinatario_id']);
        $this->assertSame('sem_assinatura', $linha['tipo_aceite']);
        $this->assertArrayHasKey('ocorrido_em', $linha);

        // Dado pessoal do TERCEIRO (recebedor B) nunca aparece.
        $this->assertArrayNotHasKey('nome_recebedor_snapshot', $linha);
        $this->assertArrayNotHasKey('empresa_snapshot', $linha);
        $this->assertArrayNotHasKey('setor_snapshot', $linha);
        $this->assertArrayNotHasKey('assinatura_path', $linha);
        $this->assertArrayNotHasKey('assinatura_hash', $linha);

        // Serializado como JSON (o que o titular realmente baixa), o nome
        // do terceiro nunca aparece em lugar nenhum.
        $json = json_encode($export);
        $this->assertStringNotContainsString('Joao Terceiro Recebedor', $json);
        $this->assertStringNotContainsString('Empreiteira XPTO', $json);
        $this->assertStringNotContainsString('algum-arquivo.png', $json);
    }

    // ---- Movimentação de estoque: registrado_por = A, retirante físico = B (texto livre) ----

    public function test_movimentacao_estoque_nunca_expoe_nome_do_retirante_externo(): void
    {
        $tenant = $this->tenant();
        $a = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $unidadeMedidaId = (string) Str::ulid();
        DB::table('unidades_medida')->insert([
            'id' => $unidadeMedidaId, 'tenant_id' => $tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $materialId = (string) Str::ulid();
        DB::table('materiais')->insert([
            'id' => $materialId, 'tenant_id' => $tenant->id, 'codigo' => 'MAT-MIN-01',
            'descricao' => 'Material Teste', 'modo_rastreabilidade' => 'quantitativo',
            'unidade_medida_id' => $unidadeMedidaId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $localId = (string) Str::ulid();
        DB::table('locais_estoque')->insert([
            'id' => $localId, 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'nome' => 'Local Teste', 'tipo' => 'almoxarifado', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('movimentacoes_estoque')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'tipo' => 'saida', 'material_id' => $materialId, 'local_estoque_id' => $localId,
            'quantidade' => 10, 'ocorrido_em' => now(),
            'registrado_por' => $a->id, 'retirado_por_externo' => 'Maria Terceira Retirante',
            'created_at' => now(),
        ]);

        $export = app(ExportUserData::class)->gerar($a);
        $linha = $export['movimentacoes_estoque_registradas'][0];

        $this->assertSame($a->id, $linha['registrado_por']);
        $this->assertSame('saida', $linha['tipo']);
        $this->assertArrayNotHasKey('retirado_por_externo', $linha);

        $json = json_encode($export);
        $this->assertStringNotContainsString('Maria Terceira Retirante', $json);
    }

    // ---- Restrição: created_by_id = A, responsável físico = B (texto livre) ----

    public function test_restricao_criada_nunca_expoe_nome_do_responsavel_externo(): void
    {
        $tenant = $this->tenant();
        $a = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        $restricao = Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividade->id,
            'created_by_id' => $a->id,
            'responsavel_id' => null,
            'responsavel_externo' => 'Carlos Terceiro Responsavel',
        ]);

        $export = app(ExportUserData::class)->gerar($a);
        $linha = collect($export['restricoes_criadas'])->firstWhere('id', $restricao->id);

        $this->assertNotNull($linha);
        $this->assertSame($a->id, $linha['created_by_id']);
        $this->assertArrayNotHasKey('responsavel_externo', $linha);

        $json = json_encode($export);
        $this->assertStringNotContainsString('Carlos Terceiro Responsavel', $json);
    }

    /**
     * `restricoes_responsavel` (filtrado por `responsavel_id`, não
     * `created_by_id`) nunca teve o risco do B2 — é o próprio titular
     * quem seria o "terceiro" nesse papel. Confirma que a minimização foi
     * aplicada só onde o risco existe, sem remover campo à toa do outro
     * bloco.
     */
    public function test_restricoes_responsavel_continua_com_todas_as_colunas(): void
    {
        $tenant = $this->tenant();
        $a = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $atividade = Atividade::factory()->create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id]);

        Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $a->id,
        ]);

        $export = app(ExportUserData::class)->gerar($a);

        $this->assertArrayHasKey('responsavel_externo', $export['restricoes_responsavel'][0]);
    }

    // ---- Entrega de produto industrializado: registrado_por = A, retirante = B (texto livre) ----

    public function test_entrega_industrializacao_nunca_expoe_nome_do_retirante_externo(): void
    {
        $tenant = $this->tenant();
        $a = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        // Cadeia de FK do domínio de Industrialização não é o objeto deste
        // teste (já coberta a fundo por GrdDominioTest/EstoqueIndustrializacaoTest)
        // — desligada só pra este INSERT isolado, restaurada logo em seguida.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::table('entregas_produto_industrializado')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'produto_industrializado_id' => (string) Str::ulid(),
            'quantidade' => 1, 'modalidade' => 'entrega_direta_campo',
            'movimentacao_saida_terceiro_id' => (string) Str::ulid(),
            'movimentacao_entrada_destino_id' => (string) Str::ulid(),
            'ocorrido_em' => now(),
            'registrado_por' => $a->id,
            'retirado_por_externo' => 'Pedro Terceiro Retirante',
            'created_at' => now(),
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $export = app(ExportUserData::class)->gerar($a);
        $linha = $export['entregas_industrializacao_registradas'][0];

        $this->assertSame($a->id, $linha['registrado_por']);
        $this->assertArrayNotHasKey('retirado_por_externo', $linha);

        $json = json_encode($export);
        $this->assertStringNotContainsString('Pedro Terceiro Retirante', $json);
    }

    /**
     * A/B no mesmo tenant, C em tenant diferente — zero vazamento entre
     * pessoas e entre tenants, reconfirmado especificamente sobre as
     * chaves minimizadas (não só as originais, já cobertas em
     * ExportUserDataTest).
     */
    public function test_isolamento_cross_usuario_e_cross_tenant_nas_chaves_minimizadas(): void
    {
        $tenant = $this->tenant();
        $a = User::factory()->create(['tenant_id' => $tenant->id]);
        $b = User::factory()->create(['tenant_id' => $tenant->id]);
        $outroTenant = Tenant::factory()->create();
        $c = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $obraOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        $unidadeMedidaId = (string) Str::ulid();
        DB::table('unidades_medida')->insert(['id' => $unidadeMedidaId, 'tenant_id' => $tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade', 'created_at' => now(), 'updated_at' => now()]);
        $materialId = (string) Str::ulid();
        DB::table('materiais')->insert(['id' => $materialId, 'tenant_id' => $tenant->id, 'codigo' => 'MAT-ISO', 'descricao' => 'Material', 'modo_rastreabilidade' => 'quantitativo', 'unidade_medida_id' => $unidadeMedidaId, 'created_at' => now(), 'updated_at' => now()]);
        $localId = (string) Str::ulid();
        DB::table('locais_estoque')->insert(['id' => $localId, 'tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'nome' => 'Local', 'tipo' => 'almoxarifado', 'created_at' => now(), 'updated_at' => now()]);

        // Movimentação registrada por B (não por A).
        DB::table('movimentacoes_estoque')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'tipo' => 'saida', 'material_id' => $materialId, 'local_estoque_id' => $localId,
            'quantidade' => 5, 'ocorrido_em' => now(), 'registrado_por' => $b->id,
            'created_at' => now(),
        ]);

        // Movimentação registrada por C, em outro tenant.
        $unidadeMedidaOutro = (string) Str::ulid();
        DB::table('unidades_medida')->insert(['id' => $unidadeMedidaOutro, 'tenant_id' => $outroTenant->id, 'codigo' => 'UN', 'nome' => 'Unidade', 'created_at' => now(), 'updated_at' => now()]);
        $materialOutro = (string) Str::ulid();
        DB::table('materiais')->insert(['id' => $materialOutro, 'tenant_id' => $outroTenant->id, 'codigo' => 'MAT-ISO-2', 'descricao' => 'Material', 'modo_rastreabilidade' => 'quantitativo', 'unidade_medida_id' => $unidadeMedidaOutro, 'created_at' => now(), 'updated_at' => now()]);
        $localOutro = (string) Str::ulid();
        DB::table('locais_estoque')->insert(['id' => $localOutro, 'tenant_id' => $outroTenant->id, 'obra_id' => $obraOutroTenant->id, 'nome' => 'Local', 'tipo' => 'almoxarifado', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('movimentacoes_estoque')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $outroTenant->id, 'obra_id' => $obraOutroTenant->id,
            'tipo' => 'saida', 'material_id' => $materialOutro, 'local_estoque_id' => $localOutro,
            'quantidade' => 5, 'ocorrido_em' => now(), 'registrado_por' => $c->id,
            'created_at' => now(),
        ]);

        $exportA = app(ExportUserData::class)->gerar($a);

        $this->assertSame([], $exportA['movimentacoes_estoque_registradas'], 'export de A trouxe movimentação de B ou C');
    }
}
