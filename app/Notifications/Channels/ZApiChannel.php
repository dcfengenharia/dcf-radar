<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Canal de notificação via WhatsApp (Z-API). Notification precisa
 * implementar toWhatsApp($notifiable): string; sem credencial
 * configurada ou sem número de destino, não envia nada — nunca lança
 * exceção (mesmo espírito de um canal de e-mail sem SMTP configurado
 * em dev, mas silencioso mesmo em produção, já que WhatsApp é um canal
 * best-effort, não crítico).
 */
class ZApiChannel
{
    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $numero = $notifiable->routeNotificationFor('whatsapp', $notification);
        if (! $numero) {
            return;
        }

        $instanceId = config('services.zapi.instance_id');
        $token = config('services.zapi.token');
        if (! $instanceId || ! $token) {
            return;
        }

        $resposta = Http::withHeaders(array_filter([
            'Client-Token' => config('services.zapi.client_token'),
        ]))->post("https://api.z-api.io/instances/{$instanceId}/token/{$token}/send-text", [
            'phone' => $this->normalizarNumero($numero),
            'message' => $notification->toWhatsApp($notifiable),
        ]);

        if ($resposta->failed()) {
            Log::warning('Falha ao enviar notificação via Z-API', [
                'status' => $resposta->status(),
                'notification' => get_class($notification),
            ]);
        }
    }

    private function normalizarNumero(string $numero): string
    {
        $digitos = preg_replace('/\D/', '', $numero);

        return str_starts_with($digitos, '55') ? $digitos : '55'.$digitos;
    }
}
