<?php

namespace Tests\Feature;

use App\Enums\StatusAssinatura;
use App\Models\Plano;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrialAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_novo_sem_plano_padrao_trial_nao_ganha_assinatura(): void
    {
        Plano::factory()->create(['padrao_trial' => false]);

        $tenant = Tenant::create(['name' => 'Obra Alpha']);

        $this->assertNull($tenant->assinaturaAtual());
    }

    public function test_tenant_novo_com_plano_padrao_trial_ganha_assinatura_trial_de_7_dias(): void
    {
        $planoTrial = Plano::factory()->create(['padrao_trial' => true, 'nome' => 'Starter']);
        Plano::factory()->create(['padrao_trial' => false, 'nome' => 'Pro']);

        Carbon::setTestNow('2026-07-20 10:00:00');
        $tenant = Tenant::create(['name' => 'Obra Beta']);

        $assinatura = $tenant->assinaturaAtual();

        $this->assertNotNull($assinatura);
        $this->assertSame($planoTrial->id, $assinatura->plano_id);
        $this->assertSame(StatusAssinatura::Trial, $assinatura->status);
        $this->assertSame('sistema', $assinatura->origem);
        $this->assertTrue($assinatura->fim_trial->isSameDay(Carbon::parse('2026-07-27')));

        Carbon::setTestNow();
    }
}
