<?php

namespace Tests\Feature;

use App\Enums\StatusAssinatura;
use App\Enums\StatusFatura;
use App\Models\Assinatura;
use App\Models\AssinaturaFatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AssinaturaInadimplenteNotification;
use App\Notifications\FaturaGeradaNotification;
use App\Services\CobrancaScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CobrancaSchedulerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Plano $plano;
    private User $criador;
    private CobrancaScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);
        $this->plano = Plano::factory()->create();

        config(['services.mercadopago.access_token' => 'token-teste']);

        Carbon::setTestNow('2026-07-20 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarAssinatura(array $overrides = []): Assinatura
    {
        return Assinatura::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'plano_id' => $this->plano->id,
            'status' => StatusAssinatura::Ativa->value,
            'metodo_pagamento' => 'pix',
            'renovar_em' => now()->addDays(3)->toDateString(),
        ], $overrides));
    }

    public function test_gera_cobranca_quando_dentro_da_antecedencia(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 'pay-1',
            'point_of_interaction' => ['transaction_data' => ['qr_code_base64' => 'QR', 'ticket_url' => 'https://mp.test/1']],
        ], 201)]);

        $assinatura = $this->criarAssinatura();

        $fatura = app(CobrancaScheduler::class)->gerarProximaCobrancaSeNecessario($assinatura);

        $this->assertNotNull($fatura);
        $this->assertSame(StatusFatura::Pendente, $fatura->status);
        $this->assertSame('pix', $fatura->metodo_pagamento);
        $this->assertSame('QR', $fatura->qr_code);

        Notification::assertSentTo($this->criador, FaturaGeradaNotification::class);
    }

    public function test_nao_gera_quando_fora_da_antecedencia(): void
    {
        Http::fake();

        $assinatura = $this->criarAssinatura(['renovar_em' => now()->addDays(10)->toDateString()]);

        $fatura = app(CobrancaScheduler::class)->gerarProximaCobrancaSeNecessario($assinatura);

        $this->assertNull($fatura);
        Http::assertNothingSent();
    }

    public function test_nao_duplica_fatura_do_mesmo_ciclo(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 'pay-1'], 201)]);

        $assinatura = $this->criarAssinatura();
        AssinaturaFatura::factory()->create([
            'tenant_id' => $this->tenant->id,
            'assinatura_id' => $assinatura->id,
            'vencimento' => $assinatura->renovar_em,
        ]);

        $fatura = app(CobrancaScheduler::class)->gerarProximaCobrancaSeNecessario($assinatura);

        $this->assertNull($fatura);
        Http::assertNothingSent();
    }

    public function test_nao_gera_para_metodo_cartao(): void
    {
        Http::fake();

        $assinatura = $this->criarAssinatura(['metodo_pagamento' => 'cartao']);

        $fatura = app(CobrancaScheduler::class)->gerarProximaCobrancaSeNecessario($assinatura);

        $this->assertNull($fatura);
        Http::assertNothingSent();
    }

    public function test_nao_gera_quando_assinatura_nao_ativa(): void
    {
        Http::fake();

        $assinatura = $this->criarAssinatura(['status' => StatusAssinatura::Trial->value]);

        $fatura = app(CobrancaScheduler::class)->gerarProximaCobrancaSeNecessario($assinatura);

        $this->assertNull($fatura);
        Http::assertNothingSent();
    }

    public function test_marca_inadimplente_trial_vencido(): void
    {
        $assinatura = $this->criarAssinatura([
            'status' => StatusAssinatura::Trial->value,
            'fim_trial' => now()->subDay()->toDateString(),
        ]);

        $nova = app(CobrancaScheduler::class)->verificarInadimplenciaSeNecessario($assinatura);

        $this->assertNotNull($nova);
        $this->assertSame(StatusAssinatura::Inadimplente, $nova->status);
        Notification::assertSentTo($this->criador, AssinaturaInadimplenteNotification::class);
    }

    public function test_nao_marca_trial_ainda_dentro_do_prazo(): void
    {
        $assinatura = $this->criarAssinatura([
            'status' => StatusAssinatura::Trial->value,
            'fim_trial' => now()->addDay()->toDateString(),
        ]);

        $nova = app(CobrancaScheduler::class)->verificarInadimplenciaSeNecessario($assinatura);

        $this->assertNull($nova);
    }

    public function test_marca_inadimplente_ativa_vencida_alem_da_carencia_sem_pagamento(): void
    {
        $assinatura = $this->criarAssinatura(['renovar_em' => now()->subDays(4)->toDateString()]);

        $nova = app(CobrancaScheduler::class)->verificarInadimplenciaSeNecessario($assinatura);

        $this->assertNotNull($nova);
        $this->assertSame(StatusAssinatura::Inadimplente, $nova->status);
        Notification::assertSentTo($this->criador, AssinaturaInadimplenteNotification::class);
    }

    public function test_nao_marca_ativa_ainda_dentro_da_carencia(): void
    {
        $assinatura = $this->criarAssinatura(['renovar_em' => now()->subDays(2)->toDateString()]);

        $nova = app(CobrancaScheduler::class)->verificarInadimplenciaSeNecessario($assinatura);

        $this->assertNull($nova);
    }

    public function test_nao_marca_ativa_quando_ja_existe_fatura_paga_pro_ciclo(): void
    {
        $assinatura = $this->criarAssinatura(['renovar_em' => now()->subDays(4)->toDateString()]);
        AssinaturaFatura::factory()->paga()->create([
            'tenant_id' => $this->tenant->id,
            'assinatura_id' => $assinatura->id,
            'vencimento' => $assinatura->renovar_em,
        ]);

        $nova = app(CobrancaScheduler::class)->verificarInadimplenciaSeNecessario($assinatura);

        $this->assertNull($nova);
    }

    public function test_comando_processa_assinaturas_de_todos_os_tenants(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response(['id' => 'pay-1'], 201)]);

        $this->criarAssinatura();

        $this->artisan('assinaturas:processar')->assertSuccessful();

        $this->assertSame(1, AssinaturaFatura::where('tenant_id', $this->tenant->id)->count());
    }
}
