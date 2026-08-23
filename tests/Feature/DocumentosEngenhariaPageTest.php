<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\PacoteEngenharia;
use App\Models\PerfilPermissao;
use App\Models\StatusDocumento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentosEngenhariaPageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function componente()
    {
        return Livewire::test('pages::engenharia.documentos-engenharia');
    }

    /**
     * Status agora é da emissão, não do documento — passar $status aqui
     * também cria a 1ª emissão (revisão "R0") com aquele status, já que
     * um documento não pode "ter status" sem nenhuma emissão registrada.
     */
    private function criarDocumento(string $codigo, string $descricao, ?StatusDocumento $status = null, ?string $dataPlanejada = null): DocumentoEngenharia
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => $codigo,
            'descricao' => $descricao,
            'data_planejada' => $dataPlanejada,
        ]);

        if ($status) {
            $documento->revisoes()->create([
                'tenant_id' => $this->tenant->id,
                'revisao' => 'R0',
                'descricao' => 'Emissão inicial',
                'status_documento_id' => $status->id,
            ]);
        }

        return $documento;
    }

    public function test_admin_cria_documento(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Estrutural']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriarDocumento')
            ->set('codigoNovo', 'DOC-001')
            ->set('descricaoNovo', 'Projeto Estrutural')
            ->set('disciplinaIdNovo', $disciplina->id)
            ->set('dataPrevistaNovo', '2026-08-01')
            ->call('salvarDocumento')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documentos_engenharia', [
            'obra_id' => $this->obra->id,
            'tenant_id' => $this->tenant->id,
            'codigo' => 'DOC-001',
            'descricao' => 'Projeto Estrutural',
            'disciplina_id' => $disciplina->id,
            'data_planejada' => '2026-08-01',
        ]);
    }

    public function test_novo_documento_sem_previsao_falha_validacao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriarDocumento')
            ->set('codigoNovo', 'DOC-SEM-PREV')
            ->set('descricaoNovo', 'Documento')
            ->call('salvarDocumento')
            ->assertHasErrors(['dataPrevistaNovo']);
    }

    public function test_admin_edita_documento(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-002', 'Descrição Antiga');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editarDocumento', $documento->id)
            ->set('descricaoNovo', 'Descrição Nova')
            ->call('salvarDocumento');

        $this->assertDatabaseHas('documentos_engenharia', ['id' => $documento->id, 'descricao' => 'Descrição Nova']);
    }

    public function test_editar_documento_altera_previsao_gera_reprogramacao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-REPROG', 'Documento', null, '2026-07-01');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editarDocumento', $documento->id)
            ->set('dataPrevistaNovo', '2026-08-15')
            ->call('salvarDocumento');

        $this->assertDatabaseHas('documento_engenharia_reprogramacoes', [
            'documento_engenharia_id' => $documento->id,
            'data_anterior' => '2026-07-01',
            'data_nova' => '2026-08-15',
            'criado_por_id' => $this->user->id,
        ]);
        $this->assertSame(1, $documento->fresh()->reprogramacoes()->count());

        // reprograma de novo — badge deve contar 2
        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editarDocumento', $documento->id)
            ->set('dataPrevistaNovo', '2026-09-01')
            ->call('salvarDocumento');

        $this->assertSame(2, $documento->fresh()->reprogramacoes()->count());
    }

    public function test_admin_exclui_documento(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-003', 'Doc a Remover');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluirDocumento', $documento->id);

        $this->assertSoftDeleted('documentos_engenharia', ['id' => $documento->id]);
    }

    public function test_cards_contam_concluidos_aguardando_e_atrasados(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $statusConcluido = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Concluído', 'conclusivo' => true]);
        $statusPendente = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Em Elaboração', 'conclusivo' => false]);

        $this->criarDocumento('DOC-C', 'Concluído', $statusConcluido);
        $this->criarDocumento('DOC-E', 'Emitido não concluído', $statusPendente);
        $this->criarDocumento('DOC-AG', 'Aguardando', null, now()->addDays(10)->toDateString());
        $this->criarDocumento('DOC-AT', 'Atrasado', null, now()->subDays(5)->toDateString());

        $component = $this->componente()->set('obraId', $this->obra->id);

        $totais = $component->instance()->totais;
        $this->assertSame(4, $totais['total']);
        $this->assertSame(1, $totais['concluidos']);
        $this->assertSame(1, $totais['aguardando']);
        $this->assertSame(1, $totais['atrasados']);
    }

    public function test_cards_de_kpi_clicaveis_aplicam_filtro_correspondente(): void
    {
        // Mesmo cenário de test_cards_contam_concluidos_aguardando_e_atrasados
        // — aqui confirma que os 3 pseudo-valores novos de statusIdFiltro
        // (usados pelo wire:click dos cards) filtram exatamente o documento
        // esperado, sem afetar o filtro por status_documento_id explícito
        // nem o "__nao_emitido__" já existente.
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $statusConcluido = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Concluído', 'conclusivo' => true]);
        $statusPendente = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Em Elaboração', 'conclusivo' => false]);

        $this->criarDocumento('DOC-C', 'Concluído', $statusConcluido);
        $this->criarDocumento('DOC-E', 'Emitido não concluído', $statusPendente);
        $this->criarDocumento('DOC-AG', 'Aguardando', null, now()->addDays(10)->toDateString());
        $this->criarDocumento('DOC-AT', 'Atrasado', null, now()->subDays(5)->toDateString());

        $component = $this->componente()->set('obraId', $this->obra->id);

        $component->set('statusIdFiltro', '__concluido__')
            ->assertSee('DOC-C')->assertDontSee('DOC-E')->assertDontSee('DOC-AG')->assertDontSee('DOC-AT');

        $component->set('statusIdFiltro', '__aguardando__')
            ->assertSee('DOC-AG')->assertDontSee('DOC-C')->assertDontSee('DOC-E')->assertDontSee('DOC-AT');

        $component->set('statusIdFiltro', '__atrasado__')
            ->assertSee('DOC-AT')->assertDontSee('DOC-C')->assertDontSee('DOC-E')->assertDontSee('DOC-AG');

        // Card "Total" limpa o filtro — todos voltam a aparecer.
        $component->set('statusIdFiltro', null)
            ->assertSee('DOC-C')->assertSee('DOC-E')->assertSee('DOC-AG')->assertSee('DOC-AT');
    }

    public function test_documento_sem_emissao_mostra_nao_emitido_ate_primeira_emissao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-NE', 'Documento Sem Emissão');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('Não Emitido');

        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aprovado']);
        $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'descricao' => 'Emissão',
            'status_documento_id' => $status->id,
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('Aprovado');
    }

    public function test_filtro_por_status_mostra_so_documentos_correspondentes(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $statusA = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Status A']);
        $statusB = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Status B']);

        $this->criarDocumento('DOC-A', 'Documento A', $statusA);
        $this->criarDocumento('DOC-B', 'Documento B', $statusB);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->set('statusIdFiltro', $statusA->id)
            ->assertSee('DOC-A')
            ->assertDontSee('DOC-B');
    }

    public function test_filtro_nao_emitido_mostra_so_documentos_sem_emissao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aprovado']);
        $this->criarDocumento('DOC-EMITIDO', 'Documento Emitido', $status);
        $this->criarDocumento('DOC-NAO-EMITIDO', 'Documento Sem Emissão');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->set('statusIdFiltro', '__nao_emitido__')
            ->assertSee('DOC-NAO-EMITIDO')
            ->assertDontSee('DOC-EMITIDO');
    }

    public function test_busca_por_codigo_ou_descricao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $this->criarDocumento('ABC-100', 'Memorial de Cálculo');
        $this->criarDocumento('XYZ-200', 'Planta Baixa');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->set('busca', 'Memorial')
            ->assertSee('ABC-100')
            ->assertDontSee('XYZ-200');
    }

    public function test_paginacao_com_15_por_pagina_como_padrao_e_configuravel_de_5_em_5(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        foreach (range(1, 18) as $i) {
            $this->criarDocumento(sprintf('DOC-%02d', $i), "Documento {$i}");
        }

        $component = $this->componente()->set('obraId', $this->obra->id);

        // padrão: 15 por página, DOC-01..DOC-15 na primeira página, DOC-16+ só na segunda
        $component->assertSet('perPage', 15)
            ->assertSee('DOC-01')
            ->assertSee('DOC-15')
            ->assertDontSee('DOC-16');

        // trocar pra 5 em 5 reseta a paginação e respeita o novo tamanho
        $component->set('perPage', 5)
            ->assertSee('DOC-01')
            ->assertSee('DOC-05')
            ->assertDontSee('DOC-06');
    }

    public function test_documentos_de_uma_obra_nao_aparecem_em_outra(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $outraObra->id, 'codigo' => 'OUT-1', 'descricao' => 'De outra obra']);
        $this->criarDocumento('EST-1', 'Desta obra');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertSee('EST-1')
            ->assertDontSee('OUT-1');
    }

    public function test_badge_de_revisoes_mostra_contagem_e_popup_lista_revisoes(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-R', 'Documento Com Revisões');
        $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'data_emissao' => '2026-05-01',
            'descricao' => 'Emissão inicial para aprovação',
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->assertDontSee('Emissão inicial para aprovação')
            ->call('abrirRevisoes', $documento->id)
            ->assertSee('R0')
            ->assertSee('Emissão inicial para aprovação');
    }

    public function test_popup_do_documento_mostra_detalhes_completos_e_linha_aciona_o_popup(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $disciplina = Disciplina::create(['tenant_id' => $this->tenant->id, 'nome' => 'Mecânica']);
        $pacote = PacoteEngenharia::create(['obra_id' => $this->obra->id, 'nome' => 'Frente 1']);
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-DETALHE',
            'descricao' => 'Documento com detalhes completos',
            'disciplina_id' => $disciplina->id,
            'pacote_engenharia_id' => $pacote->id,
        ]);

        $component = $this->componente()->set('obraId', $this->obra->id);

        // a linha inteira da tabela deve abrir o popup no clique, não só um badge isolado
        $component->assertSeeHtml('wire:click="abrirRevisoes(\'' . $documento->id . '\')"');

        $component->call('abrirRevisoes', $documento->id)
            ->assertSee('DOC-DETALHE')
            ->assertSee('Documento com detalhes completos')
            ->assertSee('Mecânica')
            ->assertSee('Frente 1');
    }

    public function test_adicionar_revisao_sem_anexo(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-NOVA-REV', 'Documento');
        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aprovado']);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->set('revisaoNovaTexto', 'R0')
            ->set('revisaoNovaData', '2026-07-01')
            ->set('revisaoNovaStatusId', $status->id)
            ->set('revisaoNovaDescricao', 'Emissão inicial')
            ->call('adicionarRevisao')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documento_engenharia_revisoes', [
            'documento_engenharia_id' => $documento->id,
            'revisao' => 'R0',
            'descricao' => 'Emissão inicial',
            'status_documento_id' => $status->id,
            'criado_por_id' => $this->user->id,
        ]);
    }

    public function test_nova_emissao_sem_status_falha_validacao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-SEM-STATUS', 'Documento');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->set('revisaoNovaTexto', 'R0')
            ->set('revisaoNovaDescricao', 'Emissão inicial')
            ->call('adicionarRevisao')
            ->assertHasErrors(['revisaoNovaStatusId']);
    }

    /**
     * Ciclo 18, Etapa 18.2 — mecanismo mudou (não a garantia funcional):
     * antes o upload ia pro disco `public` (URL direta, sem autorização);
     * agora vai pro disco `local` (privado, só acessível via
     * DocumentoEngenhariaRevisaoController::download() autorizado).
     * Prova explicitamente as duas pontas: existe no privado E nunca
     * aparece no público — exatamente o requisito da Etapa 18.2.
     */
    public function test_adicionar_revisao_com_anexo_pdf(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $documento = $this->criarDocumento('DOC-PDF', 'Documento');
        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aprovado']);
        $pdf = UploadedFile::fake()->create('projeto.pdf', 500, 'application/pdf');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->set('revisaoNovaTexto', 'R1')
            ->set('revisaoNovaData', '2026-07-02')
            ->set('revisaoNovaStatusId', $status->id)
            ->set('revisaoNovaDescricao', 'Revisão com anexo')
            ->set('revisaoNovaAnexo', $pdf)
            ->call('adicionarRevisao')
            ->assertHasNoErrors();

        $revisao = $documento->revisoes()->where('revisao', 'R1')->firstOrFail();
        $this->assertNotNull($revisao->anexo_path);
        $this->assertSame('projeto.pdf', $revisao->anexo_nome_original);
        Storage::disk('local')->assertExists($revisao->anexo_path);
        Storage::disk('public')->assertMissing($revisao->anexo_path);
    }

    public function test_revisao_duplicada_falha_validacao(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $status = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Aprovado']);
        $documento = $this->criarDocumento('DOC-DUP', 'Documento');
        $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'data_emissao' => '2026-06-01',
            'descricao' => 'Primeira',
            'status_documento_id' => $status->id,
        ]);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirRevisoes', $documento->id)
            ->set('revisaoNovaTexto', 'R0')
            ->set('revisaoNovaData', '2026-07-01')
            ->set('revisaoNovaStatusId', $status->id)
            ->set('revisaoNovaDescricao', 'Duplicada')
            ->call('adicionarRevisao')
            ->assertHasErrors(['revisaoNovaTexto']);
    }

    public function test_gerenciar_pacotes_criar_editar_e_excluir(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);

        $component = $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirGerenciarPacotes')
            ->set('novoPacoteNome', 'Tanques')
            ->call('criarPacote');

        $pacote = PacoteEngenharia::where('nome', 'Tanques')->firstOrFail();

        $component
            ->call('iniciarEdicaoPacote', $pacote->id)
            ->set('pacoteEditandoNome', 'Tanques de Armazenamento')
            ->call('salvarEdicaoPacote');

        $this->assertDatabaseHas('pacotes_engenharia', ['id' => $pacote->id, 'nome' => 'Tanques de Armazenamento']);

        $component->call('excluirPacote', $pacote->id);
        $this->assertSoftDeleted('pacotes_engenharia', ['id' => $pacote->id]);
    }

    public function test_perfil_sem_permissao_nao_consegue_criar_editar_excluir(): void
    {
        $perfil = $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', 'engenharia.pacotes')
            ->delete();
        $this->actingAs($this->user);

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('abrirCriarDocumento')
            ->assertForbidden();

        $documento = $this->criarDocumento('DOC-FORBID', 'Documento');

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('editarDocumento', $documento->id)
            ->assertForbidden();

        $this->componente()
            ->set('obraId', $this->obra->id)
            ->call('excluirDocumento', $documento->id)
            ->assertForbidden();

        $this->assertDatabaseHas('documentos_engenharia', ['id' => $documento->id, 'deleted_at' => null]);
    }

    public function test_percentual_concluido_do_pacote_considera_status_conclusivo(): void
    {
        $statusConcluido = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Concluído', 'conclusivo' => true]);
        $statusPendente = StatusDocumento::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pendente', 'conclusivo' => false]);

        $pacote = PacoteEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote com Datas']);

        $d1 = $pacote->documentos()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'D1',
            'descricao' => 'Doc Concluido',
            'data_planejada' => '2026-12-31',
        ]);
        $d1->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'descricao' => 'Emissão',
            'status_documento_id' => $statusConcluido->id,
        ]);

        $d2 = $pacote->documentos()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'D2',
            'descricao' => 'Doc Pendente',
            'data_planejada' => '2026-08-15',
        ]);
        $d2->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'descricao' => 'Emissão',
            'status_documento_id' => $statusPendente->id,
        ]);

        $pacote = $pacote->fresh(['documentos.latestRevisao.statusDocumento']);
        $this->assertSame(50.0, $pacote->percentualConcluido());
        $this->assertTrue($pacote->proximaDataLimite()->isSameDay(\Carbon\Carbon::parse('2026-08-15')));
    }
}
