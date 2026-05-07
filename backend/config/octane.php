<?php

use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Events\TickTerminated;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Laravel\Octane\Listeners\CollectGarbage;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushAuthenticationState;
use Laravel\Octane\Listeners\FlushQueuedCookies;
use Laravel\Octane\Listeners\FlushSessionState;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToAuthorizationGate;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToBroadcastManager;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToDatabaseManager;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToDatabaseSessionHandler;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToFilesystemManager;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToHttpKernel;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToMailManager;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToNotificationChannelManager;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToPipelineHub;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToQueueManager;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToRouter;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToScheduleManager;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToValidationFactory;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToViewFactory;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;

// Only load Octane class if it exists (prevents errors during composer install in Docker)
$octaneAvailable = class_exists(\Laravel\Octane\Octane::class);

return [

    /*
    |--------------------------------------------------------------------------
    | Octane Server
    |--------------------------------------------------------------------------
    |
    | This value determines the default "server" that will be used by Octane
    | when starting, restarting, or stopping your Octane server via the CLI.
    | New Swoole / RoadRunner versions will be used if available.
    |
    | Supported: "roadrunner", "swoole", "frankenphp"
    |
    */

    'server' => env('OCTANE_SERVER', 'roadrunner'),

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    |
    | When this configuration value is set to "true", Octane will inform the
    | framework that all absolute links must be generated using the HTTPS
    | protocol. Otherwise your links may use HTTP by default.
    |
    */

    'https' => env('OCTANE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | Octane Listeners
    |--------------------------------------------------------------------------
    |
    | All of the event listeners for Octane's events are defined below. These
    | listeners are responsible for resetting your application's state for
    | the next request. You may add your own listeners to this array.
    |
    */

    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],

        RequestReceived::class => $octaneAvailable
            ? [...\Laravel\Octane\Octane::prepareApplicationForNextRequest()]
            : [],

        RequestTerminated::class => [
            //
        ],

        TaskReceived::class => $octaneAvailable
            ? [...\Laravel\Octane\Octane::prepareApplicationForNextOperation()]
            : [],

        TaskTerminated::class => [
            //
        ],

        TickReceived::class => $octaneAvailable
            ? [...\Laravel\Octane\Octane::prepareApplicationForNextOperation()]
            : [],

        TickTerminated::class => [
            //
        ],

        WorkerStopping::class => [
            //
        ],

        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Warm / Reloadable Bindings
    |--------------------------------------------------------------------------
    |
    | The bindings listed below will be pre-resolved into the worker's
    | container. These bindings are also eligible to be "warm" / "hot" reloaded.
    | A "warm" binding will be re-resolved on every request, while a "hot"
    | binding will be re-resolved only when a file changes.
    |
    */

    'warm' => $octaneAvailable
        ? [...\Laravel\Octane\Octane::defaultServicesToWarm()]
        : [],

    /*
    |--------------------------------------------------------------------------
    | Hot Reloadable Bindings
    |--------------------------------------------------------------------------
    |
    | The bindings listed below will be re-resolved on every file change if
    | the --watch flag is used while serving Octane. These bindings will be
    | pre-resolved into the worker's container.
    |
    */

    'hot' => [],

    /*
    |--------------------------------------------------------------------------
    | Octane Cache Table
    |--------------------------------------------------------------------------
    |
    | While using Swoole or RoadRunner, you may leverage the Octane cache,
    | which is powered by a high-speed Swoole table. You may configure the
    | maximum number of rows and bytes for the cache.
    |
    */

    'cache' => [
        'rows' => 10000,
        'bytes' => 10000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Octane Tables
    |--------------------------------------------------------------------------
    |
    | While using Swoole or RoadRunner, you may define additional tables as
    | required by the application. These tables can be used to store data
    | that should be available across requests and workers.
    |
    */

    'tables' => [
        'cache:10000',
        'sessions:10000',
        'sockets:10000',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watching
    |--------------------------------------------------------------------------
    |
    | The following list of files and directories will be watched when using
    | the --watch option offered by Octane. When a file changes in one of
    | these directories, Octane will automatically reload your workers.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        '.env',
    ],

    /*
    |--------------------------------------------------------------------------
    | Garbage Collection Threshold
    |--------------------------------------------------------------------------
    |
    | This value determines the number of requests that will be processed
    | before Octane invokes PHP's garbage collector. A lower number means
    | less memory consumption but may impact performance. A higher number
    | means higher memory consumption but better performance.
    |
    */

    'garbage' => 500,

    /*
    |--------------------------------------------------------------------------
    | Maximum Execution Time
    |--------------------------------------------------------------------------
    |
    | This value determines the maximum number of seconds a request will be
    | allowed to execute before being terminated. Setting this value to 0
    | means that requests will never be terminated by Octane.
    |
    */

    'max_execution_time' => 30,
];
