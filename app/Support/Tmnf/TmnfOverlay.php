<?php

namespace App\Support\Tmnf;

use App\Games\ScoreMetric;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Support\Scores\ScoreAccounts;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Stacker\BlockfillWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The league's in-game overlay on its TMNF server (TmnfManialinks): what is
 * due, and sending it on tick(). The listener only marks; the command calls
 * tick() after every batch of callbacks (about once a second), so a finish is
 * stored before anything is sent and an overlay that fails never costs one.
 *
 * - began() (BeginChallenge, a new session): board and footer for everyone,
 *   the own line for every player on the server.
 * - connected() (PlayerConnect): board, footer and own line for that login.
 * - finished() (PlayerFinish): a finish that counted for the week marks the
 *   finisher's own line, the note "New personal best" when it is their best
 *   now, and the board for everyone; one of a login nobody linked gets the
 *   note "Not linked yet ...". The board for everyone goes out at most once
 *   every `board_seconds` (a burst of finishes: one update, the rest
 *   trailing); it refreshes every player's own line with it, as their places move.
 * - The note is removed after `note_seconds` (an empty manialink with its
 *   id), so it never takes another manialink with it.
 *
 * Names: the league profile name of linked players only (unlinked drivers
 * are on no board), never an npub. Nothing at all while the switch
 * `esports.tmnf.overlay.enabled` is off or TMNF is not registered.
 * Holds the state of its connection only (start() resets it), never a process-wide cache.
 */
final class TmnfOverlay
{
    public const NOTE_BEST = 'New personal best';

    public const NOTE_NOT_LINKED = 'Not linked yet – link your login on the site';

    private bool $everyone = false;

    private bool $boardDue = false;

    private ?int $boardSentAt = null;

    /** @var array<string, true> logins that get board, footer and own line */
    private array $full = [];

    /** @var array<string, true> logins whose own line is due */
    private array $own = [];

    /** @var array<string, string> notes due, by login */
    private array $notes = [];

    /** @var array<string, int> notes on screen, by login: removed at this time (ms) */
    private array $shown = [];

    public function __construct(private TmnfWeeks $weeks, private ScoreRuns $runs) {}

    /**
     * The switch: on by default while TMNF is registered.
     */
    public function enabled(): bool
    {
        return (bool) config('esports.tmnf.overlay.enabled', true) && $this->weeks->game() !== null;
    }

    /**
     * A new session: nothing of the old one is due, everything is shown again.
     */
    public function reset(): void
    {
        $this->everyone = false;
        $this->boardDue = false;
        $this->boardSentAt = null;
        $this->full = $this->own = $this->notes = $this->shown = [];
        $this->began();
    }

    public function began(): void
    {
        $this->everyone = $this->enabled();
    }

    public function connected(string $login): void
    {
        if ($this->enabled() && $login !== '') {
            $this->full[$login] = true;
        }
    }

    public function left(string $login): void
    {
        unset($this->full[$login], $this->own[$login], $this->notes[$login], $this->shown[$login]);
    }

    /**
     * A finish the listener stored: `$week` the week it counted for (null: none), `$pending` when nobody linked the login.
     */
    public function finished(string $login, ?ScoreRun $run, ?Tournament $week, bool $pending): void
    {
        if (! $this->enabled() || $login === '') {
            return;
        }

        if ($pending) {
            $this->notes[$login] = self::NOTE_NOT_LINKED;

            return;
        }

        if ($run === null || $week === null || $run->isHeld()) {
            return;
        }

        $this->own[$login] = true;
        $this->boardDue = true;
        $best = collect($this->runs->standings($week))->first(fn (ScoreStanding $row): bool => $row->participant->user_id === $run->user_id);

        if ($best?->runId === $run->id) {
            $this->notes[$login] = self::NOTE_BEST;
        }
    }

