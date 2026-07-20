<?php

namespace Tests\Feature;

use App\Actions\Atividade\MarcarNaoConcluido;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CausaNaoCumprimento;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AtividadeBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
    }

    // --- Prontidão derivada ---

    public function test_atividade_sem_restricoes_esta_pronta(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);

        $this->assertTrue($atividade->estaPronta());
    }

    public function test_atividade_com_restricao_bloqueante_aberta_nao_esta_pronta(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->assertFalse($atividade->estaPronta());
    }

    public function test_atividade_com_restricao_nao_bloqueante_esta_pronta(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => false,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->assertTrue($atividade->estaPronta());
    }

    public function test_atividade_com_restricao_bloqueante_resolvida_esta_pronta(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $this->assertTrue($atividade->estaPronta());
    }

    // --- Bloqueio do status comprometido ---

    public function test_nao_pode_comprometer_atividade_com_restricao_bloqueante(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->expectException(ValidationException::class);

        $atividade->update(['status' => StatusAtividade::Comprometido]);
    }

    public function test_pode_comprometer_atividade_pronta(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'status' => StatusAtividade::Planejado,
        ]);

        $atividade->update(['status' => StatusAtividade::Comprometido]);

        $this->assertEquals(StatusAtividade::Comprometido, $atividade->fresh()->status);
    }

    public function test_pode_comprometer_quando_unica_restricao_esta_resolvida(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $atividade->update(['status' => StatusAtividade::Comprometido]);

        $this->assertEquals(StatusAtividade::Comprometido, $atividade->fresh()->status);
    }

    // --- MarcarNaoConcluido Action ---

    public function test_marcar_nao_concluido_muda_status_e_cria_causa(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);

        (new MarcarNaoConcluido)->execute($atividade, 'Falta de material no canteiro.');

        $this->assertEquals(StatusAtividade::NaoConcluido, $atividade->fresh()->status);
        $this->assertDatabaseHas('causas_nao_cumprimento', [
            'atividade_id' => $atividade->id,
            'descricao' => 'Falta de material no canteiro.',
        ]);
    }

    public function test_marcar_nao_concluido_exige_descricao_da_causa(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);

        $this->expectException(ValidationException::class);

        (new MarcarNaoConcluido)->execute($atividade, '');
    }

    public function test_marcar_nao_concluido_e_atomico_em_caso_de_falha(): void
    {
        // Simula rollback: a causa não deve existir se algo falhar
        $atividade = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);

        try {
            (new MarcarNaoConcluido)->execute($atividade, '');
        } catch (ValidationException) {
        }

        $this->assertEquals(StatusAtividade::Planejado, $atividade->fresh()->status);
        $this->assertDatabaseMissing('causas_nao_cumprimento', ['atividade_id' => $atividade->id]);
    }

    // --- concluido_em (fonte da verdade pro PPC histórico) ---

    public function test_marcar_concluido_seta_concluido_em(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'status' => StatusAtividade::Comprometido,
        ]);

        $atividade->update(['status' => StatusAtividade::Concluido]);

        $this->assertNotNull($atividade->fresh()->concluido_em);
    }

    public function test_sair_de_concluido_limpa_concluido_em(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'status' => StatusAtividade::Concluido,
            'concluido_em' => now(),
        ]);

        $atividade->update(['status' => StatusAtividade::NaoConcluido]);

        $this->assertNull($atividade->fresh()->concluido_em);
    }

    public function test_transicao_entre_status_nao_concluidos_nao_afeta_concluido_em(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'status' => StatusAtividade::Planejado,
        ]);

        $atividade->update(['status' => StatusAtividade::Comprometido]);

        $this->assertNull($atividade->fresh()->concluido_em);
    }

    public function test_marcar_nao_concluido_action_limpa_concluido_em(): void
    {
        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'status' => StatusAtividade::Concluido,
            'concluido_em' => now(),
        ]);

        (new MarcarNaoConcluido)->execute($atividade, 'Retrabalho necessário.');

        $this->assertNull($atividade->fresh()->concluido_em);
    }

    // --- Scope pronta ---

    public function test_scope_pronta_retorna_apenas_atividades_sem_restricoes_bloqueantes(): void
    {
        $pronta = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $nao_pronta = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id]);

        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $nao_pronta->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $prontas = Atividade::prontas()->pluck('id');

        $this->assertContains($pronta->id, $prontas);
        $this->assertNotContains($nao_pronta->id, $prontas);
    }
}
