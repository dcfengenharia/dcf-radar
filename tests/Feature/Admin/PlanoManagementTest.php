<?php

namespace Tests\Feature\Admin;

use App\Models\Assinatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlanoManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->admin = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => true]);
        $this->actingAs($this->admin);
    }

    private function componente()
    {
        return Livewire::test('pages::admin.planos.index');
    }

    public function test_criar_plano(): void
    {
        $this->componente()
            ->call('abrirCriar')
            ->set('nome', 'Plano Pro')
            ->set('precoMensal', '299.90')
            ->set('maxObras', '10')
            ->set('maxUsuarios', '25')
            ->call('salvar');

        $this->assertDatabaseHas('planos', [
            'nome' => 'Plano Pro',
            'preco_mensal' => '299.90',
            'max_obras' => 10,
            'max_usuarios' => 25,
            'limite_upload_mb' => Plano::LIMITE_UPLOAD_PADRAO_MB,
        ]);
    }

    public function test_editar_plano(): void
    {
        $plano = Plano::factory()->create(['nome' => 'Antigo']);

        $this->componente()
            ->call('editar', $plano->id)
            ->set('nome', 'Novo Nome')
            ->call('salvar');

        $this->assertEquals('Novo Nome', $plano->fresh()->nome);
    }

    public function test_limite_de_upload_e_obrigatorio_e_maior_que_zero(): void
    {
        $this->componente()
            ->call('abrirCriar')
            ->set('nome', 'Plano Teste')
            ->set('precoMensal', '99.90')
            ->set('limiteUploadMb', '0')
            ->call('salvar')
            ->assertHasErrors(['limiteUploadMb' => 'min']);
    }

    public function test_editar_carrega_o_limite_de_upload_do_plano(): void
    {
        $plano = Plano::factory()->create(['limite_upload_mb' => 250]);

        $this->componente()
            ->call('editar', $plano->id)
            ->assertSet('limiteUploadMb', '250');
    }

    public function test_alterar_limite_de_upload_no_editar_persiste(): void
    {
        $plano = Plano::factory()->create(['limite_upload_mb' => 100]);

        $this->componente()
            ->call('editar', $plano->id)
            ->set('limiteUploadMb', '500')
            ->call('salvar');

        $this->assertEquals(500, $plano->fresh()->limite_upload_mb);
    }

    public function test_plano_com_assinatura_ativa_nao_pode_ser_excluido(): void
    {
        $plano = Plano::factory()->create();
        Assinatura::factory()->create(['plano_id' => $plano->id]);

        $this->componente()->call('excluir', $plano->id);

        $this->assertDatabaseHas('planos', ['id' => $plano->id]);
    }

    public function test_plano_sem_uso_pode_ser_excluido(): void
    {
        $plano = Plano::factory()->create();

        $this->componente()->call('excluir', $plano->id);

        $this->assertDatabaseMissing('planos', ['id' => $plano->id]);
    }

    public function test_plano_inativo_nao_aparece_no_seletor_de_atribuir_plano(): void
    {
        $tenant = Tenant::factory()->create();
        $planoAtivo = Plano::factory()->create(['nome' => 'Ativo']);
        $planoInativo = Plano::factory()->inativo()->create(['nome' => 'Inativo']);

        $planosAtivos = Livewire::test('pages::admin.tenants.show', ['tenant' => $tenant])
            ->instance()->planosAtivos;

        $this->assertTrue($planosAtivos->contains('id', $planoAtivo->id));
        $this->assertFalse($planosAtivos->contains('id', $planoInativo->id));
    }
}
