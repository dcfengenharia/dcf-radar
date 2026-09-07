<?php

namespace App\Http\Controllers;

use App\Actions\LicoesAprendidas\RemoverEvidenciaDaLicao;
use App\Models\LicaoAprendidaEvidencia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo 23, Etapa 23.2 — download protegido e remoção de evidência.
 * Mesmo padrão de `AtividadeAnexoController`: rota fora do grupo
 * `obra.context` (route-model-binding já resolve tenant via
 * BelongsToTenant; ID de outro tenant nunca resolve — 404, nunca vaza
 * existência).
 *
 * Autorização de download delega 100% pra `LicaoAprendidaPolicy::view()`
 * (Seção 24/25 do pedido) — a MESMA regra que já decide se o usuário
 * pode ver a lição em si: Publicada → qualquer perfil com
 * `gestao.licoes-aprendidas|ver` em QUALQUER obra do tenant (política de
 * conhecimento corporativo — nunca exige acesso à obra/entidade de
 * origem específica); Rascunho/EmValidacao → só quem tem `ver` na obra
 * de origem (mesmo escopo restrito da própria lição ainda não publicada,
 * nunca tratado como corporativo antes da hora — Seção 26).
 */
class LicaoAprendidaEvidenciaController extends Controller
{
    public function download(Request $request, LicaoAprendidaEvidencia $evidencia)
    {
        $this->authorize('view', $evidencia->licao);

        abort_unless(Storage::disk(LicaoAprendidaEvidencia::DISCO)->exists($evidencia->caminho_arquivo), 404);

        return Storage::disk(LicaoAprendidaEvidencia::DISCO)->download($evidencia->caminho_arquivo, $evidencia->nome_original);
    }

    public function destroy(LicaoAprendidaEvidencia $evidencia, RemoverEvidenciaDaLicao $action)
    {
        $this->authorize('update', $evidencia->licao);

        $action->execute($evidencia);

        return response()->noContent();
    }
}
