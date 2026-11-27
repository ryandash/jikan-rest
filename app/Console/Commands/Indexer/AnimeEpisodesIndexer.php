<?php

namespace App\\Console\\Commands\\Indexer;

use App\\Contracts\\UnitOfWork;
use Illuminate\\Console\\Command;
use Illuminate\\Http\\Client\\ConnectionException;
use Illuminate\\Support\\Collection;
use Illuminate\\Support\\Facades\\Http;

final class AnimeEpisodesIndexer extends Command
{
    protected $signature = 'indexer:anime-episodes
                            {animeId : MyAnimeList anime ID}
                            {--delay=3 : Delay between episode detail requests in seconds}';

    protected $description = 'Cache missing episode details for an anime in the background';

    private const EPISODE_DETAIL_FIELDS = [
        'mal_id',
        'url',
        'title',
        'title_japanese',
        'title_romanji',
        'duration',
        'aired',
        'filler',
        'recap',
        'synopsis',
    ];

    public function __construct(private readonly UnitOfWork $unitOfWork)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $animeId = (int) $this->argument('animeId');
        $delay = max(0, (int) $this->option('delay'));
        $episodeDocuments = $this->unitOfWork->documents('anime_episode');
        $page = 1;
        $lastPage = 1;
        $failedEpisodes = 0;
        $requestedEpisodes = 0;
        $skippedEpisodes = 0;

        do {
            $episodesUrl = $this->episodesUrl($animeId, $page);

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

            $episodes = $response->json('data', []);
            if (!is_array($episodes)) {
                $this->error("Episode list response for anime {$animeId} did not contain an episode array.");
                return self::FAILURE;
            }

            $episodeFingerprints = [];
            $episodeIds = [];

            foreach ($episodes as $episode) {
                if (!is_array($episode)) {
                    continue;
                }

                $episodeId = $episode['mal_id'] ?? null;
                if (!is_numeric($episodeId) || (int) $episodeId < 1) {
                    continue;
                }

                $episodeId = (int) $episodeId;
                $episodeIds[$episodeId] = $episodeId;
                $episodeFingerprints[$episodeId] = $this->episodeFingerprint($animeId, $episodeId);
            }

            // Read the cached detail documents for this page in one database query.
            $cachedEpisodes = $episodeFingerprints === []
                ? collect()
                : $episodeDocuments->whereIn('request_hash', array_values($episodeFingerprints))->get();

            $cachedByFingerprint = $cachedEpisodes->keyBy('request_hash');

            $episodesNeedingDetails = [];
            foreach ($episodeFingerprints as $episodeId => $fingerprint) {
                $cachedEpisode = $cachedByFingerprint->get($fingerprint);
                $cachedEpisode = $this->toArray($cachedEpisode);

                if ($this->hasCompleteEpisodeDetails($cachedEpisode)) {
                    $skippedEpisodes++;
                    continue;
                }

                $episodesNeedingDetails[] = [
                    'mal_id' => $episodeId,
                    'request_hash' => $fingerprint,
                ];
            }

            $this->info(sprintf(
                'Anime %d page %d: %d listed, %d already complete, %d need details.',
                $animeId,
                $page,
                count($episodeIds),
                count($episodeIds) - count($episodesNeedingDetails),
                count($episodesNeedingDetails)
            ));

            foreach ($episodesNeedingDetails as $episode) {
                if ($delay > 0) {
                    sleep($delay);
                }

                // Remove a partial cache document so the normal detail endpoint fetches fresh MAL data.
                $episodeDocuments->where('request_hash', $episode['request_hash'])->delete();

                $episodeUrl = $this->episodeUrl($animeId, $episode['mal_id']);

                try {
                    $detailResponse = $this->request($episodeUrl);
                } catch (ConnectionException $exception) {
                    $this->warn("Episode {$episode['mal_id']} request failed: {$exception->getMessage()}");
                    $failedEpisodes++;
                    continue;
                }

                if (!$detailResponse->successful()) {
                    $this->warn("Episode {$episode['mal_id']} request failed (HTTP {$detailResponse->status()}).");
                    $failedEpisodes++;
                    continue;
                }

                $requestedEpisodes++;
                $this->line("  Cached episode {$episode['mal_id']}");
            }

            $lastPage = max(1, (int) $response->json('pagination.last_visible_page', 1));
            $page++;
        } while ($page <= $lastPage);

        $this->info("Episode detail requests: {$requestedEpisodes}; complete episodes skipped: {$skippedEpisodes}; failures: {$failedEpisodes}");

        return $failedEpisodes === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function episodesUrl(int $animeId, int $page): string
    {
        $query = $page > 1 ? "?page={$page}" : '';

        return rtrim((string) env('APP_URL'), '/')
            . "/v4/anime/{$animeId}/episodes{$query}";
    }

    private function episodeUrl(int $animeId, int $episodeId): string
    {
        return rtrim((string) env('APP_URL'), '/')
            . "/v4/anime/{$animeId}/episodes/{$episodeId}";
    }

    private function episodeFingerprint(int $animeId, int $episodeId): string
    {
        $uri = sprintf('/v4/anime/%d/episodes/%d', $animeId, $episodeId);

        return 'request:anime:' . sha1($uri);
    }

    private function hasCompleteEpisodeDetails(array $episode): bool
    {
        foreach (self::EPISODE_DETAIL_FIELDS as $field) {
            if (!array_key_exists($field, $episode)) {
                return false;
            }
        }

        return !empty($episode['title']) && !empty($episode['url']);
    }

    private function toArray(mixed $document): array
    {
        if ($document instanceof Collection) {
            return $document->all();
        }

        if (is_object($document)) {
            return get_object_vars($document);
        }

        return is_array($document) ? $document : [];
    }

    private function request(string $url): \\Illuminate\\Http\\Client\\Response
    {
        return Http::withHeader('auth', (string) env('APP_KEY'))
            ->timeout(120)
            ->get($url);
    }
}
