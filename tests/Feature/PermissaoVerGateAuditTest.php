<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Tenant;
use App\Support\CatalogoFuncionalidades;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 21, Etapa 21.6 — Seção 30 do pedido: auditoria adversarial
 * OBRIGATÓRIA do mecanismo de restrição de `ver` introduzido na 21.5
 * (`Perfil::seedPadrao()`, `REGRAS_ESCRITA[$slug]['ver']` como MÍNIMO),
 * ANTES de criar a nova permissão `gestao.suprimentos`. Prova que o
 * mecanismo:
 * (1) restringe corretamente o slug que declara um mínimo;
 * (2) nunca alterou silenciosamente `ver` de nenhum outro slug do
 *     catálogo (varredura EXAUSTIVA, não amostral);
 * (3) respeita isolamento de tenant.
 */
class PermissaoVerGateAuditTest extends TestCase
{
    use RefreshDatabase;

    private function temVer(Perfil $perfil, string $slug): bool
    {
        return PerfilPermissao::where('perfil_id', $perfil->id)
            ->where('funcionalidade', $slug)
            ->where('acao', 'ver')
            ->exists();
    }

    // =========================================================
    // (1) Slug com mínimo declarado — gestao.cockpit
    // =========================================================

    public function test_perfis_abaixo_do_minimo_nao_tem_ver_no_cockpit(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([Papel::Encarregado, Papel::Engenheiro, Papel::ClienteLeitura] as $papel) {
            $perfil = Perfil::porSlugPadrao($tenant, $papel->value);
            $this->assertFalse(
                $this->temVer($perfil, 'gestao.cockpit'),
                "{$papel->value} não deveria ter 'ver' em gestao.cockpit"
            );
        }
    }

    public function test_perfil_minimo_e_superiores_tem_ver_no_cockpit(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([Papel::GerentePlanejamento, Papel::Admin] as $papel) {
            $perfil = Perfil::porSlugPadrao($tenant, $papel->value);
            $this->assertTrue(
                $this->temVer($perfil, 'gestao.cockpit'),
                "{$papel->value} deveria ter 'ver' em gestao.cockpit"
            );
        }
    }

    // =========================================================
    // (1b) Ciclo 22, Etapa 22.2 — gestao.engenharia, mesmo mecanismo
    // =========================================================

    public function test_perfis_abaixo_do_minimo_nao_tem_ver_no_cockpit_engenharia(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([Papel::Encarregado, Papel::Engenheiro, Papel::ClienteLeitura] as $papel) {
            $perfil = Perfil::porSlugPadrao($tenant, $papel->value);
            $this->assertFalse(
                $this->temVer($perfil, 'gestao.engenharia'),
                "{$papel->value} não deveria ter 'ver' em gestao.engenharia"
            );
        }
    }

    public function test_perfil_minimo_e_superiores_tem_ver_no_cockpit_engenharia(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([Papel::GerentePlanejamento, Papel::Admin] as $papel) {
            $perfil = Perfil::porSlugPadrao($tenant, $papel->value);
            $this->assertTrue(
                $this->temVer($perfil, 'gestao.engenharia'),
                "{$papel->value} deveria ter 'ver' em gestao.engenharia"
            );
        }
    }

    // =========================================================
    // (2) VARREDURA EXAUSTIVA — nenhum outro slug do catálogo foi
    // silenciosamente alterado. Confirma que TODOS os 5 papéis
    // continuam com 'ver' em TODO slug que NUNCA declarou um mínimo
    // próprio — comportamento bit-a-bit idêntico ao pré-21.5.
    // =========================================================

    public function test_varredura_exaustiva_nenhum_outro_slug_perdeu_ver_para_nenhum_papel(): void
    {
        $tenant = Tenant::factory()->create();

        $slugsComMinimoProprio = ['gestao.cockpit', 'gestao.suprimentos', 'gestao.engenharia'];
        $slugsSemMinimo = array_values(array_diff(CatalogoFuncionalidades::slugs(), $slugsComMinimoProprio));

        $this->assertNotEmpty($slugsSemMinimo);

        foreach (Papel::cases() as $papel) {
            $perfil = Perfil::porSlugPadrao($tenant, $papel->value);
            $comVer = PerfilPermissao::where('perfil_id', $perfil->id)
                ->where('acao', 'ver')
                ->pluck('funcionalidade')
                ->all();

            foreach ($slugsSemMinimo as $slug) {
                $this->assertContains(
                    $slug,
                    $comVer,
                    "{$papel->value} perdeu 'ver' em {$slug} — regressão do mecanismo de gate introduzido na 21.5"
                );
            }
        }
    }

    public function test_contagem_total_de_permissoes_ver_bate_com_o_esperado(): void
    {
        $tenant = Tenant::factory()->create();

        $totalSlugs = count(CatalogoFuncionalidades::slugs());
        $slugsComMinimo = [
            'gestao.cockpit' => Papel::GerentePlanejamento->nivel(),
            'gestao.suprimentos' => Papel::GerentePlanejamento->nivel(),
            'gestao.engenharia' => Papel::GerentePlanejamento->nivel(),
        ];

        foreach (Papel::cases() as $papel) {
            $perfil = Perfil::porSlugPadrao($tenant, $papel->value);
            $totalVer = PerfilPermissao::where('perfil_id', $perfil->id)->where('acao', 'ver')->count();

            $esperado = $totalSlugs;
            foreach ($slugsComMinimo as $nivelMinimo) {
                if ($papel->nivel() < $nivelMinimo) {
                    $esperado--;
                }
            }

            $this->assertSame($esperado, $totalVer, "Contagem de 'ver' errada pra {$papel->value}");
        }
    }

    // =========================================================
    // (3) Isolamento de tenant
    // =========================================================

    public function test_isolamento_de_tenant_cada_tenant_seeda_suas_proprias_permissoes(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $gerenteA = Perfil::porSlugPadrao($tenantA, Papel::GerentePlanejamento->value);
        $gerenteB = Perfil::porSlugPadrao($tenantB, Papel::GerentePlanejamento->value);

        $this->assertTrue($this->temVer($gerenteA, 'gestao.cockpit'));
        $this->assertTrue($this->temVer($gerenteB, 'gestao.cockpit'));
        $this->assertNotSame($gerenteA->id, $gerenteB->id);

        // Nenhuma linha de PerfilPermissao de A referencia o perfil de B.
        $this->assertSame(0, PerfilPermissao::where('perfil_id', $gerenteB->id)->where('tenant_id', $tenantA->id)->count());
    }
}
