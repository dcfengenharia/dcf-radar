<?php

namespace Tests\Feature;

use App\Models\PerfilPermissao;
use App\Models\Perfil;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FASE 2F.CORREÇÃO — Seção 2/17: completa a matriz E2E de evidências,
 * preenchendo lacunas concretas identificadas na correção (não uma nova
 * auditoria) — o teste com nome equivalente em
 * `Fase2BCorrecaoRbacTest::test_caso_b_legado_planejamento_mais_pivot_planejamento_e_suprimentos_e_uniao_correta`
 * nunca chegou a conceder `suprimentos.mapa` ao Perfil "Suprimentos
 * Consulta" (concede `cadastros.categorias_restricao|editar` por engano
 * de fixture) — não prova a propriedade P10 do pedido. Este arquivo
 * prova a propriedade de verdade, sem alterar o teste antigo (que
 * continua passando, só não é evidência desta célula específica).
 */
class Fase2FCorrecaoE2ETest extends TestCase
{
    use RefreshDatabase;

    /**
     * P10 — multiperfil: Planejamento (OPERAÇÃO real, `obras.linhas_base
     * |editar`) + Suprimentos (CONSULTA, `suprimentos.mapa|ver` apenas).
     * União positiva nos dois domínios (Seção 4.F) — Consulta em
     * Suprimentos nunca escala pra Operação (criar/editar/excluir/
     * comentar continuam negados), e Suprimentos Consulta nunca contamina
     * a autoridade de Planejamento em outro slug.
     */
    public function test_p10_multiperfil_planejamento_operacao_mais_suprimentos_consulta_nao_escala_para_operacao(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $planejamentoOperacao = Perfil::create(['tenant_id' => $tenant->id, 'nome' => 'Planejamento Operação']);
        PerfilPermissao::create([
            'tenant_id' => $tenant->id,
            'perfil_id' => $planejamentoOperacao->id,
            'funcionalidade' => 'obras.linhas_base',
            'acao' => 'editar',
        ]);

        $suprimentosConsulta = Perfil::create(['tenant_id' => $tenant->id, 'nome' => 'Suprimentos Consulta']);
        PerfilPermissao::create([
            'tenant_id' => $tenant->id,
            'perfil_id' => $suprimentosConsulta->id,
            'funcionalidade' => 'suprimentos.mapa',
            'acao' => 'ver',
        ]);

        $obra->users()->attach($user->id, ['perfil_id' => $planejamentoOperacao->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obra, $user->id, $planejamentoOperacao->id);
        AtribuicaoPerfilObra::adicionarPerfil($obra, $user->id, $suprimentosConsulta->id);

        $user = $user->fresh();

        // União positiva: opera Planejamento.
        $this->assertTrue($user->temPermissaoNaObra($obra, 'obras.linhas_base', 'editar'));

        // Consulta Suprimentos.
        $this->assertTrue($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'ver'));

        // Consulta NUNCA vira Operação — nenhuma ação de escrita/colaboração
        // em Suprimentos é concedida por consequência da união.
        $this->assertFalse($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'criar'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'editar'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'excluir'));
        $this->assertFalse($user->temPermissaoNaObra($obra, 'suprimentos.mapa', 'comentar'));

        // Suprimentos Consulta nunca contamina outro slug de Planejamento.
        $this->assertFalse($user->temPermissaoNaObra($obra, 'obras.importar_cronograma', 'editar'));
    }
}
