<?php

namespace App\Notifications;

use App\Models\UserNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OperationalEmailNotification extends Notification
{
    public function __construct(private readonly UserNotification $operationalNotification) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[ChargeTrackr] '.$this->operationalNotification->title)
            ->greeting('Hello '.$notifiable->name)
            ->line($this->operationalNotification->message);

        if ($this->operationalNotification->action_url !== null) {
            $mail->action(
                'Open in ChargeTrackr',
                rtrim((string) config('frontend.url'), '/').$this->operationalNotification->action_url,
            );
        }

        return $mail->line('This notification was generated automatically from your ChargeTrackr workspace.');
    }
}
