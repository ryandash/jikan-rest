<?php

namespace App\Console\Commands\Indexer;

use App\Exceptions\Console\FileNotFoundException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;


/**
 * Class AnimeIndexer
 * @package App\Console\Commands\Indexer
 */
class AnimeIndexer extends Command
{
    /**
     * The name and signature of the console command.
     *`
     * @var string
     */
    protected $signature = 'indexer:anime
                            {--failed : Run only entries that failed to index last time}
                            {--resume : Resume from the last position}
                            {--skip-existing : Skip mal_ids that already exist in the database}
                            {--episodes : Fetch and cache episodes after each anime}
                            {--reverse : Start from the end of the array}
                            {--index=0 : Start from a specific index}
                            {--delay=3 : Set a delay between requests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Index all anime';

    /**
     * @var array
     */
    private array $ids;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->ids = [];
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws FileNotFoundException
     */
    public function handle()
    {

        $failed = $this->option('failed') ?? false;
        $resume = $this->option('resume') ?? false;
        $skipExisting = $this->option('skip-existing') ?? false;
        $indexEpisodes = $this->option('episodes') ?? false;
        $reverse = $this->option('reverse') ?? false;
        $delay = $this->option('delay') ?? 3;
        $index = $this->option('index') ?? 0;

        $index = (int)$index;
        $delay = (int)$delay;

        $this->info("Info: AnimeIndexer fetches MAL IDs from anime-offline-database and updates/indexes them\n\n");

        if ($failed && Storage::exists('indexer/indexer_anime.failed')) {
            $this->ids = $this->loadFailedMalIds();
        }

        if (!$failed) {
            $this->ids = $this->fetchMalIds();
        }

        // start from the end
        if ($reverse) {
            $this->ids = array_reverse($this->ids);
        }

        // Skip existing mal_ids in the database
        if ($skipExisting) {
            $existingMalIds = $this->getExistingMalIds();
            $this->ids = array_values(array_diff($this->ids, $existingMalIds));
            
            if (count($existingMalIds) > 0) {
                $this->info("Found " . count($existingMalIds) . " existing mal_ids in database");
                $this->info("Processing " . count($this->ids) . " missing mal_ids");
            }
        }

        // Resume
        if ($resume && Storage::exists('indexer/indexer_anime.save')) {
            $index = (int)Storage::get('indexer/indexer_anime.save');

            $this->info("Resuming from index: {$index}");
        }

        // check if index even exists
        if ($index > 0 && !isset($this->ids[$index])) {
            $index = 0;
            $this->warn('Invalid index; set back to 0');
        }

        // initialize and index
        Storage::put('indexer/indexer_anime.save', 0);

        echo "Loading MAL IDs\n";
        $count = count($this->ids);
        $failedIds = [];
        $success = [];

        echo "{$count} entries available\n";
        for ($i = $index; $i <= ($count - 1); $i++) {
            $id = $this->ids[$i];

            $url = env('APP_URL') . "/v4/anime/{$id}";

            echo "Indexing/Updating " . ($i + 1) . "/{$count} {$url} [MAL ID: {$id}] \n";

            try {
                $response = json_decode(file_get_contents($url), true);

                if (!is_array($response)) {
                    throw new \RuntimeException('Invalid API response');
                }

                if (isset($response['error']) && ($response['status'] ?? null) != 404) {
                    echo "[SKIPPED] Failed to fetch {$url} - {$response['error']}\n";
                    $failedIds[] = $id;
                } else {
                    $success[] = $id;

                    if ($indexEpisodes && !isset($response['error'])) {
                        $episodeStatus = $this->call('indexer:anime-episodes', [
                            'animeId' => $id,
                            '--delay' => $delay,
                        ]);

                        if ($episodeStatus !== self::SUCCESS) {
                            echo "[WARNING] Episode indexing failed for anime {$id}\n";
                        }
                    }
                }

                Storage::put('indexer/indexer_anime.failed', json_encode($failedIds));

                sleep($delay);
            } catch (\Exception $e) {
                echo "[SKIPPED] Failed to fetch {$url}\n";
                $failedIds[] = $id;
                Storage::put('indexer/indexer_anime.failed', json_encode($failedIds));
            }

            $success[] = $id;
            Storage::put('indexer/indexer_anime.save', $i);
        }

        Storage::delete('indexer/indexer_anime.save');

        echo "---------\nIndexing complete\n";
        echo count($success) . " entries indexed or updated\n";
        echo count($failedIds) . " entries failed to index or update. Re-run with --failed to requeue failed entries only\n";
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

        $this->info("Extracting MAL IDs...\n");

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

        // Extract unique IDs and sort numerically
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $ids = array_map('intval', $ids);

        // Save cache
        Storage::put(
            'indexer/anime_mal_id.json',
            json_encode($ids)
        );

        $this->info('MAL ID extraction complete!');
        $this->info('Total unique MAL IDs: ' . count($ids));

        return $ids;
    }

    /**
     * @return array
     * @throws FileNotFoundException
     */
    private function loadFailedMalIds() : array
    {
        if (!Storage::exists('indexer/indexer_anime.failed')) {
            throw new FileNotFoundException('"indexer/indexer_anime.failed" does not exist');
        }

        return json_decode(Storage::get('indexer/indexer_anime.failed'));
    }

    /**
     * Get all existing mal_ids from the database
     *
     * @return array
     */
    private function getExistingMalIds() : array
    {
        return \App\Anime::query()
            ->pluck('mal_id')
            ->toArray();
    }

}
