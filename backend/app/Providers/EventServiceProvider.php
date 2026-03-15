<?php

namespace App\Providers;

use App\Events\TenantCreated;
use App\Events\UserCreated;
use App\Events\UserUpdated;
use App\Events\UserDeleted;
use App\Events\HotspotUserCreated;
use App\Events\PaymentCompleted;
use App\Events\PaymentFailed;
use App\Events\PaymentReceived;
use App\Events\PackageStatusChanged;
use App\Events\PppoeInvoiceDueSoon;
use App\Events\PppoeInvoiceSent;
use App\Events\PppoeMonthlyReportGenerated;
use App\Events\RouterStatusUpdated;
use App\Events\DashboardStatsUpdated;
use App\Events\RouterProvisioningCompleted;
use App\Events\PppoeGracePeriodStarted;
use App\Events\PppoeMonthEndReportRequested;
use App\Events\PppoePaymentReminderDue;
use App\Events\PppoeReminderSent;
use App\Events\PppoeUserDisconnectedForNonPayment;
use App\Events\PppoeUserReconnectedAfterPayment;
use App\Listeners\QueuePppoeInvoiceJob;
use App\Listeners\QueuePppoeMonthlyPaymentReportJob;
use App\Listeners\QueuePppoePaymentReminderJob;
use App\Listeners\QueuePppoePaymentReceipt;
use App\Listeners\TrackCompletedJobs;
use App\Listeners\UpdateRouterStatus;
use App\Listeners\LogPppoeBillingLifecycle;
use App\Listeners\LogPppoeBillingOutcome;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobProcessed;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Queue job tracking
        JobProcessed::class => [
            TrackCompletedJobs::class,
        ],
        
        // Tenant events - broadcast to system admins
        TenantCreated::class => [
            // Listeners can be added here for side effects
            // e.g., UpdateSystemDashboardStats::class
        ],
        
        // User events - broadcast to tenant channels
        UserCreated::class => [
            // e.g., UpdateTenantDashboardStats::class
        ],
        
        UserUpdated::class => [
            // Side effect listeners
        ],
        
        UserDeleted::class => [
            // Side effect listeners
        ],
        
        // Hotspot user events
        HotspotUserCreated::class => [
            // e.g., SendCredentialsSMS::class
        ],
        
        // Payment events
        PaymentCompleted::class => [
            // e.g., UpdateBillingStats::class
        ],
        
        PaymentFailed::class => [
            // e.g., NotifyAdminOfFailedPayment::class
        ],
        
        PaymentReceived::class => [
            QueuePppoePaymentReceipt::class,
        ],
        
        PppoePaymentReminderDue::class => [
            QueuePppoePaymentReminderJob::class,
        ],
        
        PppoeInvoiceDueSoon::class => [
            QueuePppoeInvoiceJob::class,
        ],
        
        PppoeMonthEndReportRequested::class => [
            QueuePppoeMonthlyPaymentReportJob::class,
        ],
        
        PppoeReminderSent::class => [
            LogPppoeBillingOutcome::class,
        ],
        
        PppoeInvoiceSent::class => [
            LogPppoeBillingOutcome::class,
        ],
        
        PppoeMonthlyReportGenerated::class => [
            LogPppoeBillingOutcome::class,
        ],
        
        PppoeGracePeriodStarted::class => [
            LogPppoeBillingLifecycle::class,
        ],
        
        PppoeUserDisconnectedForNonPayment::class => [
            LogPppoeBillingLifecycle::class,
        ],
        
        PppoeUserReconnectedAfterPayment::class => [
            LogPppoeBillingLifecycle::class,
        ],
        
        // Package events
        PackageStatusChanged::class => [
            // e.g., NotifySubscriberOfStatusChange::class
        ],

        \App\Events\PackageUpdated::class => [
            \App\Listeners\UpdatePppoeUsersRateLimit::class,
        ],
        
        // Router events
        RouterStatusUpdated::class => [
            // e.g., AlertAdminOfRouterIssues::class
        ],
        
        RouterProvisioningCompleted::class => [
            UpdateRouterStatus::class,
        ],
        
        // Dashboard events
        DashboardStatsUpdated::class => [
            // Stats are already broadcast, no additional listeners needed
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
