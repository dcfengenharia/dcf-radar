<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Ponto de acesso somente-leitura, SEM LOGIN, pra um Report específico —
 * gerado via link assinado (Illuminate\Support\Facades\URL::temporarySignedRoute,
 * ver ⚡relatorio-detalhe.blade.php::gerarLinkCliente()). A segurança
 * daqui vem inteiramente da assinatura da URL (middleware `signed`
 * já valida e expira sozinho antes de chegar neste método) — não do
 * isolamento de tenant normal, que não existe pra um visitante anônimo.
 *
 * Por isso NÃO usa route-model-binding implícito (`Report $report`):
 * o global scope de BelongsToTenant filtraria pelo TenantContext atual,
 * que é sempre null aqui (ninguém logado) e nunca acharia o report. Em
 * vez de usar withoutGlobalScope() em código de request (proibido pelo
 * CLAUDE.md), resolve o tenant dono do report com uma consulta mínima
 * fora do Eloquent e entra nele via TenantContext::actingAs() — o mesmo
 * mecanismo já usado por comandos/jobs de plataforma.
 */
class ClienteRelatorioPublicoController extends Controller
{
    public function show(string $report): View
    {
        $tenantId = DB::table('reports')->where('id', $report)->value('tenant_id');
        abort_unless($tenantId, 404);

        $tenant = Tenant::findOrFail($tenantId);

        $relatorio = TenantContext::actingAs($tenant, function () use ($report) {
            return Report::with([
                'obra',
                'curvas.datapoints',
                'curvas.desvios',
                'curvas.pontosAtencao',
                'fotos.enviadoPor:id,first_name,last_name',
            ])->findOrFail($report);
        });

        abort_unless($relatorio->estaEmitido(), 404);

        return view('cliente.relatorio-publico', ['report' => $relatorio]);
    }
}
