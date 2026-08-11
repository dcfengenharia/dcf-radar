<?php

namespace Tests\Unit;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Enums\StatusPlanoAcao;
use App\Models\CronogramaImportacao;
use App\Models\PlanoAcao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4.1 — Parte B/K "Criação": PlanoAcao::criarDeFinding() e isolamento
 * básico de tenant/obra. A lógica de reconciliação entre importações fica
 * em PlanoAcaoReconciliadorTest — este arquivo cobre só a criação isolada.
 */
class PlanoAcaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');

        $this->importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function findingFlat(string $regraId = 'PROG-001', array $uids = ['101', '102']): HealthCheckFinding
    {
        return new HealthCheckFinding(
            regraId: $regraId,
            categoria: HealthCheckCategoria::Avanco,
            severidade: HealthCheckSeveridade::Alto,
            titulo: 'Atividade atrasada',
            descricao: 'descrição de teste',
            impacto: 'impacto de teste',
            recomendacao: 'Revisar o avanço real.',
            atividades: array_map(fn ($uid) => ['uid' => $uid, 'codigo' => "1.$uid", 'nome' => "Atividade $uid"], $uids),
        );
    }

    // =====================================================================
    // CRIAÇÃO
    // =====================================================================

    public function test_criar_de_finding_nasce_aberta_com_dados_congelados_e_uids_extraidos(): void
    {
        $finding = $this->findingFlat();

        $acao = PlanoAcao::criarDeFinding($finding, $this->importacao, $this->obra->id);

        $this->assertTrue($acao->status->estaAberta());
        $this->assertSame(StatusPlanoAcao::Aberta, $acao->status);
        $this->assertSame('PROG-001', $acao->regra_id);
        $this->assertSame('Atividade atrasada', $acao->titulo);
        $this->assertSame('Revisar o avanço real.', $acao->recomendacao);
        $this->assertSame(['101', '102'], $acao->uids_referencia);
        $this->assertSame($this->obra->id, $acao->obra_id);
        $this->assertSame($this->importacao->id, $acao->cronograma_importacao_origem_id);
        $this->assertSame($this->tenant->id, $acao->tenant_id);
        $this->assertNull($acao->resolvida_em);
    }

    public function test_criar_de_finding_com_responsavel_e_prazo(): void
    {
        $responsavel = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $responsavel, 'engenheiro');

        $acao = PlanoAcao::criarDeFinding(
            $this->findingFlat(),
            $this->importacao,
            $this->obra->id,
            responsavel: $responsavel,
            prazo: '2026-08-05',
        );

        $this->assertTrue($acao->responsavel->is($responsavel));
        $this->assertSame('2026-08-05', $acao->prazo->toDateString());
    }

    public function test_autor_e_carimbado_automaticamente_via_hasauthorship(): void
    {
        $acao = PlanoAcao::criarDeFinding($this->findingFlat(), $this->importacao, $this->obra->id);

        $this->assertTrue($acao->autor->is($this->user));
    }

    public function test_uids_de_finding_agrupado_sao_extraidos_corretamente_na_criacao(): void
    {
        $finding = new HealthCheckFinding(
            regraId: 'STRUCT-005',
            categoria: HealthCheckCategoria::Estrutura,
            severidade: HealthCheckSeveridade::Critico,
            titulo: 'Ciclo lógico identificado no cronograma',
            descricao: 'descrição',
            impacto: 'impacto',
            recomendacao: 'Revise as relações.',
            atividades: [[
                'ciclo_id' => 1,
                'atividades' => [
                    ['uid' => '201', 'codigo' => '2.1', 'nome' => 'C'],
                    ['uid' => '202', 'codigo' => '2.2', 'nome' => 'D'],
                ],
                'relacoes' => [['de' => '201', 'para' => '202']],
            ]],
        );

        $acao = PlanoAcao::criarDeFinding($finding, $this->importacao, $this->obra->id);

        $this->assertEqualsCanonicalizing(['201', '202'], $acao->uids_referencia);
    }

    // =====================================================================
    // SEGURANÇA
    // =====================================================================

    public function test_criar_de_finding_com_responsavel_sem_acesso_a_obra_lanca_excecao(): void
    {
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // $semAcesso NUNCA foi vinculado a $this->obra.

        $this->expectException(\InvalidArgumentException::class);

        PlanoAcao::criarDeFinding($this->findingFlat(), $this->importacao, $this->obra->id, responsavel: $semAcesso);
    }

    public function test_plano_acao_nao_aparece_para_outro_tenant(): void
    {
        $acao = PlanoAcao::criarDeFinding($this->findingFlat(), $this->importacao, $this->obra->id);

        $outroTenant = Tenant::factory()->create();
        $outroUsuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($outroUsuario);

        // Global scope de BelongsToTenant já torna a ação invisível pra
        // qualquer query feita sob outro tenant.
        $this->assertNull(PlanoAcao::find($acao->id));
    }

    public function test_plano_acao_criado_para_outro_tenant_nao_vaza_tenant_id_do_usuario_atual(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $outraImportacao = TenantContext::actingAs($outroTenant, fn () => CronogramaImportacao::create([
            'obra_id' => $outraObra->id,
            'importado_em' => now(),
        ]));

        // BelongsToTenant sempre carimba o tenant do usuário AUTENTICADO
        // (não o que vier no array), então criar "pra outro tenant" sem
        // TenantContext::actingAs() envolvendo a própria criação da ação
        // resultaria, na prática, numa ação do tenant ATUAL — confirmando
        // esse comportamento aqui evita o mesmo falso-negativo já documentado
        // em fases anteriores (Score Etapa 4).
        $acao = TenantContext::actingAs($outroTenant, fn () => PlanoAcao::criarDeFinding(
            $this->findingFlat(),
            $outraImportacao,
            $outraObra->id,
        ));

        $this->assertSame($outroTenant->id, $acao->tenant_id);
        $this->assertNull(PlanoAcao::find($acao->id)); // invisível sob o tenant atual do teste
    }

    public function test_query_por_obra_nao_traz_acoes_de_outra_obra_do_mesmo_tenant(): void
    {
        $acaoDaObra = PlanoAcao::criarDeFinding($this->findingFlat(), $this->importacao, $this->obra->id);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()]);
        $acaoDeOutraObra = PlanoAcao::criarDeFinding($this->findingFlat(), $outraImportacao, $outraObra->id);

        $resultado = PlanoAcao::where('obra_id', $this->obra->id)->pluck('id');

        $this->assertTrue($resultado->contains($acaoDaObra->id));
        $this->assertFalse($resultado->contains($acaoDeOutraObra->id));
    }
}
