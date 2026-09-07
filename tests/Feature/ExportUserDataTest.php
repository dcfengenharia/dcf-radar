<?php

namespace Tests\Feature;

use App\Actions\ExportUserData;
use App\Models\Atividade;
use App\Models\AtividadeComentario;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ExportUserDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_inclui_dados_do_usuario_e_registros_onde_e_autor_ou_responsavel(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'first_name' => 'Maria',
            'last_name' => 'Silva',
            'email' => 'maria@example.com',
        ]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $atividadeResponsavel = Atividade::factory()->create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'responsavel_id' => $user->id,
        ]);

        $restricaoCriada = Restricao::factory()->create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividadeResponsavel->id,
            'created_by_id' => $user->id,
        ]);

        $comentario = AtividadeComentario::factory()->create([
            'tenant_id' => $tenant->id,
            'atividade_id' => $atividadeResponsavel->id,
            'autor_id' => $user->id,
        ]);

        $dados = app(ExportUserData::class)->gerar($user);

        $this->assertSame('Maria Silva', $dados['usuario']['nome']);
        $this->assertSame('maria@example.com', $dados['usuario']['email']);

        $this->assertCount(1, $dados['atividades_responsavel']);
        $this->assertSame($atividadeResponsavel->id, $dados['atividades_responsavel'][0]['id']);

        $this->assertCount(1, $dados['restricoes_criadas']);
        $this->assertSame($restricaoCriada->id, $dados['restricoes_criadas'][0]['id']);

        $this->assertCount(1, $dados['atividade_comentarios']);
        $this->assertSame($comentario->id, $dados['atividade_comentarios'][0]['id']);
    }

    public function test_export_de_usuario_sem_nenhum_dado_nao_quebra_e_vem_vazio(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $dados = app(ExportUserData::class)->gerar($user);

        $this->assertSame([], $dados['atividades_responsavel']);
        $this->assertSame([], $dados['restricoes_criadas']);
        $this->assertSame([], $dados['atividade_comentarios']);
        $this->assertSame([], $dados['reports_criados']);
    }

    public function test_export_nao_traz_dados_de_outro_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        // Mesmo user_id "vazando" numa atividade de outro tenant (não deveria
        // acontecer na prática, mas serve pra provar que o filtro de
        // tenant_id realmente protege o export).
        Atividade::factory()->create([
            'tenant_id' => $outroTenant->id,
            'obra_id' => $outraObra->id,
            'responsavel_id' => $user->id,
        ]);

        $dados = app(ExportUserData::class)->gerar($user);

        $this->assertSame([], $dados['atividades_responsavel']);
    }

    public function test_botao_de_exportar_dispara_download(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        Livewire::test('profile.export-user-data-form')
            ->call('exportar')
            ->assertFileDownloaded();
    }

    /**
     * Pré-produção, Etapa 2 — cobre uma amostra representativa das ~41
     * tabelas novas (GRD/Take Off/Suprimentos/Estoque/Industrialização/
     * Inventário/Gestão), nunca exportadas antes desta etapa. Nunca chama
     * um serviço/domínio pra criar os registros (evitaria acoplar este
     * teste a regra de negócio de outro ciclo) — insere direto via
     * DB::table(), only-scoped o suficiente pra provar que o SELECT do
     * export encontra a linha certa.
     */
    public function test_export_inclui_tabelas_novas_do_ciclo_18_em_diante(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        DB::table('grds')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'status' => 'rascunho', 'criado_por' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $unidadeMedidaId = (string) \Illuminate\Support\Str::ulid();
        DB::table('unidades_medida')->insert([
            'id' => $unidadeMedidaId, 'tenant_id' => $tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('materiais')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenant->id,
            'codigo' => 'MAT-EXPORT-TESTE', 'descricao' => 'Material Teste', 'modo_rastreabilidade' => 'quantitativo',
            'unidade_medida_id' => $unidadeMedidaId,
            'created_by_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('destinatarios')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenant->id, 'obra_id' => $obra->id,
            'nome' => 'Destinatario Teste', 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dados = app(ExportUserData::class)->gerar($user);

        $this->assertCount(1, $dados['grds_criados']);
        $this->assertCount(1, $dados['materiais_criados']);
        $this->assertCount(1, $dados['destinatarios_grd_vinculados_a_mim']);
    }

    public function test_export_de_tabelas_novas_nunca_traz_dado_de_outro_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);

        // Mesmo user_id "vazando" numa linha de OUTRO tenant — nunca deveria
        // acontecer na prática (BelongsToTenant impede isso em código real),
        // mas prova que o WHERE tenant_id do export protege mesmo assim.
        DB::table('grds')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id,
            'status' => 'rascunho', 'criado_por' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $dados = app(ExportUserData::class)->gerar($user);

        $this->assertSame([], $dados['grds_criados']);
    }

    /**
     * `empresa_que_criei` usa uma query bespoke (não o helper genérico
     * `porColuna()`, já que `tenants` não tem coluna `tenant_id`) — teste
     * dedicado pra essa exceção de implementação.
     */
    public function test_export_inclui_empresa_que_o_usuario_criou(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        DB::table('tenants')->where('id', $tenant->id)->update(['criado_por_id' => $user->id]);

        $outroUsuario = User::factory()->create(['tenant_id' => $tenant->id]);

        $dadosDoCriador = app(ExportUserData::class)->gerar($user);
        $dadosDoOutro = app(ExportUserData::class)->gerar($outroUsuario);

        $this->assertCount(1, $dadosDoCriador['empresa_que_criei']);
        $this->assertSame($tenant->id, $dadosDoCriador['empresa_que_criei'][0]['id']);
        $this->assertCount(0, $dadosDoOutro['empresa_que_criei']);
    }

    public function test_export_nunca_lanca_excecao_mesmo_sem_nenhum_registro_nas_tabelas_novas(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $dados = app(ExportUserData::class)->gerar($user);

        foreach (['grds_criados', 'materiais_criados', 'inventarios_estoque_criados', 'planos_acao_criados', 'ordens_industrializacao_criadas'] as $chave) {
            $this->assertSame([], $dados[$chave], "chave {$chave} deveria vir vazia, nunca lançar exceção");
        }
    }
}
