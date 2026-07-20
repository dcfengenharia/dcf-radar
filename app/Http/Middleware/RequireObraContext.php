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

        // radar.cronograma é sempre alcançável — é o ponto de partida
        // (sem atividade nenhuma, nenhuma outra tela do Radar faz sentido).
        // Qualquer outra rota só fica liberada se TODA pendência obrigatória
        // restante for resolvida justamente nesta página — senão o usuário
        // ficaria trancado fora da tela que precisa visitar pra concluir o
        // passo (ex.: faltar "1ª Restrição" não pode bloquear o próprio
        // Quadro de Restrições). Mas se ainda falta um passo anterior (ex.:
        // nenhuma atividade cadastrada), continua bloqueado mesmo que a
        // rota atual resolvesse um passo diferente.
        $existePendenciaQueEstaRotaNaoResolve = collect($pendenciasObrigatorias)
            ->contains(fn ($passo) => ! $request->routeIs($passo->rotaAcao));

        if ($pendenciasObrigatorias !== [] && ! $request->routeIs('radar.cronograma') && $existePendenciaQueEstaRotaNaoResolve) {
            return redirect()->route('app.onboarding')
                ->with('flash.banner', 'Cadastre ao menos uma atividade nesta obra (importando o cronograma ou manualmente) para acessar o Radar.')
                ->with('flash.bannerStyle', 'warning');
        }

        return $next($request);
    }
}
