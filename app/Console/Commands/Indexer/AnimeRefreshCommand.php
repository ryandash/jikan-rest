<?php

namespace App\Console\Commands\Indexer;

use App\Anime;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
        $delay = (int)$this->option('delay');

        $this->info('Starting anime refresh process...');
        $this->info("Delay: {$delay}s\n");

        try {
            $this->info("\nCalling indexer:anime-sweep");
            $this->call('indexer:anime-sweep');

            $this->info("\nGetting anime that are currently airing, recently finished (+ 1 month), or not yet aired...");
            $refreshMalIds = $this->getAnimeToRefresh();

            if (empty($refreshMalIds)) {
                $this->info('No anime to refresh.');
            } else {
                $this->info("Found " . count($refreshMalIds) . " anime to refresh\n");

                $totalAnime = count($refreshMalIds);
                $failedIds = [];
                $successCount = 0;

                foreach ($refreshMalIds as $index => $malId) {
                    $this->info("Processing anime " . ($index + 1) . "/{$totalAnime} (MAL ID: {$malId})");

                    try {
                        // Delete the anime record first
                        Anime::where('mal_id', $malId)->delete();
                        $this->info("  Deleted old data");

                        // Then fetch and refresh the anime
                        $url = env('APP_URL') . "/v4/anime/{$malId}";
                        $this->info("  Fetching anime {$malId}...");
                        $response = json_decode(@file_get_contents($url), true);

                        if ($response && !isset($response['error'])) {
                            $successCount++;
                            $this->info("  ✓ Refreshed successfully");
                        } else {
                            $errorMsg = $response['error'] ?? 'Unknown error';
                            $this->warn("  ✗ [FAILED] {$errorMsg}");
                            $failedIds[] = $malId;
                        }

                        sleep($delay);
                    } catch (\Exception $e) {
                        $this->warn("  ✗ [FAILED] " . $e->getMessage());
                        $failedIds[] = $malId;
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
    private function getAnimeToRefresh(): array
    {
        $oneMonthAgo = Carbon::now()->subMonth();
        $now = Carbon::now();

        // Get anime that meet any of these criteria:
        // 1. Are currently airing (airing == true)
        // 2. Finished airing within the last month (aired.to >= oneMonthAgo)
        // 3. Haven't aired yet (aired.from > now OR aired.from is null)
        $refreshAnime = Anime::where(function ($query) use ($oneMonthAgo, $now) {
            // Currently airing
            $query->where('airing', true)
                // OR finished airing within the last month
                ->orWhere(function ($q) use ($oneMonthAgo) {
                    $q->where('airing', false)
                      ->whereJsonPath('aired->to', '>=', $oneMonthAgo->toIso8601String());
                })
                // OR hasn't aired yet (future air date)
                ->orWhere(function ($q) use ($now) {
                    $q->where('airing', false)
                      ->whereJsonPath('aired->from', '>', $now->toIso8601String());
                })
                // OR has no air date set
                ->orWhere(function ($q) {
                    $q->where('airing', false)
                      ->whereJsonNull('aired->from');
                });
        })->pluck('mal_id')->toArray();

        return $refreshAnime;
    }
}
