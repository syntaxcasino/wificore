<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProvisioningCallbackGuardEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $totalDelta,
        private readonly int $windowMinutes,
        private readonly int $warnThreshold,
        private readonly int $criticalThreshold,
        private readonly int $criticalStreak,
        private readonly int $criticalStreakThreshold,
        private readonly array $deltas,
    ) {
        $this->onQueue(config('saas.notifications.queue', 'notifications'));
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $deltaSummary = collect($this->deltas)
            ->filter(fn ($value) => (int) $value > 0)
            ->map(fn ($value, $key) => str_replace('_', ' ', $key) . ': ' . (int) $value)
            ->values()
            ->implode(', ');

        return (new MailMessage)
            ->subject('Critical provisioning callback guard escalation')
            ->greeting('Hello ' . ($notifiable->name ?? 'System Admin') . ',')
            ->line('Provisioning callback guard activity has remained critical across multiple checks.')
            ->line('Window: ' . $this->windowMinutes . ' minute(s)')
            ->line('Total outcomes: ' . $this->totalDelta)
            ->line('Critical streak: ' . $this->criticalStreak . ' / ' . $this->criticalStreakThreshold)
            ->line('Warn threshold: ' . $this->warnThreshold . ', critical threshold: ' . $this->criticalThreshold)
            ->line($deltaSummary !== '' ? 'Breakdown: ' . $deltaSummary : 'Breakdown: no per-action deltas recorded')
            ->action('Open System Metrics', url('/system/metrics'))
            ->line('Review the system metrics page and provisioning callback guard panel for rollout diagnostics.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'provisioning_callback_guard_escalation',
            'level' => 'critical',
            'window_minutes' => $this->windowMinutes,
            'total_delta' => $this->totalDelta,
            'warn_threshold' => $this->warnThreshold,
            'critical_threshold' => $this->criticalThreshold,
            'critical_streak' => $this->criticalStreak,
            'critical_streak_threshold' => $this->criticalStreakThreshold,
            'deltas' => $this->deltas,
            'message' => 'Provisioning callback guard remained critical long enough to trigger escalation.',
            'action_url' => '/system/metrics',
            'action_text' => 'Open System Metrics',
            'priority' => 'critical',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
