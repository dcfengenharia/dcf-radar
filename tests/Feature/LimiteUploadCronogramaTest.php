<?php

namespace Tests\Feature;

use App\Enums\StatusAssinatura;
use App\Models\Assinatura;
use App\Models\Plano;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class LimiteUploadCronogramaTest extends TestCase
{
    use RefreshDatabase;

    private function criarObraComPlano(?int $limiteUploadMb): Work
    {
        $tenant = Tenant::factory()->create();

        if ($limiteUploadMb !== null) {
            $plano = Plano::factory()->create(['limite_upload_mb' => $limiteUploadMb]);
            Assinatura::factory()->create([
                'tenant_id' => $tenant->id,
                'plano_id' => $plano->id,
                'status' => StatusAssinatura::Ativa->value,
            ]);
        }

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($user);

        return Work::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_tenant_sem_assinatura_usa_o_teto_da_plataforma_como_fallback(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertSame(Tenant::LIMITE_UPLOAD_SEM_PLANO_MB, $tenant->limiteUploadMb());
    }

    public function test_tenant_com_plano_usa_o_limite_definido_no_plano(): void
    {
        $tenant = Tenant::factory()->create();
        $plano = Plano::factory()->create(['limite_upload_mb' => 42]);
        Assinatura::factory()->create([
            'tenant_id' => $tenant->id,
            'plano_id' => $plano->id,
            'status' => StatusAssinatura::Ativa->value,
        ]);

        $this->assertSame(42, $tenant->fresh()->limiteUploadMb());
    }

    public function test_upload_maior_que_o_limite_do_plano_mostra_aviso_amigavel_com_cta_de_upgrade(): void
    {
        $tenant = Tenant::factory()->create();
        $plano = Plano::factory()->create(['limite_upload_mb' => 1]);
        Assinatura::factory()->create([
            'tenant_id' => $tenant->id,
            'plano_id' => $plano->id,
            'status' => StatusAssinatura::Ativa->value,
        ]);

        // Usuário É o criador do tenant — deve ver o link direto pra
        // página de assinatura (podeGerenciarTenant() == true).
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->update(['criado_por_id' => $user->id]);
        $this->actingAs($user);
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);

        $arquivoGrande = UploadedFile::fake()->create('cronograma-grande.xml', 2000, 'text/xml');

        Livewire::test('pages::radar.cronograma', ['obra' => $obra])
            ->set('arquivoTemp', $arquivoGrande)
            ->call('analisar')
            ->assertHasNoErrors('arquivoTemp')
            ->assertSet('excedeuLimiteUpload', true)
            ->assertSee('Arquivo grande demais para o seu plano')
            ->assertSee('limite do plano atual é de 1 MB')
            ->assertSeeHtml(route('app.empresa.assinatura'));
    }

    public function test_aviso_de_limite_excedido_nao_mostra_cta_para_quem_nao_pode_gerenciar_o_tenant(): void
    {
        // Helper padrão: criado_por_id fica null — usuário NÃO é o
        // criador do tenant, então podeGerenciarTenant() é false.
        $obra = $this->criarObraComPlano(limiteUploadMb: 1);

        $arquivoGrande = UploadedFile::fake()->create('cronograma-grande.xml', 2000, 'text/xml');

        Livewire::test('pages::radar.cronograma', ['obra' => $obra])
            ->set('arquivoTemp', $arquivoGrande)
            ->call('analisar')
            ->assertSet('excedeuLimiteUpload', true)
            ->assertSee('Peça ao administrador da conta pra fazer upgrade do plano')
            ->assertDontSeeHtml(route('app.empresa.assinatura'));
    }

    public function test_upload_dentro_do_limite_do_plano_passa_na_validacao(): void
    {
        $obra = $this->criarObraComPlano(limiteUploadMb: 10);

        $conteudo = file_get_contents(__DIR__ . '/../Fixtures/cronograma_sample.xml');
        $arquivoPequeno = UploadedFile::fake()->createWithContent('cronograma-pequeno.xml', $conteudo);

        Livewire::test('pages::radar.cronograma', ['obra' => $obra])
            ->set('arquivoTemp', $arquivoPequeno)
            ->call('analisar')
            ->assertHasNoErrors('arquivoTemp');
    }
}
