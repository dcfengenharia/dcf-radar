<?php

namespace App\Http\Controllers;

use App\Models\GrdAceiteEntrega;
use App\Models\GrdDistribuicao;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Ciclo 18, Etapa 18.5.9 — ponto de acesso somente-leitura, SEM LOGIN,
 * pra VERIFICAR um aceite de entrega via QR Code. Mesmo padrão
 * arquitetural de `ClienteRelatorioPublicoController` (link público sem
 * login), com uma diferença deliberada: aquele usa signed/temporary URL
 * (expira em 30 dias, adequado pra "compartilhar com o cliente por um
 * tempo"); este usa um TOKEN ALEATÓRIO PERSISTIDO
 * (`grd_aceites_entrega.token`), porque um QR impresso e arquivado
 * fisicamente numa GRD precisa continuar verificável anos depois — uma
 * signed URL ficaria permanentemente inválida se `APP_KEY` rotacionar.
 *
 * Resolve o tenant dono do aceite com uma consulta mínima fora do
 * Eloquent (visitante anônimo não tem `TenantContext` nenhum) e entra
 * nele via `TenantContext::actingAs()` — mesmo mecanismo do controller
 * irmão, nunca `withoutGlobalScope()` em código de request.
 *
 * **Superfície mínima, deliberada** (seção 12/14 do pedido): a view
 * recebe SÓ o `GrdAceiteEntrega` com os relacionamentos estritamente
 * necessários pra exibir obra/GRD/documento/destinatário/data/status —
 * nunca a lista de outros destinatários, custos, cronograma, IDs
 * internos, ou qualquer link de volta pro app. QR não é autorização:
 * nenhuma ação (download, editar, recolher) é oferecida aqui.
 */
class GrdVerificacaoPublicaController extends Controller
{
    public function show(string $token): View
    {
        $tenantId = DB::table('grd_aceites_entrega')->where('token', $token)->value('tenant_id');
        abort_unless($tenantId, 404);

        $tenant = Tenant::findOrFail($tenantId);

        [$aceite, $distribuicoes] = TenantContext::actingAs($tenant, function () use ($token) {
            $aceite = GrdAceiteEntrega::with(['grdDestinatario.grd.obra'])->where('token', $token)->firstOrFail();

            // Só os itens efetivamente distribuídos a ESTE destinatário — nunca
            // a lista completa de itens da GRD (que pode incluir itens
            // entregues a OUTROS destinatários da mesma GRD).
            $distribuicoes = GrdDistribuicao::where('grd_destinatario_id', $aceite->grd_destinatario_id)
                ->with('item')
                ->orderBy('created_at')
                ->get();

            return [$aceite, $distribuicoes];
        });

        return view('publico.grd-verificacao', ['aceite' => $aceite, 'distribuicoes' => $distribuicoes]);
    }
}
