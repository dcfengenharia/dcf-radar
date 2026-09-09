<?php

namespace App\Providers;

use App\Actions\Jetstream\DeleteUser;
use App\Livewire\Profile\LogoutOtherBrowserSessionsForm;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::deleteUsersUsing(DeleteUser::class);

        // BUG TARGETED — /profile ("Outras sessões do navegador")
        // lançava "Call to a member function get() on string" (ver
        // App\Support\Agent pra causa raiz completa: incompatibilidade
        // real entre Laravel\Jetstream\Agent, vendor, e a versão de
        // mobiledetect/mobiledetectlib que o próprio Jetstream declara).
        // Reaproveita o MESMO nome de componente que
        // Laravel\Jetstream\JetstreamServiceProvider (vendor, auto-
        // discovered, sempre carregado antes deste provider explícito
        // em config/app.php) já registra — a última chamada a
        // Livewire::component() com o mesmo nome vence, sem publicar
        // nem sobrescrever nenhuma view.
        Livewire::component('profile.logout-other-browser-sessions-form', LogoutOtherBrowserSessionsForm::class);
    }

    /**
     * Configure the permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        Jetstream::permissions([
            'create',
            'read',
            'update',
            'delete',
        ]);
    }
}
