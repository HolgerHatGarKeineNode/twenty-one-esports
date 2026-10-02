<?php

namespace App\Support\Matches;

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\ScoreMetric;
use App\Games\TrackmaniaNationsForever;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ScoreRun;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Stacker\BlockfillRules;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerReplays;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

/**
 * The highscore attempts on /matches, next to the matches: the runs of every
 * registered score game, in the mempool strip and in the table.
 *
 * Two sources, one game each, never both: Blockfill reads its own runs
 * (stacker_runs), because only they know a run still waiting for the
 * verifier; its verified runs are mirrored into score_runs for the weekly
 * leaderboard and are therefore left out there. Every other score game
 * reads score_runs.
 *
 * - waiting: a Blockfill run `verifying` or `pending`, a manual submission no
 *   admin decided yet. It shows as unconfirmed and never with its claimed
 *   value: nothing unchecked reaches a public page.
 * - done: a verified Blockfill run; a verified score run with a value.
 * - never: practice runs (they could not change any standing), rejected,
 *   abandoned and issued runs, a director's entry (a correction, not an
 *   attempt), a run of an account no player owns (`user_id` null).
 *
 * Attempts are unrated: they never mine a block, so the `season` chain has
 * none and they carry the casual tag. Only while the game is registered and
 * its leaderboard page routed (`scores.show`): with Blockfill's switch off
 * none of its runs shows. Every query is one per source and state, ordered
 * by an index (stacker_runs (status, created_at), score_runs (game,
 * created_at)) and limited; the clan filter reads the clan's members, not
 * every run.
 */
final class ScoreAttempts
{
    /** Blockfill states that wait for the verifier, or (P5, review) for an admin's look at its cheat hints. */
    public const STACKER_WAITING = [StackerRunStatus::Verifying, StackerRunStatus::Pending, StackerRunStatus::Review];

    /**
     * Whether Blockfill's runs may show: registered (its switch on) and its
     * leaderboard page routed.
     */
    public static function blockfill(): bool
    {
        return app(GameRegistry::class)->find(Blockfill::SLUG) instanceof Blockfill && Route::has('scores.show');
    }

    /**
     * Every score game whose attempts may show, in display order.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return Route::has('scores.show') ? array_keys(app(GameRegistry::class)->scores()) : [];
    }

    /**
     * The score games read from score_runs: every listed one but Blockfill.
     *
     * @param  list<string>  $games
     * @return list<string>
     */
    public static function scoreSlugs(array $games): array
    {
        return array_values(array_diff(array_intersect(self::slugs(), $games), [Blockfill::SLUG]));
    }

    /**
     * Blockfill runs in a state: `waiting`, `done` or `all` (both).
     *
     * @return Builder<StackerRun>
     */
    public static function stacker(string $state, ?Clan $clan = null): Builder
    {
        $statuses = match ($state) {
            'waiting' => self::STACKER_WAITING,
            'done' => [StackerRunStatus::Verified],
            default => [...self::STACKER_WAITING, StackerRunStatus::Verified],
        };

        return StackerRun::query()
            ->whereIn('status', $statuses)
            ->when($clan !== null, fn (Builder $query) => $query->whereIn('user_id', self::members($clan)));
    }

