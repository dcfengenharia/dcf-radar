<?php

namespace App\Support\Gestao;

use App\Enums\SeveridadeSituacao;
use App\Enums\TipoSituacaoGerencial;
use Carbon\CarbonInterface;

/**
 * Ciclo 21, Etapa 21.4 — política de entrega EXPLÍCITA, TIPADA e
 * TESTÁVEL por `TipoSituacaoGerencial` (Seção 6 do pedido: "não espalhar
 * `if` por Notifications"). Única classe que decide "este tipo, nesta
 * severidade, agora, elegível a e-mail imediato?" — `SincronizarSituacoesGerenciais`
 * consulta esta classe e passa o resultado (canais) já pronto pro
 * payload da Notification; `SituacaoGerencialNotification::via()` nunca
 * decide nada sozinha, só lê o que já foi decidido.
 *
 * **Critérios por tipo, decisão desta etapa (Seção 8, "sugestão a
 * validar")**:
 * - `MaterialCritico`/`ReservaDescoberta`/`InventarioAguardandoDecisao`/
 *   `DocumentoBloqueante`: elegíveis a imediato — são situações
 *   ACIONÁVEIS AGORA (material vai faltar, aprovação pendente, atividade
 *   bloqueada), severidade mínima `Alta` (`ReservaDescoberta` é sempre
 *   `Critica` por construção, 21.2 — na prática a exigência mais alta
 *   ainda se aplica).
 * - `DesvioAplicacao`: **NUNCA imediato, sempre digest** — Seção 8/21 do
 *   pedido, explícito: "não enviar e-mail por desvio Planejado×Real como
 *   se fosse erro". Elegível a imediato SEMPRE `false`, independente de
 *   severidade.
 * - `PedidoAtrasado`: nem imediato nem digest — já suprimido desde a
 *   21.3 (`SincronizarSituacoesGerenciais::TIPOS_SEM_COMUNICACAO`, evita
 *   duplicar o sino do legado 19.7). Entrada aqui só por completude
 *   documental — nunca alcançada na prática (o código de 21.3 já retorna
 *   antes de qualquer consulta a esta classe).
 * - Demais 6 tipos (`RecebimentoPendente`/`MaterialSemDestinacao`/
 *   `SaidaSemConciliacao`/`IndustrializacaoPendente`/`MaterialParado`):
 *   sempre `Informativa`/`Atencao` por construção (21.2) — digest apenas,
 *   nunca imediato.
 *
 * **Cooldown** (Seção 6/11): 4 horas por padrão pros tipos elegíveis a
 * imediato — throttle defensivo contra oscilação rápida da fonte (o
 * mecanismo de episódio/escalada da 21.3 já impede reenvio pelo MESMO
 * estado; o cooldown aqui é uma segunda camada, temporal, pro caso de o
 * dado upstream oscilar genuinamente rápido). **Escalada sempre
 * ultrapassa o cooldown** pros 4 tipos elegíveis a imediato — uma
 * escalada de severidade é, por definição, uma piora relevante o
 * suficiente pra justificar interromper o cooldown (decisão do usuário,
 * Seção 6: "escalada permite bypass do cooldown? sim").
 */
final class PoliticaEntregaSituacao
{
    private const COOLDOWN_PADRAO_MINUTOS = 240; // 4 horas

    public function __construct(
        public readonly bool $elegivelImediato,
        public readonly bool $elegivelDigest,
        public readonly SeveridadeSituacao $severidadeMinimaImediato,
        public readonly int $cooldownMinutos,
        public readonly bool $escaladaBypassaCooldown,
    ) {
    }

    public static function para(TipoSituacaoGerencial $tipo): self
    {
        return match ($tipo) {
            TipoSituacaoGerencial::MaterialCritico,
            TipoSituacaoGerencial::InventarioAguardandoDecisao,
            TipoSituacaoGerencial::DocumentoBloqueante => new self(
                elegivelImediato: true,
                elegivelDigest: true,
                severidadeMinimaImediato: SeveridadeSituacao::Alta,
                cooldownMinutos: self::COOLDOWN_PADRAO_MINUTOS,
                escaladaBypassaCooldown: true,
            ),
            TipoSituacaoGerencial::ReservaDescoberta => new self(
                elegivelImediato: true,
                elegivelDigest: true,
                severidadeMinimaImediato: SeveridadeSituacao::Critica,
                cooldownMinutos: self::COOLDOWN_PADRAO_MINUTOS,
                escaladaBypassaCooldown: true,
            ),
            // Seção 8/21 — nunca soar acusatório sobre Planejado×Real.
            TipoSituacaoGerencial::DesvioAplicacao => new self(
                elegivelImediato: false,
                elegivelDigest: true,
                severidadeMinimaImediato: SeveridadeSituacao::Critica,
                cooldownMinutos: 0,
                escaladaBypassaCooldown: false,
            ),
            // Já suprimido desde a 21.3 (TIPOS_SEM_COMUNICACAO) — nunca
            // alcançado, entrada só por completude documental.
            TipoSituacaoGerencial::PedidoAtrasado => new self(
                elegivelImediato: false,
                elegivelDigest: false,
                severidadeMinimaImediato: SeveridadeSituacao::Critica,
                cooldownMinutos: 0,
                escaladaBypassaCooldown: false,
            ),
            TipoSituacaoGerencial::RecebimentoPendente,
            TipoSituacaoGerencial::MaterialSemDestinacao,
            TipoSituacaoGerencial::SaidaSemConciliacao,
            TipoSituacaoGerencial::IndustrializacaoPendente,
            TipoSituacaoGerencial::MaterialParado,
            // Ciclo 22, Etapa 22.3 — sem prazo/SLA formal no domínio
            // (confirmado por fresh-read), nunca urgente o bastante pra
            // e-mail imediato; digest é suficiente pra visibilidade
            // periódica sem soar como cobrança em tempo real.
            TipoSituacaoGerencial::GrdAguardandoAceite => new self(
                elegivelImediato: false,
                elegivelDigest: true,
                severidadeMinimaImediato: SeveridadeSituacao::Critica,
                cooldownMinutos: 0,
                escaladaBypassaCooldown: false,
            ),
        };
    }

    /**
     * Decide se ESTE tick, pra ESTA severidade/ocorrência, justifica
     * disparar e-mail imediato — nunca decide POR CANAL individual
     * (database/broadcast são sempre entregues, independente desta
     * política — Seção 7: "in-app/database já existentes, nunca
     * gateados por política").
     */
    public function elegivelParaEmailAgora(
        SeveridadeSituacao $severidadeAtual,
        bool $ehEscalada,
        ?CarbonInterface $ultimoEmailEm,
        CarbonInterface $agora,
    ): bool {
        if (! $this->elegivelImediato) {
            return false;
        }

        if ($severidadeAtual->peso() < $this->severidadeMinimaImediato->peso()) {
            return false;
        }

        if ($ultimoEmailEm === null) {
            return true;
        }

        $dentroDoCooldown = $ultimoEmailEm->diffInMinutes($agora) < $this->cooldownMinutos;

        if (! $dentroDoCooldown) {
            return true;
        }

        return $ehEscalada && $this->escaladaBypassaCooldown;
    }
}
