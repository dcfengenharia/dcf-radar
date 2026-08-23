<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Engenharia\AtualizarRascunhoGrd;
use App\Actions\Engenharia\CriarGrd;
use App\Actions\Engenharia\EmitirGrd;
use App\Actions\Engenharia\RegistrarRecolhimento;
use App\Enums\Papel;
use App\Enums\ResultadoRecolhimento;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdDistribuicao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 18, Etapa 18.5.4 — "Central Operacional de Distribuição": cards +
 * filtros + colunas empresa/setor + badge "Destinatário inativo" nas abas
 * Obsoletas/Candidatos já existentes (18.5.2). Nenhuma regra nova — só
 * consome `DetectorCopiasObsoletasGrd`/`CandidatosNovaEntregaGrd`
 * (18.5.1) já aprovados, filtrando/ordenando EM MEMÓRIA no componente.
 */
class GrdDistribuicaoOperacionalTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::engenharia.grds');
    }

    private function doc(array $o = [], ?Work $obra = null): DocumentoEngenharia
    {
        return DocumentoEngenharia::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'Planta baixa ' . uniqid(),
        ], $o));
    }

    private function rev(DocumentoEngenharia $d, string $texto = 'R1'): DocumentoEngenhariaRevisao
    {
        return $d->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => $texto, 'descricao' => 'x'])->fresh();
    }

    private function liberar(DocumentoEngenhariaRevisao $r, ?User $usuario = null): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($r, $usuario ?? $this->user);
    }

    private function destinatario(array $o = [], ?Work $obra = null): Destinatario
    {
        return Destinatario::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => ($obra ?? $this->obra)->id,
            'nome' => 'Destinatario ' . uniqid(),
        ], $o));
    }

    /** Emite uma GRD com 1 documento (R1) x 1 destinatário, quantidade dada. Retorna doc/r1/destinatario/grd/distribuicao. */
    private function emitirGrdComUmaEntrega(DocumentoEngenhariaRevisao $revisao, Destinatario $destinatario, int $quantidade = 1, ?User $emitente = null): array
    {
        $emitente ??= $this->user;
        $grd = (new CriarGrd())->execute($this->obra, $emitente);
        $acoes = new AtualizarRascunhoGrd();
        $item = $acoes->adicionarItem($grd, $revisao->fresh());
        $gd = $acoes->adicionarDestinatario($grd, $destinatario);
        $acoes->marcarDistribuicao($grd, $item, $gd, $quantidade);
        $grd = (new EmitirGrd())->execute($grd, $emitente);
        $dist = GrdDistribuicao::where('grd_item_id', $item->id)->where('grd_destinatario_id', $gd->id)->firstOrFail();

        return compact('grd', 'item', 'gd', 'dist');
    }

    // ===================== A-E: cards nunca divergem da lista (obsoletas) =====================

    public function test_a_cards_obsoletas_batem_com_a_lista_sem_filtro(): void
    {
        $doc1 = $this->doc();
        $r1a = $this->rev($doc1, 'R1');
        $this->liberar($r1a->fresh());
        $joao = $this->destinatario(['nome' => 'Joao']);
        $this->emitirGrdComUmaEntrega($r1a, $joao, 3);
        $r2a = $this->rev($doc1, 'R2');
        $this->liberar($r2a->fresh());

        $doc2 = $this->doc();
        $r1b = $this->rev($doc2, 'R1');
        $this->liberar($r1b->fresh());
        $maria = $this->destinatario(['nome' => 'Maria']);
        $this->emitirGrdComUmaEntrega($r1b, $maria, 5);
        $r2b = $this->rev($doc2, 'R2');
        $this->liberar($r2b->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');

        $this->assertSame(2, $c->instance()->cardsObsoletas['copias_pendentes']);
        $this->assertSame(2, $c->instance()->cardsObsoletas['destinatarios']);
        $this->assertSame(2, $c->instance()->cardsObsoletas['documentos']);
        $this->assertSame(8, $c->instance()->cardsObsoletas['quantidade_total_pendente']);
        $this->assertCount(2, $c->instance()->obsoletas);
    }

    public function test_b_busca_documento_filtra_obsoletas_e_cards_acompanham(): void
    {
        $doc1 = $this->doc(['codigo' => 'PROJ-001']);
        $r1a = $this->rev($doc1);
        $this->liberar($r1a->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1a, $joao, 1);
        $this->liberar($this->rev($doc1, 'R2')->fresh());

        $doc2 = $this->doc(['codigo' => 'PROJ-002']);
        $r1b = $this->rev($doc2);
        $this->liberar($r1b->fresh());
        $maria = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1b, $maria, 1);
        $this->liberar($this->rev($doc2, 'R2')->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');
        $c->set('buscaObsoletaDocumento', 'PROJ-001');

        $this->assertCount(1, $c->instance()->obsoletas);
        $this->assertSame(1, $c->instance()->cardsObsoletas['copias_pendentes']);
        $this->assertSame('PROJ-001', $c->instance()->obsoletas->first()->documento->codigo);
    }

    public function test_c_filtro_destinatario_e_estado_filtram_obsoletas(): void
    {
        $doc1 = $this->doc();
        $r1a = $this->rev($doc1);
        $this->liberar($r1a->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Estado']);
        ['dist' => $dist1] = $this->emitirGrdComUmaEntrega($r1a, $joao, 2);
        $this->liberar($this->rev($doc1, 'R2')->fresh());

        $doc2 = $this->doc();
        $r1b = $this->rev($doc2);
        $this->liberar($r1b->fresh());
        $maria = $this->destinatario(['nome' => 'Maria Estado']);
        $this->emitirGrdComUmaEntrega($r1b, $maria, 2);
        $this->liberar($this->rev($doc2, 'R2')->fresh());

        // Não localizado só na entrega do João
        (new RegistrarRecolhimento())->execute($dist1, ResultadoRecolhimento::NaoLocalizado, 2, $this->user);

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');

        $c->set('destinatarioObsoletaFiltro', $joao->id);
        $this->assertCount(1, $c->instance()->obsoletas);
        $this->assertSame('Joao Estado', $c->instance()->obsoletas->first()->grd_destinatario->nome_snapshot);

        $c->set('destinatarioObsoletaFiltro', null);
        $c->set('estadoObsoletaFiltro', 'nao_localizado');
        $this->assertCount(1, $c->instance()->obsoletas);
        $this->assertSame('Joao Estado', $c->instance()->obsoletas->first()->grd_destinatario->nome_snapshot);

        $c->set('estadoObsoletaFiltro', 'pendente');
        $this->assertCount(1, $c->instance()->obsoletas);
        $this->assertSame('Maria Estado', $c->instance()->obsoletas->first()->grd_destinatario->nome_snapshot);
    }

    public function test_d_busca_e_filtro_sem_correspondencia_esvazia_lista_e_cards(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $joao = $this->destinatario();
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->liberar($this->rev($doc, 'R2')->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');
        $c->set('buscaObsoletaDocumento', 'NAO-EXISTE-NADA');

        $this->assertCount(0, $c->instance()->obsoletas);
        $this->assertSame(0, $c->instance()->cardsObsoletas['copias_pendentes']);
        $this->assertSame(0, $c->instance()->cardsObsoletas['quantidade_total_pendente']);
        $c->assertSee('Nenhuma cópia obsoleta em campo nesta obra.');
    }

    public function test_e_empresa_e_setor_aparecem_na_listagem_de_obsoletas(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Empresa', 'empresa' => 'Construtora XPTO', 'setor' => 'Obras']);
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->liberar($this->rev($doc, 'R2')->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');
        $c->assertSee('Construtora XPTO')->assertSee('Obras');
    }

    // ===================== F-H: destinatário inativo (obsoletas) =====================

    public function test_f_destinatario_soft_deletado_mostra_badge_inativo_sem_alterar_nome_snapshot(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Vai Sumir', 'empresa' => 'Empresa Antiga']);
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->liberar($this->rev($doc, 'R2')->fresh());

        $joao->delete();
        $joao->update(['nome' => 'Nome Que Nunca Deve Aparecer']); // simula edição pós-inativação: nome vivo diverge do snapshot

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');
        $c->assertSee('Joao Vai Sumir'); // snapshot histórico, nunca o nome vivo
        $c->assertDontSee('Nome Que Nunca Deve Aparecer');
        $c->assertSee('Destinatário inativo');
    }

    public function test_g_destinatario_inativo_ainda_pode_ser_usado_como_filtro_em_obsoletas(): void
    {
        $doc = $this->doc();
        $r1 = $this->rev($doc);
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Inativo Filtravel']);
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->liberar($this->rev($doc, 'R2')->fresh());
        $joao->delete();

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');
        $c->set('destinatarioObsoletaFiltro', $joao->id);

        $this->assertCount(1, $c->instance()->obsoletas);
    }

    public function test_h_destinatario_inativo_nunca_aparece_no_filtro_nem_na_lista_de_candidatos(): void
    {
        ['doc' => $doc, 'joao' => $joao] = $this->cenarioComCandidato();
        $joao->delete();

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'candidatos');

        $this->assertCount(0, $c->instance()->candidatos);
        $this->assertFalse($c->instance()->opcoesDestinatarioCandidatos->pluck('id')->contains($joao->id));
    }

    // ===================== I-K: cards + filtros de candidatos =====================

    public function test_i_card_candidatos_bate_com_a_lista_sem_filtro(): void
    {
        $this->cenarioComCandidato(['nome' => 'Joao Cand 1']);
        $this->cenarioComCandidato(['nome' => 'Maria Cand 2']);

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'candidatos');

        $this->assertSame(2, $c->instance()->cardsCandidatos['destinatarios']);
        $this->assertCount(2, $c->instance()->candidatos);
    }

    public function test_j_busca_documento_filtra_candidatos(): void
    {
        ['doc' => $doc1] = $this->cenarioComCandidato(['nome' => 'Joao Busca'], ['codigo' => 'PROJ-100']);
        $this->cenarioComCandidato(['nome' => 'Maria Busca'], ['codigo' => 'PROJ-200']);

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'candidatos');
        $c->set('buscaCandidatoDocumento', 'PROJ-100');

        $this->assertCount(1, $c->instance()->candidatos);
        $this->assertSame($doc1->id, $c->instance()->candidatos->first()->documento->id);
    }

    public function test_k_filtro_destinatario_filtra_candidatos(): void
    {
        ['joao' => $joao] = $this->cenarioComCandidato(['nome' => 'Joao Filtro Cand']);
        $this->cenarioComCandidato(['nome' => 'Maria Filtro Cand']);

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'candidatos');
        $c->set('destinatarioCandidatoFiltro', $joao->id);

        $this->assertCount(1, $c->instance()->candidatos);
        $this->assertSame('Joao Filtro Cand', $c->instance()->candidatos->first()->destinatario->nome);
    }

    // ===================== L: fim a fim, João R1→R2 completo (mandatório) =====================

    /**
     * Cenário crítico ponta a ponta pedido no briefing 18.5.4: João recebe
     * R1, R2 nasce e é liberada (João vira obsoleta + candidato ao mesmo
     * tempo é impossível — obsoleta exige ter recebido a revisão entregue,
     * candidato exige NÃO ter recebido a vigente; aqui João já É obsoleta
     * assim que R2 é liberada). Cards/filtros acompanham em cada etapa,
     * recolhimento parcial não altera o card de "documentos afetados", e
     * nova entrega (R2) resolve a obsolescência de João sem tocar a
     * distribuição de R1 (mesma garantia já provada em 18.5.1 — nunca
     * recolhe automaticamente).
     */
    public function test_l_fluxo_completo_joao_r1_r2_obsoleta_recolhimento_nova_entrega(): void
    {
        $doc = $this->doc(['codigo' => 'PROJ-CRITICO']);
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario(['nome' => 'Joao Critico', 'empresa' => 'Obra Alfa']);

        $entregaR1 = $this->emitirGrdComUmaEntrega($r1, $joao, 4);
        $distR1 = $entregaR1['dist'];

        $c = $this->componente()->set('obraId', $this->obra->id);

        // Antes de R2 existir: nem obsoleta nem candidato.
        $c->call('setAba', 'obsoletas');
        $this->assertCount(0, $c->instance()->obsoletas);

        // R2 nasce (ainda não liberada): vigente já é R2 (estrutural, independe de liberação) — João já é obsoleta.
        // Candidato, ao contrário, exige revisão vigente LIBERADA — continua vazio até liberar.
        $r2 = $this->rev($doc, 'R2');
        $c->call('setAba', 'candidatos');
        $this->assertCount(0, $c->instance()->candidatos);

        $c->call('setAba', 'obsoletas');
        $this->assertCount(1, $c->instance()->obsoletas);
        $this->assertSame(4, $c->instance()->cardsObsoletas['quantidade_total_pendente']);
        $c->assertSee('Obra Alfa');

        // Liberar R2 não muda a obsolescência de R1 (já obsoleta desde que R2 nasceu) — habilita o candidato.
        $this->liberar($r2->fresh());

        // Recolhimento parcial de R1: pendência cai, mas "documentos afetados" continua 1 (ainda pendente > 0).
        $c->call('abrirModalRecolhimento', $distR1->id);
        $c->set('recolhimentoResultado', 'recolhido')->set('recolhimentoQuantidade', 1)->call('confirmarRecolhimento');
        $this->assertSame(3, $c->instance()->cardsObsoletas['quantidade_total_pendente']);
        $this->assertSame(1, $c->instance()->cardsObsoletas['documentos']);

        // Nova entrega (R2) via atalho de candidato — some de candidatos, obsoleta de R1 continua intocada.
        $c->call('setAba', 'candidatos');
        $this->assertCount(1, $c->instance()->candidatos);
        $c->call('criarGrdAPartirDeCandidato', $doc->id, $joao->id);
        $grd2 = Grd::findOrFail($c->get('grdAbertaId'));
        $item2 = $grd2->itens()->first();
        $gd2 = $grd2->destinatarios()->first();
        $c->call('marcarCelula', $item2->id, $gd2->id);
        $c->call('abrirModalEmitir')->call('confirmarEmissao');

        $c->call('setAba', 'candidatos');
        $this->assertCount(0, $c->instance()->candidatos);

        // R1 nunca foi recolhida automaticamente pela nova entrega de R2 (garantia 18.5.1).
        $this->assertSame(3, $distR1->fresh()->quantidadePendente());
        $c->call('setAba', 'obsoletas');
        $this->assertCount(1, $c->instance()->obsoletas, 'a distribuição de R1 continua obsoleta e pendente, intocada pela nova entrega de R2');
    }

    private function cenarioComCandidato(array $destOpts = [], array $docOpts = []): array
    {
        $doc = $this->doc($docOpts);
        $r1 = $this->rev($doc, 'R1');
        $this->liberar($r1->fresh());
        $joao = $this->destinatario($destOpts + ['nome' => 'Joao Candidato ' . uniqid()]);
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $r2 = $this->rev($doc, 'R2');
        $this->liberar($r2->fresh());

        return compact('doc', 'joao', 'r1', 'r2');
    }

    // ===================== M: isolamento por obra =====================

    public function test_m_filtros_e_cards_sao_escopados_por_obra_troca_de_obra_reseta_visao(): void
    {
        $obra2 = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obra2, $this->user, Papel::Admin->value);

        $doc1 = $this->doc([], $this->obra);
        $r1 = $this->rev($doc1);
        $this->liberar($r1->fresh());
        $joao = $this->destinatario([], $this->obra);
        $this->emitirGrdComUmaEntrega($r1, $joao, 1);
        $this->liberar($this->rev($doc1, 'R2')->fresh());

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');
        $this->assertCount(1, $c->instance()->obsoletas);

        $c->set('obraId', $obra2->id);
        $this->assertCount(0, $c->instance()->obsoletas, 'obra sem nenhuma GRD nunca herda obsoletas de outra obra');
        $this->assertSame(0, $c->instance()->cardsObsoletas['copias_pendentes']);
    }

    // ===================== N: isolamento cross-tenant =====================

    public function test_n_obsoletas_e_candidatos_nunca_vazam_entre_tenants(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);

        // Work usa BelongsToTenant — o trait carimba tenant_id a partir do
        // tenant ATUALMENTE autenticado (ignorando qualquer valor explícito
        // no create()), então precisa nascer DENTRO do actingAs do outro
        // tenant, senão herda silenciosamente o tenant do usuário logado
        // neste teste (mesmo achado já documentado no CLAUDE.md, Fase 3
        // Etapa 4 do Health Check).
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant, $outroUser) {
            $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->vincularObra($outraObra, $outroUser, Papel::Admin->value);

            $doc = DocumentoEngenharia::create([
                'tenant_id' => $outraObra->tenant_id,
                'obra_id' => $outraObra->id,
                'codigo' => 'OUTRO-TENANT',
                'descricao' => 'x',
            ]);
            $r1 = $doc->revisoes()->create(['tenant_id' => $outraObra->tenant_id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
            (new AlterarLiberacaoRevisaoDocumento())->liberar($r1, $outroUser);
            $dest = Destinatario::create(['tenant_id' => $outraObra->tenant_id, 'obra_id' => $outraObra->id, 'nome' => 'Dest Outro Tenant']);
            $grd = (new CriarGrd())->execute($outraObra, $outroUser);
            $acoes = new AtualizarRascunhoGrd();
            $item = $acoes->adicionarItem($grd, $r1->fresh());
            $gd = $acoes->adicionarDestinatario($grd, $dest);
            $acoes->marcarDistribuicao($grd, $item, $gd, 1);
            (new EmitirGrd())->execute($grd, $outroUser);
            $r2 = $doc->revisoes()->create(['tenant_id' => $outraObra->tenant_id, 'revisao' => 'R2', 'descricao' => 'x'])->fresh();
            (new AlterarLiberacaoRevisaoDocumento())->liberar($r2, $outroUser);
        });

        // usuário do tenant ATUAL nem consegue setar a obra do outro tenant (garantia já existente, reconfirmada aqui)
        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');
        $this->assertCount(0, $c->instance()->obsoletas);
        $c->assertDontSee('OUTRO-TENANT')->assertDontSee('Dest Outro Tenant');
    }

    // ===================== O: performance em escala =====================

    public function test_o_cards_e_filtros_nao_geram_n_mais_1_com_100_distribuicoes_obsoletas(): void
    {
        $joao = $this->destinatario(['nome' => 'Joao Escala']);
        for ($i = 0; $i < 100; $i++) {
            $doc = $this->doc(['codigo' => 'ESC-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
            $r1 = $this->rev($doc, 'R1');
            $this->liberar($r1->fresh());
            $this->emitirGrdComUmaEntrega($r1, $joao, 1);
            $this->liberar($this->rev($doc, 'R2')->fresh());
        }

        $c = $this->componente()->set('obraId', $this->obra->id)->call('setAba', 'obsoletas');

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $c->set('buscaObsoletaDocumento', 'ESC-05');
        $totalQueries = $queries;

        $this->assertLessThan(30, $totalQueries, 'filtro em memória não deve escalar linearmente com N em número de queries');
        $this->assertCount(10, $c->instance()->obsoletas); // ESC-050..ESC-059
        $this->assertSame(10, $c->instance()->cardsObsoletas['copias_pendentes']);
    }
}
