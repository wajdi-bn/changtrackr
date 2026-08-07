<?php

namespace App\Events\Concerns;

trait RoutesBroadcastsToQueue
{
    public function broadcastQueue(): string
    {
        return (string) config('queue.names.broadcasts', 'broadcasts');
    }
}
