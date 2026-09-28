<?php

namespace App\Support\Pages;

use App\Enums\TournamentFormat;
use App\Games\GameMode;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Support\FairPlay\FairPlay;
use App\Support\GameNames;
use App\Support\Prizes\PrizePool;
use App\Support\Rating\RatingSettings;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\Series\Ladders;
use App\Support\Tournaments\TournamentDeadlines;

/**
 * Every sentence of the rules page (P28, /rules) in one place, so its
 * wording can be reviewed and translated here and nowhere else. Every
 * number comes from the config or the code that applies it, read at render
 * time: the page states what the league does today and cannot drift from
 * it. Short sentences; no promises of what may come.
 *
 * A section: `id` (the anchor), `title`, `lead` (one sentence), and any of
 * `facts` (label and value, the numbers at a glance), `items` (the rules
 * as short sentences), `table` (head and rows) and `links` (label and URL
 * into the pages where the rule applies).
 *
 * @phpstan-type Section array{id: string, title: string, lead: string, facts?: list<array{0: string, 1: string}>, items?: list<string>, table?: array{head: list<string>, rows: list<list<string>>}, links?: list<array{0: string, 1: string}>}
 */
final class RulesPage
{
    /**
     * @return list<Section>
     */
    public static function sections(): array
    {
        return [
            self::league(),
            self::games(),
            self::casual(),
            self::chess(),
            self::series(),
            self::tournaments(),
            self::cups(),
            self::prizes(),
            self::fairPlay(),
            self::chat(),
        ];
    }

    /** "5 minutes", "2 hours", "3 days": a duration in the unit it is set in. */
    public static function minutes(int $minutes, ?string $locale = null): string
    {
        return match (true) {
            $minutes > 0 && $minutes % 1440 === 0 => trans_choice(':count day|:count days', intdiv($minutes, 1440), [], $locale),
            $minutes > 0 && $minutes % 60 === 0 => trans_choice(':count hour|:count hours', intdiv($minutes, 60), [], $locale),
            default => trans_choice(':count minute|:count minutes', $minutes, [], $locale),
        };
    }

    /**
     * "1 day" for 1920 minutes, "23 hours" for 1439: a time left in its
     * largest whole unit, rounded down, worded as {@see minutes()}.
     */
    public static function largestUnit(int $minutes, ?string $locale = null): string
    {
        $unit = match (true) {
            $minutes >= 1440 => 1440,
            $minutes >= 60 => 60,
            default => 1,
        };

        return self::minutes(intdiv($minutes, $unit) * $unit, $locale);
    }

    public static function seconds(int $seconds): string
    {
        return $seconds % 60 === 0 ? self::minutes(intdiv($seconds, 60)) : trans_choice(':count second|:count seconds', $seconds);
    }

    /**
     * @return Section
     */
    private static function league(): array
    {
        $season = Ladders::season();
        $rating = RatingSettings::inForce()['rating'];

        return [
            'id' => 'league',
            'title' => __('Casual and rated'),
            'lead' => $season === null
                ? __('Right now no season is live, so every match is casual.')
                : __('Season :season is live: rated matches count for its ladders.', ['season' => $season]),
            'facts' => [
                [__('Start rating'), (string) $rating['start']],
                [__('Rating change (K)'), (string) $rating['k']],
                [__('K for the first :count results', ['count' => $rating['provisional']]), (string) $rating['provisional_k']],
                [__('Trust rank for rated play'), (string) RatedTrustGate::minimum()],
            ],
            'items' => [
                __('Casual matches move only the casual rating. They never count for a season, a rank, Block Height or Clan Hashrate.'),
                __('Rated play opens with Block 0, the start of a season. Before Block 0 and between seasons every match is casual.'),
                __('A match stays what it was when it started: casual stays casual, even if it ends in a season.'),
                __('Rated play needs a Trusted account. Casual games with members, and players who list you back as an opponent, raise your trust.'),
                __('The same two opponents count for a rated ladder at most :count times a day.', ['count' => (int) $rating['daily_pair_limit']]),
            ],
            'links' => [[__('The season and mining'), route('mining')], [__('How results are verified'), route('protocol')]],
        ];
    }

