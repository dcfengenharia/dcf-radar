<?php

namespace Tests\Feature;

use App\Actions\Jetstream\DeleteUser;
use App\Models\Atividade;
use App\Models\PacoteTrabalho;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Enums\StatusRestricao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pré-produção, Etapa 2 (seção 3/4/18) — "Excluir minha conta" (Jetstream)
 * passou a DESATIVAR (users.ativo=false) em vez de hard-delete. Cobre os 2
 * cenários provados pelo probe descartável da investigação: usuário sem
 * histórico (cenário A) e usuário com histórico operacional real, incluindo
 * uma tabela RESTRICT (restricao_acoes) — a mesma que antes fazia o botão
 * lançar QueryException (cenário B).
 */
class DesativarContaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cenario_a_usuario_sem_historico_e_desativado_sem_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);

        (new DeleteUser())->delete($user);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'ativo' => false]);
    }

    public function test_cenario_b_usuario_com_historico_operacional_real_e_desativado_sem_erro_e_historico_permanece_intacto(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $pacote = PacoteTrabalho::create(['tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'nome' => 'Pacote']);
        $atividade = Atividade::create([
            'tenant_id' => $tenant->id, 'obra_id' => $obra->id, 'pacote_trabalho_id' => $pacote->id,
            'nome' => 'Atividade', 'created_by_id' => $user->id,
        ]);
        $restricao = Restricao::create([
            'tenant_id' => $tenant->id, 'atividade_id' => $atividade->id,
            'descricao' => 'Restricao', 'created_by_id' => $user->id,
            'status' => StatusRestricao::Aberta, 'aberta_em' => now(),
        ]);
        // restricao_acoes tem FK restrictOnDelete pra users — era exatamente
        // a tabela que fazia o hard delete antigo quebrar com QueryException.
        $acao = $restricao->acoes()->create([
            'tenant_id' => $tenant->id, 'autor_id' => $user->id, 'descricao' => 'Ação',
        ]);

        (new DeleteUser())->delete($user);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'ativo' => false]);
        // Nunca reescreve/apaga a identidade de autoria — diferente do
        // hard-delete antigo, que nulava created_by_id via nullOnDelete.
        $this->assertDatabaseHas('atividades', ['id' => $atividade->id, 'created_by_id' => $user->id]);
        $this->assertDatabaseHas('restricoes', ['id' => $restricao->id, 'created_by_id' => $user->id]);
        $this->assertDatabaseHas('restricao_acoes', ['id' => $acao->id, 'autor_id' => $user->id]);
    }

    public function test_usuario_desativado_e_deslogado_imediatamente(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true, 'password' => bcrypt('senha-teste-123')]);
        $this->actingAs($user);

        Livewire::test('profile.delete-user-form')
            ->set('password', 'senha-teste-123')
            ->call('deleteUser');

        $this->assertGuest();
    }

    /**
     * `App\Http\Middleware\BloquearUsuarioInativo` roda a cada requisição
     * (não só no login) — um login que consiga autenticar momentaneamente
     * (a checagem de `ativo` não faz parte das credenciais em si) é
     * derrubado na PRÓXIMA requisição autenticada. Reproduz exatamente esse
     * fluxo em 2 passos, em vez de presumir que o próprio POST de login já
     * bloqueia sozinho.
     */
    public function test_usuario_desativado_e_barrado_na_proxima_requisicao_apos_tentar_logar_de_novo(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'ativo' => false, 'password' => bcrypt('senha-teste-123')]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'senha-teste-123']);
        $this->get(route('app.home'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_senha_incorreta_nao_desativa_a_conta(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true, 'password' => bcrypt('senha-certa')]);
        $this->actingAs($user);

        Livewire::test('profile.delete-user-form')
            ->set('password', 'senha-errada')
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'ativo' => true]);
    }
}
