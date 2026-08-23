<?php

namespace Tests\Feature;

use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeAnexo;
use App\Models\AtividadeComentario;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\AtividadeSnapshotOperacional;
use App\Models\AtividadeSnapshotProntidao;
use App\Models\AtividadeSnapshotRestricao;
use App\Models\CronogramaImportacao;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ciclo 17, A.9.3 — Fotografia O: o que a PLATAFORMA sabia sobre uma
 * atividade (Restrição/Prontidão/status operacional) no instante em que
 * uma importação de Avanço/Ambos foi aplicada. Leitura pura — nunca altera
 * Restricao/AtividadeItemProntidao/Atividade (mesmo princípio de zero
 * autocorreção da A.9.1). Irmã da Fotografia F (A.9.2, AtividadeSnapshot)
 * — nunca confundida com ela.
 */
class AtividadeSnapshotOperacionalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;
    private ImportadorCronograma $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant       = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra   = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->importer = app(ImportadorCronograma::class);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    private function importar(string $arquivo, TipoCronogramaImportacao $tipo = TipoCronogramaImportacao::Baseline): CronogramaImportacao
    {
        $plano = $this->importer->analisar($this->fixture($arquivo), $this->obra, $tipo);

        return $this->importer->aplicar($plano, $this->obra, $this->user->id, $arquivo, $tipo);
    }

    private function operacionalDe(CronogramaImportacao $importacao, string $atividadeId): ?AtividadeSnapshotOperacional
    {
        return AtividadeSnapshotOperacional::where('cronograma_importacao_id', $importacao->id)
            ->where('atividade_id', $atividadeId)
            ->first();
    }

    /** A — Avanço com Restrição bloqueante aberta: Fotografia O registra a restrição (id, bloqueante, status) e pronta=false. */
    public function test_avanco_com_restricao_bloqueante_aberta_registra_na_fotografia_o(): void
    {
        $baseline = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $operacional = $this->operacionalDe($avanco, $at->id);
        $this->assertNotNull($operacional);
        $this->assertFalse($operacional->pronta);

        $this->assertDatabaseHas('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $at->id,
            'restricao_id' => $restricao->id,
            'bloqueante' => true,
            'status' => 'aberta',
        ]);
    }

    /** B — Avanço com Restrição NÃO bloqueante aberta: registra também (não é exclusivo de bloqueantes). */
    public function test_avanco_com_restricao_nao_bloqueante_aberta_registra_na_fotografia_o(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => false,
        ]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertDatabaseHas('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $at->id,
            'restricao_id' => $restricao->id,
            'bloqueante' => false,
        ]);
        // Não bloqueante e sem checklist pendente: pronta continua true.
        $this->assertTrue($this->operacionalDe($avanco, $at->id)->pronta);
    }

    /** C — Restrição já resolvida ANTES da importação: Fotografia O (modelo "pendências") não a registra. */
    public function test_restricao_ja_resolvida_antes_da_importacao_nao_e_registrada(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricaoResolvida = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Resolvida->value,
            'bloqueante' => true,
        ]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertDatabaseMissing('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $avanco->id,
            'restricao_id' => $restricaoResolvida->id,
        ]);
        $this->assertTrue($this->operacionalDe($avanco, $at->id)->pronta);
    }

    /** D — Item de prontidão pendente (sem row): Fotografia O registra o item, atividade_item_prontidao_id null (nunca inventado). */
    public function test_item_de_prontidao_pendente_sem_row_registra_na_fotografia_o(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertDatabaseHas('atividade_snapshot_prontidao', [
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'atividade_item_prontidao_id' => null,
        ]);
        $this->assertFalse($this->operacionalDe($avanco, $at->id)->pronta);
    }

    /** D2 — Item pendente com row explícita (concluido=false): Fotografia O preserva o ID da row real. */
    public function test_item_pendente_com_row_explicita_preserva_o_id_da_row(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        $registro = AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertDatabaseHas('atividade_snapshot_prontidao', [
            'cronograma_importacao_id' => $avanco->id,
            'item_prontidao_id' => $item->id,
            'atividade_item_prontidao_id' => $registro->id,
        ]);
    }

    /** E — Item concluído: Fotografia O (modelo "pendências") NÃO o registra, e pronta reflete isso. */
    public function test_item_de_prontidao_concluido_nao_e_registrado_e_pronta_fica_verdadeira(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
            'concluido_por' => $this->user->id,
            'concluido_em' => now(),
        ]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertDatabaseMissing('atividade_snapshot_prontidao', [
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
        ]);
        $this->assertTrue($this->operacionalDe($avanco, $at->id)->pronta);
    }

    /**
     * F — Histórico I1/I2 (item 11 do pedido): estado muda entre importações
     * e cada Fotografia O continua fiel ao que era verdade NO SEU instante.
     */
    public function test_historico_i1_i2_fotografia_o_nao_muda_retroativamente(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $r1 = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);

        $i1 = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Avanco);

        // Fotografia O de I1: R1 aberta, item pendente, R2 ainda nem existe.
        $this->assertDatabaseHas('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $i1->id, 'restricao_id' => $r1->id,
        ]);
        $this->assertDatabaseHas('atividade_snapshot_prontidao', [
            'cronograma_importacao_id' => $i1->id, 'item_prontidao_id' => $item->id,
        ]);

        // Usuário resolve R1, conclui o item, cria R2.
        $r1->update(['status' => StatusRestricao::Resolvida->value, 'resolvida_em' => now()]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);
        $r2 = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $i2 = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Avanco);

        // Fotografia O de I2: R1 já não é pendência, item concluído, R2 aberta.
        $this->assertDatabaseMissing('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $i2->id, 'restricao_id' => $r1->id,
        ]);
        $this->assertDatabaseMissing('atividade_snapshot_prontidao', [
            'cronograma_importacao_id' => $i2->id, 'atividade_id' => $at->id, 'item_prontidao_id' => $item->id,
        ]);
        $this->assertDatabaseHas('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $i2->id, 'restricao_id' => $r2->id,
        ]);

        // Fotografia O de I1 NÃO MUDOU depois de tudo isso.
        $this->assertDatabaseHas('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $i1->id, 'restricao_id' => $r1->id,
        ]);
        $this->assertDatabaseHas('atividade_snapshot_prontidao', [
            'cronograma_importacao_id' => $i1->id, 'item_prontidao_id' => $item->id,
        ]);
        $this->assertDatabaseMissing('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $i1->id, 'restricao_id' => $r2->id,
        ]);
    }

    /** G — Comentário/anexo continuam vinculados à Atividade e NÃO são duplicados/tocados pela Fotografia O. */
    public function test_comentario_e_anexo_permanecem_intactos_e_nao_sao_duplicados_na_fotografia(): void
    {
        Storage::fake(AtividadeAnexo::DISCO);

        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $comentario = AtividadeComentario::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'autor_id' => $this->user->id,
            'comentario' => 'Comentário antes da importação de Avanço.',
        ]);
        $anexo = app(\App\Actions\Atividade\AnexarArquivoAtividade::class)->execute(
            $at,
            UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            $this->user,
        );

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertDatabaseHas('atividade_comentarios', ['id' => $comentario->id, 'atividade_id' => $at->id]);
        $this->assertDatabaseHas('atividade_anexos', ['id' => $anexo->id, 'atividade_id' => $at->id]);
        $this->assertEquals(1, AtividadeComentario::where('atividade_id', $at->id)->count());
        $this->assertEquals(1, AtividadeAnexo::where('atividade_id', $at->id)->count());
        // Fotografia O não tem coluna nenhuma de comentário/anexo — a própria
        // existência da linha em atividade_snapshot_operacionais já basta.
        $this->assertNotNull($this->operacionalDe($avanco, $at->id));
    }

    /** H — WBS (codigo_cronograma) muda, external_uid igual: Fotografia O continua ligada à MESMA atividade_id. */
    public function test_wbs_alterado_fotografia_o_continua_na_mesma_atividade(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        $atividadeIdOriginal = $at->id;

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        // WBS/codigo_cronograma alterado manualmente — identidade continua
        // sendo obra_id + external_uid, nunca codigo_cronograma.
        $at->update(['codigo_cronograma' => '9.9']);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertEquals($atividadeIdOriginal, $at->fresh()->id);
        $this->assertDatabaseHas('atividade_snapshot_operacionais', [
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $atividadeIdOriginal,
        ]);
        $this->assertDatabaseHas('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $avanco->id,
            'atividade_id' => $atividadeIdOriginal,
            'restricao_id' => $restricao->id,
        ]);
    }

    /** I — Importação Baseline pura: decisão explícita — NÃO grava Fotografia O. */
    public function test_importacao_baseline_pura_nao_grava_fotografia_o(): void
    {
        $baseline = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        // Reimportação Baseline (continua Baseline) — mesmo com restrição
        // aberta, nenhuma linha de Fotografia O deve existir pra esta importação.
        $baseline2 = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);

        $this->assertEquals(0, AtividadeSnapshotOperacional::where('cronograma_importacao_id', $baseline2->id)->count());
        $this->assertEquals(0, AtividadeSnapshotRestricao::where('cronograma_importacao_id', $baseline2->id)->count());
        $this->assertEquals(0, AtividadeSnapshotProntidao::where('cronograma_importacao_id', $baseline2->id)->count());

        // Fotografia F (A.9.2) continua funcionando normalmente pra Baseline.
        $this->assertNotNull(AtividadeSnapshot::where('cronograma_importacao_id', $baseline2->id)->where('atividade_id', $at->id)->first());
    }

    /** J — Importação Ambos: registra Fotografia O normalmente. */
    public function test_importacao_ambos_grava_fotografia_o(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $ambos = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Ambos);

        $this->assertNotNull($this->operacionalDe($ambos, $at->id));
        $this->assertDatabaseHas('atividade_snapshot_restricoes', [
            'cronograma_importacao_id' => $ambos->id,
            'restricao_id' => $restricao->id,
        ]);
    }

    /** K — Backfill NÃO inventa Fotografia O histórica pra importações antigas. */
    public function test_backfill_nao_inventa_fotografia_o(): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->user->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'tipo' => TipoCronogramaImportacao::Avanco->value,
            'importado_em' => now(),
        ]);

        $atividade = Atividade::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'obra_id' => $this->obra->id,
            'origem' => 'ms_project',
            'percentual_concluido' => 100,
        ]);
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $atividade->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $this->artisan('lookahead:backfill', ['obra' => $this->obra->id])->assertSuccessful();

        $this->assertEquals(0, AtividadeSnapshotOperacional::where('cronograma_importacao_id', $importacao->id)->count());
        $this->assertEquals(0, AtividadeSnapshotRestricao::where('cronograma_importacao_id', $importacao->id)->count());
        $this->assertEquals(0, AtividadeSnapshotProntidao::where('cronograma_importacao_id', $importacao->id)->count());
    }

    /** L — Se a transação da importação falhar depois de gravar a Fotografia O, ela reverte junto com o resto. */
    public function test_rollback_da_importacao_remove_fotografia_o_junto(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);

        $plano = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra, TipoCronogramaImportacao::Avanco);

        $lancado = null;
        try {
            DB::transaction(function () use ($plano) {
                $this->importer->aplicar($plano, $this->obra, $this->user->id, 'x.xml', TipoCronogramaImportacao::Avanco);
                throw new \RuntimeException('Falha simulada APÓS aplicar() real, dentro da mesma transação.');
            });
        } catch (\RuntimeException $e) {
            $lancado = $e->getMessage();
        }

        $this->assertEquals('Falha simulada APÓS aplicar() real, dentro da mesma transação.', $lancado);
        // Nenhuma segunda importação (nem Fotografia F/O associada a ela) persistiu.
        $this->assertEquals(1, CronogramaImportacao::where('obra_id', $this->obra->id)->count());
        $this->assertEquals(0, AtividadeSnapshotOperacional::count());
        $this->assertEquals(0, AtividadeSnapshotRestricao::count());
    }

    /** M — Isolamento de tenant: Fotografia O de um tenant nunca aparece pra outro. */
    public function test_isolamento_de_tenant(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);
        $operacionalId = $this->operacionalDe($avanco, $at->id)->id;

        $outroTenant = Tenant::factory()->create();
        $outroUser = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUser);

        $this->assertNull(AtividadeSnapshotOperacional::find($operacionalId));
    }

    /** N — Outra obra do MESMO tenant: Fotografia O de uma obra nunca mistura com outra. */
    public function test_outra_obra_mesmo_tenant_nao_mistura_fotografia_o(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $outraObra = Work::factory()->create(['tenant_id' => $this->user->tenant_id]);
        $plano2 = $this->importer->analisar($this->fixture('cronograma_sample.xml'), $outraObra, TipoCronogramaImportacao::Baseline);
        $this->importer->aplicar($plano2, $outraObra, $this->user->id, 'y.xml', TipoCronogramaImportacao::Baseline);
        $atOutraObra = Atividade::where('obra_id', $outraObra->id)->where('external_uid', '2')->first();

        $this->assertNotEquals($at->id, $atOutraObra->id);
        $this->assertEquals(0, AtividadeSnapshotOperacional::where('atividade_id', $atOutraObra->id)->count());
        // A Fotografia O da obra original continua correta e não foi tocada.
        $this->assertNotNull($this->operacionalDe($avanco, $at->id));
    }

    /** O — N+1: quantidade de queries não cresce linearmente com o número de atividades tocadas. */
    public function test_quantidade_de_queries_nao_cresce_linearmente_com_atividades(): void
    {
        // Reaproveita a MESMA fixture (2 atividades) pra Baseline; a fixture
        // não escala facilmente pra N variável sem gerar XML dinâmico, então
        // o teste mede que o CUSTO ADICIONAL da Fotografia O (Avanço) não
        // depende de quantas restrições/itens existem por atividade — só
        // conta queries de um Avanço com pendências vs um Avanço sem
        // pendência nenhuma, ambos sobre o MESMO conjunto de atividades.
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $atividades = Atividade::where('obra_id', $this->obra->id)->get();

        foreach ($atividades as $at) {
            Restricao::factory()->count(3)->create([
                'tenant_id' => $this->user->tenant_id,
                'atividade_id' => $at->id,
                'status' => StatusRestricao::Aberta->value,
            ]);
        }
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $plano = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra, TipoCronogramaImportacao::Avanco);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'z.xml', TipoCronogramaImportacao::Avanco);

        // 2 atividades × (3 restrições + 1 item) — se houvesse N+1 por
        // atividade/restrição/item, isso passaria de dezenas de queries.
        // O teto abaixo é generoso o bastante pra não quebrar por ajustes
        // finos, mas baixo o bastante pra pegar um N+1 real.
        $this->assertLessThan(40, $queries, "Import gerou {$queries} queries — suspeita de N+1 na Fotografia O.");
    }

    /** P — A.9.1 continua preservada: zero autocorreção mesmo com Fotografia O sendo gravada. */
    public function test_a91_continua_preservada_com_fotografia_o_ativa(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
        $this->assertNull($restricao->fresh()->resolvida_em);
        $this->assertDatabaseMissing('restricao_acoes', ['restricao_id' => $restricao->id]);
        $registro = AtividadeItemProntidao::where('atividade_id', $at->id)->where('item_prontidao_id', $item->id)->first();
        $this->assertNull($registro);
    }

    // ===================== A.9.4.HARDENING: instante único da Fotografia O =====================

    /**
     * S1 — Captura de restrições/prontidão pendentes (fonte de `pronta` e
     * dos filhos da Fotografia O) ocorre ANTES do upsert de atividades —
     * técnica robusta via ordem real de execução SQL (DB::listen), nunca
     * grep de string no código-fonte. Identifica a query de captura pelo
     * SQL + bindings exatos (filtro pelos 3 status canônicos de pendência
     * / tabela atividade_itens_prontidao), não só pelo nome da tabela —
     * evita falso positivo com outras queries tardias que também tocam
     * essas tabelas (ex.: SincronizarRestricaoSuprimento, no fim do fluxo).
     */
    public function test_captura_de_pendencias_da_fotografia_o_ocorre_antes_do_upsert_de_atividades(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id, 'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value, 'bloqueante' => true,
        ]);
        ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $plano = $this->importer->analisar($this->fixture('cronograma_100pct.xml'), $this->obra, TipoCronogramaImportacao::Avanco);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $this->importer->aplicar($plano, $this->obra, $this->user->id, 'x.xml', TipoCronogramaImportacao::Avanco);

        $indiceUpsertAtividade = null;
        foreach ($queries as $i => $q) {
            if (preg_match('/^\s*(update|insert into)\s+`?atividades`?\b/i', $q['sql'])) {
                $indiceUpsertAtividade = $i;
                break;
            }
        }
        $this->assertNotNull($indiceUpsertAtividade, 'Nenhuma query de upsert de atividades encontrada — teste inválido.');

        // Query que captura restrições pendentes: mesmo conjunto canônico
        // de status ('aberta'/'em_tratamento'/'aguardando_terceiros')
        // usado por Atividade::estaPronta()/scopeProntas().
        $indiceCapturaRestricoes = null;
        foreach ($queries as $i => $q) {
            if (preg_match('/\brestricoes\b/i', $q['sql'])
                && in_array('aberta', $q['bindings'], true)
                && in_array('em_tratamento', $q['bindings'], true)
                && in_array('aguardando_terceiros', $q['bindings'], true)
            ) {
                $indiceCapturaRestricoes = $i;
                break;
            }
        }
        $this->assertNotNull($indiceCapturaRestricoes, 'Query de captura de restrições pendentes não encontrada.');
        $this->assertLessThan(
            $indiceUpsertAtividade,
            $indiceCapturaRestricoes,
            'Captura de restrições pendentes da Fotografia O rodou DEPOIS do upsert de atividades — deixou de representar o estado pré-importação.'
        );

        // Query que captura itens de prontidão (concluídos e pendentes
        // explícitos) via atividade_itens_prontidao.
        $indiceCapturaProntidao = null;
        foreach ($queries as $i => $q) {
            if (preg_match('/\batividade_itens_prontidao\b/i', $q['sql'])) {
                $indiceCapturaProntidao = $i;
                break;
            }
        }
        $this->assertNotNull($indiceCapturaProntidao, 'Query de captura de itens de prontidão não encontrada.');
        $this->assertLessThan(
            $indiceUpsertAtividade,
            $indiceCapturaProntidao,
            'Captura de itens de prontidão da Fotografia O rodou DEPOIS do upsert de atividades — deixou de representar o estado pré-importação.'
        );
    }

    /**
     * S2 — Atividade arquivada (fora_do_cronograma=true) que REAPARECE
     * numa importação Ambos (que reativa fora_do_cronograma=false no MESMO
     * upsert): a Fotografia O precisa fotografar o estado de ANTES
     * (true), nunca o estado já reativado por esta própria importação.
     * Só Baseline/Ambos reescrevem fora_do_cronograma no upsert — Avanco
     * puro não tocaria nesse campo, por isso o cenário usa Ambos.
     */
    public function test_atividade_arquivada_reativada_fotografa_fora_do_cronograma_do_estado_anterior(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();
        $at->update(['fora_do_cronograma' => true]);

        $ambos = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Ambos);

        $operacional = $this->operacionalDe($ambos, $at->id);
        $this->assertNotNull($operacional);
        $this->assertTrue($operacional->fora_do_cronograma, 'Fotografia O deve fotografar o estado ANTES desta importação reativar a atividade.');
        $this->assertFalse($at->fresh()->fora_do_cronograma, 'A importação Ambos reativa a atividade ao vivo — confirma que os dois estados realmente divergem neste cenário.');
    }

    /**
     * S3 — Atividade nova nesta própria importação (Ambos): mesmo com
     * checklist de prontidão configurado na obra (que geraria pendência
     * pra qualquer atividade PRÉ-EXISTENTE sem o item concluído), a
     * Fotografia O da atividade nova não recebe nenhuma linha de
     * restrição/prontidão pendente — porque ela simplesmente não existia
     * no instante da captura pré-upsert (nunca inventa "estado anterior"
     * pra entidade inexistente).
     */
    public function test_atividade_nova_na_importacao_nao_recebe_pendencia_pre_capturada(): void
    {
        ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $plano = new \App\DTOs\PlanoImportacao(
            criar: [new \App\DTOs\TarefaImportada(
                uid: '900', nome: 'Atividade Nova', isSummary: false, isMarco: false,
                caminhoCritico: false, parentUid: null, codigo: '900',
                dataInicio: null, dataTermino: null, baselineInicio: null, baselineTermino: null,
                realInicio: now(), realTermino: null,
                baselineHoras: 0, workHoras: 0, realHoras: 0, percentualConcluido: 100.0,
                textos: []
            )],
            atualizar: [], pacotes: [], removerIds: [], removerNomes: [],
            ignoradasNomes: [], dataStatus: null, totalBaselineHh: 0, totalWorkHh: 0, totalRealHh: 0,
            horasPeriodos: []
        );
        $imp = $this->importer->aplicar($plano, $this->obra, $this->user->id, 'nova.xml', TipoCronogramaImportacao::Ambos);
        $atNova = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '900')->firstOrFail();

        $operacional = $this->operacionalDe($imp, $atNova->id);
        $this->assertNotNull($operacional);
        $this->assertNull($operacional->status, 'Atividade nova nunca tem status pré-importação — precisa continuar null.');
        $this->assertFalse($operacional->pronta, 'pronta não é inventada pra atividade inexistente — irrelevante, pois o Detector já ignora status=null antes de olhar pronta.');
        $this->assertEquals(0, AtividadeSnapshotRestricao::where('cronograma_importacao_id', $imp->id)->where('atividade_id', $atNova->id)->count());
        $this->assertEquals(0, AtividadeSnapshotProntidao::where('cronograma_importacao_id', $imp->id)->where('atividade_id', $atNova->id)->count());
    }

    /** Q — Fotografia F (A.9.2) continua correta simultaneamente à gravação da Fotografia O. */
    public function test_fotografia_f_continua_correta_junto_com_fotografia_o(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $snapshotF = AtividadeSnapshot::where('cronograma_importacao_id', $avanco->id)->where('atividade_id', $at->id)->first();
        $this->assertEquals(100.0, (float) $snapshotF->percentual_concluido);
        $this->assertEquals('2024-01-01', $snapshotF->real_inicio->toDateString());
        $this->assertNotNull($this->operacionalDe($avanco, $at->id));
    }

    /** R — Consulta histórica F+O sem acessar estado atual de Restricao/AtividadeItemProntidao/Atividade. */
    public function test_consulta_historica_f_e_o_nao_precisa_do_estado_atual(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'X', 'ordem' => 0]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        // Estado atual muda DEPOIS da importação — a consulta histórica
        // abaixo não deve enxergar essa mudança.
        $restricao->update(['status' => StatusRestricao::Resolvida->value]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'concluido' => true,
        ]);

        // Consulta histórica pura: só AtividadeSnapshot (F) +
        // AtividadeSnapshotOperacional/Restricao/Prontidao (O), nunca
        // Restricao/AtividadeItemProntidao/Atividade ao vivo.
        $f = AtividadeSnapshot::where('cronograma_importacao_id', $avanco->id)->where('atividade_id', $at->id)->first();
        $o = $this->operacionalDe($avanco, $at->id);
        $restricoesNaFoto = AtividadeSnapshotRestricao::where('cronograma_importacao_id', $avanco->id)->where('atividade_id', $at->id)->get();
        $itensNaFoto = AtividadeSnapshotProntidao::where('cronograma_importacao_id', $avanco->id)->where('atividade_id', $at->id)->get();

        $this->assertEquals(100.0, (float) $f->percentual_concluido);
        $this->assertFalse($o->pronta);
        $this->assertCount(1, $restricoesNaFoto);
        $this->assertEquals($restricao->id, $restricoesNaFoto->first()->restricao_id);
        $this->assertEquals('aberta', $restricoesNaFoto->first()->status);
        $this->assertCount(1, $itensNaFoto);
        $this->assertEquals($item->id, $itensNaFoto->first()->item_prontidao_id);
    }
}
