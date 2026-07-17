<?php

namespace App\Notifications;

use App\Models\DemoRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewDemoRequestNotification extends Notification implements ShouldQueue
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
        $url = rtrim((string) config('frontend.url'), '/').'/demo-requests';

        return (new MailMessage)
            ->subject('[Internal] New ChargeTrackr demo request - '.$this->demoRequest->reference)
            ->greeting('A new organization demo request needs review')
            ->line($this->demoRequest->full_name.' from '.$this->demoRequest->company_name.' requested a ChargeTrackr demo.')
            ->line('Main objectives: '.implode(', ', array_map(
                fn (string $objective): string => DemoRequest::OBJECTIVE_LABELS[$objective] ?? $objective,
                $this->demoRequest->objectives ?? [],
            )))
            ->line('Email: '.$this->demoRequest->email)
            ->action('Review demo request', $url)
            ->line('This link is for platform administrators only.');
    }
}
