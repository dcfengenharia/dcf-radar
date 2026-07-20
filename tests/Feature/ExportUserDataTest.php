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
}
