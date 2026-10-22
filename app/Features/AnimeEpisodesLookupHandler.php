<?php

namespace App\Features;

use App\Contracts\CachedScraperService;
use App\Contracts\UnitOfWork;
use App\Dto\AnimeEpisodesLookupCommand;
use App\Support\CachedData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Jikan\MyAnimeList\MalClient;
use Jikan\Request\Anime\AnimeEpisodesRequest;
use MongoDB\BSON\UTCDateTime;

final class AnimeEpisodesLookupHandler extends RequestHandlerWithScraperCache
{
    public function __construct(
        CachedScraperService $scraperService,
        private readonly UnitOfWork $unitOfWork
    ) {
        parent::__construct($scraperService);
    }

    /**
     * @inheritDoc
     */
    public function requestClass(): string
    {
        return AnimeEpisodesLookupCommand::class;
    }

    protected function getScraperData(string $requestFingerPrint, Collection $requestParams): CachedData
    {
        $id = $requestParams->get("id");

        $results = $this->scraperService->findList(
            $requestFingerPrint,
            fn (MalClient $jikan, ?int $page = null) => $jikan->getAnimeEpisodes(new AnimeEpisodesRequest($id, $page)),
            $requestParams->get("page", 1)
        );

        $data = $results->toArray();
        $animeId = (int) $id;

        if (isset($data["results"]) && \is_array($data["results"])) {
            foreach ($data["results"] as $index => $episode) {
                $episodeId = $episode["mal_id"] ?? null;
                if (!is_numeric($episodeId) || (int) $episodeId < 1) {
                    continue;
                }

                $episodeId = (int) $episodeId;
                $episodeRecord = $this->unitOfWork->documents("anime_episode")
                    ->where("request_hash", $this->episodeFingerprint($animeId, $episodeId))
                    ->first();
                $episodeDetails = $episodeRecord === null ? [] : (array) $episodeRecord;

                foreach (["url", "title", "title_japanese", "title_romanji", "duration", "aired", "filler", "recap", "synopsis"] as $field) {
                    if (array_key_exists($field, $episodeDetails)) {
                        $data["results"][$index][$field] = $episodeDetails[$field];
                    }
                }

                $data["results"][$index]["url"] ??= null;
                $data["results"][$index]["duration"] ??= null;
                $data["results"][$index]["synopsis"] ??= null;
            }

            $this->unitOfWork->documents("anime_episodes")
                ->where("request_hash", $requestFingerPrint)
                ->update([
                    "results" => $data["results"],
                    "modifiedAt" => new UTCDateTime((int) Carbon::now()->getPreciseTimestamp(3))
                ]);
        }

        return CachedData::fromArray($data);
    }

    private function episodeFingerprint(int $animeId, int $episodeId): string
    {
        $uri = \sprintf("/v4/anime/%d/episodes/%d", $animeId, $episodeId);

        return "request:anime:" . \sha1($uri);
    }
}
