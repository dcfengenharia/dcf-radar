<?php

namespace App\Notifications;

use App\Support\CentralProntidao\DestaqueProntidao;
use App\Support\CentralProntidao\ResumoDigestProntidao;
use App\Support\CentralProntidao\SnapshotDigestProntidao;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Digest Semanal de Prontidão (Ciclo 16, Etapa A.3) — "esta obra possui
 * atividades que exigem atenção nos próximos N dias". Canais leves
 * (`database`+`broadcast`), mesmo precedente de
 * `PlanoSemanalGeradoNotification`: evento recorrente pra múltiplos
 * usuários da obra, nunca os 4 canais reservados a alertas raros e
 * pessoais (`AlertaPrazoSuprimentoNotification`).
 *
 * O construtor recebe o `ResumoDigestProntidao` completo (produzido por
 * `App\Services\DigestProntidao::consolidar()`, Etapa A.2) só de
 * PASSAGEM — reduz imediatamente pra `SnapshotDigestProntidao`
 * (Etapa A.3) e guarda SÓ o snapshot como propriedade. O
 * `ResumoDigestProntidao` original (com a árvore completa de
 * `AtividadeProntidaoView`) nunca é armazenado, então nunca é
 * serializado pra dentro do job de fila — só o snapshot leve viaja pro
 * Redis. Depois de construída, a Notification não toca banco de dado
 * nenhum: tudo que `toArray()`/`toBroadcast()` precisam já está no
 * snapshot (filosofia "detecção agora → fotografia → mensagem futura",
 * evita divergência entre o que foi detectado e o que é entregue).
 */
class ProntidaoSemanalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private readonly SnapshotDigestProntidao $snapshot;

    public function __construct(ResumoDigestProntidao $resumo)
    {
        $this->connection = 'redis';
        $this->snapshot = SnapshotDigestProntidao::deResumo($resumo);
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return [
            'titulo' => "Central de Prontidão — {$this->snapshot->obraNome}",
            'mensagem' => $this->mensagem(),
            'icone' => 'bx-check-shield',
            'cor' => 'warning',
            'link' => $this->link(),
            'obra_id' => $this->snapshot->obraId,
            'total_exige_atencao' => $this->snapshot->totalExigeAtencao,
            'total_nao_pronta' => $this->snapshot->totalNaoPronta,
            'total_atencao' => $this->snapshot->totalAtencao,
            'horizonte_dias' => $this->snapshot->horizonteDias,
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    private function mensagem(): string
    {
        $mensagem = "{$this->snapshot->totalExigeAtencao} atividade(s) exige(m) atenção nos próximos "
            . "{$this->snapshot->horizonteDias} dias: {$this->snapshot->totalNaoPronta} Não Pronta(s) e "
            . "{$this->snapshot->totalAtencao} Em Atenção.";

        if ($this->snapshot->destaques !== []) {
            $linhas = collect($this->snapshot->destaques)
                ->map(fn (DestaqueProntidao $destaque) => $this->linhaDestaque($destaque))
                ->implode(' | ');

            $mensagem .= " Próximas: {$linhas}.";
        }

        return $mensagem;
    }

    private function linhaDestaque(DestaqueProntidao $destaque): string
    {
        $codigo = $destaque->codigo ? "{$destaque->codigo} " : '';
        $data = $destaque->inicioPlanejado ? " — {$destaque->inicioPlanejado->format('d/m/Y')}" : '';

        return "{$codigo}{$destaque->nome} — {$destaque->statusOperacional->label()}{$data}";
    }

    /**
     * Sem deep-link pra Central nesta etapa (Ciclo 16, A.1/A.2, achado de
     * auditoria: `radar.central-prontidao` depende da obra ativa em
     * sessão, sem `{obra}` no path). `radar.entrar/{obraId}` valida
     * acesso, seta o `ObraContext` certo e redireciona pro Quadro de
     * Restrições — não a Central diretamente. Limitação aceita pro MVP:
     * 1 clique adicional (menu → Central de Prontidão) depois de entrar
     * na obra certa.
     */
    private function link(): string
    {
        return route('radar.entrar', ['obraId' => $this->snapshot->obraId]);
    }

    /**
     * Getter público só pra permitir asserção em teste (mesma convenção
     * de `RestricoesPendentesNotification::restricoesParaTeste()`) — o
     * snapshot é privado/readonly no construtor.
     */
    public function snapshotParaTeste(): SnapshotDigestProntidao
    {
        return $this->snapshot;
    }
}
