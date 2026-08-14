<?php

namespace Tests\Feature;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\TipoRelacionamentoPredecessora;
use App\Enums\TipoRestricaoCronograma;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2A do Health Check — valida SÓ a leitura aditiva dos novos campos
 * estruturais (predecessoras, lag, folga, restrição, Active). Nenhuma
 * regra de Health Check é avaliada aqui (Fase 2B ainda não começou) — só
 * confere que MsProjectImporter::analisar() está extraindo os dados certos
 * pro DTO TarefaImportada, sem tocar em nada de HH/baseline/realizado.
 */
class MsProjectImporterEstruturalTest extends TestCase
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

    private function plano(): PlanoImportacao
    {
        return $this->importer->analisar($this->fixture('cronograma_fase2a_estrutural.xml'), $this->obra);
    }

    private function porUid(PlanoImportacao $plano, string $uid): TarefaImportada
    {
        foreach ([...$plano->criar, ...$plano->pacotes] as $tarefa) {
            if ($tarefa->uid === $uid) {
                return $tarefa;
            }
        }

        $this->fail("Tarefa UID={$uid} não encontrada no plano.");
    }

    // -------------------------------------------------------------------
    // Predecessoras
    // -------------------------------------------------------------------

    public function test_atividade_sem_predecessora_tem_array_vazio(): void
    {
        $t = $this->porUid($this->plano(), '2');

        $this->assertSame([], $t->predecessoras);
    }

    public function test_predecessora_unica_fs_com_lag_positivo_bruto(): void
    {
        $t = $this->porUid($this->plano(), '11');

        $this->assertCount(1, $t->predecessoras);
        $link = $t->predecessoras[0];
        $this->assertSame('10', $link->predecessoraUid);
        $this->assertSame(TipoRelacionamentoPredecessora::FinishToStart, $link->tipo);
        $this->assertSame(1, $link->tipoCodigoOriginal);
        // Bruto, sem conversão de unidade — exatamente o valor do XML.
        $this->assertSame(4800, $link->linkLag);
        $this->assertSame(7, $link->lagFormat);
    }

    public function test_predecessora_ss_com_lag_zero(): void
    {
        $t = $this->porUid($this->plano(), '12');

        $link = $t->predecessoras[0];
        $this->assertSame(TipoRelacionamentoPredecessora::StartToStart, $link->tipo);
        $this->assertSame(3, $link->tipoCodigoOriginal);
        $this->assertSame(0, $link->linkLag);
    }

    public function test_predecessora_ff_com_lag_negativo_lead(): void
    {
        $t = $this->porUid($this->plano(), '13');

        $link = $t->predecessoras[0];
        $this->assertSame(TipoRelacionamentoPredecessora::FinishToFinish, $link->tipo);
        $this->assertSame(0, $link->tipoCodigoOriginal);
        // Bruto e negativo — nunca convertido/arredondado/normalizado.
        $this->assertSame(-2400, $link->linkLag);
    }

    public function test_predecessora_sf(): void
    {
        $t = $this->porUid($this->plano(), '14');

        $link = $t->predecessoras[0];
        $this->assertSame(TipoRelacionamentoPredecessora::StartToFinish, $link->tipo);
        $this->assertSame(2, $link->tipoCodigoOriginal);
    }

    public function test_multiplas_predecessoras_sao_todas_capturadas(): void
    {
        $t = $this->porUid($this->plano(), '15');

        $this->assertCount(2, $t->predecessoras);
        $uids = array_map(fn ($l) => $l->predecessoraUid, $t->predecessoras);
        $this->assertEqualsCanonicalizing(['10', '11'], $uids);

        // O segundo link tem LagFormat diferente (4 = Days) do primeiro (7 = Elapsed Weeks) — preservado à parte.
        $linkComLagFormat4 = collect($t->predecessoras)->firstWhere('lagFormat', 4);
        $this->assertNotNull($linkComLagFormat4);
        $this->assertSame(1200, $linkComLagFormat4->linkLag);
    }

    public function test_predecessora_com_type_ausente_assume_fs(): void
    {
        $t = $this->porUid($this->plano(), '16');

        $link = $t->predecessoras[0];
        $this->assertSame(TipoRelacionamentoPredecessora::FinishToStart, $link->tipo);
        $this->assertSame(1, $link->tipoCodigoOriginal);
    }

    // -------------------------------------------------------------------
    // Folgas (TotalSlack / FreeSlack)
    // -------------------------------------------------------------------

    public function test_folga_total_e_livre_positivas(): void
    {
        $t = $this->porUid($this->plano(), '20');

        $this->assertSame(4800, $t->totalSlack);
        $this->assertSame(2400, $t->freeSlack);
    }

    public function test_folga_total_e_livre_zero(): void
    {
        $t = $this->porUid($this->plano(), '21');

        $this->assertSame(0, $t->totalSlack);
        $this->assertSame(0, $t->freeSlack);
        $this->assertTrue($t->caminhoCritico);
    }

    public function test_folga_total_e_livre_negativas(): void
    {
        $t = $this->porUid($this->plano(), '22');

        $this->assertSame(-1200, $t->totalSlack);
        $this->assertSame(-600, $t->freeSlack);
    }

    public function test_folga_ausente_vira_null_nunca_zero(): void
    {
        $t = $this->porUid($this->plano(), '23');

        $this->assertNull($t->totalSlack);
        $this->assertNull($t->freeSlack);
    }

    // -------------------------------------------------------------------
    // Restrições (8 tipos)
    // -------------------------------------------------------------------

    public function test_restricao_asap(): void
    {
        $t = $this->porUid($this->plano(), '30');

        $this->assertSame(TipoRestricaoCronograma::AssimQuePossivel, $t->tipoRestricao);
        $this->assertSame(0, $t->tipoRestricaoCodigoOriginal);
        $this->assertFalse($t->tipoRestricao->imposDataFixa());
    }

    public function test_restricao_alap(): void
    {
        $t = $this->porUid($this->plano(), '31');

        $this->assertSame(TipoRestricaoCronograma::OMaisTardePossivel, $t->tipoRestricao);
        $this->assertFalse($t->tipoRestricao->imposDataFixa());
    }

    public function test_restricao_must_start_on(): void
    {
        $t = $this->porUid($this->plano(), '32');

        $this->assertSame(TipoRestricaoCronograma::DeveComecarEm, $t->tipoRestricao);
        $this->assertTrue($t->tipoRestricao->imposDataFixa());
        $this->assertNotNull($t->dataRestricao);
        $this->assertSame('2024-03-01', $t->dataRestricao->toDateString());
    }

    public function test_restricao_must_finish_on(): void
    {
        $t = $this->porUid($this->plano(), '33');

        $this->assertSame(TipoRestricaoCronograma::DeveTerminarEm, $t->tipoRestricao);
        $this->assertSame('2024-03-15', $t->dataRestricao->toDateString());
    }

    public function test_restricao_start_no_earlier_than(): void
    {
        $t = $this->porUid($this->plano(), '34');

        $this->assertSame(TipoRestricaoCronograma::NaoComecarAntesDe, $t->tipoRestricao);
        $this->assertSame('2024-03-01', $t->dataRestricao->toDateString());
    }

    public function test_restricao_start_no_later_than(): void
    {
        $t = $this->porUid($this->plano(), '35');

        $this->assertSame(TipoRestricaoCronograma::NaoComecarDepoisDe, $t->tipoRestricao);
        $this->assertSame('2024-03-10', $t->dataRestricao->toDateString());
    }

    public function test_restricao_finish_no_earlier_than(): void
    {
        $t = $this->porUid($this->plano(), '36');

        $this->assertSame(TipoRestricaoCronograma::NaoTerminarAntesDe, $t->tipoRestricao);
        $this->assertSame('2024-03-01', $t->dataRestricao->toDateString());
    }

    public function test_restricao_finish_no_later_than(): void
    {
        $t = $this->porUid($this->plano(), '37');

        $this->assertSame(TipoRestricaoCronograma::NaoTerminarDepoisDe, $t->tipoRestricao);
        $this->assertSame('2024-03-20', $t->dataRestricao->toDateString());
    }

    public function test_restricao_ausente_vira_null(): void
    {
        $t = $this->porUid($this->plano(), '38');

        $this->assertNull($t->tipoRestricao);
        $this->assertNull($t->tipoRestricaoCodigoOriginal);
        $this->assertNull($t->dataRestricao);
    }

    // -------------------------------------------------------------------
    // Active
    // -------------------------------------------------------------------

    public function test_active_explicito_1_e_ativa(): void
    {
        $t = $this->porUid($this->plano(), '40');

        $this->assertTrue($t->ativa);
    }

    public function test_active_explicito_0_e_inativa(): void
    {
        $t = $this->porUid($this->plano(), '41');

        $this->assertFalse($t->ativa);
    }

    public function test_active_ausente_e_ativa_por_padrao(): void
    {
        // Comportamento default documentado: ausência de <Active> NUNCA é
        // tratada como inativa.
        $t = $this->porUid($this->plano(), '42');

        $this->assertTrue($t->ativa);
    }

    // -------------------------------------------------------------------
    // Estrutura (resumo / marco / projeto)
    // -------------------------------------------------------------------

    public function test_marco_capturado_com_isMarco_true(): void
    {
        $t = $this->porUid($this->plano(), '50');

        $this->assertTrue($t->isMarco);
        $this->assertSame([], $t->predecessoras);
    }

    public function test_tarefa_resumo_vai_para_pacotes_nao_para_criar(): void
    {
        $plano = $this->plano();

        $uidsCriar = array_map(fn ($t) => $t->uid, $plano->criar);
        $uidsPacotes = array_map(fn ($t) => $t->uid, $plano->pacotes);

        $this->assertNotContains('1', $uidsCriar);
        $this->assertContains('1', $uidsPacotes);
        // Tarefa-raiz do projeto (uid=0) também é tratada como pacote.
        $this->assertContains('0', $uidsPacotes);
    }

    // -------------------------------------------------------------------
    // Regressão — leitura estrutural não interfere com os campos já existentes
    // -------------------------------------------------------------------

    public function test_campos_ja_existentes_continuam_intactos(): void
    {
        $t = $this->porUid($this->plano(), '11');

        $this->assertSame('Sucessora FS Lag Positivo', $t->nome);
        $this->assertFalse($t->isSummary);
        $this->assertSame('2024-01-15', $t->dataInicio->toDateString());
        $this->assertSame('2024-01-28', $t->dataTermino->toDateString());
    }
}
