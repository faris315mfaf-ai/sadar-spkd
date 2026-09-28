<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class EmployeeSetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        // Sent by "Lupa kata sandi", so the wording must fit an existing account too.
        return (new MailMessage)
            ->subject('Atur Password Akun '.config('app.name'))
            ->greeting('Halo, '.$notifiable->name.'!')
            ->line('Kami menerima permintaan untuk mengatur password akun '.config('app.name').' Anda.')
            ->line('Silakan buat password baru melalui tombol di bawah ini.')
            ->action('Atur Password', $url)
            ->line('Link ini akan kedaluwarsa dalam 60 menit.')
            ->line('Jika Anda merasa tidak seharusnya menerima email ini, abaikan email ini.');
    }
}