    /**
     * Sends what is due. A fault the server answers for one page (a login
     * that just left) is reported and the rest is sent; a broken connection
     * is thrown (the listener reconnects, and a new session shows all again).
     *
     * @throws GbxUnavailable
     * @throws GbxProtocolError
     */
    public function tick(TmnfServer $server, ?CarbonImmutable $now = null): void
    {
        if (! $this->enabled()) {
            $this->full = $this->own = $this->notes = $this->shown = [];
            $this->everyone = $this->boardDue = false;

            return;
        }

        $now ??= CarbonImmutable::now();
        $ms = (int) $now->getPreciseTimestamp(3);
        $boardSeconds = max(1, (int) config('esports.tmnf.overlay.board_seconds', 5));
        $all = $this->everyone || ($this->boardDue && ($this->boardSentAt === null || $ms - $this->boardSentAt >= $boardSeconds * 1000));
        // Taken before anything is sent: what fails is not sent again every second.
        [$full, $own, $notes] = [$this->full, $this->own, $this->notes];
        $this->full = $this->own = $this->notes = [];
        $board = null;

        if ($all) {
            $this->everyone = $this->boardDue = false;
            $this->boardSentAt = $ms;
            $board = $this->board($now);
            $this->send($server, TmnfManialinks::page($board['xml'], TmnfManialinks::footer($this->site())));
            $own += array_fill_keys($server->players(), true);
        }

        foreach (array_keys($full) as $login) {
            $board ??= $this->board($now);
            $this->send($server, TmnfManialinks::page($board['xml'], $this->ownLine((string) $login, $board['standings']), TmnfManialinks::footer($this->site())), (string) $login);
            unset($own[$login]);
        }

        foreach (array_keys($own) as $login) {
            $board ??= $this->board($now);
            $this->send($server, TmnfManialinks::page($this->ownLine((string) $login, $board['standings'])), (string) $login);
        }

        foreach ($notes as $login => $text) {
            $this->send($server, TmnfManialinks::page(TmnfManialinks::note($text)), (string) $login);
            $this->shown[$login] = $ms + max(1, (int) config('esports.tmnf.overlay.note_seconds', 3)) * 1000;
        }

        foreach ($this->shown as $login => $until) {
            if ($until <= $ms) {
                unset($this->shown[$login]);
                $this->send($server, TmnfManialinks::page(TmnfManialinks::remove(TmnfManialinks::ID_NOTE)), (string) $login);
            }
        }
    }

    /**
     * The running week's board: its manialink and its standings (for the own lines).
     *
     * @return array{xml: string, standings: list<ScoreStanding>}
     */
    private function board(CarbonImmutable $now): array
    {
        $week = $this->weeks->current($now);
        $standings = $week === null ? [] : $this->runs->standings($week);
        $placed = array_values(array_filter($standings, fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null));
        $metric = ScoreMetric::time();
        $rows = array_map(fn (ScoreStanding $row): array => [
            'place' => (int) $row->place,
            'name' => self::nameOf($row),
            'time' => $metric->format((int) $row->value),
        ], array_slice($placed, 0, TmnfManialinks::ROWS));
        $number = ($week === null ? BlockfillWeeks::startOf($now) : CarbonImmutable::instance($week->starts_at))->setTimezone(BlockfillWeeks::TIMEZONE)->format('W');

        return ['xml' => TmnfManialinks::board((string) config('esports.tmnf.server.name', 'TWENTY ONE'), (int) $number, $rows), 'standings' => $placed];
    }

    /**
     * @param  list<ScoreStanding>  $standings  placed rows, best first
     */
    private function ownLine(string $login, array $standings): string
    {
        $game = $this->weeks->game();
        $userId = $game === null ? null : ScoreAccounts::userFor($game, $login);

        if ($userId === null) {
            return TmnfManialinks::own(null, null, false);
        }

        foreach ($standings as $row) {
            if ($row->participant->user_id === $userId) {
                return TmnfManialinks::own($row->place, ScoreMetric::time()->format((int) $row->value), true);
            }
        }

        return TmnfManialinks::own(null, null, true);
    }

    /**
     * The league profile name; a player without one shows as "Player", never as their npub.
     */
    private static function nameOf(ScoreStanding $row): string
    {
        $name = trim((string) $row->participant->user?->name);

        return $name === '' || Str::startsWith(Str::lower($name), 'npub1') ? 'Player' : $name;
    }

    private function site(): string
    {
        return rtrim((string) preg_replace('#^https?://#', '', (string) config('twentyone.stream.scene.url')), '/');
    }

    private function send(TmnfServer $server, string $xml, ?string $login = null): void
    {
        try {
            $server->showPage($xml, $login);
        } catch (GbxFault $e) {
            report($e);
        }
    }
}
