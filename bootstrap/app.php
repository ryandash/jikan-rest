<?php

use App\Console\Commands\CacheRemove;
use App\Console\Commands\Indexer\AnimeIndexer;
use App\Console\Commands\Indexer\AnimeRefreshCommand;
use App\Console\Commands\Indexer\AnimeScheduleIndexer;
use App\Console\Commands\Indexer\AnimeSweepIndexer;
use App\Console\Commands\Indexer\CommonIndexer;
use App\Console\Commands\Indexer\CurrentSeasonIndexer;
use App\Console\Commands\Indexer\GenreIndexer;
use App\Console\Commands\Indexer\IncrementalIndexer;
use App\Console\Commands\Indexer\MangaIndexer;
use App\Console\Commands\Indexer\MangaSweepIndexer;
use App\Console\Commands\Indexer\ProducersIndexer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Composer\InstalledVersions;

defined('JIKAN_PARSER_VERSION') or define('JIKAN_PARSER_VERSION', InstalledVersions::getPrettyVersion('jikan-me/jikan'));

$commonMiddleware = [
    'source-health-monitor',
    'microcaching',
    'cache-ttl',
];

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(using: function () use ($commonMiddleware): void {
        Route::prefix('v4')
            ->namespace('App\Http\Controllers\V4DB')
            ->middleware($commonMiddleware)
            ->group(base_path('routes/web.v4.php'));

        Route::get('/', function () {
            return response()->json([
                'author_url' => 'https://github.com/irfan-dahir',
                'discord_url' => 'http://discord.jikan.moe',
                'version' => env('APP_VERSION'),
                'parser_version' => JIKAN_PARSER_VERSION,
                'website_url' => 'https://jikan.moe',
                'documentation_url' => 'https://docs.api.jikan.moe/',
                'github_url' => 'https://github.com/jikan-me/jikan-rest',
                'parser_github_url' => 'https://github.com/jikan-me/jikan',
                'production_api_url' => 'https://api.jikan.moe/v4/',
                'status_url' => 'https://status.jikan.moe',
                'myanimelist_heartbeat' => [
                    'status' => \App\Providers\SourceHeartbeatProvider::getHeartbeatStatus(),
                    'score' => \App\Providers\SourceHeartbeatProvider::getHeartbeatScore(),
                    'down' => \App\Providers\SourceHeartbeatProvider::isFailoverEnabled(),
                    'last_downtime' => \App\Providers\SourceHeartbeatProvider::getLastDowntime(),
                ],
            ]);
        });

        foreach (['v1', 'v2', 'v3'] as $version) {
            Route::prefix($version)->group(function (): void {
                Route::get('/', function () {
                    return response()->json([
                        'status' => 400,
                        'type' => 'HttpException',
                        'message' => 'This version is discontinued. Please check the documentation for supported version(s).',
                        'error' => null,
                    ], 400);
                });
            });
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $globalMiddleware = [];

        if (env('INSIGHTS', false)) {
            $globalMiddleware[] = \App\Http\Middleware\Insights::class;
        }

        $middleware->remove(\Illuminate\Http\Middleware\HandleCors::class);

        if (env('CORS_MIDDLEWARE', false)) {
            $globalMiddleware[] = \App\Http\Middleware\CorsMiddleware::class;
        }

        $middleware->append($globalMiddleware);
        $middleware->alias([
            'microcaching' => \App\Http\Middleware\MicroCaching::class,
            'source-health-monitor' => \App\Http\Middleware\SourceHeartbeatMonitor::class,
            'cache-ttl' => \App\Http\Middleware\EndpointCacheTtlMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->expectsJson() || $request->is('v*'),
        );
    })
    ->withCommands([
        CacheRemove::class,
        CommonIndexer::class,
        AnimeScheduleIndexer::class,
        CurrentSeasonIndexer::class,
        AnimeIndexer::class,
        AnimeRefreshCommand::class,
        MangaIndexer::class,
        GenreIndexer::class,
        ProducersIndexer::class,
        AnimeSweepIndexer::class,
        MangaSweepIndexer::class,
        IncrementalIndexer::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('indexer:anime-schedule')->daily();
        $schedule->command('indexer:anime-current-season')->daily();
        $schedule->command('indexer:common')->daily();
        $schedule->command('indexer:genres')->daily();
        $schedule->command('indexer:producers')->daily();
        $schedule->command('indexer:anime-sweep')->daily();
        $schedule->command('indexer:manga-sweep')->daily();
    })
    ->create();
