<?php

namespace App\Listeners;

use App\Notifications\BoasVindasNotification;
use Illuminate\Auth\Events\Verified;

class EnviarBoasVindasAposVerificarEmail
{
    public function handle(Verified $event): void
    {
        $event->user->notify(new BoasVindasNotification());
    }
}
