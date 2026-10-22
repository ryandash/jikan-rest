<?php

namespace App\Console\Commands\Indexer;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class AnimeEpisodesIndexer extends Command
{
    protected $signature = 'indexer:anime-episodes
                            {animeId : MyAnimeList anime ID}
                            {--delay=3 : Delay between episode detail requests in seconds}';

    protected $description = 'Cache episode details for an anime in the background';

    public function handle(): int
    {
        $animeId = (int) $this->argument("animeId");
        $delay = max(0, (int) $this->option("delay"));
        $page = 1;
        $failedEpisodes = 0;

        do {
            $pageQuery = $page > 1 ? "?page={$page}" : "";
            $episodesUrl = rtrim((string) env("APP_URL"), "/")
                . "/v4/anime/{$animeId}/episodes{$pageQuery}";

            try {
                $response = $this->request($episodesUrl);
            } catch (ConnectionException $exception) {
                $this->error("Could not fetch episode list for anime {$animeId}: {$exception->getMessage()}");
                return self::FAILURE;
            }

            if (!$response->successful()) {
                $this->error("Episode list request failed for anime {$animeId} (HTTP {$response->status()}).");
                return self::FAILURE;
            }

            $episodes = $response->json("data", []);
            if (!is_array($episodes)) {
                $this->error("Episode list response for anime {$animeId} did not contain an episode array.");
                return self::FAILURE;
            }

            $this->info("Prefetching anime {$animeId} episode details (page {$page})...");
            foreach ($episodes as $episode) {
                if (!is_array($episode)) {
                    continue;
                }

                $episodeId = $episode["mal_id"] ?? null;
                if (!is_numeric($episodeId) || (int) $episodeId < 1) {
                    continue;
                }

                if ($delay > 0) {
                    sleep($delay);
                }

                $episodeUrl = rtrim((string) env("APP_URL"), "/")
                    . "/v4/anime/{$animeId}/episodes/" . (int) $episodeId;

                try {
                    $detailResponse = $this->request($episodeUrl);
                } catch (ConnectionException $exception) {
                    $this->warn("Episode {$episodeId} request failed: {$exception->getMessage()}");
                    $failedEpisodes++;
                    continue;
                }

                if (!$detailResponse->successful()) {
                    $this->warn("Episode {$episodeId} request failed (HTTP {$detailResponse->status()}).");
                    $failedEpisodes++;
                } else {
                    $this->line("  Cached episode {$episodeId}");
                }
            }

            try {
                $refreshResponse = $this->request($episodesUrl);
            } catch (ConnectionException $exception) {
                $this->error("Could not refresh episode list for anime {$animeId}: {$exception->getMessage()}");
                return self::FAILURE;
            }

            if (!$refreshResponse->successful()) {
                $this->error("Episode list refresh failed for anime {$animeId} (HTTP {$refreshResponse->status()}).");
                return self::FAILURE;
            }

            $lastPage = (int) $response->json("pagination.last_visible_page", 1);
            $page++;
        } while ($page <= $lastPage);

        return $failedEpisodes === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function request(string $url): \Illuminate\Http\Client\Response
    {
        return Http::withHeader("auth", (string) env("APP_KEY"))
            ->timeout(120)
            ->get($url);
    }
}
