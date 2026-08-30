<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();

        $schedule->command('suprimentos:recalcular-status')->dailyAt('05:00');

        // Ciclo 19, Etapa 19.7 — mesmo bloco diário de Suprimentos (05h),
        // logo após o recálculo do mecanismo legado — analogia direta:
        // ambos existem pela MESMA razão (deriva pura de calendário, sem
        // nenhuma mutação de model), só que sobre domínios paralelos e
        // nunca sincronizados entre si (ver App\Support\
        // SincronizarRestricaoCadeiaSuprimento).
        $schedule->command('suprimentos:sincronizar-cadeia-formal')->dailyAt('05:10');

        $schedule->command('reports:gerar-automatico')->dailyAt('06:00');
        $schedule->command('assinaturas:processar')->dailyAt('07:00');

        // Ciclo 16, Etapa A.4 — Digest Semanal de Prontidão. Segunda-feira
        // (início da semana de planejamento, mesma convenção do Last
        // Planner System que o produto já assume) às 08:00, logo após a
        // sequência diária 05h-07h já existente (sem colidir com ela).
        // withoutOverlapping() evita que a MESMA execução agendada rode 2x
        // em paralelo (ex.: execução anterior atrasada) — a proteção real
        // contra duplicidade por semana (inclusive execução manual) é o
        // lock/marcador do próprio Command (App\Console\Commands\
        // NotificarProntidaoSemanalCommand), não este mutex do Scheduler.
        // onOneServer() não foi adicionado — sem evidência de deployment
        // multi-servidor neste projeto (nenhum outro comando agendado usa).
        $schedule->command('prontidao:notificar-semanal')->weeklyOn(1, '08:00')->withoutOverlapping();

        // Ciclo 18, Etapa 18.5.7 — Digest Semanal de Pendências GED. Mesmo
        // dia/horário do Digest Semanal de Prontidão acima (decisão
        // explícita do usuário: reaproveitar a cadência já estabelecida,
        // nunca criar uma segunda convenção de horário) — as 2 execuções
        // não colidem entre si (Commands independentes, cada um com seu
        // próprio lock por obra via Cache::lock() dentro do Command).
        $schedule->command('engenharia:notificar-pendencias-grd')->weeklyOn(1, '08:00')->withoutOverlapping();

        $schedule->command('backup:run --only-db')->dailyAt('03:00');
        $schedule->command('backup:clean')->dailyAt('04:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
