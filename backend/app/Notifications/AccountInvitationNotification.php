<?php

namespace App\Notifications;

use App\Models\AccountInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly AccountInvitation $invitation,
        private readonly string $token,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = match ($this->invitation->role) {
            'admin' => 'organization administrator',
            'operator' => 'network operator',
            'technician' => 'field technician',
            default => 'organization member',
        };
        $url = rtrim((string) config('frontend.url'), '/').'/activate-invitation?'.http_build_query([
            'token' => $this->token,
            'email' => $this->invitation->email,
        ]);

        return (new MailMessage)
            ->subject('Activate your ChargeTrackr organization account')
            ->greeting('Welcome to ChargeTrackr, '.$this->invitation->name)
            ->line('You have been invited to '.$this->invitation->organization->name.' as a '.$role.'.')
            ->line('Use the secure link below to activate your account and choose your password.')
            ->action('Activate my account', $url)
            ->line('This one-time invitation expires at '.$this->invitation->expires_at->format('Y-m-d H:i T').'.')
            ->line('Ignore this message if you were not expecting this invitation.');
    }
}
