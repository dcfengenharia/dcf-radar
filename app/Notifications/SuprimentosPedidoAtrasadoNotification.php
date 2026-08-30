<?php

namespace App\Notifications;

use App\Models\PedidoCompra;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Ciclo 19, Etapa 19.7 — Alerta B: o Pedido já ultrapassou
 * `data_prevista_entrega` e ainda tem saldo físico a receber
 * (`PedidoCompra::diasAtrasoAtual()`). É um alerta COMERCIAL — nunca cria
 * Restrição sozinho, mesmo quando a necessidade da atividade ainda não
 * chegou.
 */
class SuprimentosPedidoAtrasadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PedidoCompra $pedido,
    ) {
        $this->connection = 'redis';
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        $dias = $this->pedido->diasAtrasoAtual();
        $numero = $this->pedido->numero ? '#'.str_pad((string) $this->pedido->numero, 4, '0', STR_PAD_LEFT) : '—';

        return [
            'titulo' => 'Pedido de Compra com entrega atrasada',
            'mensagem' => "Pedido {$numero} está com entrega atrasada em {$dias} dia(s), com saldo pendente.",
            'icone' => 'bx-error-circle',
            'cor' => 'warning',
            'link' => $this->link(),
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toArray($notifiable);
    }

    private function link(): string
    {
        return route('radar.entrar', ['obraId' => $this->pedido->obra_id]);
    }
}
