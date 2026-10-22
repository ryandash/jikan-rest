<?php

use App\Providers\AppServiceProvider;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\WorkerErrorOccurred;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Listeners\EnsureUploadedFilesAreValid;
use Laravel\Octane\Listeners\EnsureUploadedFilesCanBeMoved;
use Laravel\Octane\Listeners\FlushOnce;
use Laravel\Octane\Listeners\FlushTemporaryContainerInstances;
use Laravel\Octane\Listeners\ReportException;
use Laravel\Octane\Listeners\StopWorkerIfNecessary;
use Laravel\Octane\Octane;

return [
    'server' => env('OCTANE_SERVER', 'roadrunner'),
    'https' => env('OCTANE_HTTPS', false),
    'listeners' => [
        WorkerStarting::class => [
            EnsureUploadedFilesAreValid::class,
            EnsureUploadedFilesCanBeMoved::class,
        ],
        RequestReceived::class => [
            ...Octane::prepareApplicationForNextOperation(),
            ...Octane::prepareApplicationForNextRequest(),
        ],
        OperationTerminated::class => [
            FlushOnce::class,
            FlushTemporaryContainerInstances::class,
        ],
        WorkerErrorOccurred::class => [
            ReportException::class,
            StopWorkerIfNecessary::class,
        ],
    ],
    'warm' => [
        ...Octane::defaultServicesToWarm(),
        ...AppServiceProvider::servicesToWarm(),
    ],
    'flush' => AppServiceProvider::servicesToClear(),
    'tables' => [],
    'cache' => [
        'rows' => 1000,
        'bytes' => 10000,
    ],
    'watch' => [],
    'garbage' => 50,
    'max_execution_time' => 30,
    'state_file' => env('OCTANE_STATE_FILE', storage_path('logs/octane-server-state.json')),
    'roadrunner' => [
        'command' => env('OCTANE_ROADRUNNER_COMMAND', 'vendor/bin/roadrunner-worker'),
        'http_middleware' => env('OCTANE_ROADRUNNER_HTTP_MIDDLEWARE', 'static'),
    ],
];
