<?php

namespace App\Support\CentralProntidao;

use App\Enums\StatusItemSuprimento;
use Carbon\Carbon;

/**
 * Leitura resumida de UM ItemSuprimento em EmRisco/Atrasado vinculado à
 * atividade pra Central de Prontidão (Ciclo 15, Etapa B.1) — MVP mostra
 * só os itens com alerta (ver CentralProntidaoQuery), nunca todos os
 * vínculos (isso fica pro detalhe futuro, Ciclo 14 seção C). Puramente
 * CONTEXTO — nunca altera `AtividadeProntidaoView::$pronta` (Ciclo 14,
 * princípio 6). Se o item já gerou uma Restricao bloqueante via
 * `SincronizarRestricaoSuprimento` (mecanismo já existente), essa
 * Restrição aparece separadamente em `restricoesBloqueantes` — este DTO
 * nunca duplica esse bloqueio.
 */
final readonly class SuprimentoAlerta
{
    public function __construct(
        public string $itemId,
        public string $nome,
        public StatusItemSuprimento $status,
        public ?Carbon $necessidade,
    ) {
    }
}
