<?php

namespace Tests\Feature\Admin;

use App\Models\AvisoPlataforma;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AvisoPlataformaManagementTest extends TestCase
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
        return Livewire::test('pages::admin.avisos.index');
    }

    public function test_criar_aviso(): void
    {
        $this->componente()
            ->call('abrirCriar')
            ->set('titulo', 'Manutenção programada')
            ->set('mensagem', 'O sistema ficará fora do ar das 22h às 23h.')
            ->set('tipo', 'urgente')
            ->call('salvar');

        $this->assertDatabaseHas('avisos_plataforma', [
            'titulo' => 'Manutenção programada',
            'tipo' => 'urgente',
            'ativo' => 1,
            'criado_por_id' => $this->admin->id,
        ]);
    }

    public function test_editar_aviso(): void
    {
        $aviso = AvisoPlataforma::factory()->create(['titulo' => 'Antigo']);

        $this->componente()
            ->call('editar', $aviso->id)
            ->set('titulo', 'Novo Título')
            ->call('salvar');

        $this->assertEquals('Novo Título', $aviso->fresh()->titulo);
    }

    public function test_descontinuar_e_reativar_aviso(): void
    {
        $aviso = AvisoPlataforma::factory()->create();

        $this->componente()->call('descontinuar', $aviso->id);
        $this->assertFalse($aviso->fresh()->ativo);

        $this->componente()->call('reativar', $aviso->id);
        $this->assertTrue($aviso->fresh()->ativo);
    }

    public function test_excluir_aviso_remove_dispensas_em_cascata(): void
    {
        $aviso = AvisoPlataforma::factory()->create();
        $usuario = User::factory()->create();
        $aviso->dispensas()->attach($usuario->id);

        $this->componente()->call('excluir', $aviso->id);

        $this->assertDatabaseMissing('avisos_plataforma', ['id' => $aviso->id]);
        $this->assertDatabaseMissing('aviso_plataforma_dispensas', ['aviso_plataforma_id' => $aviso->id]);
    }

    public function test_usuario_comum_recebe_403_ao_acessar_avisos(): void
    {
        $tenant = Tenant::factory()->create();
        $comum = User::factory()->create(['tenant_id' => $tenant->id, 'is_platform_admin' => false]);
        $this->actingAs($comum);

        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup.
        $this->get('/admin/avisos')
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    /**
     * Melhoria pedida pelo usuário: editor rico (Quill) no lugar do
     * textarea puro. A mensagem grava HTML, mas sempre passando pelo
     * HTMLPurifier no model (App\Models\AvisoPlataforma::
     * setMensagemAttribute) — tags fora da toolbar configurada (ex:
     * script) nunca sobrevivem, mesmo se alguém injetar HTML manualmente
     * via wire:model (nunca confiar só na UI).
     */
    public function test_mensagem_permite_formatacao_da_toolbar_mas_remove_tags_perigosas(): void
    {
        $this->componente()
            ->call('abrirCriar')
            ->set('titulo', 'Com formatação')
            ->set('mensagem', '<p><strong>Importante</strong>: <a href="https://exemplo.com">clique aqui</a></p><script>alert(1)</script>')
            ->call('salvar');

        $aviso = AvisoPlataforma::where('titulo', 'Com formatação')->firstOrFail();

        $this->assertStringContainsString('<strong>Importante</strong>', $aviso->mensagem);
        $this->assertStringContainsString('<a href="https://exemplo.com"', $aviso->mensagem);
        $this->assertStringNotContainsString('<script>', $aviso->mensagem);
        $this->assertStringNotContainsString('alert(1)', $aviso->mensagem);
    }

    /**
     * Chamado pelo handler de imagem do editor Quill: recebe o upload
     * temporário e devolve a URL pública pra inserir no <img> — mesmo
     * padrão de armazenamento das fotos do Report (disco 'public').
     */
    public function test_processar_imagem_aviso_armazena_no_disco_publico_e_devolve_url(): void
    {
        Storage::fake('public');
        $imagem = UploadedFile::fake()->image('aviso.jpg', 300, 300);

        $componente = $this->componente();
        $componente->set('imagemTemporaria', $imagem);

        $url = $componente->instance()->processarImagemAviso();

        $this->assertStringContainsString('/storage/avisos-plataforma/', $url);
        $caminhoRelativo = 'avisos-plataforma/' . basename($url);
        Storage::disk('public')->assertExists($caminhoRelativo);
    }

    /**
     * Melhoria pedida pelo usuário: imagem de tela cheia (promocional) —
     * diferente da imagem embutida no texto rico, é upload direto e
     * simples (wire:model automático), grava o caminho relativo.
     */
    public function test_upload_de_imagem_promocional_armazena_e_remocao_apaga_do_disco(): void
    {
        Storage::fake('public');
        $imagem = UploadedFile::fake()->image('promo.jpg', 800, 600);

        $componente = $this->componente();
        $componente->set('imagemPromocionalTemp', $imagem);

        $caminho = $componente->get('imagemPromocional');
        $this->assertNotNull($caminho);
        Storage::disk('public')->assertExists($caminho);

        $componente->call('removerImagemPromocional');
        Storage::disk('public')->assertMissing($caminho);
        $this->assertNull($componente->get('imagemPromocional'));
    }

    public function test_excluir_aviso_remove_imagem_promocional_do_disco(): void
    {
        Storage::fake('public');
        $caminho = UploadedFile::fake()->image('promo.jpg')->store('avisos-plataforma', 'public');
        $aviso = AvisoPlataforma::factory()->create(['imagem_promocional' => $caminho]);

        $this->componente()->call('excluir', $aviso->id);

        Storage::disk('public')->assertMissing($caminho);
    }

    public function test_mensagem_pode_ficar_vazia_quando_ha_imagem_promocional(): void
    {
        Storage::fake('public');
        $imagem = UploadedFile::fake()->image('promo.jpg');

        $this->componente()
            ->call('abrirCriar')
            ->set('titulo', 'Só Imagem')
            ->set('imagemPromocionalTemp', $imagem)
            ->set('mensagem', '')
            ->set('linkUrl', 'https://exemplo.com/oferta')
            ->set('linkTexto', 'Ver Oferta')
            ->call('salvar');

        $this->assertDatabaseHas('avisos_plataforma', [
            'titulo' => 'Só Imagem',
            'mensagem' => null,
            'link_url' => 'https://exemplo.com/oferta',
            'link_texto' => 'Ver Oferta',
        ]);
    }

    public function test_mensagem_continua_obrigatoria_sem_imagem_promocional(): void
    {
        $this->componente()
            ->call('abrirCriar')
            ->set('titulo', 'Sem Nada')
            ->set('mensagem', '')
            ->call('salvar')
            ->assertHasErrors(['mensagem']);
    }

    /**
     * Melhoria pedida pelo usuário: direcionar aviso pra empresas
     * específicas (uma, várias, ou todas — vazio na tabela de pivô).
     */
    public function test_direcionar_para_empresas_especificas_grava_pivo_correto(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $this->componente()
            ->call('abrirCriar')
            ->set('titulo', 'Direcionado')
            ->set('mensagem', 'Texto')
            ->set('todasEmpresas', false)
            ->set('tenantsSelecionados', [$tenantA->id, $tenantB->id])
            ->call('salvar');

        $aviso = AvisoPlataforma::where('titulo', 'Direcionado')->firstOrFail();
        $this->assertEqualsCanonicalizing([$tenantA->id, $tenantB->id], $aviso->tenants->pluck('id')->all());
    }

    public function test_todas_empresas_mantem_direcionamento_vazio(): void
    {
        $tenant = Tenant::factory()->create();

        $this->componente()
            ->call('abrirCriar')
            ->set('titulo', 'Broadcast')
            ->set('mensagem', 'Texto')
            ->set('todasEmpresas', true)
            ->set('tenantsSelecionados', [$tenant->id]) // lixo residual — deve ser ignorado
            ->call('salvar');

        $aviso = AvisoPlataforma::where('titulo', 'Broadcast')->firstOrFail();
        $this->assertTrue($aviso->tenants->isEmpty());
    }

    public function test_direcionar_sem_selecionar_empresa_nenhuma_da_erro(): void
    {
        $this->componente()
            ->call('abrirCriar')
            ->set('titulo', 'Sem Empresa')
            ->set('mensagem', 'Texto')
            ->set('todasEmpresas', false)
            ->set('tenantsSelecionados', [])
            ->call('salvar')
            ->assertHasErrors(['tenantsSelecionados']);
    }
}
