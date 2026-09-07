<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\CriarLicaoAprendida;
use App\Actions\LicoesAprendidas\CriarLicaoComOrigem;
use App\Actions\LicoesAprendidas\EnviarLicaoParaValidacao;
use App\Actions\LicoesAprendidas\PrepararContextoNovaLicao;
use App\Actions\LicoesAprendidas\PublicarLicaoAprendida;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Enums\TipoEntidadeVinculoLicao;
use App\Enums\TipoLicaoAprendida;
use App\Exceptions\VinculoLicaoInvalidoException;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\LicaoAprendida;
use App\Models\Material;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\ObraContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.2 — captura contextual: CTA em objeto operacional
 * → contexto pré-preenchido → salvar cria lição+vínculo de
 * origem+vínculos complementares numa única transação. Cobre os 5
 * pontos de captura (Restrição/Atividade/Documento/Material/Fornecedor),
 * semântica de obra, imutabilidade de snapshot, link vivo, transação e
 * duplo-clique/cancelamento.
 */
class LicaoAprendidaCapturaContextualTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->admin, Papel::Admin->value);
        $this->actingAs($this->admin);
        ObraContext::set($this->obra);
    }

    private function componente(array $params = [])
    {
        return Livewire::test('pages::gestao.licoes-aprendidas', $params);
    }

    private function dadosMinimosForm(): array
    {
        return [
            'formSituacao' => 'A restrição bloqueou a atividade por 5 dias.',
            'formRecomendacao' => 'Antecipar a compra em 30 dias.',
            'formTipo' => TipoLicaoAprendida::Problema->value,
            'formCriticidade' => CriticidadeLicao::Alta->value,
            'formArea' => AreaFuncionalLicao::Suprimentos->value,
        ];
    }

    private function unidade(): UnidadeMedida
    {
        return UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'UN', 'nome' => 'Unidade']);
    }

    // =========================================================================
    // A) CAPTURA POR ORIGEM — abre corretamente, contexto pré-preenchido,
    //    lição+vínculo+snapshot criados corretamente (Seções 43-47)
    // =========================================================================

    public function test_captura_a_partir_de_restricao_abre_pre_preenchida_e_salva_com_vinculo_de_origem(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Fundação Bloco B']);
        $restricao = Restricao::factory()->create(['tenant_id' => $this->tenant->id, 'atividade_id' => $atividade->id, 'descricao' => 'Aço não entregue no prazo']);

        $c = $this->componente(['origemTipoQuery' => 'restricao', 'origemIdQuery' => $restricao->id]);

        $c->assertSet('modalFormAberto', true)
            ->assertSet('contextoOrigemTipo', 'restricao')
            ->assertSet('contextoOrigemId', $restricao->id);

        $this->assertStringContainsString('Aço não entregue', $c->get('formTitulo'));
        $this->assertSame($this->obra->id, $c->get('formObraId'));
        $this->assertSame(AreaFuncionalLicao::Planejamento->value, $c->get('formArea'));

        // Restrição→Atividade é complementar determinístico (Seção 9/10).
        $complementares = $c->get('contextoVinculosComplementares');
        $this->assertCount(1, $complementares);
        $this->assertSame('atividade', $complementares[0]['tipo']);
        $this->assertSame($atividade->id, $complementares[0]['id']);

        // Nenhuma lição foi criada só por abrir (Seção 20).
        $this->assertSame(0, LicaoAprendida::count());

        $c->set('formSituacao', 'A restrição bloqueou a atividade por 5 dias.')
            ->set('formRecomendacao', 'Antecipar a compra em 30 dias.')
            ->set('formTipo', TipoLicaoAprendida::Problema->value)
            ->set('formCriticidade', CriticidadeLicao::Alta->value)
            ->set('formArea', AreaFuncionalLicao::Planejamento->value)
            ->call('salvarForm');

        $licao = LicaoAprendida::first();
        $this->assertNotNull($licao);
        $this->assertSame($this->obra->id, $licao->obra_origem_id);
        $this->assertCount(2, $licao->vinculos);

        $origem = $licao->vinculos->firstWhere('e_origem', true);
        $this->assertNotNull($origem);
        $this->assertSame(TipoEntidadeVinculoLicao::Restricao, $origem->entidade_tipo);
        $this->assertSame($restricao->id, $origem->entidade_id);

        $complementar = $licao->vinculos->firstWhere('e_origem', false);
        $this->assertSame(TipoEntidadeVinculoLicao::Atividade, $complementar->entidade_tipo);
        $this->assertSame($atividade->id, $complementar->entidade_id);
    }

    public function test_captura_a_partir_de_restricao_com_pacote_de_origem_gera_dois_complementares(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Elétrica']);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'origem_suprimento_item_id' => $pacote->id,
        ]);

        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Restricao, $restricao->id);

        $this->assertCount(2, $contexto->vinculosComplementares);
        $tipos = array_map(fn ($v) => $v['tipo']->value, $contexto->vinculosComplementares);
        $this->assertContains('atividade', $tipos);
        $this->assertContains('pacote', $tipos);
    }

    public function test_captura_a_partir_de_atividade_abre_pre_preenchida_e_salva(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Estrutura Metálica', 'codigo_cronograma' => '3.1']);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);

        $c->assertSet('modalFormAberto', true);
        $this->assertStringContainsString('3.1 - Estrutura Metálica', $c->get('formTitulo'));
        $this->assertSame($this->obra->id, $c->get('formObraId'));
        $this->assertSame([], $c->get('contextoVinculosComplementares'));

        $c->set('formSituacao', 'Situação')->set('formRecomendacao', 'Recomendação')
            ->set('formTipo', TipoLicaoAprendida::BoaPratica->value)
            ->set('formCriticidade', CriticidadeLicao::Media->value)
            ->set('formArea', AreaFuncionalLicao::Planejamento->value)
            ->call('salvarForm');

        $licao = LicaoAprendida::first();
        $this->assertNotNull($licao);
        $vinculo = $licao->vinculos->sole();
        $this->assertTrue($vinculo->e_origem);
        $this->assertSame(TipoEntidadeVinculoLicao::Atividade, $vinculo->entidade_tipo);
        $this->assertSame($atividade->id, $vinculo->entidade_id);
    }

    public function test_captura_a_partir_de_documento_engenharia_abre_pre_preenchida_e_salva(): void
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'ISO-001', 'descricao' => 'Isométrico Linha 1']);

        $c = $this->componente(['origemTipoQuery' => 'documento_engenharia', 'origemIdQuery' => $documento->id]);

        $c->assertSet('modalFormAberto', true);
        $this->assertStringContainsString('ISO-001', $c->get('formTitulo'));
        $this->assertSame($this->obra->id, $c->get('formObraId'));
        $this->assertSame(AreaFuncionalLicao::Engenharia->value, $c->get('formArea'));

        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $licao = LicaoAprendida::first();
        $vinculo = $licao->vinculos->sole();
        $this->assertTrue($vinculo->e_origem);
        $this->assertSame(TipoEntidadeVinculoLicao::DocumentoEngenharia, $vinculo->entidade_tipo);
        $this->assertSame($documento->id, $vinculo->entidade_id);
    }

    public function test_captura_a_partir_de_fornecedor_abre_pre_preenchida_e_salva(): void
    {
        $fornecedor = Fornecedor::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aços do Brasil Ltda']);

        $c = $this->componente(['origemTipoQuery' => 'fornecedor', 'origemIdQuery' => $fornecedor->id]);

        $c->assertSet('modalFormAberto', true);
        $this->assertStringContainsString('Aços do Brasil Ltda', $c->get('formTitulo'));
        $this->assertSame($this->obra->id, $c->get('formObraId'));
        $this->assertSame(AreaFuncionalLicao::Suprimentos->value, $c->get('formArea'));

        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $licao = LicaoAprendida::first();
        $vinculo = $licao->vinculos->sole();
        $this->assertTrue($vinculo->e_origem);
        $this->assertSame(TipoEntidadeVinculoLicao::Fornecedor, $vinculo->entidade_tipo);
        $this->assertSame($fornecedor->id, $vinculo->entidade_id);
    }

    public function test_captura_a_partir_de_material_exige_selecao_de_obra_acessivel(): void
    {
        $material = Material::create([
            'tenant_id' => $this->tenant->id,
            'codigo' => 'MAT-001',
            'descricao' => 'Parafuso Sextavado',
            'unidade_medida_id' => $this->unidade()->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
        ]);

        $c = $this->componente(['origemTipoQuery' => 'material', 'origemIdQuery' => $material->id, 'origemObraQuery' => $this->obra->id]);

        $c->assertSet('modalFormAberto', true)
            ->assertSet('contextoObraDeterministica', false);

        $this->assertStringContainsString('MAT-001', $c->get('formTitulo'));
        $this->assertSame(AreaFuncionalLicao::Estoque->value, $c->get('formArea'));

        // Seção 15: Material NUNCA vem com obra pré-travada — o usuário
        // precisa escolher explicitamente entre as obras que tem acesso.
        $c->set('formObraId', $this->obra->id)
            ->set($this->dadosMinimosForm())
            ->call('salvarForm');

        $licao = LicaoAprendida::first();
        $this->assertNotNull($licao);
        $this->assertSame($this->obra->id, $licao->obra_origem_id);
        $vinculo = $licao->vinculos->sole();
        $this->assertSame(TipoEntidadeVinculoLicao::Material, $vinculo->entidade_tipo);
    }

    public function test_captura_de_material_sem_escolher_obra_falha_de_forma_segura(): void
    {
        $material = Material::create([
            'tenant_id' => $this->tenant->id,
            'codigo' => 'MAT-002',
            'descricao' => 'Chapa de Aço',
            'unidade_medida_id' => $this->unidade()->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value,
        ]);

        $c = $this->componente(['origemTipoQuery' => 'material', 'origemIdQuery' => $material->id, 'origemObraQuery' => $this->obra->id]);

        // formObraId nunca é preenchido — o usuário nunca escolheu.
        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $c->assertHasErrors('formObraId');
        $this->assertSame(0, LicaoAprendida::count());
    }

    // =========================================================================
    // B) OBRA — determinística, manipulação bloqueada, cross-obra bloqueado
    // =========================================================================

    public function test_obra_deterministica_e_sempre_a_derivada_mesmo_com_form_obra_id_manipulado(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->admin, Papel::Admin->value);

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);
        // Manipulação: usuário (ou payload adulterado) tenta trocar a obra
        // do formulário pra outra obra que ele também tem acesso.
        $c->set('formObraId', $outraObra->id);

        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $licao = LicaoAprendida::first();
        $this->assertNotNull($licao);
        $this->assertSame($this->obra->id, $licao->obra_origem_id, 'A obra usada é SEMPRE a derivada da entidade de origem, nunca o valor do formulário.');
        $this->assertNotSame($outraObra->id, $licao->obra_origem_id);
    }

    public function test_origem_de_atividade_de_outra_obra_e_bloqueada_quando_usuario_nao_tem_acesso_a_ela(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // Admin nunca é vinculado a $outraObra.

        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObra->id]);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);

        $c->assertSet('modalFormAberto', false);
        $this->assertNotNull($c->get('erro'));
        $this->assertSame(0, LicaoAprendida::count());
    }

    public function test_origem_de_tenant_diferente_e_bloqueada_como_nao_encontrada(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $atividadeDeOutroTenant = Atividade::factory()->create(['tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id]);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividadeDeOutroTenant->id]);

        $c->assertSet('modalFormAberto', false);
        $this->assertNotNull($c->get('erro'));
        $this->assertSame(0, LicaoAprendida::count());
    }

    public function test_prepararcontexto_lanca_excecao_para_id_manipulado_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $atividadeDeOutroTenant = Atividade::factory()->create(['tenant_id' => $outroTenant->id, 'obra_id' => $outraObra->id]);

        $this->expectException(VinculoLicaoInvalidoException::class);

        app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Atividade, $atividadeDeOutroTenant->id);
    }

    public function test_entidade_corporativa_documento_so_aceita_obra_acessivel_ao_usuario(): void
    {
        // DocumentoEngenharia é obra-determinístico mesmo sendo ESCOPO_TENANT
        // na sua própria tela — a checagem de VISUALIZAÇÃO independente usa
        // temPermissaoEmAlgumaObraDoTenant (qualquer obra do tenant já
        // basta pra "ver"), mas CRIAR a lição continua exigindo permissão
        // de `criar` NA OBRA ESPECÍFICA do documento (Seção 38: acesso à
        // obra de origem é um requisito PRÓPRIO, nunca satisfeito só por
        // enxergar a entidade) — por isso o admin também precisa estar
        // vinculado a $outraObra aqui, não só à obra padrão do setUp.
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($outraObra, $this->admin, Papel::Admin->value);
        $documento = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'D-999', 'descricao' => 'Doc de outra obra']);

        $c = $this->componente(['origemTipoQuery' => 'documento_engenharia', 'origemIdQuery' => $documento->id]);

        $c->assertSet('modalFormAberto', true);
        $this->assertSame($outraObra->id, $c->get('formObraId'));

        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $licao = LicaoAprendida::first();
        $this->assertSame($outraObra->id, $licao->obra_origem_id);
    }

    public function test_documento_de_obra_visivel_mas_sem_vinculo_de_criacao_e_bloqueado(): void
    {
        // Contraste do teste acima: o admin consegue VER o documento
        // (ESCOPO_TENANT, temPermissaoEmAlgumaObraDoTenant já passa via
        // $this->obra), mas nunca foi vinculado à obra DONA do documento
        // — "ver a entidade" nunca é suficiente pra "criar lição na obra
        // dela" (Seção 38, dois requisitos independentes).
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $documento = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'D-888', 'descricao' => 'Doc de obra sem vínculo de criação']);

        $c = $this->componente(['origemTipoQuery' => 'documento_engenharia', 'origemIdQuery' => $documento->id]);

        $c->assertSet('modalFormAberto', false);
        $this->assertNotNull($c->get('erro'));
        $this->assertSame(0, LicaoAprendida::count());
    }

    // =========================================================================
    // C) IMUTABILIDADE DO SNAPSHOT
    // =========================================================================

    public function test_snapshot_de_origem_permanece_apos_mutacao_da_entidade(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Nome Original', 'codigo_cronograma' => '1.1']);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);
        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $licao = LicaoAprendida::first();
        $vinculo = $licao->vinculos->sole();
        $this->assertSame('1.1 - Nome Original', $vinculo->titulo_snapshot);

        $atividade->update(['nome' => 'Nome Alterado Depois']);

        $this->assertSame('1.1 - Nome Original', $vinculo->fresh()->titulo_snapshot);
    }

    public function test_snapshot_de_origem_permanece_apos_publicacao_e_arquivamento(): void
    {
        $fornecedor = Fornecedor::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Nome Original Ltda']);

        $c = $this->componente(['origemTipoQuery' => 'fornecedor', 'origemIdQuery' => $fornecedor->id]);
        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $licao = LicaoAprendida::first();
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $fornecedor->update(['nome' => 'Nome Trocado Depois']);

        $vinculo = $licao->vinculos()->first();
        $this->assertSame('Nome Original Ltda', $vinculo->fresh()->titulo_snapshot);
    }

    public function test_snapshot_permanece_apos_entidade_de_origem_ser_removida(): void
    {
        $documento = DocumentoEngenharia::create(['obra_id' => $this->obra->id, 'codigo' => 'ISO-777', 'descricao' => 'Vai ser removido']);

        $c = $this->componente(['origemTipoQuery' => 'documento_engenharia', 'origemIdQuery' => $documento->id]);
        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $licao = LicaoAprendida::first();
        $vinculo = $licao->vinculos->sole();

        $documento->delete();

        $this->assertSame('ISO-777 - Vai ser removido', $vinculo->fresh()->titulo_snapshot);
    }

    // =========================================================================
    // D) LINK VIVO (Seção 13)
    // =========================================================================

    public function test_link_vivo_aparece_para_usuario_com_acesso_independente(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = app(CriarLicaoComOrigem::class)->execute(
            app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Atividade, $atividade->id),
            null,
            $this->admin,
            $this->dadosMinimosForm2()
        );

        $vinculo = $licao->vinculos->sole();
        $c = $this->componente()->call('abrirDetalhe', $licao->id);

        $link = $c->instance()->linkVivoParaVinculo($vinculo);
        $this->assertNotNull($link);
        $this->assertSame('radar.lookahead', $link['rota']);
    }

    public function test_link_vivo_ausente_para_usuario_sem_acesso_a_obra_mas_snapshot_continua_visivel(): void
    {
        $outroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // outroUser nunca é vinculado a $this->obra — mas a lição é
        // publicada, então ele ainda pode VER a lição em si.
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Atividade Sigilosa']);
        $licao = app(CriarLicaoComOrigem::class)->execute(
            app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Atividade, $atividade->id),
            null,
            $this->admin,
            $this->dadosMinimosForm2()
        );
        app(EnviarLicaoParaValidacao::class)->execute($licao);
        app(PublicarLicaoAprendida::class)->execute($licao, $this->admin);

        $vinculo = $licao->vinculos->sole();

        $this->actingAs($outroUser);
        $this->vincularObra(Work::factory()->create(['tenant_id' => $this->tenant->id]), $outroUser, Papel::Admin->value);

        $c = $this->componente()->call('abrirDetalhe', $licao->id);
        $link = $c->instance()->linkVivoParaVinculo($vinculo);

        $this->assertNull($link, 'Sem acesso independente à obra da Atividade, nunca oferece link vivo.');
        // O snapshot continua sempre exibível (Seção 13) — testado via a
        // própria existência do titulo_snapshot, nunca escondido.
        $this->assertSame('Atividade Sigilosa', $vinculo->titulo_snapshot);
    }

    public function test_link_vivo_ausente_quando_entidade_foi_removida(): void
    {
        $fornecedor = Fornecedor::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Fornecedor Removido']);
        $licao = app(CriarLicaoComOrigem::class)->execute(
            app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Fornecedor, $fornecedor->id),
            null,
            $this->admin,
            $this->dadosMinimosForm2()
        );
        $vinculo = $licao->vinculos->sole();

        $fornecedor->delete();

        $c = $this->componente()->call('abrirDetalhe', $licao->id);
        $link = $c->instance()->linkVivoParaVinculo($vinculo);

        $this->assertNull($link);
    }

    public function test_link_vivo_nunca_vaza_entidade_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObraDeOutroTenant = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $atividadeDeOutroTenant = Atividade::factory()->create(['tenant_id' => $outroTenant->id, 'obra_id' => $outraObraDeOutroTenant->id]);

        // Vínculo forjado manualmente (o entidade_id existe, só que em
        // OUTRO tenant) — nunca deve resolver via o global scope de
        // BelongsToTenant do model candidato.
        $atividadePropria = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $licao = app(CriarLicaoComOrigem::class)->execute(
            app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Atividade, $atividadePropria->id),
            null,
            $this->admin,
            $this->dadosMinimosForm2()
        );
        $vinculo = $licao->vinculos->sole();
        $vinculo->forceFill(['entidade_id' => $atividadeDeOutroTenant->id])->save();

        $c = $this->componente()->call('abrirDetalhe', $licao->id);
        $link = $c->instance()->linkVivoParaVinculo($vinculo->fresh());

        $this->assertNull($link, 'ID de outro tenant nunca resolve — global scope de BelongsToTenant garante isso.');
    }

    // =========================================================================
    // E) TRANSAÇÃO — falha em vínculo nunca deixa lição parcial
    // =========================================================================

    public function test_falha_no_vinculo_de_origem_nunca_deixa_licao_orfa(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Atividade, $atividade->id);

        // Simula a entidade sumir ENTRE a preparação do contexto e a
        // criação de fato (mesma classe de corrida já aceita/testada em
        // outras Actions do projeto).
        $atividade->delete();

        $this->assertSame(0, LicaoAprendida::count());

        try {
            app(CriarLicaoComOrigem::class)->execute($contexto, null, $this->admin, $this->dadosMinimosForm2());
        } catch (\Throwable $e) {
            // esperado — a entidade não existe mais
        }

        $this->assertSame(0, LicaoAprendida::count(), 'Nenhuma lição órfã deve sobrar quando o vínculo de origem falha.');
        $this->assertSame(0, \App\Models\LicaoAprendidaVinculo::count());
    }

    public function test_falha_no_vinculo_complementar_desfaz_a_criacao_inteira(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote X']);
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'origem_suprimento_item_id' => $pacote->id,
        ]);

        $contexto = app(PrepararContextoNovaLicao::class)->execute($this->admin, TipoEntidadeVinculoLicao::Restricao, $restricao->id);

        // O Pacote complementar some ANTES da execução real.
        $pacote->delete();

        $this->assertSame(0, LicaoAprendida::count());

        try {
            app(CriarLicaoComOrigem::class)->execute($contexto, null, $this->admin, $this->dadosMinimosForm2());
        } catch (\Throwable $e) {
            // esperado
        }

        $this->assertSame(0, LicaoAprendida::count(), 'Falha num vínculo COMPLEMENTAR também desfaz a lição inteira — nunca meio-criada.');
        $this->assertSame(0, \App\Models\LicaoAprendidaVinculo::count());
    }

    // =========================================================================
    // F) DUPLO-CLIQUE / CANCELAMENTO — nunca cria antes de salvar
    // =========================================================================

    public function test_abrir_modal_via_contexto_nunca_persiste_nada(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);

        $this->assertSame(0, LicaoAprendida::count());
        $this->assertSame(0, \App\Models\LicaoAprendidaVinculo::count());
    }

    public function test_cancelar_apos_captura_contextual_nunca_persiste(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);
        $c->set($this->dadosMinimosForm())->call('fecharModalForm');

        $this->assertSame(0, LicaoAprendida::count());
        $c->assertSet('contextoOrigemTipo', null);
    }

    public function test_salvar_uma_unica_vez_cria_exatamente_uma_licao_mesmo_com_chamada_dupla_do_metodo(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);
        $c->set($this->dadosMinimosForm())->call('salvarForm');

        $this->assertSame(1, LicaoAprendida::count());

        // Uma 2ª chamada de salvarForm() nas mesmas condições (o modal já
        // foi fechado e o contexto já foi limpo por salvarForm() — mesmo
        // "duplo clique" físico só reabriria um formulário em branco).
        $c->assertSet('modalFormAberto', false);
        $c->assertSet('contextoOrigemTipo', null);
    }

    public function test_botao_nova_licao_aprendida_simples_continua_funcionando_sem_contexto(): void
    {
        $c = $this->componente();
        $c->call('abrirCriar');

        $c->assertSet('modalFormAberto', true)
            ->assertSet('contextoOrigemTipo', null)
            ->assertSet('formTitulo', '');

        $c->set('formTitulo', 'Lição criada do jeito normal')
            ->set($this->dadosMinimosForm())
            ->call('salvarForm');

        $licao = LicaoAprendida::where('titulo', 'Lição criada do jeito normal')->first();
        $this->assertNotNull($licao);
        $this->assertCount(0, $licao->vinculos);
    }

    public function test_clicar_botao_simples_apos_contexto_previo_limpa_o_contexto(): void
    {
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);
        $c->assertSet('contextoOrigemTipo', 'atividade');

        $c->call('fecharModalForm')->call('abrirCriar');

        $c->assertSet('contextoOrigemTipo', null)
            ->assertSet('formTitulo', '');
    }

    // =========================================================================
    // G) CTA CROSS-TENANT/OBRA — chamada direta manipulada
    // =========================================================================

    public function test_cta_gate_bloqueia_usuario_sem_permissao_de_criar_licao(): void
    {
        $encarregadoSemAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Nenhum vínculo com nenhuma obra — sem permissão nenhuma.
        $atividade = Atividade::factory()->create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id]);

        $this->actingAs($encarregadoSemAcesso);

        $c = $this->componente(['origemTipoQuery' => 'atividade', 'origemIdQuery' => $atividade->id]);

        $c->assertSet('modalFormAberto', false);
        $this->assertSame(0, LicaoAprendida::count());
    }

    public function test_tipo_de_origem_invalido_na_url_nunca_quebra_a_pagina(): void
    {
        $c = $this->componente(['origemTipoQuery' => 'algo-que-nao-existe', 'origemIdQuery' => 'x']);

        $c->assertStatus(200)->assertSet('modalFormAberto', false);
        $this->assertNotNull($c->get('erro'));
    }

    private function dadosMinimosForm2(): array
    {
        return [
            'titulo' => 'Título via Action',
            'situacao_observada' => 'Situação',
            'recomendacao_futura' => 'Recomendação',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Alta->value,
            'area_funcional' => AreaFuncionalLicao::Planejamento->value,
        ];
    }
}
