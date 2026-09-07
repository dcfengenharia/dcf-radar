<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\CriarLicaoAprendida;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaVinculo;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.5.A — cobertura da UI da aba "Inteligência
 * Corporativa" dentro de `pages::gestao.licoes-aprendidas`. Não duplica
 * a cobertura de agregação já exaustiva em `InteligenciaLicoesQueryTest`
 * — só garante o drill-down (Decisão 8) e a autorização/privacidade da
 * própria aba.
 */
class InteligenciaLicoesPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obraA;
    private Work $obraB;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obraA = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra A']);
        $this->obraB = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra B']);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraA, $this->admin, Papel::Admin->value);
        $this->vincularObra($this->obraB, $this->admin, Papel::Admin->value);
        $this->actingAs($this->admin);
        ObraContext::set($this->obraA);
    }

    private function componente()
    {
        return Livewire::test('pages::gestao.licoes-aprendidas')->set('abaAtiva', 'inteligencia');
    }

    private function criarLicaoPublicada(Work $obra, array $overrides = []): LicaoAprendida
    {
        $licao = app(CriarLicaoAprendida::class)->execute($obra, $this->admin, array_merge([
            'titulo' => 'Lição publicada '.uniqid(),
            'situacao_observada' => 'Situação observada.',
            'recomendacao_futura' => 'Recomendação futura.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Media->value,
            'area_funcional' => AreaFuncionalLicao::Campo->value,
        ], $overrides));

        $licao->update(['status' => StatusLicaoAprendida::Publicada->value, 'publicado_em' => now()]);

        return $licao->fresh();
    }

    private function criarMaterial(): Material
    {
        $unidade = UnidadeMedida::create(['codigo' => 'UN', 'nome' => 'Unidade', 'ativo' => true]);

        return Material::create([
            'codigo' => 'MAT-TESTE',
            'descricao' => 'Material de Teste Drill-down',
            'unidade_medida_id' => $unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
            'ativo' => true,
        ]);
    }

    public function test_aba_inteligencia_renderiza_cabecalho_com_contadores(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['area_funcional' => AreaFuncionalLicao::Suprimentos->value]);
        $this->criarLicaoPublicada($this->obraB, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);

        $this->componente()
            ->assertSee('Inteligência de Lições Aprendidas')
            ->assertSee('O que a memória publicada das nossas obras está mostrando?')
            ->assertSeeText('2') // total publicadas
            ->assertSee('Boas práticas publicadas')
            ->assertSee('memória registrada')
            ->assertDontSee('Áreas com mais problemas');
    }

    public function test_aba_inteligencia_sem_licoes_mostra_estado_vazio_amigavel(): void
    {
        $this->componente()
            ->assertSee('Nenhuma lição publicada ainda');
    }

    public function test_drilldown_por_area_muda_aba_filtro_e_lista_correta(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['area_funcional' => AreaFuncionalLicao::Suprimentos->value]);
        $this->criarLicaoPublicada($this->obraA, ['area_funcional' => AreaFuncionalLicao::Engenharia->value]);

        $c = $this->componente()->call('irParaBibliotecaPorArea', AreaFuncionalLicao::Suprimentos->value);

        $c->assertSet('abaAtiva', 'biblioteca')
            ->assertSet('modoVisualizacao', 'todas_obras')
            ->assertSet('statusFiltro', StatusLicaoAprendida::Publicada->value)
            ->assertSet('areaFiltro', AreaFuncionalLicao::Suprimentos->value);

        $this->assertSame(1, $c->instance()->licoes->total());
    }

    public function test_drilldown_por_tipo(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::BoaPratica->value]);
        $this->criarLicaoPublicada($this->obraA, ['tipo' => TipoLicaoAprendida::Problema->value]);

        $c = $this->componente()->call('irParaBibliotecaPorTipo', TipoLicaoAprendida::BoaPratica->value);

        $c->assertSet('tipoFiltro', TipoLicaoAprendida::BoaPratica->value);
        $this->assertSame(1, $c->instance()->licoes->total());
    }

    public function test_drilldown_por_criticidade(): void
    {
        $this->criarLicaoPublicada($this->obraA, ['criticidade' => CriticidadeLicao::Critica->value]);
        $this->criarLicaoPublicada($this->obraA, ['criticidade' => CriticidadeLicao::Baixa->value]);

        $c = $this->componente()->call('irParaBibliotecaPorCriticidade', CriticidadeLicao::Critica->value);

        $c->assertSet('criticidadeFiltro', CriticidadeLicao::Critica->value);
        $this->assertSame(1, $c->instance()->licoes->total());
    }

    public function test_drilldown_por_disciplina(): void
    {
        $disciplina = \App\Models\Disciplina::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->criarLicaoPublicada($this->obraA, ['disciplina_id' => $disciplina->id]);
        $this->criarLicaoPublicada($this->obraA, ['disciplina_id' => null]);

        $c = $this->componente()->call('irParaBibliotecaPorDisciplina', $disciplina->id);

        $c->assertSet('disciplinaFiltroId', $disciplina->id);
        $this->assertSame(1, $c->instance()->licoes->total());
    }

    public function test_drilldown_por_material_filtra_licoes_vinculadas_e_mostra_chip(): void
    {
        $material = $this->criarMaterial();
        $licaoVinculada = $this->criarLicaoPublicada($this->obraA);
        $this->criarLicaoPublicada($this->obraA); // sem vínculo — nunca deve aparecer no filtro

        LicaoAprendidaVinculo::create([
            'licao_aprendida_id' => $licaoVinculada->id,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Material->value,
            'entidade_id' => $material->id,
            'titulo_snapshot' => $material->codigo.' - '.$material->descricao,
            'e_origem' => false,
        ]);

        $c = $this->componente()->call('irParaBibliotecaPorMaterial', $material->id, $material->codigo.' - '.$material->descricao);

        $c->assertSet('materialFiltroId', $material->id)
            ->assertSee('Filtrando por Material')
            ->assertSee($material->codigo);

        $this->assertSame(1, $c->instance()->licoes->total());
        $this->assertSame($licaoVinculada->id, $c->instance()->licoes->first()->id);
    }

    public function test_limpar_filtro_material_remove_o_filtro(): void
    {
        $material = $this->criarMaterial();

        $c = $this->componente()->call('irParaBibliotecaPorMaterial', $material->id, 'X');
        $c->assertSet('materialFiltroId', $material->id);

        $c->set('materialFiltroId', '');
        $c->assertSet('materialFiltroId', '');
    }

    public function test_presenca_cross_obra_lista_material_com_2_obras_e_link_de_drilldown(): void
    {
        $material = $this->criarMaterial();
        $licaoA = $this->criarLicaoPublicada($this->obraA);
        $licaoB = $this->criarLicaoPublicada($this->obraB);

        foreach ([$licaoA, $licaoB] as $licao) {
            LicaoAprendidaVinculo::create([
                'licao_aprendida_id' => $licao->id,
                'entidade_tipo' => TipoEntidadeVinculoLicao::Material->value,
                'entidade_id' => $material->id,
                'titulo_snapshot' => $material->codigo,
                'e_origem' => false,
            ]);
        }

        $this->componente()
            ->assertSee('Materiais associados a lições publicadas provenientes de mais de uma obra')
            ->assertSee($material->descricao)
            ->assertSee('Ver lições');
    }

    public function test_candidato_pendente_nunca_influencia_o_painel_de_inteligencia(): void
    {
        // Decisão 2 — candidato pendente nunca é conhecimento corporativo.
        // Cria um candidato pendente e confirma que o painel continua
        // mostrando zero publicações (nenhuma lição real existe ainda).
        \App\Models\CandidatoLicaoAprendida::create([
            'obra_id' => $this->obraA->id,
            'tipo' => \App\Enums\TipoCandidatoLicaoAprendida::RestricaoRelevante->value,
            'chave_logica' => 'chave-teste-'.uniqid(),
            'status' => \App\Enums\StatusCandidatoLicaoAprendida::Pendente->value,
            'entidade_tipo' => TipoEntidadeVinculoLicao::Restricao->value,
            'entidade_id' => (string) \Illuminate\Support\Str::ulid(),
            'titulo' => 'Candidato pendente',
            'descricao' => 'Descrição',
            'dados_snapshot' => [],
            'gerado_em' => now(),
        ]);

        $this->componente()
            ->assertSee('Nenhuma lição publicada ainda')
            ->assertDontSee('Candidato pendente');
    }

    /**
     * Decisão 10 — a aba reutiliza `gestao.licoes-aprendidas|ver` sem
     * nenhum gate novo: nenhum caminho de código próprio desta etapa
     * checa autorização, é o `mount()` do componente (já coberto
     * exaustivamente em `LicaoAprendidaPageTest`) que protege a página
     * inteira, aba de inteligência incluída. Prova aqui é só de
     * isolamento de tenant — nunca vaza dado de outro tenant no cálculo
     * do resumo mesmo quando o usuário atual tem acesso amplo.
     */
    public function test_resumo_da_inteligencia_nunca_mistura_dado_de_outro_tenant(): void
    {
        $this->criarLicaoPublicada($this->obraA);

        $outroTenant = Tenant::factory()->create();
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->criarLicaoPublicada($outraObra);
            $this->criarLicaoPublicada($outraObra);
        });

        $c = $this->componente();

        $this->assertSame(1, $c->instance()->inteligencia->totalLicoesPublicadas);
    }
}
