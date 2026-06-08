<?php

namespace App\Console\Commands\Indexer;

use App\Anime;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Class AnimeRefreshCommand
 * Refreshes anime data that finished airing + 1 month ago or newer
 *
 * @package App\Console\Commands\Indexer
 */
class AnimeRefreshCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indexer:anime-refresh
                            {--batch-size=100 : Number of anime to process in each batch}
                            {--delay=3 : Delay between API requests in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh anime data for currently airing, recently finished (+ 1 month), or not yet aired anime';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $batchSize = (int)$this->option('batch-size');
        $delay = (int)$this->option('delay');

        $this->info('Starting anime refresh process...');
        $this->info("Batch size: {$batchSize}, Delay: {$delay}s\n");

        try {
            $this->info("\nCalling indexer:anime-sweep");
            $this->call('indexer:anime-sweep');

            $this->info("\nGetting anime that are currently airing, recently finished (+ 1 month), or not yet aired...");
            $refreshMalIds = $this->getAnimaToRefresh();

            if (empty($refreshMalIds)) {
                $this->info('No anime to refresh.');
            } else {
                $this->info("Found " . count($refreshMalIds) . " anime to refresh\n");

                // Delete old data for these anime
                $this->info('Deleting old anime data...');
                $deleteCount = Anime::whereIn('mal_id', $refreshMalIds)->delete();
                $this->info("Deleted {$deleteCount} anime records\n");

                // Process in batches
                $batches = array_chunk($refreshMalIds, $batchSize);
                $totalBatches = count($batches);

                $this->info("Processing " . count($refreshMalIds) . " anime in {$totalBatches} batches...\n");

                $failedIds = [];
                $successCount = 0;

                foreach ($batches as $batchIndex => $batch) {
                    $this->info("Processing batch " . ($batchIndex + 1) . "/{$totalBatches}");

                    foreach ($batch as $malId) {
                        $url = env('APP_URL') . "/v4/anime/{$malId}";

                        try {
                            $this->info("  Fetching anime {$malId}...");
                            $response = json_decode(@file_get_contents($url), true);

                            if ($response && !isset($response['error'])) {
                                $successCount++;
                            } else {
                                $errorMsg = $response['error'] ?? 'Unknown error';
                                $this->warn("    [FAILED] {$errorMsg}");
                                $failedIds[] = $malId;
                            }

                            sleep($delay);
                        } catch (\Exception $e) {
                            $this->warn("    [FAILED] " . $e->getMessage());
                            $failedIds[] = $malId;
                        }
                    }
                }

                $this->info("\n" . str_repeat("=", 50));
                $this->info("Refresh completed!");
                $this->info("Success: {$successCount}");
                $this->info("Failed: " . count($failedIds));

                if (!empty($failedIds)) {
                    $this->warn("\nFailed MAL IDs: " . implode(', ', $failedIds));
                }
            }

            // Call indexer:anime with --skip-existing to fill in any missing data
            $this->info("\nCalling indexer:anime --skip-existing to fill missing data...");
            $this->call('indexer:anime', [
                '--skip-existing' => true,
                '--delay' => $delay,
            ]);

            return 0;
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            Log::error('AnimeRefreshCommand error: ' . $e->getMessage(), $e->getTrace());
            return 1;
        }
    }

    /**
     * Get array of MAL IDs for anime that are:
     * - Currently airing (airing == true)
     * - Recently finished (within the last month)
     * - Not yet aired (future air dates)
     *
     * @return array
     */
    private function getAnimaToRefresh(): array
    {
        $oneMonthAgo = Carbon::now()->subMonth();
        $now = Carbon::now();

        // Get anime that meet any of these criteria:
        // 1. Are currently airing (airing == true)
        // 2. Finished airing within the last month (aired.to >= oneMonthAgo)
        // 3. Haven't aired yet (aired.from > now OR aired.from is null)
        $refreshAnime = DB::table('anime')
            ->select('mal_id', 'aired', 'airing', 'status', 'title')
            ->where(function ($query) use ($oneMonthAgo, $now) {
                // Currently airing
                $query->where('airing', true)
                    // OR finished airing within the last month
                    ->orWhere(function ($q) use ($oneMonthAgo) {
                        $q->where('airing', false)
                          ->where('aired.to', '>=', $oneMonthAgo->toIso8601String());
                    })
                    // OR hasn't aired yet (future air date)
                    ->orWhere(function ($q) use ($now) {
                        $q->where('airing', false)
                          ->where('aired.from', '>', $now->toIso8601String());
                    })
                    // OR has no air date set
                    ->orWhere(function ($q) {
                        $q->where('airing', false)
                          ->whereNull('aired.from');
                    });
            })
            ->get();

        $malIds = [];
        foreach ($refreshAnime as $anime) {
            $malIds[] = $anime->mal_id;
        }

        return $malIds;
    }
}
