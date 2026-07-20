<?php

namespace Tests\Feature;

use App\Enums\StatusAssinatura;
use App\Enums\StatusFatura;
use App\Models\Assinatura;
use App\Models\AssinaturaFatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AssinaturaAutoatendimentoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $criador;
    private Plano $plano;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->criador = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenant->update(['criado_por_id' => $this->criador->id]);
        $this->plano = Plano::factory()->create(['nome' => 'Pro', 'preco_mensal' => 299.90]);

        config([
            'services.mercadopago.access_token' => 'token-teste',
            'services.mercadopago.public_key' => 'chave-publica-teste',
        ]);
    }

    private function componente()
    {
        $this->actingAs($this->criador);

        return Livewire::test('pages::empresa.assinatura', ['tenant' => $this->tenant]);
    }

    public function test_criador_do_tenant_acessa_a_pagina(): void
    {
        $this->actingAs($this->criador);

        $this->get(route('app.empresa.assinatura'))
            ->assertOk()
            ->assertSeeLivewire('pages::empresa.assinatura');
    }

    public function test_usuario_sem_permissao_recebe_forbidden(): void
    {
        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($outroUsuario);

        $this->get(route('app.empresa.assinatura'))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_gerar_cobranca_pix_cria_assinatura_e_fatura_pendente(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments' => Http::response([
            'id' => 'pay-pix-1',
            'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code_base64' => 'BASE64', 'ticket_url' => 'https://mp.test/pix']],
        ], 201)]);

        $this->componente()
            ->set('planoSelecionadoId', $this->plano->id)
            ->set('metodoSelecionado', 'pix')
            ->call('gerarCobranca');

        $fatura = AssinaturaFatura::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($fatura);
        $this->assertSame(StatusFatura::Pendente, $fatura->status);
        $this->assertSame('pix', $fatura->metodo_pagamento);
        $this->assertSame('BASE64', $fatura->qr_code);

        $assinatura = $fatura->assinatura;
        $this->assertSame($this->plano->id, $assinatura->plano_id);
        $this->assertSame('mercadopago', $assinatura->origem);
    }

    public function test_confirmar_cartao_autorizado_ativa_assinatura(): void
    {
        Http::fake(['api.mercadopago.com/preapproval' => Http::response([
            'id' => 'preap-abc',
            'status' => 'authorized',
        ], 201)]);

        $this->componente()
            ->set('planoSelecionadoId', $this->plano->id)
            ->call('confirmarCartao', 'card-token-xyz', 'cliente@obra.com');

        $assinatura = $this->tenant->fresh()->assinaturaAtual();
        $this->assertSame(StatusAssinatura::Ativa, $assinatura->status);
        $this->assertSame('cartao', $assinatura->metodo_pagamento);
        $this->assertSame('preap-abc', $assinatura->mp_preapproval_id);
    }

    public function test_confirmar_cartao_nao_autorizado_nao_cria_assinatura_ativa(): void
    {
        Http::fake(['api.mercadopago.com/preapproval' => Http::response([
            'id' => 'preap-rejeitado',
            'status' => 'pending',
        ], 201)]);

        $this->componente()
            ->set('planoSelecionadoId', $this->plano->id)
            ->call('confirmarCartao', 'card-token-invalido', 'cliente@obra.com');

        $this->assertNull($this->tenant->fresh()->assinaturaAtual());
    }

    public function test_cancelar_assinatura_registra_status_cancelada(): void
    {
        Assinatura::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plano_id' => $this->plano->id,
            'status' => StatusAssinatura::Ativa->value,
            'origem' => 'mercadopago',
            'metodo_pagamento' => 'pix',
        ]);

        $this->componente()->call('cancelarAssinatura');

        $assinatura = $this->tenant->fresh()->assinaturaAtual();
        $this->assertSame(StatusAssinatura::Cancelada, $assinatura->status);
        $this->assertNotNull($assinatura->cancelada_em);
    }

    public function test_faturas_de_outro_tenant_nunca_aparecem(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraAssinatura = Assinatura::factory()->create(['tenant_id' => $outroTenant->id, 'plano_id' => $this->plano->id]);
        AssinaturaFatura::factory()->create([
            'tenant_id' => $outroTenant->id,
            'assinatura_id' => $outraAssinatura->id,
            'mp_payment_id' => 'pay-de-outro-tenant',
        ]);

        $componente = $this->componente();

        $ids = collect($componente->get('faturas'))->pluck('tenant_id');
        $this->assertFalse($ids->contains($outroTenant->id));
    }
}