    /**
     * @return Section
     */
    private static function games(): array
    {
        $rows = [];

        foreach (app(GameRegistry::class)->all() as $game) {
            foreach ($game->modes() as $mode) {
                /** @var GameMode $mode */
                $rows[] = [
                    GameNames::game($game->slug()),
                    __($mode->name),
                    $mode->bestOf === [] ? __('one game') : __('best of :list', ['list' => implode(' / ', $mode->bestOf)]),
                    $mode->rates === 'player' ? __('players') : __('clan lineups'),
                ];
            }
        }

        return [
            'id' => 'games',
            'title' => __('Games and modes'),
            'lead' => __('Each game and mode has its own ladder.'),
            'table' => ['head' => [__('Game'), __('Mode'), __('Series'), __('Ladder of')], 'rows' => $rows],
            'items' => [
                __('Clan lineups play the team modes. The captain picks who plays each series.'),
                __('A series ends as soon as one side has won the majority of its games.'),
            ],
            'links' => [[__('All games and modes'), route('play')], [__('Ladders'), route('ladder.show', ['chess', 'blitz'])]],
        ];
    }

    /**
     * @return Section
     */
    private static function casual(): array
    {
        $c = (array) config('esports.casual');
        $games = implode(', ', array_map(GameNames::game(...), (array) $c['games']));
        $excluded = collect((array) $c['crossplay_excluded'])->map(fn (array $platforms, string $game) => GameNames::game($game).': '.implode(', ', $platforms))->implode('; ');

        return [
            'id' => 'casual-1v1',
            'title' => __('Casual 1v1'),
            'lead' => __('A quick match without a clan in :games. Always casual.', ['games' => $games]),
            'facts' => [
                [__('Press Ready'), self::seconds((int) $c['ready_seconds'])],
                [__('Host shares the lobby'), self::minutes((int) $c['lobby_minutes'])],
                [__('Guest joins'), self::minutes((int) $c['join_minutes'])],
                [__('Answer a no-show claim'), self::minutes((int) $c['contest_minutes'])],
                [__('Report the result'), self::minutes((int) $c['report_minutes'])],
                [__('Answer the report'), self::minutes((int) $c['confirm_minutes'])],
            ],
            'items' => [
                __('Find an opponent, or invite a player who is looking to play. An invite stays open for :time.', ['time' => self::seconds((int) $c['invite_seconds'])]),
                __('You pair with the same platform, or with any platform when both allow crossplay. Never cross-platform: :list.', ['list' => $excluded]),
                __('Both press Ready in time, or the match is off. The one who was ready searches again, first in line.'),
                __('The host is drawn. The host shares the lobby (Rocket League) or the EA ID (EA FC) in the encrypted match chat. The league never sees it.'),
                __('The clock of each step runs from the step before. When the other side misses its step, you can claim a no-show; unanswered, it is a forfeit.'),
                __('Nobody reports: the match is void. A report nobody answers is confirmed by the league.'),
                __(':count forfeited no-shows within :hours pause casual 1v1 for :minutes.', ['count' => (int) $c['lock']['noshows'], 'hours' => self::minutes((int) $c['lock']['window_hours'] * 60), 'minutes' => self::minutes((int) $c['lock']['minutes'])]),
                __('A scheduled 1v1 names 1 to 3 times. The check-in opens :before before the start and closes :after after it; a side not checked in forfeits.', ['before' => self::minutes((int) $c['checkin_before_minutes']), 'after' => self::minutes((int) $c['checkin_after_minutes'])]),
                __('You can send :total scheduled challenges a day, :each to the same player.', ['total' => (int) $c['challenges_per_day'], 'each' => (int) $c['challenges_per_recipient_per_day']]),
                __('After a result the room offers a rematch for :time.', ['time' => self::minutes((int) $c['rematch_minutes'])]),
            ],
            'links' => [[__('Play 1v1 casual'), route('play')], [__('Schedule a 1v1'), route('challenges.casual')]],
        ];
    }

