<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RestablecerPasswordAdministrador extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = (string) config('ecoruta.recuperacion_por_correo.frontend_url');
        $enlace = $base.'/#/restablecer?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable instanceof User ? $notifiable->email : '',
        ]);
        $minutos = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Restablecer tu contraseña de EcoRuta')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta de administrador.')
            ->action('Elegir una contraseña nueva', $enlace)
            ->line("El enlace sirve una sola vez y vence en {$minutos} minutos.")
            ->line('Si no fuiste tú, ignora este mensaje: tu contraseña no cambia.');
    }
}
