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

        // Ciclo 21, Etapa 21.4 — sincronizador de Situações Gerenciais.
        // Frequência única de 15 minutos pra TODOS os 11 tipos (Seção 5 do
        // pedido: "se uma única frequência de sincronização for mais
        // simples, tudo bem — a política de comunicação decide quando
        // avisar de verdade", ver App\Support\Gestao\PoliticaEntregaSituacao).
        // 15 min é o equilíbrio escolhido entre "quase tempo real" pros
        // tipos acionáveis agora (Material Crítico/Reserva Descoberta/etc.)
        // e não sobrecarregar a fila — o Command só DESPACHA 1 Job por obra
        // (App\Jobs\SincronizarSituacaoObraJob, com lock de unicidade
        // próprio via ShouldBeUnique), nunca processa nada diretamente.
        // withoutOverlapping() aqui é só defesa em profundidade no nível do
        // Scheduler (o Command coordenador é rápido — só itera e despacha);
        // a proteção real contra 2 execuções da MESMA obra é o lock do Job.
        $schedule->command('gestao:sincronizar-situacoes')->everyFifteenMinutes()->withoutOverlapping();

        // Ciclo 21, Etapa 21.4 — Digest Operacional diário (Seção 10:
        // "apenas um digest simples, nunca um segundo digest sem
        // necessidade"). 07:30 — depois do bloco diário 05h-07h já
        // existente, antes dos digests semanais de segunda-feira 08:00 —
        // nunca colide com nenhum agendamento já existente. Mesmo padrão
        // UTC/lock por Command de sempre (ver docblock do Command).
        $schedule->command('gestao:digest-situacoes')->dailyAt('07:30')->withoutOverlapping();
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
