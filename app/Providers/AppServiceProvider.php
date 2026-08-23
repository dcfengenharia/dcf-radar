<?php

namespace App\Providers;

use App\Imports\Contracts\ImportadorCronograma;
use App\Imports\MsProjectImporter;
use App\Models\Atividade;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Grd;
use App\Models\GrdAceiteEntrega;
use App\Models\Restricao;
use App\Models\RevisaoLiberacao;
use App\Observers\AtividadeObserver;
use App\Observers\DocumentoEngenhariaRevisaoObserver;
use App\Observers\GrdAceiteEntregaObserver;
use App\Observers\GrdObserver;
use App\Observers\RestricaoObserver;
use App\Observers\RevisaoLiberacaoObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImportadorCronograma::class, MsProjectImporter::class);
    }

    public function boot(): void
    {
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(! app()->isProduction());

        Atividade::observe(AtividadeObserver::class);
        Restricao::observe(RestricaoObserver::class);
        Grd::observe(GrdObserver::class);
        GrdAceiteEntrega::observe(GrdAceiteEntregaObserver::class);
        DocumentoEngenhariaRevisao::observe(DocumentoEngenhariaRevisaoObserver::class);
        RevisaoLiberacao::observe(RevisaoLiberacaoObserver::class);

        // Marca a sessão pra <x-onboarding-popup /> mostrar o popup de boas-vindas
        // no próximo carregamento de página, se ainda houver cadastro obrigatório
        // pendente. Cobre login normal e Auth::login() pós-registro (Fortify e
        // RegisteredUserController disparam este mesmo evento).
        Event::listen(Login::class, function (): void {
            session(['mostrar_popup_onboarding' => true]);
        });
    }
}
