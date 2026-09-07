<?php

namespace Tests\Feature;

use App\Actions\LicoesAprendidas\AvaliarReaplicacaoLicao;
use App\Actions\LicoesAprendidas\RegistrarReaplicacaoLicao;
use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\Papel;
use App\Enums\ResultadoAvaliacaoReaplicacao;
use App\Enums\StatusLicaoAprendida;
use App\Enums\TipoLicaoAprendida;
use App\Exceptions\AvaliacaoReaplicacaoImutavelException;
use App\Models\LicaoAprendida;
use App\Models\LicaoAprendidaReaplicacao;
use App\Models\LicaoAprendidaReaplicacaoAvaliacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 23, Etapa 23.5.B (Decisão 26) — cobertura de
 * `AvaliarReaplicacaoLicao`, do enum `ResultadoAvaliacaoReaplicacao` e
 * de `LicaoAprendidaReaplicacao::resultadoAtual()`/`ultimaAvaliacao()`.
 */
class AvaliacaoReaplicacaoLicaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obraOrigem;
    private Work $obraDestino;
    private User $usuario;
    private LicaoAprendidaReaplicacao $reaplicacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obraOrigem = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraDestino = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraOrigem, $this->usuario, Papel::Admin->value);
        $this->vincularObra($this->obraDestino, $this->usuario, Papel::Admin->value);
        $this->actingAs($this->usuario);

        $licao = LicaoAprendida::create([
            'obra_origem_id' => $this->obraOrigem->id,
            'titulo' => 'Lição de teste',
            'situacao_observada' => 'Situação observada.',
            'recomendacao_futura' => 'Recomendação futura.',
            'tipo' => TipoLicaoAprendida::Problema->value,
            'criticidade' => CriticidadeLicao::Media->value,
            'area_funcional' => AreaFuncionalLicao::Campo->value,
            'status' => StatusLicaoAprendida::Publicada->value,
            'publicado_em' => now(),
        ]);

        $this->reaplicacao = app(RegistrarReaplicacaoLicao::class)->execute($licao, $this->obraDestino, $this->usuario);
    }

    private function avaliar(ResultadoAvaliacaoReaplicacao $resultado, ?string $observacao = null): LicaoAprendidaReaplicacaoAvaliacao
    {
        return app(AvaliarReaplicacaoLicao::class)->execute($this->reaplicacao, $resultado, $this->usuario, $observacao);
    }

    // =========================================================================
    // ZERO AVALIAÇÕES = AGUARDANDO
    // =========================================================================

    public function test_zero_avaliacoes_significa_aguardando_avaliacao(): void
    {
        $this->assertNull($this->reaplicacao->resultadoAtual());
        $this->assertNull($this->reaplicacao->ultimaAvaliacao());
        $this->assertCount(0, $this->reaplicacao->avaliacoes);
    }

    public function test_nenhum_valor_aguardando_e_persistido_no_enum(): void
    {
        $valores = array_column(ResultadoAvaliacaoReaplicacao::cases(), 'value');
        $this->assertNotContains('aguardando_avaliacao', $valores);
        $this->assertNotContains('aguardando', $valores);
        $this->assertCount(4, $valores);
    }

    // =========================================================================
    // OS 4 RESULTADOS
    // =========================================================================

    public function test_avaliacao_positiva(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo, 'Funcionou muito bem.');

        $this->assertSame(ResultadoAvaliacaoReaplicacao::Positivo, $avaliacao->resultado);
        $this->assertSame('Funcionou muito bem.', $avaliacao->observacao);
        $this->assertSame($this->usuario->id, $avaliacao->avaliado_por_id);
        $this->assertNotNull($avaliacao->avaliado_em);
    }

    public function test_avaliacao_parcial(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::Parcial);
        $this->assertSame(ResultadoAvaliacaoReaplicacao::Parcial, $avaliacao->resultado);
    }

    public function test_avaliacao_negativa(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::Negativo);
        $this->assertSame(ResultadoAvaliacaoReaplicacao::Negativo, $avaliacao->resultado);
    }

    public function test_avaliacao_nao_aplicavel(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::NaoAplicavel);
        $this->assertSame(ResultadoAvaliacaoReaplicacao::NaoAplicavel, $avaliacao->resultado);
    }

    // =========================================================================
    // AUTORIZAÇÃO — `editar` obrigatório (Seção 16)
    // =========================================================================

    public function test_policy_avaliar_exige_editar_na_obra_destino(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $encarregado, Papel::Encarregado->value);
        $this->assertFalse($encarregado->can('avaliar', $this->reaplicacao));

        $engenheiro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $engenheiro, Papel::Engenheiro->value);
        $this->assertTrue($engenheiro->can('avaliar', $this->reaplicacao));
    }

    public function test_policy_avaliar_e_pela_obra_de_destino_da_reaplicacao_nunca_a_de_origem(): void
    {
        // Usuário SÓ tem `editar` na obra de ORIGEM da lição — nunca deveria bastar.
        $usuario = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraOrigem, $usuario, Papel::Engenheiro->value);

        $this->assertFalse($usuario->can('avaliar', $this->reaplicacao));
    }

    // =========================================================================
    // Ciclo 23, Etapa 23.5.B.CORREÇÃO (Seção 1/2) — AUTORIZAÇÃO NO WRITE
    // PATH: `AvaliarReaplicacaoLicao` chamada DIRETAMENTE, sem passar por
    // nenhum componente Livewire/trait.
    // =========================================================================

    public function test_action_rejeita_usuario_sem_editar_na_obra_destino_mesmo_em_chamada_direta(): void
    {
        $semEditar = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $semEditar, Papel::Encarregado->value);

        $this->expectException(\App\Exceptions\AvaliacaoReaplicacaoNaoAutorizadaException::class);
        app(AvaliarReaplicacaoLicao::class)->execute($this->reaplicacao, ResultadoAvaliacaoReaplicacao::Positivo, $semEditar);
    }

    public function test_action_aceita_usuario_com_editar_na_obra_destino_em_chamada_direta(): void
    {
        $comEditar = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraDestino, $comEditar, Papel::Engenheiro->value);

        $avaliacao = app(AvaliarReaplicacaoLicao::class)->execute($this->reaplicacao, ResultadoAvaliacaoReaplicacao::Positivo, $comEditar);

        $this->assertNotNull($avaliacao->id);
        $this->assertSame($comEditar->id, $avaliacao->avaliado_por_id);
    }

    public function test_action_rejeita_permissao_apenas_na_obra_de_origem(): void
    {
        $soNaOrigem = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraOrigem, $soNaOrigem, Papel::Engenheiro->value);

        $this->expectException(\App\Exceptions\AvaliacaoReaplicacaoNaoAutorizadaException::class);
        app(AvaliarReaplicacaoLicao::class)->execute($this->reaplicacao, ResultadoAvaliacaoReaplicacao::Positivo, $soNaOrigem);
    }

    public function test_action_rejeita_usuario_de_outro_tenant(): void
    {
        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $usuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $this->vincularObra($obra, $usuario, Papel::Admin->value);

            return $usuario;
        });

        $this->expectException(\App\Exceptions\AvaliacaoReaplicacaoNaoAutorizadaException::class);
        app(AvaliarReaplicacaoLicao::class)->execute($this->reaplicacao, ResultadoAvaliacaoReaplicacao::Positivo, $usuarioOutroTenant);

        $this->assertSame(0, $this->reaplicacao->fresh()->avaliacoes->count());
    }

    // =========================================================================
    // APPEND-ONLY (Seção 10/13)
    // =========================================================================

    public function test_avaliacao_e_append_only_segunda_avaliacao_nunca_apaga_a_primeira(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $primeira = $this->avaliar(ResultadoAvaliacaoReaplicacao::Parcial, 'Resultado parcial em 20/09.');

        Carbon::setTestNow('2026-10-15 10:00:00');
        $segunda = $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo, 'Melhorou, positivo em 15/10.');
        Carbon::setTestNow();

        $this->assertCount(2, $this->reaplicacao->fresh()->avaliacoes);
        $this->assertNotNull($primeira->fresh());
        $this->assertSame(ResultadoAvaliacaoReaplicacao::Parcial, $primeira->fresh()->resultado);
        $this->assertSame('Resultado parcial em 20/09.', $primeira->fresh()->observacao);
    }

    public function test_segunda_avaliacao_legitima_e_sempre_permitida_nunca_deduplicada(): void
    {
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo);
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo);
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Negativo);

        $this->assertCount(3, $this->reaplicacao->fresh()->avaliacoes);
    }

    public function test_avaliacao_nao_pode_ser_editada(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::Parcial);

        $this->expectException(AvaliacaoReaplicacaoImutavelException::class);
        $avaliacao->update(['resultado' => ResultadoAvaliacaoReaplicacao::Positivo->value]);
    }

    public function test_avaliacao_nao_pode_ser_excluida(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::Parcial);

        $this->expectException(AvaliacaoReaplicacaoImutavelException::class);
        $avaliacao->delete();
    }

    public function test_avaliacao_forcedelete_tambem_bloqueado(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::Parcial);

        $this->expectException(AvaliacaoReaplicacaoImutavelException::class);
        $avaliacao->forceDelete();
    }

    // =========================================================================
    // RESULTADO ATUAL / DESEMPATE DETERMINÍSTICO (Seção 14)
    // =========================================================================

    public function test_resultado_atual_e_a_avaliacao_mais_recente(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Parcial);

        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo);
        Carbon::setTestNow();

        $this->assertSame(ResultadoAvaliacaoReaplicacao::Positivo, $this->reaplicacao->fresh()->resultadoAtual());
    }

    public function test_resultado_atual_funciona_com_avaliacoes_eager_loaded_em_lote(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Negativo);
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo);
        Carbon::setTestNow();

        $reaplicacaoComEagerLoad = LicaoAprendidaReaplicacao::query()
            ->with(['avaliacoes' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id')])
            ->find($this->reaplicacao->id);

        $this->assertSame(ResultadoAvaliacaoReaplicacao::Positivo, $reaplicacaoComEagerLoad->resultadoAtual());
    }

    /**
     * Desempate determinístico (Seção 14) — critério é ordem de REGISTRO
     * (`created_at`/`id`), nunca `avaliado_em`. Aqui forçamos
     * `avaliado_em` da 1ª avaliação a ser POSTERIOR à da 2ª (simulando
     * uma divergência hipotética entre os dois campos) — o resultado
     * atual continua sendo o da avaliação criada por ÚLTIMO (ordem de
     * registro), nunca o de `avaliado_em` mais recente.
     */
    public function test_desempate_e_por_ordem_de_registro_nunca_por_avaliado_em(): void
    {
        $primeira = $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo);
        $primeira->forceFill(['avaliado_em' => now()->addDays(10)])->saveQuietly();

        $segunda = $this->avaliar(ResultadoAvaliacaoReaplicacao::Negativo);

        $this->assertSame(ResultadoAvaliacaoReaplicacao::Negativo, $this->reaplicacao->fresh()->resultadoAtual());
        $this->assertSame($segunda->id, $this->reaplicacao->fresh()->ultimaAvaliacao()->id);
    }

    // =========================================================================
    // TENANT/OBRA ISOLATION (Seção 17)
    // =========================================================================

    public function test_avaliacao_herda_o_tenant_da_reaplicacao(): void
    {
        $avaliacao = $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo);

        $this->assertSame($this->tenant->id, $avaliacao->tenant_id);
    }

    public function test_avaliacao_de_outro_tenant_nunca_aparece_na_listagem(): void
    {
        $this->avaliar(ResultadoAvaliacaoReaplicacao::Positivo);

        $outroTenant = Tenant::factory()->create();
        \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obraA = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $obraB = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $usuario = User::factory()->create(['tenant_id' => $outroTenant->id]);
            $licao = LicaoAprendida::create([
                'obra_origem_id' => $obraA->id,
                'titulo' => 'Lição outro tenant',
                'situacao_observada' => 'Situação.',
                'recomendacao_futura' => 'Recomendação.',
                'tipo' => TipoLicaoAprendida::Problema->value,
                'criticidade' => CriticidadeLicao::Media->value,
                'area_funcional' => AreaFuncionalLicao::Campo->value,
                'status' => StatusLicaoAprendida::Publicada->value,
                'publicado_em' => now(),
            ]);
            $this->vincularObra($obraB, $usuario, Papel::Admin->value);
            $reaplicacaoOutroTenant = app(RegistrarReaplicacaoLicao::class)->execute($licao, $obraB, $usuario);
            app(AvaliarReaplicacaoLicao::class)->execute($reaplicacaoOutroTenant, ResultadoAvaliacaoReaplicacao::Negativo, $usuario);
        });

        $this->assertSame(1, LicaoAprendidaReaplicacaoAvaliacao::count());
    }
}
