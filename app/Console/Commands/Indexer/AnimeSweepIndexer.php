<?php

namespace App\Console\Commands\Indexer;

use App\Exceptions\Console\CommandAlreadyRunningException;
use App\Exceptions\Console\FileNotFoundException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Class AnimeSweepIndexer
 *
 * @package App\Console\Commands\Indexer
 */
class AnimeSweepIndexer extends Command
{
    /**
     * The name and signature of the console command.
     *`
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
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws FileNotFoundException
     */
    public function handle()
    {
        $this->info("Info: Delete removed MAL IDs\n\n");

        echo "Loading MAL IDs\n";
        $malIds = array_fill_keys($this->fetchMalIds(), true);
        if (empty($malIds)) {
            $this->error('No MAL IDs retrieved. Aborting sweep.');
            return 1;
        }
        
        echo "Loading MAL IDs from local DB\n";
        $results = DB::table('anime')->pluck('mal_id')->toArray();

        echo "Compare MAL IDs\n";
        $remove = [];

        foreach ($results as $malId) {
            if (!isset($malIds[(int) $malId])) {
                echo "Removing https://myanimelist.net/anime/".$malId."\n";
                $remove[] = (int) $malId;
            }
        }

        echo "\n\nRemoved ".count($remove)." anime\n";

        echo "\nDelete removed MAL IDs\n";
        if ($remove !== []) {
            DB::table('anime')->whereIn('mal_id', $remove)->delete();
        }
    }

    /**
     * @return array
     * @url https://github.com/cedya77/anime-offline-database
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

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }

                $anime = json_decode($line, true);

                if (!is_array($anime)) {
                    continue;
                }

                foreach (($anime['sources'] ?? []) as $source) {
                    if (
                        is_string($source) &&
                        preg_match('~myanimelist\.net/anime/(\d+)~i', $source, $matches)
                    ) {
                        $ids[$matches[1]] = true;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $ids = array_map('intval', $ids);

        Storage::put('indexer/anime_mal_id_sweep.json', json_encode($ids));

        return $ids;
    }
}
