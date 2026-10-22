<?php

namespace App\Testing;

use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * @codeCoverageIgnore
 */
trait ScoutFlush
{
    protected array $searchIndexModelCleanupList = [
        "App\\Anime",
        "App\\Manga",
        "App\\Character",
        "App\\GenreAnime",
        "App\\GenreManga",
        "App\\Person",
        "App\\Club",
        "App\\Magazine",
        "App\\Producers",
        "App\\Profile",
    ];

    public function runScoutFlush(): void
    {
        if (config("scout.driver") === "typesense") {
            $typesenseClient = app(Client::class);
            foreach ($this->searchIndexModelCleanupList as $model) {
                $modelInstance = new $model;
                try {
                    $collection = $typesenseClient->getCollections()->{$modelInstance->searchableAs()};
                    $collection->retrieve();
                    $collection->delete();
                } catch (ObjectNotFound) {
                }
            }
        }
        else {
            foreach ($this->searchIndexModelCleanupList as $model) {
                $this->artisan("scout:flush", ["model" => $model]);
            }
        }
    }
}
