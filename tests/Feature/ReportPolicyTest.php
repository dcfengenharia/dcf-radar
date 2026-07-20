<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Enums\StatusReport;
use App\Models\CronogramaImportacao;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->importacao = CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function usuarioComPapel(Papel $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $user, $papel->value);

        return $user;
    }

    private function criarReport(StatusReport $status): Report
    {
        return Report::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'cronograma_importacao_id' => $this->importacao->id,
            'status' => $status->value,
        ]);
    }

    public function test_gerente_planejamento_ve_report_rascunho(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $this->assertTrue($gerente->can('view', $report));
    }

    public function test_encarregado_nao_ve_report_rascunho(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $encarregado = $this->usuarioComPapel(Papel::Encarregado);
        $clienteLeitura = $this->usuarioComPapel(Papel::ClienteLeitura);

        $this->assertFalse($encarregado->can('view', $report));
        $this->assertFalse($clienteLeitura->can('view', $report));
    }

    public function test_todos_com_acesso_veem_report_emitido(): void
    {
        $report = $this->criarReport(StatusReport::Emitido);

        foreach ([Papel::Encarregado, Papel::Engenheiro, Papel::ClienteLeitura, Papel::GerentePlanejamento] as $papel) {
            $user = $this->usuarioComPapel($papel);
            $this->assertTrue($user->can('view', $report), "papel {$papel->value} deveria ver o report emitido");
        }
    }

    public function test_apenas_planejamento_pode_emitir(): void
    {
        $report = $this->criarReport(StatusReport::Rascunho);
        $engenheiro = $this->usuarioComPapel(Papel::Engenheiro);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $this->assertFalse($engenheiro->can('emitir', $report));
        $this->assertTrue($gerente->can('emitir', $report));
    }

    public function test_nao_e_possivel_emitir_um_report_ja_emitido(): void
    {
        $report = $this->criarReport(StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $this->assertFalse($gerente->can('emitir', $report));
    }

    public function test_comentario_so_apos_emissao(): void
    {
        $rascunho = $this->criarReport(StatusReport::Rascunho);
        $emitido = $this->criarReport(StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $this->assertFalse($gerente->can('comentar', $rascunho));
        $this->assertTrue($gerente->can('comentar', $emitido));
    }

    public function test_edicao_so_e_permitida_em_rascunho(): void
    {
        $rascunho = $this->criarReport(StatusReport::Rascunho);
        $emitido = $this->criarReport(StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $this->assertTrue($gerente->can('update', $rascunho));
        $this->assertFalse($gerente->can('update', $emitido));
    }

    public function test_scope_visivel_para_esconde_rascunho_de_quem_nao_e_planejamento(): void
    {
        $rascunho = $this->criarReport(StatusReport::Rascunho);
        $emitido = $this->criarReport(StatusReport::Emitido);
        $encarregado = $this->usuarioComPapel(Papel::Encarregado);

        $visiveis = Report::where('obra_id', $this->obra->id)
            ->visivelPara($encarregado, $this->obra->id)
            ->pluck('id');

        $this->assertFalse($visiveis->contains($rascunho->id));
        $this->assertTrue($visiveis->contains($emitido->id));
    }

    public function test_scope_visivel_para_mostra_tudo_para_planejamento(): void
    {
        $rascunho = $this->criarReport(StatusReport::Rascunho);
        $emitido = $this->criarReport(StatusReport::Emitido);
        $gerente = $this->usuarioComPapel(Papel::GerentePlanejamento);

        $visiveis = Report::where('obra_id', $this->obra->id)
            ->visivelPara($gerente, $this->obra->id)
            ->pluck('id');

        $this->assertTrue($visiveis->contains($rascunho->id));
        $this->assertTrue($visiveis->contains($emitido->id));
    }
}
