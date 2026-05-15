<?php

namespace App\Console\Commands;

use App\Anime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class AnimeUpdateSidecar extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sidecar:anime-update 
                            {--airing-months=1 : Number of months after airing end to stop updating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sidecar service that runs daily: updates anime, runs AnimeSweepIndexer, then AnimeIndexer';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $airingMonths = (int)$this->option('airing-months');

        Log::info('Anime Update Sidecar started', [
            'airing_months' => $airingMonths
        ]);

        $this->info("Anime Update Sidecar starting");

        try {
            // Step 1: Update all anime that need updating
            $this->info("\n=== STEP 1: Updating Anime ===");
            $this->updateAllAiringAnime($airingMonths);

            // Step 2: Run AnimeSweepIndexer
            $this->info("\n=== STEP 2: Running AnimeSweepIndexer ===");
            $this->call('indexer:anime-sweep');

            // Step 3: Run AnimeIndexer starting from last mal_id in database
            $this->info("\n=== STEP 3: Running AnimeIndexer ===");
            $this->call('indexer:anime', [
                '--last-from-db' => true
            ]);

            Log::info('Anime Update Sidecar daily cycle completed successfully');
            $this->info("\n✓ Daily update cycle completed successfully");
            
            return 0;

        } catch (Exception $e) {
            Log::error('Error in anime update sidecar daily cycle', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error('Error during daily cycle: ' . $e->getMessage());
            
            return 1;
        }
    }

    /**
     * Update all anime that should be refreshed based on airing status
     *
     * @param int $airingMonths
     * @return void
     */
    private function updateAllAiringAnime(int $airingMonths): void
    {
        // Calculate the cutoff date: anime that finished airing more than X months ago
        $cutoffDate = CarbonImmutable::now()->subMonths($airingMonths);

        $this->info("Fetching all anime to update (cutoff date: {$cutoffDate->toDateString()})...");

        // Query anime that are either:
        // 1. Currently airing (airing = true)
        // 2. Finished airing less than X months ago (aired.to is after cutoffDate)
        $animeToUpdate = Anime::query()
            ->where(function ($query) use ($cutoffDate) {
                // Currently airing
                $query->where('airing', true)
                    // OR finished airing recently
                    ->orWhere('aired.to', '>=', $cutoffDate->toAtomString());
            })
            ->get(['mal_id', 'airing', 'aired']);

        $totalCount = $animeToUpdate->count();
        
        if ($totalCount === 0) {
            $this->info("No anime to update");
            return;
        }

        $this->info("Found {$totalCount} anime to update");

        $updateCount = 0;
        $skipCount = 0;
        $failureCount = 0;

        foreach ($animeToUpdate as $anime) {
            try {
                // Check if anime has finished airing more than X months ago
                if (!$anime->airing && isset($anime->aired['to'])) {
                    try {
                        $airedTo = CarbonImmutable::parse($anime->aired['to']);
                        if ($airedTo < $cutoffDate) {
                            // Skip this anime - it finished airing more than X months ago
                            $skipCount++;
                            continue;
                        }
                    } catch (Exception $e) {
                        Log::warning("Could not parse aired date for anime {$anime->mal_id}", [
                            'aired_to' => $anime->aired['to'] ?? null,
                            'error' => $e->getMessage()
                        ]);
                        $skipCount++;
                        continue;
                    }
                }

                // Fetch fresh data from Jikan API
                $freshData = Anime::scrape($anime->mal_id);

                log::info("Fetched fresh data for anime", [
                    'mal_id' => $anime->mal_id,
                    'airing' => $freshData['airing'] ?? null
                ]);

                // Update the anime record
                Anime::query()
                    ->where('mal_id', $anime->mal_id)
                    ->update([
                        'url' => $freshData['url'] ?? null,
                        'title' => $freshData['title'] ?? null,
                        'title_english' => $freshData['title_english'] ?? null,
                        'title_japanese' => $freshData['title_japanese'] ?? null,
                        'title_synonyms' => $freshData['title_synonyms'] ?? [],
                        'titles' => $freshData['titles'] ?? [],
                        'images' => $freshData['images'] ?? [],
                        'type' => $freshData['type'] ?? null,
                        'source' => $freshData['source'] ?? null,
                        'episodes' => $freshData['episodes'] ?? null,
                        'status' => $freshData['status'] ?? null,
                        'airing' => $freshData['airing'] ?? false,
                        'aired' => $freshData['aired'] ?? [],
                        'duration' => $freshData['duration'] ?? null,
                        'rating' => $freshData['rating'] ?? null,
                        'score' => $freshData['score'] ?? null,
                        'scored_by' => $freshData['scored_by'] ?? null,
                        'rank' => $freshData['rank'] ?? null,
                        'popularity' => $freshData['popularity'] ?? null,
                        'members' => $freshData['members'] ?? null,
                        'favorites' => $freshData['favorites'] ?? null,
                        'synopsis' => $freshData['synopsis'] ?? null,
                        'background' => $freshData['background'] ?? null,
                        'broadcast' => $freshData['broadcast'] ?? [],
                        'related' => $freshData['related'] ?? [],
                        'producers' => $freshData['producers'] ?? [],
                        'licensors' => $freshData['licensors'] ?? [],
                        'studios' => $freshData['studios'] ?? [],
                        'genres' => $freshData['genres'] ?? [],
                        'explicit_genres' => $freshData['explicit_genres'] ?? [],
                        'themes' => $freshData['themes'] ?? [],
                        'demographics' => $freshData['demographics'] ?? [],
                        'opening_themes' => $freshData['opening_themes'] ?? [],
                        'ending_themes' => $freshData['ending_themes'] ?? [],
                        'trailer' => $freshData['trailer'] ?? [],
                        'approved' => $freshData['approved'] ?? false,
                        'modifiedAt' => Carbon::now(),
                    ]);

                $updateCount++;
                $this->line("  ✓ Updated anime {$anime->mal_id}");

                Log::info("Updated anime in sidecar", [
                    'mal_id' => $anime->mal_id,
                    'airing' => $freshData['airing'] ?? false
                ]);

            } catch (Exception $e) {
                $failureCount++;
                Log::error('Failed to update anime', [
                    'mal_id' => $anime->mal_id,
                    'error' => $e->getMessage()
                ]);
                $this->warn("  ✗ Failed to update anime {$anime->mal_id}: {$e->getMessage()}");
            }
            sleep(1); // Sleep 1 second between updates to avoid overwhelming the database
        }

        $this->info("Update complete - Updated: {$updateCount}, Skipped: {$skipCount}, Failed: {$failureCount}");
    }
}
