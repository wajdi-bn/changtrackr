<?php

namespace Tests\Unit;

use App\Events\ChargingAttemptChanged;
use App\Events\ChargingSessionChanged;
use App\Events\OcppCommandChanged;
use App\Events\StationAvailabilityChanged;
use App\Events\UserNotificationCreated;
use App\Jobs\CaptureAuthorizedSessionPayment;
use App\Jobs\GenerateMaintenanceOccurrences;
use App\Jobs\SendOperationalNotificationEmail;
use App\Models\AccountInvitation;
use App\Models\DemoRequest;
use App\Notifications\AccountInvitationNotification;
use App\Notifications\DemoRequestReceivedNotification;
use App\Notifications\NewDemoRequestNotification;
use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyClientEmail;
use Tests\TestCase;

class RedisQueueConfigurationTest extends TestCase
{
    public function test_redis_workloads_use_isolated_connections_and_safe_worker_settings(): void
    {
        $this->assertSame('cache', config('cache.stores.redis.connection'));
        $this->assertSame('cache', config('cache.stores.redis.lock_connection'));
        $this->assertSame('session', config('session.connection'));
        $this->assertSame('queue', config('queue.connections.redis.connection'));
        $this->assertSame(120, config('queue.connections.redis.retry_after'));
        $this->assertSame(5, config('queue.connections.redis.block_for'));
        $this->assertTrue(config('queue.connections.redis.after_commit'));
        $this->assertSame('1', (string) config('database.redis.cache.database'));
        $this->assertSame('2', (string) config('database.redis.session.database'));
        $this->assertSame('3', (string) config('database.redis.queue.database'));
    }

    public function test_environment_example_selects_redis_without_affecting_test_drivers(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertIsString($example);
        $this->assertStringContainsString('CACHE_STORE=redis', $example);
        $this->assertStringContainsString('SESSION_DRIVER=redis', $example);
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $example);
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
    }

    public function test_business_jobs_are_routed_to_dedicated_queues(): void
    {
        $this->assertSame('payments', (new CaptureAuthorizedSessionPayment(10))->queue);
        $this->assertSame('emails', (new SendOperationalNotificationEmail(20))->queue);
        $this->assertSame('maintenance', (new GenerateMaintenanceOccurrences(30))->queue);
    }

    public function test_realtime_events_are_routed_to_the_broadcast_queue(): void
    {
        $events = [
            new ChargingSessionChanged(1, 2, 3, 4, 'charging', 1.5, 900, null),
            new ChargingAttemptChanged('attempt', 2, 3, 4, 'charging', 'authorized', 1),
            new StationAvailabilityChanged(1, 2, 'available', 'connector_available', 'ocpp', now()->toISOString(), [], true),
            new OcppCommandChanged('command', 2, 1, null, 'Reset', 'pending', null, null, null, now()->toISOString()),
            new UserNotificationCreated(1, 3, 'alert', 'warning', 'Station warning', now()->toISOString()),
        ];

        foreach ($events as $event) {
            $this->assertSame('broadcasts', $event->broadcastQueue());
        }
    }

    public function test_queued_notifications_are_routed_to_the_email_queue(): void
    {
        $notifications = [
            new VerifyClientEmail,
            new ResetAccountPassword('token'),
            new NewDemoRequestNotification(new DemoRequest),
            new DemoRequestReceivedNotification(new DemoRequest),
            new AccountInvitationNotification(new AccountInvitation, 'token'),
        ];

        foreach ($notifications as $notification) {
            $this->assertSame(['mail' => 'emails'], $notification->viaQueues());
        }
    }
}
