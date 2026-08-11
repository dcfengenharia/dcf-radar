<?php

namespace Tests\Feature;

use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckSeveridade;
use App\Exceptions\PlanoAcaoDuplicadoException;
use App\Models\CronogramaImportacao;
use App\Models\PlanoAcao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\HealthCheck\HealthCheckFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4.2, Parte 2/8 — "duplicação controlada": bloqueia só quando existe
 * uma ação Aberta da MESMA obra + regra_id com sobreposição de uid com o
 * finding usado agora (MESMO critério do PlanoAcaoReconciliador,
 * SobreposicaoUid — nunca uma heurística nova). Findings distintos da
 * mesma regra sem sobreposição entre si coexistem livremente.
 */
class PlanoAcaoDuplicacaoTest extends TestCase
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

    private function finding(string $regraId, array $uids): HealthCheckFinding
    {
        return new HealthCheckFinding(
            regraId: $regraId,
            categoria: HealthCheckCategoria::Estrutura,
            severidade: HealthCheckSeveridade::Critico,
            titulo: "Finding {$regraId}",
            descricao: 'descrição',
            impacto: 'impacto',
            recomendacao: 'recomendação',
            atividades: array_map(fn ($uid) => ['uid' => $uid], $uids),
        );
    }

    public function test_bloqueia_criacao_quando_ha_sobreposicao_com_acao_aberta_da_mesma_regra(): void
    {
        $existente = PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['1', '2', '3']), $this->importacao, $this->obra->id);

        try {
            PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['3', '4']), $this->importacao, $this->obra->id);
            $this->fail('Esperava PlanoAcaoDuplicadoException.');
        } catch (PlanoAcaoDuplicadoException $e) {
            $this->assertTrue($e->acaoExistente->is($existente));
        }

        $this->assertSame(1, PlanoAcao::count(), 'não deve ter criado uma segunda ação duplicada');
    }

    public function test_permite_coexistencia_de_findings_distintos_da_mesma_regra_sem_sobreposicao(): void
    {
        // Exemplo literal do diagnóstico da Fase 4: Finding A [1,2,3] e Finding B [10,11].
        PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['1', '2', '3']), $this->importacao, $this->obra->id);
        $segunda = PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['10', '11']), $this->importacao, $this->obra->id);

        $this->assertSame(2, PlanoAcao::count());
        $this->assertEqualsCanonicalizing(['10', '11'], $segunda->uids_referencia);
    }

    public function test_regra_diferente_com_mesmos_uids_nao_e_tratada_como_duplicata(): void
    {
        PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['1', '2']), $this->importacao, $this->obra->id);
        $segunda = PlanoAcao::criarDeFinding($this->finding('STRUCT-004', ['1', '2']), $this->importacao, $this->obra->id);

        $this->assertSame(2, PlanoAcao::count());
        $this->assertSame('STRUCT-004', $segunda->regra_id);
    }

    public function test_acao_resolvida_nao_bloqueia_nova_criacao_para_o_mesmo_problema(): void
    {
        $antiga = PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['1', '2']), $this->importacao, $this->obra->id);
        $antiga->update(['status' => \App\Enums\StatusPlanoAcao::Resolvida]);

        // Mesmo com sobreposição total, a ação antiga não está mais Aberta —
        // não deve contar pra checagem de duplicação.
        $nova = PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['1', '2']), $this->importacao, $this->obra->id);

        $this->assertSame(2, PlanoAcao::count());
        $this->assertTrue($nova->status->estaAberta());
    }

    public function test_duplicacao_e_escopada_por_obra_mesma_regra_outra_obra_nao_bloqueia(): void
    {
        PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['1', '2']), $this->importacao, $this->obra->id);

        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outraImportacao = CronogramaImportacao::create(['obra_id' => $outraObra->id, 'importado_em' => now()]);

        $acaoOutraObra = PlanoAcao::criarDeFinding($this->finding('STRUCT-005', ['1', '2']), $outraImportacao, $outraObra->id);

        $this->assertSame(2, PlanoAcao::count());
        $this->assertSame($outraObra->id, $acaoOutraObra->obra_id);
    }
}
