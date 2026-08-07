<?php

namespace App\Notifications\Concerns;

trait RoutesMailToQueue
{
    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['mail' => (string) config('queue.names.emails', 'emails')];
    }
}
