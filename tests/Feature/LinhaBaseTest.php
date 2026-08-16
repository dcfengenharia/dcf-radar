<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\CronogramaImportacao;
use App\Models\LinhaBase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    // =========================================================================
    // Ciclo 17, correção pós-QA — recriação de Linha de Base após exclusão
    // (bug real: unique(obra_id, cronograma_importacao_id) + SoftDeletes
    // bloqueava um segundo INSERT pra mesma importação, mesmo com a linha
    // antiga já em lixeira, e o toast genérico escondia a causa).
    // =========================================================================

    private function componente()
    {
        return Livewire::test('pages::radar.linhas-base', ['obra' => $this->obra]);
    }

    public function test_recriar_linha_base_apos_exclusao_reutilizando_mesma_importacao_pelo_fluxo_real(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $importacao = $this->criarImportacao();

        $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacao->id)
            ->set('nome', 'Baseline Original')
            ->call('salvar')
            ->assertHasNoErrors();

        $l1 = LinhaBase::where('cronograma_importacao_id', $importacao->id)->firstOrFail();
        $idOriginal = $l1->id;

        $this->componente()->call('excluir', $l1->id);
        $this->assertSoftDeleted('linhas_base', ['id' => $idOriginal]);

        // Reproduz exatamente o cenário relatado: sem nova importação,
        // recriar a Linha de Base usando a MESMA importação antiga.
        $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacao->id)
            ->set('nome', 'Baseline Recriada')
            ->set('descricao', 'Nova descrição')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertEquals(1, LinhaBase::where('cronograma_importacao_id', $importacao->id)->count());

        $restaurada = LinhaBase::where('cronograma_importacao_id', $importacao->id)->firstOrFail();
        $this->assertEquals($idOriginal, $restaurada->id, 'Deve restaurar a mesma row (histórico preservado), não criar uma segunda.');
        $this->assertNull($restaurada->deleted_at);
        $this->assertEquals('Baseline Recriada', $restaurada->nome);
        $this->assertEquals('Nova descrição', $restaurada->descricao);
        $this->assertEquals($this->obra->id, $restaurada->obra_id);
        $this->assertEquals($this->user->id, $restaurada->criado_por);
    }

    public function test_criar_linha_base_com_importacao_ja_vinculada_a_ativa_mostra_erro_didatico(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $importacao = $this->criarImportacao();
        LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Baseline 02',
            'cronograma_importacao_id' => $importacao->id,
            'criado_por' => $this->user->id,
        ]);

        $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacao->id)
            ->set('nome', 'Tentativa Duplicada')
            ->call('salvar')
            ->assertHasErrors(['importacaoSelecionada'])
            ->assertSee('Baseline 02');

        // Nenhuma segunda row, nenhum erro SQL cru chegou ao usuário.
        $this->assertEquals(1, LinhaBase::where('cronograma_importacao_id', $importacao->id)->count());
    }

    public function test_restaura_linha_base_soft_deleted_em_vez_de_criar_nova_row(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $importacao = $this->criarImportacao();
        $l1 = LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Antiga',
            'cronograma_importacao_id' => $importacao->id,
            'criado_por' => $this->user->id,
        ]);
        $l1->delete();

        $this->assertNotNull(LinhaBase::onlyTrashed()->find($l1->id));

        $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacao->id)
            ->set('nome', 'Restaurada')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertEquals(1, LinhaBase::withTrashed()->where('cronograma_importacao_id', $importacao->id)->count());
        $restaurada = LinhaBase::find($l1->id);
        $this->assertNotNull($restaurada);
        $this->assertEquals('Restaurada', $restaurada->nome);
    }

    public function test_criar_linha_base_para_outra_importacao_diferente_continua_funcionando(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $imp1 = $this->criarImportacao();
        $imp2 = $this->criarImportacao();

        LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'LB1',
            'cronograma_importacao_id' => $imp1->id,
            'criado_por' => $this->user->id,
        ]);

        $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $imp2->id)
            ->set('nome', 'LB2')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertEquals(1, LinhaBase::where('cronograma_importacao_id', $imp1->id)->count());
        $this->assertEquals(1, LinhaBase::where('cronograma_importacao_id', $imp2->id)->count());
    }

    public function test_seletor_de_importacoes_reflete_os_3_estados_possiveis(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $impNuncaUsada = $this->criarImportacao();
        $impAtiva = $this->criarImportacao();
        $impExcluida = $this->criarImportacao();

        LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'Ativa',
            'cronograma_importacao_id' => $impAtiva->id,
            'criado_por' => $this->user->id,
        ]);
        $lbExcluida = LinhaBase::create([
            'obra_id' => $this->obra->id,
            'nome' => 'ExcluidaAntes',
            'cronograma_importacao_id' => $impExcluida->id,
            'criado_por' => $this->user->id,
        ]);
        $lbExcluida->delete();

        $disponiveis = $this->componente()->instance()->importacoesDisponiveis->pluck('id')->all();

        $this->assertContains($impNuncaUsada->id, $disponiveis, 'Importação nunca usada deve aparecer.');
        $this->assertNotContains($impAtiva->id, $disponiveis, 'Importação com LinhaBase ATIVA não deve aparecer.');
        $this->assertContains($impExcluida->id, $disponiveis, 'Importação com LinhaBase excluída deve aparecer (reutilizável).');
    }

    // =========================================================================
    // Ciclo 17, correção de segurança pós-auditoria — importacaoSelecionada é
    // propriedade pública Livewire, manipulável direto pelo cliente. A regra
    // exists:cronograma_importacoes,id sozinha não impede referenciar uma
    // importação de outra obra (mesmo tenant) ou de outro tenant inteiramente
    // — comprovado empiricamente na auditoria anterior. Estes testes fecham
    // essa lacuna usando o fluxo Livewire real (nunca chamando o Model direto).
    // =========================================================================

    private function mensagemImportacaoInvalida(): string
    {
        return 'Selecione uma importação válida desta obra.';
    }

    public function test_cross_obra_mesmo_tenant_e_rejeitado(): void
    {
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $importacaoB = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obraB->id,
            'user_id' => $this->user->id,
            'criadas' => 1,
            'atualizadas' => 0,
            'removidas' => 0,
            'importado_em' => now(),
        ]);

        $componente = $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacaoB->id)
            ->set('nome', 'Tentativa Cross-Obra')
            ->call('salvar')
            ->assertHasErrors(['importacaoSelecionada']);

        $this->assertEquals($this->mensagemImportacaoInvalida(), $componente->errors()->first('importacaoSelecionada'));

        $this->assertEquals(0, LinhaBase::withTrashed()->where('cronograma_importacao_id', $importacaoB->id)->count());
        $this->assertEquals(0, LinhaBase::where('obra_id', $this->obra->id)->count());
        $this->assertEquals(0, LinhaBase::where('obra_id', $obraB->id)->count());
    }

    public function test_cross_obra_mesmo_tenant_com_usuario_tendo_acesso_as_duas_obras_ainda_e_rejeitado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->vincularObra($obraB, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $importacaoB = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $obraB->id,
            'user_id' => $this->user->id,
            'criadas' => 1,
            'atualizadas' => 0,
            'removidas' => 0,
            'importado_em' => now(),
        ]);

        // Componente continua montado na Obra A — ter permissão/acesso à
        // Obra B em outro lugar do sistema não muda a fronteira: a regra é
        // "a importação pertence ao CONTEXTO DA OBRA ATUAL", não "o usuário
        // tem acesso à importação em algum lugar".
        $componente = $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacaoB->id)
            ->set('nome', 'Tentativa Cross-Obra Com Acesso')
            ->call('salvar')
            ->assertHasErrors(['importacaoSelecionada']);

        $this->assertEquals($this->mensagemImportacaoInvalida(), $componente->errors()->first('importacaoSelecionada'));

        $this->assertEquals(0, LinhaBase::withTrashed()->where('cronograma_importacao_id', $importacaoB->id)->count());
    }

    public function test_cross_tenant_e_rejeitado(): void
    {
        $tenantB = Tenant::factory()->create();
        $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
        $importacaoB = TenantContext::actingAs($tenantB, function () use ($tenantB, $obraB) {
            return CronogramaImportacao::create([
                'tenant_id' => $tenantB->id,
                'obra_id' => $obraB->id,
                'criadas' => 1,
                'atualizadas' => 0,
                'removidas' => 0,
                'importado_em' => now(),
            ]);
        });

        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $componente = $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacaoB->id)
            ->set('nome', 'Tentativa Cross-Tenant')
            ->call('salvar')
            ->assertHasErrors(['importacaoSelecionada']);

        $this->assertEquals($this->mensagemImportacaoInvalida(), $componente->errors()->first('importacaoSelecionada'));

        $this->assertEquals(0, LinhaBase::withTrashed()->where('obra_id', $this->obra->id)->count());
        $criadaNoBancoInteiro = TenantContext::actingAs($tenantB, fn () => LinhaBase::withTrashed()->where('cronograma_importacao_id', $importacaoB->id)->count());
        $this->assertEquals(0, $criadaNoBancoInteiro);
    }

    public function test_restore_cross_obra_e_bloqueado(): void
    {
        $obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $userB = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($obraB, $userB, Papel::GerentePlanejamento->value);

        // Ainda sem nenhum actingAs() nesta thread de teste — sem contexto de
        // tenant resolvível, o auto-stamp de BelongsToTenant não teria de onde
        // tirar o tenant_id. Usa TenantContext::actingAs() explicitamente,
        // igual ao resto da suíte, pra montar o cenário da Obra B.
        [$importacaoB, $linhaBaseB] = TenantContext::actingAs($this->tenant, function () use ($obraB, $userB) {
            $importacaoB = CronogramaImportacao::create([
                'tenant_id' => $this->tenant->id,
                'obra_id' => $obraB->id,
                'user_id' => $userB->id,
                'criadas' => 1,
                'atualizadas' => 0,
                'removidas' => 0,
                'importado_em' => now(),
            ]);
            $linhaBaseB = LinhaBase::create([
                'obra_id' => $obraB->id,
                'nome' => 'Baseline Obra B',
                'cronograma_importacao_id' => $importacaoB->id,
                'criado_por' => $userB->id,
            ]);
            $linhaBaseB->delete();

            return [$importacaoB, $linhaBaseB];
        });

        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $componente = $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacaoB->id)
            ->set('nome', 'Tentativa Restore Cross-Obra')
            ->call('salvar')
            ->assertHasErrors(['importacaoSelecionada']);

        $this->assertEquals($this->mensagemImportacaoInvalida(), $componente->errors()->first('importacaoSelecionada'));

        // A LinhaBase da Obra B continua exatamente como estava: excluída,
        // mesma PK, nunca restaurada, nunca "roubada" pela Obra A.
        $aindaExcluida = LinhaBase::onlyTrashed()->find($linhaBaseB->id);
        $this->assertNotNull($aindaExcluida);
        $this->assertEquals('Baseline Obra B', $aindaExcluida->nome);
        $this->assertNull(LinhaBase::find($linhaBaseB->id));

        // Nenhuma LinhaBase nova foi criada na Obra A.
        $this->assertEquals(0, LinhaBase::where('obra_id', $this->obra->id)->count());
    }

    public function test_restore_cross_tenant_e_bloqueado(): void
    {
        $tenantB = Tenant::factory()->create();
        [$obraB, $importacaoB, $linhaBaseB] = TenantContext::actingAs($tenantB, function () use ($tenantB) {
            $obraB = Work::factory()->create(['tenant_id' => $tenantB->id]);
            $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
            $importacaoB = CronogramaImportacao::create([
                'tenant_id' => $tenantB->id,
                'obra_id' => $obraB->id,
                'user_id' => $userB->id,
                'criadas' => 1,
                'atualizadas' => 0,
                'removidas' => 0,
                'importado_em' => now(),
            ]);
            $linhaBaseB = LinhaBase::create([
                'obra_id' => $obraB->id,
                'nome' => 'Baseline Tenant B',
                'cronograma_importacao_id' => $importacaoB->id,
                'criado_por' => $userB->id,
            ]);
            $linhaBaseB->delete();

            return [$obraB, $importacaoB, $linhaBaseB];
        });

        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);

        $componente = $this->componente()
            ->set('modalCriar', true)
            ->set('importacaoSelecionada', $importacaoB->id)
            ->set('nome', 'Tentativa Restore Cross-Tenant')
            ->call('salvar')
            ->assertHasErrors(['importacaoSelecionada']);

        $this->assertEquals($this->mensagemImportacaoInvalida(), $componente->errors()->first('importacaoSelecionada'));

        $aindaExcluida = TenantContext::actingAs($tenantB, fn () => LinhaBase::onlyTrashed()->find($linhaBaseB->id));
        $this->assertNotNull($aindaExcluida);
        $ativaNoTenantB = TenantContext::actingAs($tenantB, fn () => LinhaBase::find($linhaBaseB->id));
        $this->assertNull($ativaNoTenantB);

        $this->assertEquals(0, LinhaBase::where('obra_id', $this->obra->id)->count());
    }
}