    /**
     * Score runs of the given games (never Blockfill) in a state: `waiting`,
     * `done` or `all` (both).
     *
     * @param  list<string>  $games
     * @return Builder<ScoreRun>
     */
    public static function scores(array $games, string $state, ?Clan $clan = null): Builder
    {
        return ScoreRun::query()
            ->whereIn('game', $games)
            ->whereNotNull('user_id')
            ->where('source', '!=', ScoreRun::DIRECTOR)
            ->whereNull('rejected_at')
            ->when($state === 'waiting', fn (Builder $query) => $query->whereNull('verified_at'))
            ->when($state === 'done', fn (Builder $query) => $query->whereNotNull('verified_at')->whereNotNull('value'))
            ->when($state === 'all', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('verified_at')->orWhereNotNull('value')))
            ->when($clan !== null, fn (Builder $query) => $query->whereIn('user_id', self::members($clan)));
    }

    /**
     * The latest attempts of every listed game in a state, for the strip, at
     * most `$limit` per source.
     *
     * @return list<StackerRun|ScoreRun>
     */
    public static function latest(string $state, int $limit): array
    {
        $scores = self::scoreSlugs(self::slugs());

        return [
            ...(self::blockfill() ? self::stacker($state)->with('user')->latest()->limit($limit)->get()->all() : []),
            ...($scores === [] ? [] : self::scores($scores, $state)->with('user')->latest()->limit($limit)->get()->all()),
        ];
    }

    public static function isWaiting(StackerRun|ScoreRun $run): bool
    {
        return $run instanceof StackerRun ? $run->status !== StackerRunStatus::Verified : $run->verified_at === null;
    }

    /**
     * When the attempt happened: verified when verified, else submitted.
     */
    public static function at(StackerRun|ScoreRun $run): ?CarbonInterface
    {
        if ($run instanceof StackerRun) {
            return $run->verified_at ?? $run->submitted_at ?? $run->created_at;
        }

        return $run->achieved_at;
    }

    public static function game(StackerRun|ScoreRun $run): string
    {
        return $run instanceof StackerRun ? Blockfill::SLUG : $run->game;
    }

    /** The mode as people read it; a Blockfill run by the blocks of its own rules ("60 blocks"). */
    public static function mode(StackerRun|ScoreRun $run): string
    {
        return $run instanceof StackerRun ? BlockfillRules::blocks($run->engine) : GameNames::mode($run->game, $run->mode);
    }

    /**
     * The checked value for people (Blockfill "5:16.500"); null while unconfirmed.
     */
    public static function value(StackerRun|ScoreRun $run): ?string
    {
        if (self::isWaiting($run)) {
            return null;
        }

        return $run instanceof StackerRun ? ScoreMetric::time()->format(Blockfill::milliseconds((int) $run->ticks)) : $run->formatted();
    }

    /**
     * Where each attempt links, by its key(): a Blockfill run whose replay the
     * viewer may watch to that replay (P5, StackerReplays::forRuns()), else a
     * verified Blockfill run or TMNF finish to its week's leaderboard (one
     * query for all of them), a score run to its tournament's leaderboard,
     * everything else to the game's leaderboards.
     *
     * @param  iterable<StackerRun|ScoreRun>  $runs
     * @return array<string, string>
     */
    public static function links(iterable $runs): array
    {
        $slugs = [];

        foreach ($runs as $run) {
            if ($run instanceof StackerRun && ! self::isWaiting($run) && $run->submitted_at !== null) {
                $slugs[self::key($run)] = BlockfillWeeks::slugOf(BlockfillWeeks::startOf($run->submitted_at));
            }

            // A TMNF finish (plan "Trackmania und Restposten", P2): the week of its moment, the same rhythm.
            if ($run instanceof ScoreRun && $run->game === TrackmaniaNationsForever::SLUG && ! self::isWaiting($run)) {
                $slugs[self::key($run)] = TmnfWeeks::slugOf(BlockfillWeeks::startOf($run->achieved_at));
            }
        }

        $weeks = $slugs === [] ? collect() : Tournament::query()->whereIn('game', [Blockfill::SLUG, TrackmaniaNationsForever::SLUG])->whereIn('slug', array_unique($slugs))->pluck('id', 'slug');
        $viewer = auth()->user();
        $replays = app(StackerReplays::class)->forRuns(array_filter(is_array($runs) ? $runs : iterator_to_array($runs), fn ($run): bool => $run instanceof StackerRun), $viewer instanceof User ? $viewer : null);
        $links = [];

        foreach ($runs as $run) {
            $week = $weeks->get($slugs[self::key($run)] ?? '');
            $links[self::key($run)] = match (true) {
                $run instanceof StackerRun && isset($replays[$run->id]) => $replays[$run->id],
                $week !== null => route('tournaments.scores', $week),
                $run instanceof ScoreRun && $run->tournament_id !== null => route('tournaments.scores', $run->tournament_id),
                default => route('scores.show', self::game($run)),
            };
        }

        return $links;
    }

    public static function key(StackerRun|ScoreRun $run): string
    {
        return ($run instanceof StackerRun ? 'run-' : 'score-').$run->id;
    }

    /**
     * The cube of an attempt in the mempool strip (MatchBlocks::shape()):
     * one player, the checked value, or "pending" and "to confirm" on the
     * waiting side. The value takes the word size: "5:16.500" in the score
     * size was cut in the 88 px cube of a phone (100 px wide), as was
     * "unconfirmed" in the word size (116 px).
     *
     * @return array<string, mixed>
     */
    public static function block(StackerRun|ScoreRun $run, string $href, bool $newest = false): array
    {
        $waiting = self::isWaiting($run);
        $name = $run->user?->displayName() ?? __('Deleted player');
        $score = self::value($run) ?? __('pending');
        $game = self::game($run);
        $mode = self::mode($run);

        return MatchBlocks::shape(
            key: self::key($run),
            number: '',
            slug: $game,
            mode: $mode,
            score: $score,
            who: $name,
            when: $waiting ? __('to confirm') : (self::at($run)?->diffForHumans(['short' => true]) ?? ''),
            sides: [['name' => $name, 'user' => $run->user, 'clan' => null, 'won' => ! $waiting]],
            href: $href,
            aria: implode(', ', [GameNames::game($game).' '.$mode, $waiting ? __('unconfirmed') : $score, $name]),
            state: $waiting ? 'live' : 'fin',
            casual: true,
            word: true,
            newest: $newest,
        );
    }

    /**
     * @return Builder<ClanMember>
     */
    private static function members(Clan $clan): Builder
    {
        return ClanMember::query()->where('clan_id', $clan->id)->select('user_id');
    }
}
