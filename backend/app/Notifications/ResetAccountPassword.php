<?php

namespace App\Notifications;

use App\Notifications\Concerns\RoutesMailToQueue;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ResetAccountPassword extends ResetPassword implements ShouldQueue
{
    use Queueable, RoutesMailToQueue;
}