    /**
     * @return Section
     */
    private static function chess(): array
    {
        $c = (array) config('esports.chess');

        return [
            'id' => 'chess',
            'title' => __('Chess'),
            'lead' => __('Blitz 5+3 live, or daily chess with one move a day.'),
            'facts' => [
                [__('First move'), self::seconds((int) $c['first_move_seconds'])],
                [__('Claim after a disconnect'), self::seconds((int) $c['disconnect_claim_seconds'])],
                [__('Answer a daily challenge'), self::minutes((int) $c['challenge_hours'] * 60)],
                [__('Blitz invite open'), self::seconds((int) $c['invite_seconds'])],
            ],
            'items' => [
                __('Each side makes its first move within the time above, or the game is aborted. In a tournament a missed first move loses by forfeit.'),
                __('A daily game gives the side to move one day. A missed day loses on time; before both first moves it aborts the game.'),
                __('If your opponent stays disconnected from a running blitz game, you can claim the win after the time above.'),
                __('The blitz queue starts near your rating and widens the range by :step every :seconds.', ['step' => (int) $c['queue']['range']['step'], 'seconds' => self::seconds((int) $c['queue']['range']['every_seconds'])]),
                __('You can send :total daily challenges a day, :each to the same player.', ['total' => (int) $c['challenges_per_day'], 'each' => (int) $c['challenges_per_recipient_per_day']]),
            ],
            'links' => [[__('Chess'), route('chess.lobby')]],
        ];
    }

    /**
     * @return Section
     */
    private static function series(): array
    {
        $s = (array) config('esports.series');

        return [
            'id' => 'clan-series',
            'title' => __('Clan series'),
            'lead' => __('A clan lineup challenges another clan lineup to a series.'),
            'facts' => [
                [__('Latest answer'), self::minutes((int) $s['respond_max_days'] * 1440)],
                [__('Latest start'), self::minutes((int) $s['plan_max_days'] * 1440)],
                [__('No-show after the start'), self::minutes((int) $s['noshow_minutes'])],
                [__('Unanswered report to the admins'), self::minutes((int) config('esports.tournaments.unanswered_report_hours') * 60)],
            ],
            'items' => [
                __('The challenge suggests up to three times. The other captain accepts one or declines.'),
                __('Enter the goals of each game after it; the score shows as provisional until both sides agree.'),
                __('One captain submits the final score, the other accepts it or reports a problem with evidence.'),
                __('A reported problem goes to the admins. Either side may submit a corrected score in the meantime.'),
            ],
            'links' => [[__('Challenge a clan'), route('challenges.create')], [__('Matches'), route('matches.index')]],
        ];
    }

    /**
     * @return Section
     */
    private static function tournaments(): array
    {
        $t = (array) config('esports.tournaments');
        $defaults = TournamentDeadlines::defaults('3v3');
        $formats = implode(', ', array_map(fn (TournamentFormat $format) => $format->label(), TournamentFormat::cases()));

        return [
            'id' => 'tournaments',
            'title' => __('Tournaments'),
            'lead' => __('Each tournament page shows its own format, times and deadlines. The league defaults are these.'),
            'facts' => [
                [__('Chess first move'), self::seconds((int) $t['first_move_seconds']['blitz'])],
                [__('Series no-show'), self::minutes($defaults['noshow_minutes'])],
                [__('Report due'), self::minutes($defaults['report_hours'] * 60)],
                [__('Answer a report'), self::minutes($defaults['response_minutes'])],
            ],
            'items' => [
                __('Formats: :formats.', ['formats' => $formats]),
                __('Sign up on the tournament page before sign-up closes. Rules and prizes are fixed from then on.'),
                __('Mixed teams and seeds are drawn from the hash of a Bitcoin block mined after sign-up closed, once it has :count confirmations. Anyone can check the draw.', ['count' => (int) config('esports.bitcoin.confirmations')]),
                __('A side that misses its first move or does not show up loses the match by forfeit. Both missing: the higher seed advances in a knockout, both lose in Swiss and round robin.'),
                __('A drawn knockout chess game is replayed with the colours swapped, at most :count times; then the higher seed advances.', ['count' => (int) $t['drawn_replays']]),
                __('A report or a no-show claim the other side does not answer in time is decided by the league.'),
                __('Players get reminders :list minutes before the league decides a waiting match.', ['list' => implode(', ', (array) $t['reminders'])]),
                __('In a tournament run by its directors, the directors enter every result; players report nothing.'),
                __('A one-day tournament of a minute game runs its deadlines from each match’s own start: no-show after :noshow, answer within :answer.', ['noshow' => self::minutes((int) $t['round_clock']['noshow_minutes']), 'answer' => self::minutes((int) $t['round_clock']['response_minutes'])]),
            ],
            'links' => [[__('Tournaments'), route('tournaments.index')]],
        ];
    }

