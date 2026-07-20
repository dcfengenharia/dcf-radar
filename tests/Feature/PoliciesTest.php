<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\PacoteTrabalho;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoliciesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function userComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    // --- WorkPolicy ---

    public function test_cliente_leitura_pode_ver_obra(): void
    {
        $user = $this->userComPapel(Papel::ClienteLeitura);
        $this->assertTrue($user->can('view', $this->obra));
    }

    public function test_usuario_sem_acesso_nao_pode_ver_obra(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->assertFalse($user->can('view', $this->obra));
    }

    public function test_cliente_leitura_nao_pode_editar_obra(): void
    {
        $user = $this->userComPapel(Papel::ClienteLeitura);
        $this->assertFalse($user->can('update', $this->obra));
    }

    public function test_engenheiro_nao_pode_editar_obra(): void
    {
        $user = $this->userComPapel(Papel::Engenheiro);
        $this->assertFalse($user->can('update', $this->obra));
    }

    public function test_gerente_pode_editar_obra(): void
    {
        $user = $this->userComPapel(Papel::GerentePlanejamento);
        $this->assertTrue($user->can('update', $this->obra));
    }

    public function test_apenas_admin_pode_deletar_obra(): void
    {
        $gerente = $this->userComPapel(Papel::GerentePlanejamento);
        $admin = $this->userComPapel(Papel::Admin);

        $this->assertFalse($gerente->can('delete', $this->obra));
        $this->assertTrue($admin->can('delete', $this->obra));
    }

    // --- AtividadePolicy ---

    public function test_engenheiro_pode_criar_atividade(): void
    {
        $user = $this->userComPapel(Papel::Engenheiro);
        $this->actingAs($user);

        $this->assertTrue($user->can('create', [Atividade::class, $this->obra->id]));
    }

    public function test_cliente_leitura_nao_pode_criar_atividade(): void
    {
        $user = $this->userComPapel(Papel::ClienteLeitura);
        $this->actingAs($user);

        $this->assertFalse($user->can('create', [Atividade::class, $this->obra->id]));
    }

    public function test_encarregado_pode_editar_atividade(): void
    {
        $user = $this->userComPapel(Papel::Encarregado);
        $this->actingAs($user);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $this->assertTrue($user->can('update', $atividade));
    }

    public function test_cliente_leitura_nao_pode_editar_atividade(): void
    {
        $user = $this->userComPapel(Papel::ClienteLeitura);
        $this->actingAs($user);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $this->assertFalse($user->can('update', $atividade));
    }

    public function test_apenas_gerente_ou_acima_pode_deletar_atividade(): void
    {
        $engenheiro = $this->userComPapel(Papel::Engenheiro);
        $gerente = $this->userComPapel(Papel::GerentePlanejamento);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $this->actingAs($engenheiro);
        $this->assertFalse($engenheiro->can('delete', $atividade));

        $this->actingAs($gerente);
        $this->assertTrue($gerente->can('delete', $atividade));
    }

    // --- RestricaoPolicy ---

    public function test_encarregado_pode_criar_restricao(): void
    {
        $user = $this->userComPapel(Papel::Encarregado);
        $this->actingAs($user);

        $this->assertTrue($user->can('create', [Restricao::class, $this->obra->id]));
    }

    public function test_cliente_leitura_nao_pode_criar_restricao(): void
    {
        $user = $this->userComPapel(Papel::ClienteLeitura);
        $this->actingAs($user);

        $this->assertFalse($user->can('create', [Restricao::class, $this->obra->id]));
    }

    public function test_apenas_gerente_pode_deletar_restricao(): void
    {
        $engenheiro = $this->userComPapel(Papel::Engenheiro);
        $gerente = $this->userComPapel(Papel::GerentePlanejamento);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
        ]);

        $this->actingAs($engenheiro);
        $this->assertFalse($engenheiro->can('delete', $restricao));

        $this->actingAs($gerente);
        $this->assertTrue($gerente->can('delete', $restricao));
    }

    public function test_responsavel_pela_restricao_pode_resolvela(): void
    {
        $encarregado = $this->userComPapel(Papel::Encarregado);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $encarregado->id,
        ]);

        $this->actingAs($encarregado);
        $this->assertTrue($encarregado->can('resolver', $restricao));
    }

    // --- PacoteTrabalhoPolicy ---

    public function test_apenas_gerente_pode_gerenciar_pacotes(): void
    {
        $engenheiro = $this->userComPapel(Papel::Engenheiro);
        $gerente = $this->userComPapel(Papel::GerentePlanejamento);

        $pacote = PacoteTrabalho::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);

        $this->actingAs($engenheiro);
        $this->assertFalse($engenheiro->can('update', $pacote));
        $this->assertFalse($engenheiro->can('delete', $pacote));

        $this->actingAs($gerente);
        $this->assertTrue($gerente->can('update', $pacote));
        $this->assertTrue($gerente->can('delete', $pacote));
    }

    // --- Hierarquia de papéis ---

    public function test_papel_admin_pode_ao_menos_o_nivel_de_gerente(): void
    {
        $this->assertTrue(Papel::Admin->podeAoMenos(Papel::GerentePlanejamento));
        $this->assertTrue(Papel::Admin->podeAoMenos(Papel::Engenheiro));
        $this->assertTrue(Papel::Admin->podeAoMenos(Papel::ClienteLeitura));
    }

    public function test_papel_cliente_leitura_nao_pode_acima_de_si_mesmo(): void
    {
        $this->assertFalse(Papel::ClienteLeitura->podeAoMenos(Papel::Encarregado));
        $this->assertFalse(Papel::ClienteLeitura->podeAoMenos(Papel::Admin));
    }
}
