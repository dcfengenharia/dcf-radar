<?php

namespace Tests\Feature;

use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeItemProntidao;
use App\Models\AtividadeSnapshot;
use App\Models\CronogramaImportacao;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 17, A.9.2 — Fotografia F: percentual_concluido/real_inicio/
 * real_termino em atividade_snapshots preservam exatamente o que o
 * cronograma declarou NAQUELA importação, independente do estado
 * operacional (Restricao/AtividadeItemProntidao) e independente do valor
 * "ao vivo" atual em Atividade. Nunca inclui dado operacional (ver
 * App\Support\ConclusaoAutomaticaAtividades — não tocada nesta fase).
 */
class AtividadeSnapshotFotografiaFTest extends TestCase
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

    private function snapshotDe(CronogramaImportacao $importacao, string $atividadeId): AtividadeSnapshot
    {
        return AtividadeSnapshot::where('cronograma_importacao_id', $importacao->id)
            ->where('atividade_id', $atividadeId)
            ->firstOrFail();
    }

    /** A — snapshot de importação com percentual parcial (40%) guarda 40, não null nem zero. */
    public function test_snapshot_avanco_parcial_guarda_o_percentual_declarado(): void
    {
        $importacao = $this->importar('cronograma_sample.xml');
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $snapshot = $this->snapshotDe($importacao, $at->id);

        $this->assertEquals(40.0, (float) $snapshot->percentual_concluido);
        $this->assertEquals('2024-01-01', $snapshot->real_inicio->toDateString());
        $this->assertNull($snapshot->real_termino);
    }

    /** B — snapshot 100% com ActualStart e ActualFinish preenchidos no XML guarda os dois. */
    public function test_snapshot_100_guarda_real_inicio_e_real_termino(): void
    {
        $importacao = $this->importar('cronograma_fase_a92_zero_e_termino.xml');
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();

        $snapshot = $this->snapshotDe($importacao, $at->id);

        $this->assertEquals(100.0, (float) $snapshot->percentual_concluido);
        $this->assertEquals('2024-03-01', $snapshot->real_inicio->toDateString());
        $this->assertEquals('2024-03-10', $snapshot->real_termino->toDateString());
    }

    /**
     * C + H — três importações sucessivas (40 -> 70 -> 100) da MESMA
     * atividade preservam TRÊS fotografias distintas; nenhum snapshot
     * histórico é sobrescrito por uma importação posterior. Este é o teste
     * mais importante da A.9.2 (item 11 do pedido).
     */
    public function test_tres_importacoes_sucessivas_preservam_tres_fotografias_distintas(): void
    {
        $i1 = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $i2 = $this->importar('cronograma_fase_a92_70pct.xml', TipoCronogramaImportacao::Avanco);
        $i3 = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        // Atividade ao vivo reflete só o estado mais recente.
        $this->assertEquals(100.0, (float) $at->fresh()->percentual_concluido);

        // Mas os TRÊS snapshots continuam intactos, cada um com seu próprio fato.
        $this->assertEquals(40.0, (float) $this->snapshotDe($i1, $at->id)->percentual_concluido);
        $this->assertEquals(70.0, (float) $this->snapshotDe($i2, $at->id)->percentual_concluido);
        $this->assertEquals(100.0, (float) $this->snapshotDe($i3, $at->id)->percentual_concluido);

        // Consulta histórica explícita por importação (nunca só "o último").
        $this->assertEquals(
            3,
            AtividadeSnapshot::where('atividade_id', $at->id)->count(),
            'cada importação precisa ter deixado seu próprio snapshot, nenhum sobrescrito'
        );
    }

    /** D — importação Baseline pura registra o fato presente no arquivo (mesmo não sendo elegível como Tendência na UI). */
    public function test_snapshot_de_importacao_baseline_pura_registra_o_fato_do_arquivo(): void
    {
        $importacao = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $this->assertEquals(TipoCronogramaImportacao::Baseline, $importacao->tipo);

        $snapshot = $this->snapshotDe($importacao, $at->id);
        $this->assertEquals(40.0, (float) $snapshot->percentual_concluido);
        $this->assertEquals('2024-01-01', $snapshot->real_inicio->toDateString());
    }

    /** E — importação tipo Ambos armazena a Fotografia F normalmente. */
    public function test_snapshot_de_importacao_ambos_armazena_corretamente(): void
    {
        $importacao = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Ambos);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $snapshot = $this->snapshotDe($importacao, $at->id);
        $this->assertEquals(40.0, (float) $snapshot->percentual_concluido);
        $this->assertEquals('2024-01-01', $snapshot->real_inicio->toDateString());
    }

    /** F — percentual 0 explícito no XML permanece 0, nunca vira null. */
    public function test_percentual_zero_explicito_permanece_zero_nao_null(): void
    {
        $importacao = $this->importar('cronograma_fase_a92_zero_e_termino.xml');
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $snapshot = $this->snapshotDe($importacao, $at->id);

        $this->assertNotNull($snapshot->percentual_concluido);
        $this->assertEquals(0.0, (float) $snapshot->percentual_concluido);
        $this->assertNull($snapshot->real_inicio);
        $this->assertNull($snapshot->real_termino);
    }

    /** G — ausência total de dado no XML (sem PercentWorkComplete/ActualStart/ActualFinish) permanece null, nunca 0. */
    public function test_ausencia_de_dado_no_xml_permanece_null(): void
    {
        $importacao = $this->importar('cronograma_sample.xml');
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '3')->first();

        $snapshot = $this->snapshotDe($importacao, $at->id);

        $this->assertNull($snapshot->percentual_concluido);
        $this->assertNull($snapshot->real_inicio);
        $this->assertNull($snapshot->real_termino);
    }

    /**
     * I + J — gravar a Fotografia F de uma importação a 100% nunca reabre,
     * nunca fecha Restrição/item de prontidão, e nunca cria RestricaoAcao
     * automática (a regra da A.9.1 continua intacta depois da A.9.2).
     */
    public function test_gravar_fotografia_f_nao_reintroduz_autocorrecao_da_a91(): void
    {
        $i1 = $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Projeto aprovado', 'ordem' => 0]);
        AtividadeItemProntidao::create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'item_prontidao_id' => $item->id,
            'concluido' => false,
        ]);

        $i2 = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Baseline);

        // Fotografia F da importação 2 registra o fato: 100%.
        $this->assertEquals(100.0, (float) $this->snapshotDe($i2, $at->id)->percentual_concluido);

        // Pendências operacionais: intocadas.
        $this->assertEquals(StatusRestricao::Aberta, $restricao->fresh()->status);
        $this->assertNull($restricao->fresh()->resolvida_em);
        $this->assertDatabaseMissing('restricao_acoes', ['restricao_id' => $restricao->id]);

        $registro = AtividadeItemProntidao::where('atividade_id', $at->id)
            ->where('item_prontidao_id', $item->id)
            ->first();
        $this->assertFalse((bool) $registro->concluido);
        $this->assertNull($registro->concluido_em);

        // A fotografia da PRIMEIRA importação (40%) continua intacta também.
        $this->assertEquals(40.0, (float) $this->snapshotDe($i1, $at->id)->percentual_concluido);
    }

    /** L — snapshot criado sem os campos novos (equivalente a um registro legado, anterior à A.9.2) aceita null sem exceção. */
    public function test_snapshot_sem_campos_novos_aceita_null(): void
    {
        $importacao = CronogramaImportacao::create([
            'tenant_id' => $this->user->tenant_id,
            'obra_id' => $this->obra->id,
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'importado_em' => now(),
        ]);
        $at = Atividade::factory()->create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id]);

        $snapshot = AtividadeSnapshot::create([
            'tenant_id' => $this->user->tenant_id,
            'cronograma_importacao_id' => $importacao->id,
            'atividade_id' => $at->id,
            'inicio_planejado' => now(),
            'data_termino' => now()->addDays(5),
        ]);

        $this->assertNull($snapshot->fresh()->percentual_concluido);
        $this->assertNull($snapshot->fresh()->real_inicio);
        $this->assertNull($snapshot->fresh()->real_termino);
    }
}
