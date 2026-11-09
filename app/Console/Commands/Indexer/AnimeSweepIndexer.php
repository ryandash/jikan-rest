
<?php

namespace App\Console\Commands\Indexer;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnimeSweepIndexer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indexer:anime-sweep';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all removed anime';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info("Info: Delete removed MAL IDs\n\n");

        $this->info("Loading MAL IDs from Anime Offline Database");

        $malIds = $this->fetchMalIds();

        // Prevent accidental deletion if the download or extraction failed.
        if (count($malIds) === 0) {
            $this->error('No MAL IDs were retrieved. Aborting sweep to prevent accidental deletion.');
            return self::FAILURE;
        }

        $malIds = array_fill_keys($malIds, true);

        $this->info("Loading MAL IDs from local DB");

        $results = DB::table('anime')->pluck('mal_id')->toArray();

        $this->info("Comparing MAL IDs");

        $remove = [];

        foreach ($results as $malId) {
            if (!isset($malIds[(int) $malId])) {
                $this->line("Removing https://myanimelist.net/anime/{$malId}");
                $remove[] = (int) $malId;
            }
        }

        $this->info("\n\nFound " . count($remove) . " removed anime");

        if ($remove !== []) {
            $this->info("\nDeleting removed MAL IDs");

            DB::table('anime')
                ->whereIn('mal_id', $remove)
                ->delete();
        }

        $this->info("Anime sweep complete.");

        return self::SUCCESS;
    }

    /**
     * Fetch all available MAL IDs from anime-offline-database.
     *
     * @return array
     */
    private function fetchMalIds(): array
    {
        $url = 'https://github.com/cedya77/anime-offline-database/releases/download/latest/anime-offline-database.jsonl';

        $this->info("Fetching Anime Offline Database...\n");
        $this->info($url);

        $handle = fopen($url, 'r');

        if ($handle === false) {
            throw new \RuntimeException('Failed to download anime database');
        }

        $ids = [];
        $lineNumber = 0;

        $this->info("\nExtracting MAL IDs...\n");

        try {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                if (trim($line) === '') {
                    continue;
                }

                $anime = json_decode($line, true);

                if (!is_array($anime)) {
                    $this->warn('Invalid JSON on line ' . $lineNumber);
                    continue;
                }

                foreach (($anime['sources'] ?? []) as $source) {
                    if (!is_string($source)) {
                        continue;
                    }

                    if (preg_match(
                        '~myanimelist\.net/anime/(\d+)~i',
                        $source,
                        $matches
                    )) {
                        $ids[$matches[1]] = true;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        // Extract unique IDs and sort numerically.
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $ids = array_map('intval', $ids);

        $this->info('MAL ID extraction complete!');
        $this->info('Total unique MAL IDs: ' . count($ids));

        return $ids;
    }
}
