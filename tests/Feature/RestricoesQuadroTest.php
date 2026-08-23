<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Enums\Papel;
use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\CronogramaImportacao;
use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\FrenteTrabalho;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\RestricoesPendentesNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RestricoesQuadroTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::Engenheiro->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::radar.restricoes', ['obra' => $this->obra]);
    }

    private function criarAtividade(array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
        ], $overrides));
    }

    private function criarDocumento(array $overrides = [], ?Work $obra = null): DocumentoEngenharia
    {
        $obra ??= $this->obra;

        return DocumentoEngenharia::create(array_merge([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo' => 'DOC-' . uniqid(),
            'descricao' => 'Documento de teste',
        ], $overrides));
    }

    private function criarRevisao(DocumentoEngenharia $documento, string $texto = 'R1'): DocumentoEngenhariaRevisao
    {
        return $documento->revisoes()->create([
            'tenant_id' => $documento->tenant_id,
            'revisao' => $texto,
            'descricao' => 'Emissão ' . $texto,
        ]);
    }

    private function liberar(DocumentoEngenhariaRevisao $revisao): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $this->user);
    }

    private function revogar(DocumentoEngenhariaRevisao $revisao): void
    {
        (new AlterarLiberacaoRevisaoDocumento())->revogar($revisao, $this->user);
    }

    // ===================== 18.4.CORREÇÃO.HARDENING: popup delega a estaPronta() =====================

    /**
     * Reprodução exata do achado B da auditoria: Documento explicitamente
     * vinculado, com revisão vigente NÃO liberada, zero Restrição
     * bloqueante, checklist vazio (satisfeito por definição) — o popup
     * ANTES desta correção afirmava "pode ser comprometida no Plano
     * Semanal" mesmo assim, porque calculava prontidão manualmente sem
     * considerar GED.
     */
    public function test_popup_nao_afirma_pronta_quando_ged_bloqueante(): void
    {
        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1'); // não liberada
        $atividade->documentosEngenharia()->attach($documento->id, ['tenant_id' => $this->obra->tenant_id]);

        $this->assertFalse($atividade->fresh()->estaPronta(), 'Pré-condição: fonte canônica deve considerar não pronta.');

        $html = $this->componente()
            ->call('verAtividade', $atividade->id)
            ->html();

        $this->assertStringNotContainsString('Atividade pronta — pode ser comprometida no Plano Semanal', $html);
        $this->assertStringContainsString('Pendências impedem o comprometimento no Plano Semanal', $html);
        $this->assertStringContainsString('⚠ Com pendências', $html);
    }

    /**
     * Controle positivo: liberando a MESMA revisão pelo mecanismo real
     * (App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento), sem
     * nenhuma outra pendência, o popup deve voltar a afirmar prontidão —
     * prova que a correção delega de verdade, não apenas esconde o texto.
     */
    public function test_popup_afirma_pronta_quando_ged_liberado(): void
    {
        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisao($documento, 'R1');
        $this->liberar($revisao);
        $atividade->documentosEngenharia()->attach($documento->id, ['tenant_id' => $this->obra->tenant_id]);

        $this->assertTrue($atividade->fresh()->estaPronta());

        $html = $this->componente()
            ->call('verAtividade', $atividade->id)
            ->html();

        $this->assertStringContainsString('Atividade pronta — pode ser comprometida no Plano Semanal', $html);
        $this->assertStringContainsString('✅ Pronta', $html);
    }

    public function test_popup_nova_revisao_volta_a_bloquear_sem_cache_stale(): void
    {
        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $r1 = $this->criarRevisao($documento, 'R1');
        $this->liberar($r1);
        $atividade->documentosEngenharia()->attach($documento->id, ['tenant_id' => $this->obra->tenant_id]);

        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Atividade pronta — pode ser comprometida no Plano Semanal');

        $this->criarRevisao($documento, 'R2'); // nasce não liberada
        $this->assertFalse($atividade->fresh()->estaPronta());

        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Pendências impedem o comprometimento no Plano Semanal');

        $r2 = $documento->fresh()->revisaoVigente();
        $this->liberar($r2);
        $this->assertTrue($atividade->fresh()->estaPronta());

        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Atividade pronta — pode ser comprometida no Plano Semanal');
    }

    public function test_popup_restricao_bloqueante_normal_continua_funcionando(): void
    {
        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisao($documento, 'R1');
        $this->liberar($revisao); // GED liberado
        $atividade->documentosEngenharia()->attach($documento->id, ['tenant_id' => $this->obra->tenant_id]);

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->assertFalse($atividade->fresh()->estaPronta());
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Pendências impedem o comprometimento no Plano Semanal');

        $this->componente()
            ->call('abrirModalResolucao', $restricao->id)
            ->set('acaoTexto', 'Resolvida.')
            ->call('resolver');

        $this->assertTrue($atividade->fresh()->estaPronta());
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Atividade pronta — pode ser comprometida no Plano Semanal');
    }

    public function test_popup_checklist_pendente_bloqueia_e_completar_libera(): void
    {
        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $revisao = $this->criarRevisao($documento, 'R1');
        $this->liberar($revisao);
        $atividade->documentosEngenharia()->attach($documento->id, ['tenant_id' => $this->obra->tenant_id]);

        $item = ItemProntidao::create(['obra_id' => $this->obra->id, 'nome' => 'Item obrigatório', 'ordem' => 0]);

        $this->assertFalse($atividade->fresh()->estaPronta());
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Pendências impedem o comprometimento no Plano Semanal');

        AtividadeItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);

        $this->assertTrue($atividade->fresh()->estaPronta());
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Atividade pronta — pode ser comprometida no Plano Semanal');
    }

    public function test_popup_sem_documento_continua_pronta(): void
    {
        $atividade = $this->criarAtividade();

        $this->assertTrue($atividade->estaPronta());
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Atividade pronta — pode ser comprometida no Plano Semanal');
    }

    public function test_popup_cross_obra_pivot_corrompido_nao_bloqueia(): void
    {
        $atividade = $this->criarAtividade();
        $obraB = Work::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $documentoObraB = $this->criarDocumento([], $obraB);
        $this->criarRevisao($documentoObraB, 'R1'); // não liberada

        DB::table('documento_engenharia_atividades')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $this->obra->tenant_id,
            'documento_engenharia_id' => $documentoObraB->id,
            'atividade_id' => $atividade->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($atividade->fresh()->estaPronta(), 'Documento de outra obra nunca deve bloquear.');
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Atividade pronta — pode ser comprometida no Plano Semanal');
    }

    public function test_popup_soft_delete_restore_documento(): void
    {
        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1'); // não liberada
        $atividade->documentosEngenharia()->attach($documento->id, ['tenant_id' => $this->obra->tenant_id]);

        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Pendências impedem o comprometimento no Plano Semanal');

        $documento->delete();
        $this->assertTrue($atividade->fresh()->estaPronta());
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Atividade pronta — pode ser comprometida no Plano Semanal');

        $documento->restore();
        $this->assertFalse($atividade->fresh()->estaPronta());
        $this->componente()->call('verAtividade', $atividade->id)
            ->assertSee('Pendências impedem o comprometimento no Plano Semanal');
    }

    public function test_popup_abertura_gera_apenas_uma_query_de_prontidao(): void
    {
        $atividade = $this->criarAtividade();
        $documento = $this->criarDocumento();
        $this->criarRevisao($documento, 'R1');
        $atividade->documentosEngenharia()->attach($documento->id, ['tenant_id' => $this->obra->tenant_id]);

        $queries = 0;
        $queriesProntas = 0;
        DB::listen(function ($q) use (&$queries, &$queriesProntas) {
            $queries++;
            if (str_contains($q->sql, 'from `atividades`') && str_contains($q->sql, 'not exists')) {
                $queriesProntas++;
            }
        });

        $this->componente()->call('verAtividade', $atividade->id);

        $this->assertEquals(1, $queriesProntas, "Esperava exatamente 1 query de scopeProntas() por abertura de popup, encontrou {$queriesProntas}.");
    }

    public function test_resolver_restricao_via_fluxo_completo_do_modal(): void
    {
        $atividade = $this->criarAtividade();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->componente()
            ->call('abrirModalResolucao', $restricao->id)
            ->set('acaoTexto', 'Material entregue pelo fornecedor.')
            ->call('resolver');

        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Resolvida, $restricao->status);
        $this->assertNotNull($restricao->resolvida_em);
        $this->assertDatabaseHas('restricao_acoes', [
            'restricao_id' => $restricao->id,
            'descricao' => 'Material entregue pelo fornecedor.',
        ]);
    }

    public function test_falha_de_banco_ao_reabrir_mostra_toast_de_erro_sem_quebrar_e_sem_gravar(): void
    {
        $atividade = $this->criarAtividade();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $conexaoReal = app('db');
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('falha forçada de teste'));

        $this->componente()
            ->call('reabrirRestricao', $restricao->id)
            ->assertDispatched('show-toast', function (string $name, array $params) {
                return ($params['type'] ?? null) === 'error';
            })
            ->assertNotDispatched('show-toast', function (string $name, array $params) {
                return ($params['message'] ?? null) === 'Restrição reaberta.';
            });

        DB::swap($conexaoReal);
        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Resolvida, $restricao->status);
    }

    public function test_reabrir_restricao_volta_status_para_aberta(): void
    {
        $atividade = $this->criarAtividade();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $this->componente()->call('reabrirRestricao', $restricao->id);

        $restricao->refresh();
        $this->assertEquals(StatusRestricao::Aberta, $restricao->status);
        $this->assertNull($restricao->resolvida_em);
        $this->assertDatabaseHas('restricao_acoes', [
            'restricao_id' => $restricao->id,
            'descricao' => 'Restrição reaberta.',
        ]);
    }

    public function test_usuario_sem_permissao_nao_pode_reabrir(): void
    {
        $atividade = $this->criarAtividade();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.restricoes', ['obra' => $this->obra])
            ->call('reabrirRestricao', $restricao->id)
            ->assertForbidden();
    }

    public function test_notificar_responsaveis_agrupa_restricoes_por_usuario(): void
    {
        Notification::fake();

        $atividade = $this->criarAtividade();
        $responsavel = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $responsavel, Papel::Engenheiro->value);

        $r1 = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $responsavel->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $r2 = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $responsavel->id,
            'status' => StatusRestricao::EmTratamento->value,
        ]);

        $this->componente()->call('notificarResponsaveis');

        Notification::assertSentTo(
            $responsavel,
            RestricoesPendentesNotification::class,
            function (RestricoesPendentesNotification $notification) use ($r1, $r2, $responsavel) {
                $ids = $notification->restricoesParaTeste()->pluck('id');
                $linkEsperado = route('radar.entrar', ['obraId' => $this->obra->id, 'responsavel' => $responsavel->id]);

                return $ids->count() === 2 && $ids->contains($r1->id) && $ids->contains($r2->id)
                    && $notification->toArray($responsavel)['link'] === $linkEsperado;
            }
        );
        Notification::assertSentTimes(RestricoesPendentesNotification::class, 1);
    }

    public function test_filtro_responsavel_e_inicializado_via_query_string(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);

        Livewire::withQueryParams(['responsavel' => $responsavel->id])
            ->test('pages::radar.restricoes', ['obra' => $this->obra])
            ->assertSet('filtroResponsavelId', $responsavel->id);
    }

    public function test_notificar_responsaveis_ignora_quem_nao_tem_pendencias(): void
    {
        Notification::fake();

        $atividade = $this->criarAtividade();
        $semPendencia = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $semPendencia, Papel::Engenheiro->value);
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $semPendencia->id,
            'status' => StatusRestricao::Resolvida->value,
        ]);

        $this->componente()->call('notificarResponsaveis');

        Notification::assertNotSentTo($semPendencia, RestricoesPendentesNotification::class);
    }

    public function test_notificar_responsaveis_ignora_responsavel_externo(): void
    {
        Notification::fake();

        $atividade = $this->criarAtividade();
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => null,
            'responsavel_externo' => 'Fornecedor XYZ',
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->componente()->call('notificarResponsaveis');

        Notification::assertNothingSent();
    }

    public function test_notificar_responsaveis_exclui_resolvidas(): void
    {
        Notification::fake();

        $atividade = $this->criarAtividade();
        $responsavel = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $responsavel, Papel::Engenheiro->value);
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $responsavel->id,
            'status' => StatusRestricao::Resolvida->value,
        ]);

        $this->componente()->call('notificarResponsaveis');

        Notification::assertNotSentTo($responsavel, RestricoesPendentesNotification::class);
    }

    public function test_notificar_responsaveis_bloqueado_sem_permissao_editar(): void
    {
        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.restricoes', ['obra' => $this->obra])
            ->call('notificarResponsaveis')
            ->assertForbidden();
    }

    public function test_criar_restricao_para_multiplas_atividades_cria_uma_por_atividade(): void
    {
        $at1 = $this->criarAtividade(['nome' => 'Atividade Um']);
        $at2 = $this->criarAtividade(['nome' => 'Atividade Dois']);
        $at3 = $this->criarAtividade(['nome' => 'Atividade Três']);

        $this->componente()
            ->call('abrirModalNova')
            ->call('toggleAtividadeSelecionada', $at1->id)
            ->call('toggleAtividadeSelecionada', $at2->id)
            ->call('toggleAtividadeSelecionada', $at3->id)
            ->set('descricaoNova', 'Falta liberação do projeto executivo')
            ->call('salvarRestricao');

        $this->assertEquals(3, Restricao::where('descricao', 'Falta liberação do projeto executivo')->count());
        $this->assertDatabaseHas('restricoes', ['atividade_id' => $at1->id]);
        $this->assertDatabaseHas('restricoes', ['atividade_id' => $at2->id]);
        $this->assertDatabaseHas('restricoes', ['atividade_id' => $at3->id]);
    }

    public function test_toggle_atividade_selecionada_remove_quando_ja_selecionada(): void
    {
        $atividade = $this->criarAtividade();

        $componente = $this->componente()
            ->call('abrirModalNova')
            ->call('toggleAtividadeSelecionada', $atividade->id);

        $this->assertContains($atividade->id, $componente->get('atividadesIdsNova'));

        $componente->call('toggleAtividadeSelecionada', $atividade->id);
        $this->assertNotContains($atividade->id, $componente->get('atividadesIdsNova'));
    }

    public function test_editar_restricao_mantem_atividade_unica(): void
    {
        $atividade = $this->criarAtividade();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'descricao' => 'Descrição original',
        ]);

        $this->componente()
            ->call('abrirModalEdicao', $restricao->id)
            ->assertSet('atividadesIdsNova', [$atividade->id])
            ->set('descricaoNova', 'Descrição atualizada')
            ->call('salvarRestricao');

        $this->assertEquals(1, Restricao::count());
        $this->assertEquals('Descrição atualizada', $restricao->fresh()->descricao);
        $this->assertEquals($atividade->id, $restricao->fresh()->atividade_id);
    }

    public function test_busca_de_atividades_no_modal_filtra_por_nome(): void
    {
        $achada = $this->criarAtividade(['nome' => 'Concretagem do berço 3']);
        $naoAchada = $this->criarAtividade(['nome' => 'Montagem de forma']);

        $componente = $this->componente()
            ->call('abrirModalNova')
            ->set('buscaAtividadeNova', 'berço 3');

        $nomes = collect($componente->instance()->atividadesParaSelecao)->pluck('nome')->all();
        $this->assertContains($achada->nome, $nomes);
        $this->assertNotContains($naoAchada->nome, $nomes);
    }

    public function test_criar_restricao_sem_autorizacao_e_bloqueada(): void
    {
        $atividade = $this->criarAtividade();

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.restricoes', ['obra' => $this->obra])
            ->call('toggleAtividadeSelecionada', $atividade->id)
            ->set('descricaoNova', 'Tentativa sem permissão')
            ->call('salvarRestricao')
            ->assertForbidden();
    }

    public function test_exportar_excel_com_filtro_gera_download(): void
    {
        $atividade = $this->criarAtividade();
        Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->componente()
            ->set('filtroStatus', 'aberta')
            ->call('exportarExcel')
            ->assertFileDownloaded();
    }

    public function test_per_page_altera_a_paginacao(): void
    {
        $atividade = $this->criarAtividade();
        Restricao::factory()->count(8)->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
        ]);

        $componente = $this->componente()->set('perPage', 5);

        $this->assertEquals(5, $componente->instance()->restricoes->count());
        $this->assertEquals(8, $componente->instance()->restricoes->total());
    }

    public function test_filtro_por_disciplina(): void
    {
        $disciplina = Disciplina::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $comDisciplina = $this->criarAtividade(['disciplina_id' => $disciplina->id]);
        $semDisciplina = $this->criarAtividade();

        $rCom = Restricao::factory()->create(['tenant_id' => $this->obra->tenant_id, 'atividade_id' => $comDisciplina->id]);
        $rSem = Restricao::factory()->create(['tenant_id' => $this->obra->tenant_id, 'atividade_id' => $semDisciplina->id]);

        $componente = $this->componente()->set('filtroDisciplinaId', $disciplina->id);
        $ids = $componente->instance()->restricoes->pluck('id');

        $this->assertTrue($ids->contains($rCom->id));
        $this->assertFalse($ids->contains($rSem->id));
    }

    public function test_filtro_por_frente_de_trabalho(): void
    {
        $frente = FrenteTrabalho::factory()->create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id]);
        $comFrente = $this->criarAtividade(['frente_trabalho_id' => $frente->id]);
        $semFrente = $this->criarAtividade();

        $rCom = Restricao::factory()->create(['tenant_id' => $this->obra->tenant_id, 'atividade_id' => $comFrente->id]);
        $rSem = Restricao::factory()->create(['tenant_id' => $this->obra->tenant_id, 'atividade_id' => $semFrente->id]);

        $componente = $this->componente()->set('filtroFrenteTrabalhoId', $frente->id);
        $ids = $componente->instance()->restricoes->pluck('id');

        $this->assertTrue($ids->contains($rCom->id));
        $this->assertFalse($ids->contains($rSem->id));
    }

    public function test_filtro_por_responsavel(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $responsavel, Papel::Engenheiro->value);

        $atividade = $this->criarAtividade();
        $rCom = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => $responsavel->id,
        ]);
        $rSem = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'responsavel_id' => null,
        ]);

        $componente = $this->componente()->set('filtroResponsavelId', $responsavel->id);
        $ids = $componente->instance()->restricoes->pluck('id');

        $this->assertTrue($ids->contains($rCom->id));
        $this->assertFalse($ids->contains($rSem->id));
    }

    public function test_adicionar_comentario_cria_acao(): void
    {
        $atividade = $this->criarAtividade();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
        ]);

        $this->componente()
            ->call('abrirModalComentarios', $restricao->id)
            ->set('comentarioTexto', 'Aguardando retorno do fornecedor.')
            ->call('adicionarComentario');

        $this->assertDatabaseHas('restricao_acoes', [
            'restricao_id' => $restricao->id,
            'descricao' => 'Aguardando retorno do fornecedor.',
        ]);
    }

    public function test_usuario_cliente_leitura_nao_pode_comentar(): void
    {
        $atividade = $this->criarAtividade();
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
        ]);

        $leitor = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->vincularObra($this->obra, $leitor, Papel::ClienteLeitura->value);
        $this->actingAs($leitor);

        Livewire::test('pages::radar.restricoes', ['obra' => $this->obra])
            ->call('abrirModalComentarios', $restricao->id)
            ->set('comentarioTexto', 'Tentativa sem permissão')
            ->call('adicionarComentario')
            ->assertForbidden();
    }

    public function test_marcar_item_de_prontidao_na_detalhe_registra_quem_concluiu(): void
    {
        $atividade = $this->criarAtividade();
        $item = ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Projeto executivo liberado',
            'ordem' => 0,
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->call('marcarItemNaDetalhe', $atividade->id, $item->id, true)
            ->assertSee($this->user->first_name)
            ->assertSee($this->user->last_name);

        $this->assertDatabaseHas('atividade_itens_prontidao', [
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
            'concluido_por' => $this->user->id,
        ]);
    }

    public function test_desmarcar_item_de_prontidao_remove_o_registro_de_quem_concluiu(): void
    {
        $atividade = $this->criarAtividade();
        $item = ItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'nome' => 'Projeto executivo liberado',
            'ordem' => 0,
        ]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->obra->tenant_id,
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
            'concluido_por' => $this->user->id,
            'concluido_em' => now(),
        ]);

        $componente = $this->componente()
            ->call('verAtividade', $atividade->id)
            ->call('marcarItemNaDetalhe', $atividade->id, $item->id, false);

        $checklist = $componente->instance()->atividadeDetalhe['checklist'];
        $this->assertNull($checklist->firstWhere('id', $item->id)['concluidoPor']);

        $this->assertDatabaseHas('atividade_itens_prontidao', [
            'atividade_id' => $atividade->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
            'concluido_por' => null,
        ]);
    }

    public function test_popup_detalhe_mostra_linha_de_base_e_importacao_seguida(): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Baseline->value,
            'importado_em' => now()->subDays(3),
        ]);
        $atividade = $this->criarAtividade([
            'baseline_inicio' => now()->addDays(5),
            'baseline_termino' => now()->addDays(10),
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee($atividade->baseline_inicio->format('d/m/Y'))
            ->assertSee($atividade->baseline_termino->format('d/m/Y'))
            ->assertSee($importacao->importado_em->format('d/m/Y'));
    }

    public function test_popup_detalhe_mostra_tendencia_na_quando_sem_importacao_de_avanco(): void
    {
        $atividade = $this->criarAtividade([
            'inicio_planejado' => now()->addDays(5),
            'data_termino' => now()->addDays(10),
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSeeHtml('>N/A<')
            ->assertSee('sem importação registrada');
    }

    public function test_popup_detalhe_mostra_tendencia_com_importacao_de_avanco(): void
    {
        $atividade = $this->criarAtividade();
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->obra->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now()->subDay(),
        ]);
        AtividadeSnapshot::create([
            'tenant_id' => $this->obra->tenant_id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $atividade->id,
            'inicio_planejado' => now()->addDays(5),
            'data_termino' => now()->addDays(10),
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertSee(now()->addDays(5)->format('d/m/Y'))
            ->assertSee(now()->addDays(10)->format('d/m/Y'))
            ->assertSee($importacao->importado_em->format('d/m/Y'));
    }

    public function test_popup_detalhe_nao_mostra_mais_situacao_de_dias_restantes(): void
    {
        $atividade = $this->criarAtividade([
            'data_termino' => now()->addDays(5),
        ]);

        $this->componente()
            ->call('verAtividade', $atividade->id)
            ->assertDontSee('dias restantes')
            ->assertDontSee('dias em atraso')
            ->assertDontSee('Situação');
    }
}
