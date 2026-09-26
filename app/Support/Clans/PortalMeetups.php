<?php

namespace App\Support\Clans;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Meetups of the EINUNDZWANZIG portal, for filling in a new clan (name, logo,
 * text, city, link, coordinates). Reads the public `GET /api/meetups` list
 * with intro and logo (`/api/meetup` needs a portal login and is not used).
 *
 * A successful list is cached; a failing portal is never cached and answers
 * null, so the create page can say "portal down" and the clan is still
 * created from the typed fields.
 */
final class PortalMeetups
{
    private const CACHE_KEY = 'portal:meetups';

    /**
     * Meetups whose name or city contains the query, best match first.
     *
     * @return list<array{id: int, name: string, city: string, country: string, url: string, logo: string|null, intro: string|null, latitude: float|null, longitude: float|null}>|null null when the portal is unreachable
     */
    public function search(string $query, int $limit = 5): ?array
    {
        $query = Str::lower(trim($query));

        if (mb_strlen($query) < 2) {
            return [];
        }

        $meetups = $this->all();

        if ($meetups === null) {
            return null;
        }

        $matches = array_filter($meetups, fn (array $meetup) => str_contains(Str::lower($meetup['name'].' '.$meetup['city']), $query));

        usort($matches, fn (array $a, array $b) => [! str_starts_with(Str::lower($a['city']), $query), $a['name']] <=> [! str_starts_with(Str::lower($b['city']), $query), $b['name']]);

        return array_slice($matches, 0, $limit);
    }

    /**
     * @return array{id: int, name: string, city: string, country: string, url: string, logo: string|null, intro: string|null, latitude: float|null, longitude: float|null}|null
     */
    public function find(int $id): ?array
    {
        foreach ($this->all() ?? [] as $meetup) {
            if ($meetup['id'] === $id) {
                return $meetup;
            }
        }

        return null;
    }

    /**
     * The meetup a clan links to (clans store the portal link, not the id).
     *
     * @return array{id: int, name: string, city: string, country: string, url: string, logo: string|null, intro: string|null, latitude: float|null, longitude: float|null}|null
     */
    public function findByUrl(string $url): ?array
    {
        foreach ($this->all() ?? [] as $meetup) {
            if ($meetup['url'] !== '' && $meetup['url'] === $url) {
                return $meetup;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, name: string, city: string, country: string, url: string, logo: string|null, intro: string|null, latitude: float|null, longitude: float|null}>|null
     */
    private function all(): ?array
    {
        /** @var list<array{id: int, name: string, city: string, country: string, url: string, logo: string|null, intro: string|null, latitude: float|null, longitude: float|null}>|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('esports.portal.timeout_seconds', 4))
                ->get((string) config('esports.portal.meetups_url'), ['withIntro' => 1, 'withLogos' => 1]);
        } catch (Throwable) {
            return null;
        }

        $rows = $response->successful() ? $response->json() : null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        $meetups = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_int($row['id'] ?? null) || ! is_string($row['name'] ?? null) || trim($row['name']) === '') {
                continue;
            }

            $meetups[] = [
                'id' => $row['id'],
                'name' => Str::limit(trim($row['name']), 64, ''),
                'city' => is_string($row['city'] ?? null) ? $row['city'] : '',
                'country' => is_string($row['country'] ?? null) ? $row['country'] : '',
                'url' => $this->httpUrl($row['portalLink'] ?? null) ?? '',
                'logo' => $this->httpUrl($row['logo'] ?? null),
                'intro' => is_string($row['intro'] ?? null) && trim(strip_tags($row['intro'])) !== ''
                    ? Str::limit(trim(html_entity_decode(strip_tags($row['intro']))), 1000, '')
                    : null,
                'latitude' => is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null,
                'longitude' => is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null,
            ];
        }

        Cache::put(self::CACHE_KEY, $meetups, now()->addMinutes((int) config('esports.portal.cache_minutes', 60)));

        return $meetups;
    }

    private function httpUrl(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^https?://#i', $value) === 1 && mb_strlen($value) <= 2048 ? $value : null;
    }
}