    /**
     * @return Section
     */
    private static function cups(): array
    {
        $c = (array) config('esports.casual_cups');
        $e = (array) $c['evening'];
        $sizes = (array) $c['sizes'];

        return [
            'id' => 'casual-cups',
            'title' => __('Casual cups'),
            'lead' => __('The league opens a casual cup per game on its own, one at a time, numbered in order.'),
            'facts' => [
                [__('Places'), implode(' → ', $sizes)],
                [__('Sign-up'), self::minutes((int) $c['signup_hours'] * 60)],
                [__('Round window'), self::minutes((int) $c['window_hours'] * 60)],
                [__('Longest cup'), self::minutes((int) $c['max_days'] * 1440)],
            ],
            'items' => [
                __('A cup opens with :first places. When only one place is left, it grows to the next size, up to :last, until :freeze before sign-up closes.', ['first' => $sizes[0] ?? 0, 'last' => end($sizes) ?: 0, 'freeze' => self::minutes((int) $c['growth_freeze_minutes'])]),
                __('A full cup starts at once. At the close, :min or more players play a double elimination; more than 8 play a 16-slot bracket with byes for the top seeds.', ['min' => (int) $c['min_players']]),
                __('2 to :max players play one live evening at :start (:zone): 2 players one match, 3 to 5 a round robin, about :budget of play each.', ['max' => (int) $c['min_players'] - 1, 'start' => $e['start'], 'zone' => $c['timezone'], 'budget' => self::minutes((int) $e['max_play_minutes'])]),
                __('Fewer than 2 players: sign-up is extended once by :hours, then the cup is called off. The next cup opens :gap after a final or a call-off.', ['hours' => self::minutes((int) $c['extension_hours'] * 60), 'gap' => self::minutes((int) $c['gap_hours'] * 60)]),
                __('A round opens when the round before is done and lasts :window (:large with more than 8 players).', ['window' => self::minutes((int) $c['window_hours'] * 60), 'large' => self::minutes((int) $c['large_window_hours'] * 60)]),
                __('Rocket League and EA FC players propose one to three times in the window; the other answers within :answer. Without an agreement the match starts at :slot (:zone) on the window’s last evening.', ['answer' => self::minutes((int) $c['answer_hours'] * 60), 'slot' => $c['auto_slot'], 'zone' => $c['timezone']]),
                __('Cup matches are casual and use the casual 1v1 check-in and deadlines.'),
            ],
            'links' => [[__('Tournaments'), route('tournaments.index')]],
        ];
    }

