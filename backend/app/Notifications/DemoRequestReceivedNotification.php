<?php

namespace App\Notifications;

use App\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DemoRequestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly DemoRequest $demoRequest)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('ChargeTrackr demo request received - '.$this->demoRequest->reference)
            ->greeting('Thank you, '.$this->demoRequest->full_name)
            ->line('We received the organization demo request for '.$this->demoRequest->company_name.'.')
            ->line('Reference: '.$this->demoRequest->reference)
            ->line('Our platform team will review the request. If it is accepted, you will receive a separate secure invitation to activate the organization administrator account.')
            ->line('No action is required from you at this stage. Keep the reference above for follow-up.');
    }
}
