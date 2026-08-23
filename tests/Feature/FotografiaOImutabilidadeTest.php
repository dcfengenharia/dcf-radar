<?php

namespace Tests\Feature;

use App\Enums\StatusRestricao;
use App\Enums\TipoCronogramaImportacao;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Atividade;
use App\Models\AtividadeSnapshotOperacional;
use App\Models\AtividadeSnapshotProntidao;
use App\Models\AtividadeSnapshotRestricao;
use App\Models\CronogramaImportacao;
use App\Models\ItemProntidao;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 17, A.9.3.CORREÇÃO — prova que a Fotografia O agora é
 * verdadeiramente imutável: soft delete de Restricao/ItemProntidao
 * continua funcionando normalmente, mas um forceDelete() de qualquer uma
 * das duas, enquanto referenciada por alguma Fotografia O histórica, é
 * REJEITADO pelo banco (restrictOnDelete()) — nunca apaga a linha
 * histórica como efeito colateral.
 */
class FotografiaOImutabilidadeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;
    private ImportadorCronograma $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->importer = app(ImportadorCronograma::class);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    private function importar(string $arquivo, TipoCronogramaImportacao $tipo): CronogramaImportacao
    {
        $plano = $this->importer->analisar($this->fixture($arquivo), $this->obra, $tipo);

        return $this->importer->aplicar($plano, $this->obra, $this->user->id, $arquivo, $tipo);
    }

    /** A1 — soft delete de Restricao referenciada por Fotografia O continua funcionando normalmente. */
    public function test_soft_delete_de_restricao_referenciada_continua_funcionando(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $snapshotAntes = AtividadeSnapshotRestricao::where('cronograma_importacao_id', $avanco->id)
            ->where('restricao_id', $restricao->id)->first();
        $this->assertNotNull($snapshotAntes);

        $restricao->delete(); // soft delete

        $this->assertTrue($restricao->trashed());
        $this->assertNotNull(Restricao::withTrashed()->find($restricao->id));

        $snapshotDepois = AtividadeSnapshotRestricao::find($snapshotAntes->id);
        $this->assertNotNull($snapshotDepois, 'Soft delete não deveria afetar a Fotografia O.');
        $this->assertEquals($restricao->id, $snapshotDepois->restricao_id);
    }

    /** A2 — forceDelete() de Restricao referenciada por Fotografia O é bloqueado pelo banco. */
    public function test_force_delete_de_restricao_referenciada_e_bloqueado(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
            'bloqueante' => true,
        ]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $snapshotAntes = AtividadeSnapshotRestricao::where('cronograma_importacao_id', $avanco->id)
            ->where('restricao_id', $restricao->id)->first();
        $this->assertNotNull($snapshotAntes);

        $restricao->delete(); // soft delete primeiro — reflete o fluxo real de exclusão

        $excecaoLancada = false;
        try {
            $restricao->forceDelete();
        } catch (QueryException $e) {
            $excecaoLancada = true;
        }

        $this->assertTrue($excecaoLancada, 'forceDelete() deveria ter sido rejeitado pela FK restrictOnDelete().');

        // Estado depois da exceção: nada foi perdido.
        $restricaoAposFalha = Restricao::withTrashed()->find($restricao->id);
        $this->assertNotNull($restricaoAposFalha, 'Restricao deveria continuar existindo (withTrashed) após forceDelete() rejeitado.');
        $this->assertEquals($restricao->id, $restricaoAposFalha->id);
        $this->assertTrue($restricaoAposFalha->trashed());

        $snapshotDepois = AtividadeSnapshotRestricao::find($snapshotAntes->id);
        $this->assertNotNull($snapshotDepois, 'Fotografia O não deveria ter desaparecido.');
        $this->assertEquals($restricao->id, $snapshotDepois->restricao_id);
        $this->assertEquals($snapshotAntes->id, $snapshotDepois->id);
        $this->assertEquals($snapshotAntes->cronograma_importacao_id, $snapshotDepois->cronograma_importacao_id);
        $this->assertEquals($snapshotAntes->atividade_id, $snapshotDepois->atividade_id);
    }

    /** B1 — soft delete de ItemProntidao referenciado por Fotografia O continua funcionando normalmente. */
    public function test_soft_delete_de_item_prontidao_referenciado_continua_funcionando(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Item imutável', 'ordem' => 0]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $snapshotAntes = AtividadeSnapshotProntidao::where('cronograma_importacao_id', $avanco->id)
            ->where('item_prontidao_id', $item->id)->first();
        $this->assertNotNull($snapshotAntes);

        $item->delete(); // soft delete

        $this->assertTrue($item->trashed());
        $this->assertNotNull(ItemProntidao::withTrashed()->find($item->id));

        $snapshotDepois = AtividadeSnapshotProntidao::find($snapshotAntes->id);
        $this->assertNotNull($snapshotDepois, 'Soft delete não deveria afetar a Fotografia O.');
        $this->assertEquals($item->id, $snapshotDepois->item_prontidao_id);
    }

    /** B2 — forceDelete() de ItemProntidao referenciado por Fotografia O é bloqueado pelo banco. */
    public function test_force_delete_de_item_prontidao_referenciado_e_bloqueado(): void
    {
        $this->importar('cronograma_sample.xml', TipoCronogramaImportacao::Baseline);
        $at = Atividade::where('obra_id', $this->obra->id)->where('external_uid', '2')->first();

        $item = ItemProntidao::create(['tenant_id' => $this->user->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'Item imutável', 'ordem' => 0]);

        $avanco = $this->importar('cronograma_100pct.xml', TipoCronogramaImportacao::Avanco);

        $snapshotAntes = AtividadeSnapshotProntidao::where('cronograma_importacao_id', $avanco->id)
            ->where('item_prontidao_id', $item->id)->first();
        $this->assertNotNull($snapshotAntes);

        $item->delete(); // soft delete primeiro

        $excecaoLancada = false;
        try {
            $item->forceDelete();
        } catch (QueryException $e) {
            $excecaoLancada = true;
        }

        $this->assertTrue($excecaoLancada, 'forceDelete() deveria ter sido rejeitado pela FK restrictOnDelete().');

        $itemAposFalha = ItemProntidao::withTrashed()->find($item->id);
        $this->assertNotNull($itemAposFalha, 'ItemProntidao deveria continuar existindo (withTrashed) após forceDelete() rejeitado.');
        $this->assertEquals($item->id, $itemAposFalha->id);
        $this->assertTrue($itemAposFalha->trashed());

        $snapshotDepois = AtividadeSnapshotProntidao::find($snapshotAntes->id);
        $this->assertNotNull($snapshotDepois, 'Fotografia O não deveria ter desaparecido.');
        $this->assertEquals($item->id, $snapshotDepois->item_prontidao_id);
        $this->assertEquals($snapshotAntes->id, $snapshotDepois->id);
        $this->assertEquals($snapshotAntes->cronograma_importacao_id, $snapshotDepois->cronograma_importacao_id);
        $this->assertEquals($snapshotAntes->atividade_id, $snapshotDepois->atividade_id);
    }

    /** C — forceDelete() de Restricao SEM nenhuma Fotografia O referenciando continua permitido (comportamento normal preservado). */
    public function test_force_delete_de_restricao_sem_fotografia_o_continua_permitido(): void
    {
        $at = Atividade::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'obra_id' => $this->obra->id,
        ]);
        $restricao = Restricao::factory()->create([
            'tenant_id' => $this->user->tenant_id,
            'atividade_id' => $at->id,
            'status' => StatusRestricao::Aberta->value,
        ]);
        $restricao->delete();

        $restricao->forceDelete();

        $this->assertNull(Restricao::withTrashed()->find($restricao->id));
    }

    /** D — dados históricos: contagens/IDs/FKs permanecem consistentes após operação normal com a nova constraint ativa. */
    public function test_dados_historicos_permanecem_consistentes_com_a_nova_constraint(): void
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

        $operacional = AtividadeSnapshotOperacional::where('cronograma_importacao_id', $avanco->id)->where('atividade_id', $at->id)->first();
        $restricaoSnap = AtividadeSnapshotRestricao::where('cronograma_importacao_id', $avanco->id)->where('restricao_id', $restricao->id)->first();
        $prontidaoSnap = AtividadeSnapshotProntidao::where('cronograma_importacao_id', $avanco->id)->where('item_prontidao_id', $item->id)->first();

        $this->assertNotNull($operacional);
        $this->assertNotNull($restricaoSnap);
        $this->assertNotNull($prontidaoSnap);
        // cronograma_sample.xml tem 2 atividades — atividade_snapshot_operacionais
        // grava 1 linha por atividade TOCADA na importação (não só a com pendência),
        // então o count por importacao é 2; scopar por atividade_id também confirma unicidade real.
        $this->assertEquals(1, AtividadeSnapshotOperacional::where('cronograma_importacao_id', $avanco->id)->where('atividade_id', $at->id)->count());
        $this->assertEquals(1, AtividadeSnapshotRestricao::where('cronograma_importacao_id', $avanco->id)->where('restricao_id', $restricao->id)->count());
        $this->assertEquals(1, AtividadeSnapshotProntidao::where('cronograma_importacao_id', $avanco->id)->where('atividade_id', $at->id)->where('item_prontidao_id', $item->id)->count());
        $this->assertEquals($at->id, $operacional->atividade_id);
        $this->assertEquals($restricao->id, $restricaoSnap->restricao_id);
        $this->assertEquals($item->id, $prontidaoSnap->item_prontidao_id);
    }
}