    /**
     * @return Section
     */
    private static function prizes(): array
    {
        $presets = implode(', ', array_map(fn (array $split) => implode('/', $split), PrizePool::PRESETS));

        return [
            'id' => 'prizes',
            'title' => __('Prize pots and payouts'),
            'lead' => __('A prize pot is the tournament’s own Lightning wallet. The league’s own wallet is never used for it.'),
            'facts' => [
                [__('Default split'), implode(' / ', Tournament::DEFAULT_SPLIT).' %'],
                [__('Fee reserve'), __(':percent %, at least :min sats', ['percent' => PrizePool::WALLET_FEE_PERCENT, 'min' => PrizePool::WALLET_FEE_MIN])],
                [__('Places with a prize'), __('up to :count', ['count' => PrizePool::MAX_PLACES])],
            ],
            'items' => [
                __('The pot shown is the pot as the tournament sets it, less what was already paid out.'),
                __('Prizes are a share per place (for example :presets) or fixed sats per place. They cannot change after sign-up closed.', ['presets' => $presets]),
                __('The fee reserve stays in the wallet for routing fees. With shares, the places split the pot less the reserve; with fixed sats, the pot must cover them plus the reserve.'),
                __('Tied places share their prizes equally. A team’s prize is split equally among its roster. Rounding is down to whole sats; the rest stays in the pot.'),
                __('After the tournament an admin checks the places and approves the payouts. Each player is paid to the Lightning address in their Nostr profile at that moment.'),
                __('Without a Lightning address the payout waits until you add one and an admin approves it. A changed address also needs an admin’s approval.'),
                __('A Lightning address is never shown publicly; only the admins who approve a payout see it.'),
            ],
            'links' => [[__('Tournaments'), route('tournaments.index')]],
        ];
    }

    /**
     * @return Section
     */
    private static function fairPlay(): array
    {
        return [
            'id' => 'fair-play',
            'title' => __('Fair play'),
            'lead' => __('Play every match you accept, and report only what happened.'),
            'facts' => [
                [__('False reports before a pause'), __(':count within :days days', ['count' => FairPlay::threshold(), 'days' => FairPlay::windowDays()])],
                [__('No rated play after that'), trans_choice(':count day|:count days', FairPlay::lockDays())],
            ],
            'items' => [
                __('A match you do not show up to is a forfeit, and in casual 1v1 it counts toward the pause above.'),
                __('For a dispute, keep a screenshot of the end screen or the match history. Only admins see evidence.'),
                __('Reports about players count for trust :count times per reporter and season.', ['count' => (int) config('esports.trust.reports_per_author')]),
                __('Admins decide disputes and can void or correct a result. Their decision is shown on the match.'),
                __('If an admin rules a disputed result report false, the reporting side loses the match and the captain who reported gets a confirmed false report.'),
                __(':count confirmed false reports within :days days mean no rated play for :lock days from the last one. Casual play stays open, and the page where rated play is refused says until when.', ['count' => FairPlay::threshold(), 'days' => FairPlay::windowDays(), 'lock' => FairPlay::lockDays()]),
                __('One player, one account in the league. An admin can link the accounts of one player: only the main account plays rated matches and wins prizes, and results between the accounts are void. Unlinking brings rated play and prizes back; voided results stay void.'),
            ],
            'links' => [[__('How results are verified'), route('protocol')]],
        ];
    }

    /**
     * @return Section
     */
    private static function chat(): array
    {
        return [
            'id' => 'chat',
            'title' => __('Chat'),
            'lead' => __('Be kind. Talk about the game, not about the person.'),
            'facts' => [
                [__('Characters per message'), (string) (int) config('esports.game_chat.max_length')],
                [__('Pause between messages'), self::seconds(intdiv((int) config('esports.game_chat.cooldown_ms'), 1000))],
            ],
            'items' => [
                __('The chats of a match room and of a chess game are end-to-end encrypted over Nostr: the league server never receives or stores these messages.'),
                __('The game channels and the stream chat are public Nostr messages. Anyone can read them.'),
                __('Mute a player to hide their messages; the mute is yours alone.'),
                __('Polls and votes in a game channel count only from members and players with a result in the league.'),
                __('No spam, no hate, no doxxing: never share another player’s account, address or real name.'),
            ],
            'links' => [[__('Live'), route('live')]],
        ];
    }
}
