<?php

namespace App\Support\Seo;

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Support\Nostr\PlayerProfile;
use Carbon\CarbonInterface;

/**
 * schema.org nodes for the JSON-LD graph of a page (P14), built only from
 * what the page itself shows: a field the league does not know is left out,
 * never filled with a guess. partials/head.blade.php prints them as one
 * `@graph`, so the nodes here carry no `@context` of their own.
 */
final class StructuredData
{
    /**
     * TWENTY ONE esports, the esports arm of EINUNDZWANZIG (config/twentyone.php,
     * README.md). On every page.
     *
     * @return array<string, mixed>
     */
    public static function organization(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => self::organizationId(),
            'name' => 'TWENTY ONE esports',
            'url' => route('home'),
            'logo' => asset('images/twentyone/avatar-1024.png'),
            'description' => __('The esports arm of the German-speaking Bitcoin community EINUNDZWANZIG.'),
            'parentOrganization' => [
                '@type' => 'Organization',
                'name' => 'EINUNDZWANZIG',
                'url' => 'https://einundzwanzig.space',
            ],
        ];
    }

    public static function organizationId(): string
    {
        return route('home').'#organization';
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items  [name, url] from the root to the page
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        $elements = [];

        foreach ($items as $index => [$name, $url]) {
            $elements[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $name, 'item' => $url];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    /**
     * The player page: a ProfilePage about one Person.
     *
     * @return array<string, mixed>
     */
    public static function profilePage(PlayerProfile $profile, string $url): array
    {
        $person = array_filter([
            '@type' => 'Person',
            'name' => $profile->name,
            'url' => $url,
            'identifier' => $profile->user->npub,
            'image' => $profile->picture ?? $profile->generatedAvatar,
            'description' => filled($profile->about) ? $profile->about : null,
            'sameAs' => $profile->website !== null ? [$profile->website] : null,
            'memberOf' => $profile->clan === null ? null : [
                '@type' => 'SportsTeam',
                'name' => $profile->clan->name,
                'url' => route('clans.show', $profile->clan),
            ],
        ], fn (mixed $value): bool => $value !== null);

        return array_filter([
            '@type' => 'ProfilePage',
            'url' => $url,
            'dateCreated' => $profile->user->created_at?->toIso8601String(),
            'mainEntity' => $person,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * A Rocket League series between two clan lineups.
     *
     * @return array<string, mixed>
     */
    public static function series(SeriesMatch $match, string $url): array
    {
        $competitors = [];

        foreach (SeriesMatch::SIDES as $side) {
            $clan = $match->lineup($side)?->clan;
            $competitors[] = array_filter([
                '@type' => 'SportsTeam',
                'name' => $match->sideName($side),
                'url' => $clan === null ? null : route('clans.show', $clan),
            ], fn (mixed $value): bool => $value !== null);
        }

        return self::sportsEvent(
            name: __('Series :number', ['number' => $match->label()]).': '.$match->challenger_name.' vs '.$match->challenged_name,
            url: $url,
            sport: 'Rocket League',
            start: $match->start_at,
            end: $match->finished_at,
            cancelled: in_array($match->status, [SeriesStatus::Declined, SeriesStatus::Withdrawn, SeriesStatus::Expired], true),
            competitors: $competitors,
        );
    }

    /**
     * A chess game (blitz or daily) between two players. A chess game starts
     * when it is created (pages/matches/index).
     *
     * @return array<string, mixed>
     */
    public static function chessGame(ChessGame $game, string $name, string $url): array
    {
        $competitors = [];

        // A deleted account stays in the game as "Deleted player", without a player page.
        foreach ([$game->white, $game->black] as $player) {
            $competitors[] = $player->exists
                ? ['@type' => 'Person', 'name' => $player->displayName(), 'url' => route('players.show', $player->npub)]
                : ['@type' => 'Person', 'name' => $player->displayName()];
        }

        return self::sportsEvent(
            name: $name,
            url: $url,
            sport: __('Chess'),
            start: $game->created_at,
            end: $game->ended_at,
            cancelled: $game->status === ChessGameStatus::Aborted,
            competitors: $competitors,
        );
    }

    /**
     * An online SportsEvent organized by the league. The building block of
     * series() and chessGame(), and the hook for the tournament pages (P8b).
     *
     * @param  list<array<string, mixed>>  $competitors  SportsTeam or Person nodes
     * @return array<string, mixed>
     */
    public static function sportsEvent(string $name, string $url, string $sport, ?CarbonInterface $start, ?CarbonInterface $end, bool $cancelled, array $competitors): array
    {
        return array_filter([
            '@type' => 'SportsEvent',
            'name' => $name,
            'url' => $url,
            'sport' => $sport,
            'startDate' => $start?->toIso8601String(),
            'endDate' => $end?->toIso8601String(),
            'eventStatus' => $cancelled ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
            'location' => ['@type' => 'VirtualLocation', 'url' => $url],
            'organizer' => ['@id' => self::organizationId()],
            'competitor' => $competitors === [] ? null : $competitors,
        ], fn (mixed $value): bool => $value !== null);
    }
}
