<?php

namespace App\Console\Commands\Indexer;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class IncrementalIndexer extends Command
{
    /**
     * @var bool
     */
    private bool $cancelled = false;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'indexer:incremental {mediaType*}
    {--delay=3 : Set a delay between requests}
    {--resume : Resume from the last position}
    {--failed : Run only entries that failed to index last time}';

    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'mediaType' => ['The media type to index.', 'Valid values: anime, manga']
        ];
    }

    private function getExistingIds(string $mediaType): array
    {
        $existingIdsHash = "";
        $existingIdsRaw = "";

        if (Storage::exists("indexer/incremental/$mediaType.json"))
        {
            $existingIdsRaw = Storage::get("indexer/incremental/$mediaType.json");
            $existingIdsHash = sha1($existingIdsRaw);
        }

        return [$existingIdsHash, $existingIdsRaw];
    }

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

                if ($this->cancelled) {
                    return [];
                }

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

        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        $ids = array_map('intval', $ids);

        $this->info('MAL ID extraction complete!');
        $this->info('Total unique MAL IDs: ' . count($ids));

        return $ids;
    }

    private function getIdsToFetch(string $mediaType): array
    {
        [$existingIdsHash, $existingIdsRaw] = $this->getExistingIds($mediaType);

        if ($this->cancelled) {
            return [];
        }

        if ($mediaType === 'anime') {
            // Fetch IDs from the new Anime Offline Database
            $newIds = $this->fetchMalIds();
            $newIdsRaw = json_encode($newIds);

            // Support both the old sfw/nsfw cache and the new flat cache
            $existingIds = json_decode($existingIdsRaw, true) ?? [];

            if (isset($existingIds['sfw']) || isset($existingIds['nsfw'])) {
                $existingIds = array_merge(
                    $existingIds['sfw'] ?? [],
                    $existingIds['nsfw'] ?? []
                );
            }

            $idsToFetch = [
                'sfw' => array_values(array_diff($newIds, $existingIds)),
                'nsfw' => []
            ];
        } else {
            // Manga still uses the existing MAL ID cache
            $url = "https://raw.githubusercontent.com/purarue/mal-id-cache/master/cache/{$mediaType}_cache.json";

            $newIdsRaw = file_get_contents($url);

            if ($newIdsRaw === false) {
                throw new \RuntimeException("Failed to download MAL IDs for {$mediaType}");
            }

            $newIds = json_decode($newIdsRaw, true);

            if (!is_array($newIds)) {
                throw new \RuntimeException("Invalid MAL ID response for {$mediaType}");
            }

            $existingIds = json_decode($existingIdsRaw, true) ?? [];

            $idsToFetch = [];

            foreach (['sfw', 'nsfw'] as $type) {
                $idsToFetch[$type] = array_values(array_diff(
                    $newIds[$type] ?? [],
                    $existingIds[$type] ?? []
                ));
            }
        }

        if ($this->cancelled) {
            return [];
        }

        $newIdsHash = sha1($newIdsRaw);

        if ($newIdsHash !== $existingIdsHash) {
            Storage::put(
                "indexer/incremental/{$mediaType}.json.tmp",
                $newIdsRaw
            );
        } else {
            return [
                'sfw' => [],
                'nsfw' => []
            ];
        }

        return $idsToFetch;
    }

    private function getFailedIdsToFetch(string $mediaType): array
    {
        return json_decode(Storage::get("indexer/incremental/{$mediaType}_failed.json"));
    }

    private function fetchIds(string $mediaType, array $idsToFetch, bool $resume): void
    {
        $index = 0;
        $success = [];
        $failedIds = [];

        $ids = array_merge(
            $idsToFetch['sfw'] ?? [],
            $idsToFetch['nsfw'] ?? []
        );

        $idCount = count($ids);

        if ($resume && Storage::exists("indexer/incremental/{$mediaType}_resume.save")) {
            $index = (int) Storage::get("indexer/incremental/{$mediaType}_resume.save");
            $this->info("Resuming from index: $index");
        }

        if ($index > 0 && !isset($ids[$index])) {
            $index = 0;
            $this->warn('Invalid index; set back to 0');
        }

        Storage::put("indexer/incremental/{$mediaType}_resume.save", 0);

        $this->info("$idCount $mediaType entries available");

        for ($i = $index; $i <= ($idCount - 1); $i++)
        {
            if ($this->cancelled)
            {
                return;
            }

            $id = $ids[$i];

            $url = env('APP_URL') . "/v4/$mediaType/$id";
            $this->info("Indexing/Updating " . ($i + 1) . "/$idCount $url [MAL ID: $id]");

            try
            {
                $response = json_decode(file_get_contents($url), true);
                if (!isset($response['error']) || $response['status'] == 404)
                {
                    continue;
                }

                $this->error("[SKIPPED] Failed to fetch $url - {$response['error']}");
            }
            catch (\Exception)
            {
                $this->warn("[SKIPPED] Failed to fetch $url");
                $failedIds[] = $id;
                Storage::put("indexer/incremental/$mediaType.failed", json_encode($failedIds));
            }

            $success[] = $id;
            Storage::put("indexer/incremental/{$mediaType}_resume.save", $i);
        }

        Storage::delete("indexer/incremental/{$mediaType}_resume.save");

        $this->info("--- Indexing of $mediaType is complete.");
        $this->info(count($success) . ' entries indexed or updated.');
        if (count($failedIds) > 0)
        {
            $this->info(count($failedIds) . ' entries failed to index or update. Re-run with --failed to requeue failed entries only.');
        }

        // finalize the latest state
        Storage::move("indexer/incremental/$mediaType.json.tmp", "indexer/incremental/$mediaType.json");
    }

    public function handle(): int
    {
        // validate inputs
        $validator = Validator::make(
            [
                'mediaType' => $this->argument('mediaType'),
                'delay' => $this->option('delay'),
                'resume' => $this->option('resume') ?? false,
                'failed' => $this->option('failed') ?? false
            ],
            [
                'mediaType' => 'required|in:anime,manga',
                'delay' => 'integer|min:1',
                'resume' => 'bool|prohibited_with:failed',
                'failed' => 'bool|prohibited_with:resume'
            ]
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->toJson());
            return 1;
        }

        // we want to handle signals from the OS
        $this->trap([SIGTERM, SIGQUIT, SIGINT], fn () => $this->cancelled = true);

        $resume = $this->option('resume') ?? false;
        $onlyFailed = $this->option('failed') ?? false;

        /**
         * @var $mediaTypes array
         */
        $mediaTypes = $this->argument("mediaType");

        foreach ($mediaTypes as $mediaType)
        {
            $idsToFetch = [];

            // if "--failed" option is specified just run the failed ones
            if ($onlyFailed && Storage::exists("indexer/incremental/{$mediaType}_failed.json"))
            {
                $idsToFetch["sfw"] = $this->getFailedIdsToFetch($mediaType);
            }
            else
            {
                $idsToFetch = $this->getIdsToFetch($mediaType);
            }

            if ($this->cancelled)
            {
                return 127;
            }

            $idCount = count($idsToFetch['sfw'] ?? [])
                + count($idsToFetch['nsfw'] ?? []);

            if ($idCount === 0) {
                $this->info("No new $mediaType entries to index.");
                continue;
            }

            $this->fetchIds($mediaType, $idsToFetch, $resume);
        }

        return 0;
    }
}
