<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\PilarLean;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BenchmarkingObrasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function darAcesso(Work $obra): void
    {
        $this->vincularObra($obra, $this->user, Papel::Admin->value);
    }

    private function criarRestricaoAberta(Work $obra, PilarLean $pilar): Restricao
    {
        $categoria = CategoriaRestricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'pilar_lean' => $pilar->value,
        ]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obra->id,
        ]);

        return Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'categoria_id' => $categoria->id,
        ]);
    }

    public function test_usuario_com_perfil_sem_permissao_recebe_forbidden(): void
    {
        // Um usuário sem NENHUM vínculo obra_user cai no fallback de
        // bootstrap de temPermissaoEmAlgumaObraDoTenant() (libera acesso
        // pra quem ainda não tem nenhuma obra no tenant) — pra testar
        // "sem permissão" de verdade, precisa de um perfil custom (sem
        // PerfilPermissao nenhuma) vinculado a uma obra.
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $perfilRestrito = \App\Models\Perfil::create([
            'tenant_id' => $this->tenant->id,
            'nome' => 'Sem Benchmarking',
        ]);
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $obra->users()->attach($semAcesso->id, ['perfil_id' => $perfilRestrito->id]);
        $this->actingAs($semAcesso);

        $this->get(route('gestao.benchmarking'))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_tenant_com_uma_obra_so_mostra_sem_erro(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->darAcesso($obra);
        $this->actingAs($this->user);

        $this->get(route('gestao.benchmarking'))
            ->assertOk()
            ->assertSeeLivewire('pages::gestao.benchmarking-obras')
            ->assertSee($obra->name);
    }

    public function test_tenant_com_duas_obras_mostra_indicadores_lado_a_lado(): void
    {
        $obraA = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Alpha']);
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Beta']);
        $this->darAcesso($obraA);
        $this->darAcesso($obraB);
        $this->actingAs($this->user);

        $this->criarRestricaoAberta($obraA, PilarLean::Materiais);
        $this->criarRestricaoAberta($obraA, PilarLean::Materiais);
        $this->criarRestricaoAberta($obraB, PilarLean::MaoDeObra);

        $componente = Livewire::test('pages::gestao.benchmarking-obras');

        $componente->assertSee('Obra Alpha')->assertSee('Obra Beta');

        $indicadores = collect($componente->get('indicadoresPorObra'));

        $linhaA = $indicadores->firstWhere('obra.id', $obraA->id);
        $linhaB = $indicadores->firstWhere('obra.id', $obraB->id);

        $this->assertSame(2, $linhaA['restricoesAbertasPorPilar'][PilarLean::Materiais->value]);
        $this->assertSame(0, $linhaA['restricoesAbertasPorPilar'][PilarLean::MaoDeObra->value]);

        $this->assertSame(0, $linhaB['restricoesAbertasPorPilar'][PilarLean::Materiais->value]);
        $this->assertSame(1, $linhaB['restricoesAbertasPorPilar'][PilarLean::MaoDeObra->value]);
    }

    public function test_obra_de_outro_tenant_nunca_aparece(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->darAcesso($obra);

        // Criar os dados do outro tenant ANTES de actingAs() — o hook de
        // BelongsToTenant::creating() carimba tenant_id automaticamente a
        // partir do usuário autenticado no momento do create, então
        // criar "de outro tenant" depois de já estar logado sobrescreve
        // silenciosamente o tenant_id passado (mesmo padrão usado em
        // TenantIsolationTest::makeTenantWithUser()).
        $outroTenant = Tenant::factory()->create();
        $obraDeFora = Work::factory()->create(['tenant_id' => $outroTenant->id, 'name' => 'Obra De Outro Tenant']);

        $this->actingAs($this->user);

        $componente = Livewire::test('pages::gestao.benchmarking-obras');

        $componente->assertDontSee('Obra De Outro Tenant');

        $ids = collect($componente->get('indicadoresPorObra'))->pluck('obra.id');
        $this->assertFalse($ids->contains($obraDeFora->id));
    }
}
