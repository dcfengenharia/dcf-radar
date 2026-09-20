<?php

namespace Tests\Feature;

use App\Enums\ResultadoRecolhimento;
use App\Enums\ResultadoReconciliacaoPlanoAcao;
use App\Enums\StatusPlanoAcao;
use App\Exceptions\GrdRecolhimentoInvalidoException;
use App\Exceptions\PlanoAcaoReconciliacaoImutavelException;
use App\Models\CronogramaImportacao;
use App\Models\Destinatario;
use App\Models\DocumentoEngenharia;
use App\Models\Grd;
use App\Models\GrdDestinatario;
use App\Models\GrdDistribuicao;
use App\Models\GrdItem;
use App\Models\GrdRecolhimento;
use App\Models\PlanoAcao;
use App\Models\PlanoAcaoReconciliacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A1, DB-03 — prova que GrdRecolhimento e
 * PlanoAcaoReconciliacao (ambos já documentados como append-only, mas sem
 * nenhuma barreira real antes desta correção) agora bloqueiam update()/
 * delete() via Eloquent — E documenta explicitamente, com teste real, o
 * limite dessa proteção: ela é SÓ a nível de APLICAÇÃO (Eloquent Observer),
 * nunca a nível de BANCO — DB::table()/raw SQL continuam conseguindo
 * alterar a linha, porque nenhum trigger/constraint foi criado nesta
 * correção (fora do escopo autorizado).
 */
class AppendOnlyModelsGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    private function criarRecolhimento(): GrdRecolhimento
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id,
            'codigo' => 'DOC-A1-DB03', 'descricao' => 'x',
        ]);
        $revisao = $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id, 'revisao' => 'R0', 'descricao' => 'x',
        ]);
        $destinatario = Destinatario::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Fulano',
        ]);
        $grd = Grd::create([
            'tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'status' => 'rascunho',
        ]);
        $item = GrdItem::create([
            'tenant_id' => $this->tenant->id, 'grd_id' => $grd->id, 'documento_engenharia_revisao_id' => $revisao->id,
        ]);
        $grdDestinatario = GrdDestinatario::create([
            'tenant_id' => $this->tenant->id, 'grd_id' => $grd->id, 'destinatario_id' => $destinatario->id,
        ]);
        $distribuicao = GrdDistribuicao::create([
            'tenant_id' => $this->tenant->id, 'grd_item_id' => $item->id,
            'grd_destinatario_id' => $grdDestinatario->id, 'quantidade' => 5,
        ]);

        return GrdRecolhimento::create([
            'tenant_id' => $this->tenant->id, 'grd_distribuicao_id' => $distribuicao->id,
            'resultado' => ResultadoRecolhimento::Recolhido, 'quantidade' => 1, 'ocorrido_em' => now(),
        ]);
    }

    private function criarReconciliacao(): PlanoAcaoReconciliacao
    {
        $importacao = CronogramaImportacao::create(['obra_id' => $this->obra->id, 'importado_em' => now()]);
        $acao = PlanoAcao::create([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $importacao->id,
            'regra_id' => 'PROG-001',
            'titulo' => 'teste', 'recomendacao' => 'teste',
            'status' => StatusPlanoAcao::Aberta, 'uids_referencia' => ['2'],
        ]);

        return PlanoAcaoReconciliacao::create([
            'plano_acao_id' => $acao->id, 'cronograma_importacao_id' => $importacao->id,
            'resultado' => ResultadoReconciliacaoPlanoAcao::Persistente,
            'status_anterior' => StatusPlanoAcao::Aberta, 'status_novo' => StatusPlanoAcao::Aberta,
            'uids_anteriores' => ['2'], 'uids_atuais' => ['2'],
            'quantidade_anterior' => 1, 'quantidade_atual' => 1,
        ]);
    }

    // ---- GrdRecolhimento ----

    public function test_grd_recolhimento_update_via_eloquent_e_bloqueado(): void
    {
        $recolhimento = $this->criarRecolhimento();

        $this->expectException(GrdRecolhimentoInvalidoException::class);

        $recolhimento->update(['quantidade' => 999]);
    }

    public function test_grd_recolhimento_delete_via_eloquent_e_bloqueado(): void
    {
        $recolhimento = $this->criarRecolhimento();

        $this->expectException(GrdRecolhimentoInvalidoException::class);

        $recolhimento->delete();
    }

    public function test_grd_recolhimento_nunca_e_alterado_apos_tentativa_bloqueada(): void
    {
        $recolhimento = $this->criarRecolhimento();

        try {
            $recolhimento->update(['quantidade' => 999]);
        } catch (GrdRecolhimentoInvalidoException) {
            // esperado
        }

        $this->assertSame(1, $recolhimento->fresh()->quantidade);
    }

    /**
     * Limite documentado da proteção — SÓ a nível de aplicação
     * (Eloquent Observer), NUNCA a nível de banco: um `DB::table()`/raw SQL
     * ainda consegue alterar a linha, porque nenhum trigger/constraint foi
     * criado (fora do escopo desta correção).
     */
    public function test_grd_recolhimento_via_query_builder_cru_nao_e_bloqueado_protecao_e_so_de_aplicacao(): void
    {
        $recolhimento = $this->criarRecolhimento();

        DB::table('grd_recolhimentos')->where('id', $recolhimento->id)->update(['quantidade' => 999]);

        $this->assertSame(999, $recolhimento->fresh()->quantidade);
    }

    // ---- PlanoAcaoReconciliacao ----

    public function test_plano_acao_reconciliacao_update_via_eloquent_e_bloqueado(): void
    {
        $reconciliacao = $this->criarReconciliacao();

        $this->expectException(PlanoAcaoReconciliacaoImutavelException::class);

        $reconciliacao->update(['quantidade_atual' => 999]);
    }

    public function test_plano_acao_reconciliacao_delete_via_eloquent_e_bloqueado(): void
    {
        $reconciliacao = $this->criarReconciliacao();

        $this->expectException(PlanoAcaoReconciliacaoImutavelException::class);

        $reconciliacao->delete();
    }

    public function test_plano_acao_reconciliacao_nunca_e_alterada_apos_tentativa_bloqueada(): void
    {
        $reconciliacao = $this->criarReconciliacao();

        try {
            $reconciliacao->update(['quantidade_atual' => 999]);
        } catch (PlanoAcaoReconciliacaoImutavelException) {
            // esperado
        }

        $this->assertSame(1, $reconciliacao->fresh()->quantidade_atual);
    }

    /**
     * Limite documentado da proteção — SÓ a nível de aplicação, nunca de
     * banco (mesma ressalva de GrdRecolhimento acima).
     */
    public function test_plano_acao_reconciliacao_via_query_builder_cru_nao_e_bloqueado_protecao_e_so_de_aplicacao(): void
    {
        $reconciliacao = $this->criarReconciliacao();

        DB::table('plano_acao_reconciliacoes')->where('id', $reconciliacao->id)->update(['quantidade_atual' => 999]);

        $this->assertSame(999, $reconciliacao->fresh()->quantidade_atual);
    }
}
