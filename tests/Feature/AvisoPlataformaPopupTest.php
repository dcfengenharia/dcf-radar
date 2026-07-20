<?php

namespace Tests\Feature;

use App\Models\AvisoPlataforma;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AvisoPlataformaPopupTest extends TestCase
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

    private function componente()
    {
        return Livewire::test('avisos-plataforma.popup');
    }

    public function test_aviso_ativo_aparece_para_o_usuario(): void
    {
        $aviso = AvisoPlataforma::factory()->create(['titulo' => 'Bem-vindo']);

        $this->componente()
            ->assertSee('Bem-vindo')
            ->assertSet('avisoAtual.id', $aviso->id);
    }

    /**
     * Melhoria pedida pelo usuário: editor rico no admin (negrito, link,
     * imagem) — a mensagem sanitizada (ver AvisoPlataformaManagementTest)
     * precisa renderizar como HTML de verdade no popup, não escapada.
     */
    public function test_mensagem_com_formatacao_renderiza_como_html(): void
    {
        AvisoPlataforma::factory()->create([
            'mensagem' => '<p><strong>Atenção</strong>: leia o <a href="https://exemplo.com">comunicado</a>.</p>',
        ]);

        $this->componente()->assertSeeHtml('<strong>Atenção</strong>');
    }

    public function test_aviso_inativo_nunca_aparece(): void
    {
        AvisoPlataforma::factory()->inativo()->create(['titulo' => 'Rascunho']);

        $this->componente()->assertDontSee('Rascunho');
    }

    public function test_dispensa_com_checkbox_grava_permanente_e_nao_reaparece_em_nova_sessao(): void
    {
        $aviso = AvisoPlataforma::factory()->create();

        $this->componente()
            ->set('naoMostrarNovamente', true)
            ->call('fecharAviso');

        $this->assertDatabaseHas('aviso_plataforma_dispensas', [
            'aviso_plataforma_id' => $aviso->id,
            'user_id' => $this->user->id,
        ]);

        session()->flush();

        $this->componente()->assertSet('avisoAtual', null);
    }

    public function test_dispensa_sem_checkbox_nao_reaparece_na_mesma_sessao_mas_volta_em_nova_sessao(): void
    {
        $aviso = AvisoPlataforma::factory()->create();

        $this->componente()->call('fecharAviso');

        $this->assertDatabaseMissing('aviso_plataforma_dispensas', [
            'aviso_plataforma_id' => $aviso->id,
            'user_id' => $this->user->id,
        ]);

        $this->componente()->assertSet('avisoAtual', null);

        session()->flush();

        $this->componente()->assertSet('avisoAtual.id', $aviso->id);
    }

    public function test_multiplos_avisos_aparecem_um_de_cada_vez(): void
    {
        $primeiro = AvisoPlataforma::factory()->create(['created_at' => now()->subMinute()]);
        $segundo = AvisoPlataforma::factory()->create(['created_at' => now()]);

        $componente = $this->componente();
        $componente->assertSet('avisoAtual.id', $primeiro->id);

        $componente->call('fecharAviso')
            ->assertDispatched('aviso-plataforma-fechado', temProximo: true)
            ->assertSet('avisoAtual.id', $segundo->id);
    }

    /**
     * Melhoria pedida pelo usuário: direcionar aviso pra empresas
     * específicas — sem nenhuma linha em tenants() continua sendo
     * "todas as empresas" (comportamento de antes, sem regressão).
     */
    public function test_aviso_sem_direcionamento_aparece_para_qualquer_tenant(): void
    {
        AvisoPlataforma::factory()->create(['titulo' => 'Para Todos']);

        $this->componente()->assertSee('Para Todos');
    }

    public function test_aviso_direcionado_aparece_so_para_o_tenant_selecionado(): void
    {
        $aviso = AvisoPlataforma::factory()->create(['titulo' => 'Promo Exclusiva']);
        $aviso->tenants()->attach($this->user->tenant_id);

        $this->componente()->assertSee('Promo Exclusiva');
    }

    public function test_aviso_direcionado_nao_aparece_para_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $aviso = AvisoPlataforma::factory()->create(['titulo' => 'Promo Exclusiva']);
        $aviso->tenants()->attach($outroTenant->id);

        $this->componente()->assertDontSee('Promo Exclusiva');
    }

    /**
     * Melhoria pedida pelo usuário: imagem em tela cheia + botão de link
     * pra promoções, em vez do layout de texto padrão.
     */
    public function test_popup_com_imagem_promocional_mostra_imagem_e_botao_de_link(): void
    {
        Storage::fake('public');

        AvisoPlataforma::factory()->create([
            'titulo' => 'Promoção de Verão',
            'mensagem' => null,
            'imagem_promocional' => 'avisos-plataforma/promo.jpg',
            'link_url' => 'https://exemplo.com/oferta',
            'link_texto' => 'Ver Oferta',
        ]);

        $this->componente()
            ->assertSeeHtml('avisos-plataforma/promo.jpg')
            ->assertSeeHtml('href="https://exemplo.com/oferta"')
            ->assertSee('Ver Oferta');
    }
}
