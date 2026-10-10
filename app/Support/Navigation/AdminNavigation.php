<?php

namespace App\Support\Navigation;

use App\Enums\PayoutStatus;
use App\Enums\StackerRunStatus;
use App\Models\LeagueWeek;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Scores\ScoreAccounts;
use Illuminate\Support\Facades\Route;

/**
 * The map of the admin area (P17): every admin page in one of four groups,
 * League, Tournaments, Players & roles and System. <x-admin.nav> draws it,
 * <x-admin.page> takes the group of the active page for its breadcrumbs, and
 * the status page (the admin home) uses the same groups, so the words match.
 *
 * Organizers reach the tournament pages only; they get no nav, just the
 * breadcrumbs, because a nav with one tab is no map.
 *
 * @phpstan-type AdminNavItem array{key: string, label: string, href: string, count: int, test: string}
 * @phpstan-type AdminNavGroup array{key: string, label: string, items: list<AdminNavItem>}
 */
final class AdminNavigation
{
    /** Page key => group key; also the list of valid `active` values. */
    public const PAGES = [
        'seasons' => 'league',
        'disputes' => 'league',
        'events' => 'league',
        'settings' => 'league',
        'tournaments' => 'tournaments',
        'league-weeks' => 'tournaments',
        'payouts' => 'tournaments',
        'scores' => 'tournaments',
        'blockfill' => 'tournaments',
        'admins' => 'people',
        'organizers' => 'people',
        'trust' => 'people',
        'fair-play' => 'people',
        'nip05' => 'people',
        'status' => 'system',
        // Site-wide mutes and bans. Here, not under Players & roles or League: in German both are as wide as the nav allows (NavigationMenusTest, 640 px).
        'moderation' => 'system',
        // OBS overlay presets (plan "OBS-Broadcast-Overlays", P2): the stream's tools, next to the status page.
        'overlays' => 'system',
    ];

    public function __construct(private readonly ?User $user) {}

    public static function forCurrentUser(): self
    {
        $user = auth()->user();

        return new self($user instanceof User ? $user : null);
    }

    public function isAdmin(): bool
    {
        return $this->user?->can('admin') ?? false;
    }

    /**
     * The groups with their pages, for admins; empty for everybody else.
     *
     * @return list<AdminNavGroup>
     */
    public function groups(): array
    {
        if (! $this->isAdmin()) {
            return [];
        }

        $openCases = SeriesMatch::query()->openCase()->count();
        $payable = TournamentPayout::query()->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Failed])->count();

        return [
            ['key' => 'league', 'label' => self::groupLabel('league'), 'items' => [
                self::item('seasons', __('Seasons'), route('admin.season')),
                self::item('disputes', __('Disputes'), route('admin.disputes'), $openCases),
                self::item('events', __('Weekly events'), route('admin.events')),
                self::item('settings', __('League settings'), route('admin.settings')),
            ]],
            ['key' => 'tournaments', 'label' => self::groupLabel('tournaments'), 'items' => [
                // Not „Tournaments“ again: the group already says it, the strip would read it twice.
                self::item('tournaments', __('All tournaments'), route('admin.tournaments')),
                // The weekly Blockfill and TMNF boards wait for an admin's approval (LeagueWeekDrafts); the count is the drafts not approved yet.
                self::item('league-weeks', __('League weeks'), route('admin.league-weeks'), LeagueWeek::query()->whereNull('approved_at')->whereNull('tournament_id')->where('starts_at', '>', now()->subWeek())->count()),
                self::item('payouts', __('Payouts'), route('admin.payouts'), $payable),
                // Score submissions waiting for a check (plan "AoE2 und Trackmania", P4), only while a score game is registered.
                ...(Route::has('admin.scores') ? [self::item('scores', __('Score submissions'), route('admin.scores'), ScoreRun::query()->pendingReview()->count() + ScoreAccounts::pendingAccounts())] : []),
                // Blockfill runs held for cheat hints (plan "Blockfill", P5), only while Blockfill is switched on.
                ...(Route::has('admin.blockfill') ? [self::item('blockfill', __('Blockfill review'), route('admin.blockfill'), StackerRun::query()->where('status', StackerRunStatus::Review)->count())] : []),
            ]],
            ['key' => 'people', 'label' => self::groupLabel('people'), 'items' => [
                self::item('admins', __('Admins'), route('admin.admins')),
                self::item('organizers', __('Organizers'), route('admin.organizers')),
                self::item('trust', __('Trust'), route('admin.trust')),
                self::item('fair-play', __('Fair play'), route('admin.fair-play')),
                self::item('nip05', __('Nostr addresses'), route('admin.nip05')),
            ]],
            ['key' => 'system', 'label' => self::groupLabel('system'), 'items' => [
                self::item('status', __('Status'), route('admin.status')),
                self::item('moderation', __('Moderation'), route('admin.moderation')),
                self::item('overlays', __('OBS overlays'), route('admin.overlays')),
            ]],
        ];
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'league' => __('League'),
            'tournaments' => __('Tournaments'),
            'people' => __('Players & roles'),
            'system' => __('System'),
            default => '',
        };
    }

    /** Where "Admin" in the breadcrumbs leads: the status page, for an organizer their tournaments. */
    public function homeHref(): string
    {
        return $this->isAdmin() ? route('admin.status') : route('admin.tournaments');
    }

    /** @return AdminNavItem */
    private static function item(string $key, string $label, string $href, int $count = 0): array
    {
        return ['key' => $key, 'label' => $label, 'href' => $href, 'count' => $count, 'test' => 'admin-nav-'.$key];
    }
}
