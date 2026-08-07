<?php

namespace App\Notifications;

use App\Notifications\Concerns\RoutesMailToQueue;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class VerifyClientEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable, RoutesMailToQueue;
}
