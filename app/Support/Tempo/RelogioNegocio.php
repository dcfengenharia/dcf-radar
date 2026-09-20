<?php

namespace App\Support\Tempo;

use Carbon\Carbon;

/**
 * Auditoria Pré-Produção A2, Seção 4 (Timezone) — única fonte de verdade
 * pra "hoje/agora, do ponto de vista do usuário brasileiro" (Classe B:
 * datas de negócio — prazo, vencimento, "hoje", janela de dias). NUNCA usar
 * pra timestamps técnicos (Classe A: created_at/updated_at/login/audit
 * log/fila) — esses continuam corretamente em UTC, carimbados pelo próprio
 * framework via config('app.timezone').
 *
 * Achado que motivou esta classe: `now()`/`Carbon::today()`/`->isPast()`
 * sem fuso explícito usam o fuso PADRÃO da aplicação (UTC, config/app.php).
 * Brasil é UTC-3 — comparações de "hoje"/"vencido" feitas em UTC ficam
 * erradas por horas todo santo dia:
 * - `Carbon::today()` "vira o dia" às 21h00 (horário de Brasília) —
 *   qualquer guarda de "data não pode ser futura" comparada contra
 *   Carbon::today() aceita incorretamente uma data que ainda É futura
 *   pro usuário brasileiro, das 21h00 às 23h59.
 * - Um campo `date` (meia-noite UTC daquele dia) comparado via `isPast()`
 *   contra `Carbon::now()` (UTC) vira "vencido" a partir das 21h00 do dia
 *   ANTERIOR ao prazo — quase 27h antes do prazo real (fim do dia, horário
 *   de Brasília) ter de fato passado. Provado empiricamente via tinker
 *   antes de qualquer correção.
 *
 * Nunca altera `config('app.timezone')` nem qualquer timestamp já
 * persistido — os campos `date`/`datetime` do banco continuam em UTC; só
 * o INSTANTE DE COMPARAÇÃO ("o que é hoje/agora, pro usuário") passa a
 * ser resolvido no fuso de negócio. Comparação sempre por DATA (Y-m-d),
 * nunca por instante absoluto — evita a armadilha de comparar meia-noite
 * UTC de um dia contra meia-noite BRT de outro (dois "meio-dias" com
 * offsets de fuso diferentes nunca deveriam ser comparados como
 * instantes quando a granularidade de negócio é o DIA calendário).
 */
final class RelogioNegocio
{
    public const FUSO = 'America/Sao_Paulo';

    /**
     * Agora, no fuso de negócio (Brasil) — nunca use pra gravar
     * created_at/updated_at/timestamps técnicos, só pra decidir "hoje é
     * qual dia" do ponto de vista do usuário.
     */
    public static function agora(): Carbon
    {
        return Carbon::now(self::FUSO);
    }

    /**
     * Início do dia atual, no fuso de negócio.
     */
    public static function hoje(): Carbon
    {
        return self::agora()->startOfDay();
    }

    /**
     * Início da semana atual (segunda-feira, convenção padrão do Carbon),
     * no fuso de negócio — nunca `Carbon::now()->startOfWeek()` puro, que
     * "vira a semana" um dia cedo demais durante a noite de domingo.
     */
    public static function inicioDaSemanaAtual(): Carbon
    {
        return self::agora()->startOfWeek();
    }

    /**
     * Compara SÓ a data calendário (nunca o instante absoluto) — é isso
     * que evita comparar meia-noite UTC contra meia-noite BRT como se
     * fossem diretamente comparáveis quando a granularidade é o dia.
     */
    private static function dataEhPosteriorAHoje(?Carbon $data): bool
    {
        if ($data === null) {
            return false;
        }

        return $data->toDateString() > self::agora()->toDateString();
    }

    /**
     * "Esta data (um campo `date`, sem hora) já está no passado, do ponto
     * de vista do calendário brasileiro?" — substitui `$data->isPast()`
     * pra qualquer campo `date` de negócio (prazo_limite, data_termino,
     * data_planejada, end_date_baseline, etc.). `null` nunca é "vencido".
     */
    public static function dataEstaVencida(?Carbon $data): bool
    {
        if ($data === null) {
            return false;
        }

        return self::agora()->toDateString() > $data->toDateString();
    }

    /**
     * "Esta data é estritamente posterior a hoje, do ponto de vista do
     * calendário brasileiro?" — substitui `$data->gt(Carbon::today())` /
     * `$data->isFuture()` em guardas de "data não pode ser futura".
     * `null` nunca é considerado futuro.
     */
    public static function dataEstaNoFuturo(?Carbon $data): bool
    {
        return self::dataEhPosteriorAHoje($data);
    }

    /**
     * Auditoria Pré-Produção A2.2, Seção 9 — quantidade de dias corridos
     * entre duas DATAS DE NEGÓCIO (dia calendário puro, nunca instante
     * absoluto). Achado real: `RelogioNegocio::hoje()` carrega o fuso
     * `America/Sao_Paulo` (meia-noite local = 03:00 UTC), enquanto um
     * campo `date`-cast (ex.: `Work.end_date_baseline`) é sempre
     * hidratado como meia-noite no fuso PADRÃO da app (UTC) — comparar
     * os dois Carbon diretamente via `diffInDays()` (que opera sobre o
     * INSTANTE absoluto) subtrai as ~3h de diferença de fuso do
     * resultado, fazendo "hoje + 28 dias corridos" virar "27" sempre que
     * o prazo cair num horário-do-dia posterior ao de `hoje()`. A
     * correção reduz as DUAS pontas a uma string `Y-m-d` (nunca um
     * instante) antes de comparar — "28 de calendário" sempre bate com
     * "28", em qualquer hora do dia, dos dois lados.
     */
    public static function diasEntreDatas(Carbon $de, Carbon $ate): int
    {
        return (int) Carbon::parse($de->toDateString())->diffInDays(Carbon::parse($ate->toDateString()), true);
    }
}
