<?php

namespace App\Http\Middleware;

use App\Models\Work;
use App\Support\ObraContext;
use App\Support\Onboarding\OnboardingChecklist;
use Closure;
use Illuminate\Http\Request;

class RequireObraContext
{
    public function handle(Request $request, Closure $next)
    {
        $obraId = ObraContext::currentId();

        if (! $obraId) {
            return redirect()->route('gestao.minhas-obras')
                ->with('flash.banner', 'Selecione uma obra para acessar o Radar.')
                ->with('flash.bannerStyle', 'warning');
        }

        $obra = Work::find($obraId);

        if (! $obra || ! $request->user()->temAcessoAObra($obra)) {
            ObraContext::clear();

            return redirect()->route('gestao.minhas-obras')
                ->with('flash.banner', 'Você não tem acesso a essa obra ou ela não existe mais.')
                ->with('flash.bannerStyle', 'danger');
        }

        // Disponibiliza a obra atual para todas as views
        view()->share('obraAtual', $obra);

        $pendenciasObrigatorias = OnboardingChecklist::pendentesObrigatorios(OnboardingChecklist::passosObra($obra));

        if ($pendenciasObrigatorias !== [] && ! $request->routeIs('radar.cronograma')) {
            return redirect()->route('app.onboarding')
                ->with('flash.banner', 'Cadastre ao menos uma atividade nesta obra (importando o cronograma ou manualmente) para acessar o Radar.')
                ->with('flash.bannerStyle', 'warning');
        }

        return $next($request);
    }
}
