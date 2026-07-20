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

    public function test_upload_maior_que_o_limite_do_plano_e_rejeitado_na_validacao(): void
    {
        $obra = $this->criarObraComPlano(limiteUploadMb: 1);

        $arquivoGrande = UploadedFile::fake()->create('cronograma-grande.xml', 2000, 'text/xml');

        Livewire::test('pages::radar.cronograma', ['obra' => $obra])
            ->set('arquivoTemp', $arquivoGrande)
            ->call('analisar')
            ->assertHasErrors(['arquivoTemp' => 'max']);
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
