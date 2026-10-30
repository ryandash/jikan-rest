<?php

namespace App\Console\Commands\Indexer;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MangaSweepIndexer extends Command
{
    protected $signature = 'indexer:manga-sweep';

    protected $description = 'Delete all removed manga';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Info: Delete removed MAL IDs\n\n");

        echo "Loading MAL IDs\n";
        $malIds = array_fill_keys($this->fetchMalIds(), true);

        echo "Loading MAL IDs from local DB\n";
        $results = DB::table('manga')->pluck('mal_id')->toArray();

        echo "Compare MAL IDs\n";
        $remove = [];

        foreach ($results as $malId) {
            if (!isset($malIds[(int) $malId])) {
                echo "Removing https://myanimelist.net/manga/".$malId."\n";
                $remove[] = (int) $malId;
            }
        }

        echo "Delete removed MAL IDs\n";
        if ($remove !== []) {
            DB::table('manga')->whereIn('mal_id', $remove)->delete();
        }
    }

    /**
     * @return array
     * @url https://github.com/purarue/mal-id-cache
     */
    private function fetchMalIds(): array
    {
        $this->info("Fetching MAL ID Cache https://raw.githubusercontent.com/purarue/mal-id-cache/master/cache/manga_cache.json...\n");

        $cache = json_decode(
            file_get_contents('https://raw.githubusercontent.com/purarue/mal-id-cache/master/cache/manga_cache.json'),
            true
        );

        $ids = array_merge($cache['sfw'], $cache['nsfw']);
        Storage::put('indexer/manga_mal_id_sweep.json', json_encode($ids));

        return $ids;
    }
}
