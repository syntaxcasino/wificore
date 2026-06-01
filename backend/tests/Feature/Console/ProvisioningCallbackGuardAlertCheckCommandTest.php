<?php

namespace Tests\Feature\Console;

use App\Events\ProvisioningCallbackGuardAlertRaised;
use App\Models\User;
use App\Notifications\ProvisioningCallbackGuardEscalationNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProvisioningCallbackGuardAlertCheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'log']);

        foreach ([
            'identity_validation_failed',
            'freshness_validation_failed',
            'terminal_status_mutation_ignored',
            'regressive_stage_ignored',
        ] as $action) {
            Cache::forget('metrics:provisioning:callback_guard:trend:' . $action);
        }

        Cache::forget('metrics:provisioning:callback_guard:alert_cooldown');
    }

    public function test_it_reports_normal_when_below_threshold(): void
    {
        $minuteKey = now()->format('Y-m-d\\TH:i');
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            $minuteKey => 1,
        ], now()->addMinutes(10));

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutput('Callback guard trend is within normal range.')
            ->assertExitCode(0);
    }

    public function test_it_emits_alert_and_sets_cooldown_when_threshold_exceeded(): void
    {
        $minuteKey = now()->format('Y-m-d\\TH:i');
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            $minuteKey => 7,
        ], now()->addMinutes(10));

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutputToContain('trend alert')
            ->assertExitCode(0);

        $cooldown = Cache::get('metrics:provisioning:callback_guard:alert_cooldown');
        $this->assertIsArray($cooldown);
        $this->assertSame(7, (int) ($cooldown['total_delta'] ?? 0));
    }


    public function test_it_broadcasts_alert_event_when_threshold_exceeded(): void
    {
        $minuteKey = now()->format('Y-m-d\TH:i');
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            $minuteKey => 7,
        ], now()->addMinutes(10));

        Event::fake([ProvisioningCallbackGuardAlertRaised::class]);

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutputToContain('trend alert')
            ->assertExitCode(0);

        Event::assertDispatched(ProvisioningCallbackGuardAlertRaised::class, function (ProvisioningCallbackGuardAlertRaised $event): bool {
            return $event->level === 'warning'
                && $event->totalDelta === 7
                && $event->windowMinutes === 10
                && $event->warnThreshold === 5
                && $event->criticalThreshold === 20;
        });
    }


    public function test_it_escalates_sustained_critical_alerts_to_system_admins_and_webhook(): void
    {
        config([
            'services.provisioning.callback_guard_escalation_consecutive_critical_checks' => 3,
            'services.provisioning.callback_guard_escalation_cooldown_seconds' => 3600,
            'services.provisioning.callback_guard_alert_webhook_url' => 'https://alerts.example.test/webhook',
        ]);

        $admin = new class ('admin-' . uniqid() . '@example.test') {
            public function __construct(
                public string $email,
                public string $name = 'System Admin',
            ) {
            }

            public function getKey(): int
            {
                return 1;
            }
        };

        $builder = \Mockery::mock();
        $builder->shouldReceive('where')->with('role', 'system_admin')->andReturnSelf();
        $builder->shouldReceive('whereNull')->with('tenant_id')->andReturnSelf();
        $builder->shouldReceive('where')->with('is_active', true)->andReturnSelf();
        $builder->shouldReceive('whereNotNull')->with('email')->andReturnSelf();
        $builder->shouldReceive('get')->andReturn(collect([$admin]));

        $userModel = \Mockery::mock('alias:' . User::class);
        $userModel->shouldReceive('query')->andReturn($builder);

        Notification::fake();
        Http::fake([
            'https://alerts.example.test/*' => Http::response(['ok' => true], 200),
        ]);
        Event::fake([ProvisioningCallbackGuardAlertRaised::class]);

        $minuteKey = now()->format('Y-m-d\TH:i');
        Cache::put('metrics:provisioning:callback_guard:trend:identity_validation_failed', [
            $minuteKey => 25,
        ], now()->addMinutes(10));

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutputToContain('trend alert')
            ->assertExitCode(0);

        Notification::assertNothingSent();
        Http::assertNothingSent();

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutputToContain('trend alert')
            ->assertExitCode(0);

        Notification::assertNothingSent();
        Http::assertNothingSent();

        $this->artisan('provisioning:callback-guard-alert-check')
            ->expectsOutputToContain('trend alert')
            ->assertExitCode(0);

        Notification::assertSentTo(
            $admin,
            ProvisioningCallbackGuardEscalationNotification::class,
            function (ProvisioningCallbackGuardEscalationNotification $notification): bool {
                return true;
            }
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://alerts.example.test/webhook'
                && $request['level'] === 'critical'
                && ($request['critical_streak'] ?? null) === 3;
        });
    }

}
