<?php

namespace Tests\Feature;

use App\Enums\SeveridadeSituacao;
use App\Enums\TipoSituacaoGerencial;
use App\Support\Gestao\PoliticaEntregaSituacao;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.4 — testes puros (sem banco) da política de
 * entrega, Seção 6/25 do pedido: "criar conceito explícito, tipado e
 * testável".
 */
class PoliticaEntregaSituacaoTest extends TestCase
{
    public function test_todos_os_tipos_tem_politica_definida(): void
    {
        foreach (TipoSituacaoGerencial::cases() as $tipo) {
            $politica = PoliticaEntregaSituacao::para($tipo);
            $this->assertInstanceOf(PoliticaEntregaSituacao::class, $politica);
        }
    }

    public function test_desvio_aplicacao_nunca_elegivel_a_imediato_mesmo_critica(): void
    {
        // Seção 8/21 — nunca soar acusatório sobre Planejado×Real.
        $politica = PoliticaEntregaSituacao::para(TipoSituacaoGerencial::DesvioAplicacao);
        $this->assertFalse($politica->elegivelImediato);
        $this->assertTrue($politica->elegivelParaEmailAgora(SeveridadeSituacao::Critica, false, null, Carbon::now()) === false);
        $this->assertTrue($politica->elegivelDigest);
    }

    public function test_pedido_atrasado_nem_imediato_nem_digest(): void
    {
        $politica = PoliticaEntregaSituacao::para(TipoSituacaoGerencial::PedidoAtrasado);
        $this->assertFalse($politica->elegivelImediato);
        $this->assertFalse($politica->elegivelDigest);
    }

    public function test_material_critico_severidade_abaixo_do_minimo_nunca_email(): void
    {
        $politica = PoliticaEntregaSituacao::para(TipoSituacaoGerencial::MaterialCritico);
        $this->assertFalse($politica->elegivelParaEmailAgora(SeveridadeSituacao::Atencao, false, null, Carbon::now()));
        $this->assertTrue($politica->elegivelParaEmailAgora(SeveridadeSituacao::Alta, false, null, Carbon::now()));
        $this->assertTrue($politica->elegivelParaEmailAgora(SeveridadeSituacao::Critica, false, null, Carbon::now()));
    }

    public function test_cooldown_bloqueia_reenvio_dentro_da_janela(): void
    {
        $politica = PoliticaEntregaSituacao::para(TipoSituacaoGerencial::MaterialCritico);
        $agora = Carbon::now();
        $ultimoEmail = $agora->copy()->subMinutes(30); // dentro do cooldown de 240min

        $this->assertFalse($politica->elegivelParaEmailAgora(SeveridadeSituacao::Alta, false, $ultimoEmail, $agora));
    }

    public function test_cooldown_expira_apos_a_janela(): void
    {
        $politica = PoliticaEntregaSituacao::para(TipoSituacaoGerencial::MaterialCritico);
        $agora = Carbon::now();
        $ultimoEmail = $agora->copy()->subMinutes(300); // além do cooldown de 240min

        $this->assertTrue($politica->elegivelParaEmailAgora(SeveridadeSituacao::Alta, false, $ultimoEmail, $agora));
    }

    public function test_escalada_ultrapassa_cooldown(): void
    {
        $politica = PoliticaEntregaSituacao::para(TipoSituacaoGerencial::MaterialCritico);
        $agora = Carbon::now();
        $ultimoEmail = $agora->copy()->subMinutes(10); // bem dentro do cooldown

        $this->assertFalse($politica->elegivelParaEmailAgora(SeveridadeSituacao::Alta, false, $ultimoEmail, $agora));
        $this->assertTrue($politica->elegivelParaEmailAgora(SeveridadeSituacao::Alta, true, $ultimoEmail, $agora));
    }

    public function test_tipos_sempre_informativos_nunca_imediato(): void
    {
        foreach ([
            TipoSituacaoGerencial::RecebimentoPendente,
            TipoSituacaoGerencial::MaterialSemDestinacao,
            TipoSituacaoGerencial::SaidaSemConciliacao,
            TipoSituacaoGerencial::IndustrializacaoPendente,
            TipoSituacaoGerencial::MaterialParado,
        ] as $tipo) {
            $politica = PoliticaEntregaSituacao::para($tipo);
            $this->assertFalse($politica->elegivelImediato, "{$tipo->value} não deveria ser imediato");
            $this->assertTrue($politica->elegivelDigest, "{$tipo->value} deveria ser elegível a digest");
        }
    }
}
