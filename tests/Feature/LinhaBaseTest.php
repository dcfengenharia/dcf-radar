<?php

namespace Tests\Feature;

use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinhaBaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User   $user;
    private Work   $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user   = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra   = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function criarImportacao(): CronogramaImportacao
    {
        return CronogramaImportacao::create([
            'tenant_id'  => $this->tenant->id,
            'obra_id'    => $this->obra->id,
            'user_id'    => $this->user->id,
            'criadas'    => 2,
            'atualizadas'=> 0,
            'removidas'  => 0,
            'importado_em' => now(),
        ]);
    }

    public function test_criar_linha_base(): void
    {
        $this->actingAs($this->user);

        $importacao = $this->criarImportacao();

        LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'Baseline 0 – Contrato Original',
            'descricao'                => 'Referência inicial do projeto',
            'cronograma_importacao_id' => $importacao->id,
            'criado_por'               => $this->user->id,
        ]);

        $this->assertDatabaseHas('linhas_base', [
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'Baseline 0 – Contrato Original',
            'cronograma_importacao_id' => $importacao->id,
            'tenant_id'                => $this->tenant->id,
        ]);
    }

    public function test_nao_pode_criar_dois_baselines_para_mesma_importacao(): void
    {
        $this->actingAs($this->user);

        $importacao = $this->criarImportacao();

        LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'Primeira',
            'cronograma_importacao_id' => $importacao->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'Duplicada',
            'cronograma_importacao_id' => $importacao->id,
        ]);
    }

    public function test_importacao_com_baseline_nao_pode_ser_apagada(): void
    {
        $this->actingAs($this->user);

        $importacao = $this->criarImportacao();

        LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'Com baseline',
            'cronograma_importacao_id' => $importacao->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $importacao->delete();
    }

    public function test_soft_delete_libera_importacao_para_nova_linha_base(): void
    {
        $this->actingAs($this->user);

        $importacao = $this->criarImportacao();

        $lb = LinhaBase::create([
            'obra_id'                  => $this->obra->id,
            'nome'                     => 'Temporária',
            'cronograma_importacao_id' => $importacao->id,
        ]);

        // Após soft delete, a importação fica "livre" pois o registro está em lixeira
        $lb->delete();

        $this->assertSoftDeleted('linhas_base', ['id' => $lb->id]);

        // O modelo deve existir e ter deleted_at preenchido
        $lbNoBanco = LinhaBase::withTrashed()->find($lb->id);
        $this->assertNotNull($lbNoBanco);
        $this->assertNotNull($lbNoBanco->deleted_at);

        // A importação não deve ter sido deletada junto
        $this->assertDatabaseHas('cronograma_importacoes', ['id' => $importacao->id]);
    }
}
