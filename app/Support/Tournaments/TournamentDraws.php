<?php

namespace App\Support\Tournaments;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Series\Ladders;
use Illuminate\Support\Facades\DB;

/**
 * From sign-up to the bracket (TournamentDraw.dc.html, NIP "Tournament Draw"):
 *
 * 1. close(): once sign-up has closed, the entries are frozen and the draw
 *    commits to the next Bitcoin block (tip + 1, the first block after the
 *    deadline; open question 10, CEO default). With a solo pool in a team
 *    mode the league publishes the `2155` (entrants, block, team size, meme
 *    names) before that block exists, so nobody, the league included, can
 *    steer who plays with whom. The tournament waits in "Draw pending".
 * 2. resolve(): once the block is mined, its hash sorts the solo pool into
 *    mix teams (`sha256-v1`, DrawOrder), leftover solo players stay reserves
 *    (substitutes on a waitlist), the participants are created (lineups and
 *    players with their Elo in sign-up order, then the mix teams in draw
 *    order), and the P8a engine builds and stores the bracket with the
 *    block hash as its seed. Then the first matches start (TournamentRunner).
 *
 * Fewer than two entries after the close calls the tournament off. Fail
 * closed: without the league key or a readable block nothing moves; the
 * scheduler tries again (`tournaments:advance`).
 */
final class TournamentDraws
{
    public const TOURNAMENT_DRAW = 2155;

    public function __construct(
        private BitcoinBlocks $blocks,
        private TournamentBrackets $brackets,
        private TournamentRunner $runner,
    ) {}

    /**
     * Every step that is due: close sign-ups, resolve draws, start matches.
     *
     * @return array{closed: int, drawn: int}
     */
    public function advanceDue(): array
    {
        $closed = 0;
        $drawn = 0;

        foreach (Tournament::query()->where('status', TournamentStatus::Signup)->where('signup_closes_at', '<=', now())->get() as $tournament) {
            $closed += $this->close($tournament) ? 1 : 0;
        }

        foreach (Tournament::query()->where('status', TournamentStatus::Drawing)->get() as $tournament) {
            $drawn += $this->resolve($tournament) ? 1 : 0;
        }

        foreach (Tournament::query()->where('status', TournamentStatus::Running)->get() as $tournament) {
            $this->runner->sync($tournament);
        }

        return ['closed' => $closed, 'drawn' => $drawn];
    }

    public function close(Tournament $tournament): bool
    {
        if ($tournament->status !== TournamentStatus::Signup || $tournament->signup_closes_at === null || $tournament->signup_closes_at->isFuture()) {
            return false;
        }

        $league = LeagueKey::fromConfig();
        $tip = $this->blocks->tipHeight();

        if ($league === null || $tip === null) {
            return false;
        }

        return DB::transaction(function () use ($tournament, $league, $tip): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== TournamentStatus::Signup) {
                return false;
            }

            $signups = TournamentSignup::query()->where('tournament_id', $locked->id)->active()->orderBy('id')->get();
            $size = $locked->teamSize();
            $solos = $signups->whereNull('lineup_id');
            $entries = $signups->whereNotNull('lineup_id')->count() + intdiv($solos->count(), $size);

            if ($entries < 2) {
                $locked->forceFill(['status' => TournamentStatus::Cancelled])->save();

                return true;
            }

            $locked->draw_height = $tip + 1;

            if ($locked->profile()->entersTeams() && $solos->isNotEmpty()) {
                $pubkeys = User::query()->whereIn('id', $solos->pluck('members')->flatten()->all())->pluck('pubkey', 'id');
                $entrants = array_values(array_filter($solos->map(fn (TournamentSignup $signup): ?string => $pubkeys[$signup->members[0] ?? 0] ?? null)->all()));
                $event = $league->publish(self::TOURNAMENT_DRAW, $this->drawTags($locked, $entrants, $size), $this->drawContent($locked, count($entrants), $size), now()->getTimestamp());
                $locked->draw_event_id = $event->id;
            }

            $locked->status = TournamentStatus::Drawing;
            $locked->save();

            return true;
        });
    }

    public function resolve(Tournament $tournament): bool
    {
        if ($tournament->status !== TournamentStatus::Drawing || $tournament->draw_height === null) {
            return false;
        }

        $hash = $this->blocks->hashAt($tournament->draw_height);

        if ($hash === null) {
            return false;
        }

        $started = DB::transaction(function () use ($tournament, $hash): bool {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);

            if ($locked->status !== TournamentStatus::Drawing || $locked->participants()->exists()) {
                return false;
            }

            $this->createParticipants($locked, $hash);

            if ($locked->participants()->count() < 2) {
                $locked->forceFill(['status' => TournamentStatus::Cancelled, 'draw_hash' => $hash])->save();

                return false;
            }

            $locked->forceFill(['draw_hash' => $hash])->save();
            $this->brackets->generate($locked, $hash);
            $locked->forceFill(['status' => TournamentStatus::Running])->save();

            return true;
        });

        if ($started) {
            $this->runner->sync($tournament->refresh());
        }

        return $started;
    }

    /**
     * The mix teams a block hash makes of this tournament's solo pool, with
     * their names: the same hash always gives the same teams.
     *
     * @return array{teams: list<array{name: string, members: list<int>}>, reserves: list<int>}
     */
    public function mixTeams(Tournament $tournament, string $hash): array
    {
        $solos = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->whereNull('lineup_id')->orderBy('id')->get();
        $ids = $solos->map(fn (TournamentSignup $signup): int => (int) ($signup->members[0] ?? 0))->all();
        $byPubkey = User::query()->whereIn('id', $ids)->pluck('id', 'pubkey');
        $drawn = DrawOrder::teams($hash, array_values(array_map(strval(...), $byPubkey->keys()->all())), $tournament->teamSize());
        $names = MemeNames::for((string) $tournament->slug, count($drawn['teams']));

        return [
            'teams' => array_map(fn (array $team, int $index): array => [
                'name' => $names[$index],
                'members' => array_map(fn (string $pubkey): int => (int) $byPubkey[$pubkey], $team),
            ], $drawn['teams'], array_keys($drawn['teams'])),
            'reserves' => array_map(fn (string $pubkey): int => (int) $byPubkey[$pubkey], $drawn['reserves']),
        ];
    }

    private function createParticipants(Tournament $tournament, string $hash): void
    {
        $pool = Ratings::pool(Ladders::isOpen($tournament->game, $tournament->mode));
        $signups = TournamentSignup::query()->where('tournament_id', $tournament->id)->active()->orderBy('id')->get();
        $teams = $tournament->profile()->entersTeams();
        $lineupRatings = Ratings::forLineups($signups->pluck('lineup_id')->filter()->all(), $tournament->game, $tournament->mode, $pool);
        $userRatings = Ratings::forUsers($signups->pluck('members')->flatten()->all(), $tournament->game, $tournament->mode, $pool);

        foreach ($signups as $signup) {
            if ($signup->lineup_id !== null) {
                $rating = $lineupRatings[$signup->lineup_id]['rating'] ?? null;
            } elseif (! $teams) {
                $rating = $userRatings[$signup->members[0] ?? 0]['rating'] ?? null;
            } else {
                continue;
            }

            TournamentParticipant::query()->create([
                'tournament_id' => $tournament->id,
                'user_id' => $signup->lineup_id === null ? ($signup->members[0] ?? null) : null,
                'lineup_id' => $signup->lineup_id,
                'name' => $signup->name,
                'rating' => $rating ?? (int) config('season.rating.start', 1000),
                'members' => $signup->members,
                'tournament_signup_id' => $signup->id,
            ]);
        }

        if (! $teams) {
            return;
        }

        foreach ($this->mixTeams($tournament, $hash)['teams'] as $index => $team) {
            TournamentParticipant::query()->create([
                'tournament_id' => $tournament->id,
                'name' => $team['name'],
                'rating' => (int) config('season.rating.start', 1000),
                'members' => $team['members'],
                'draw_position' => $index + 1,
            ]);
        }
    }

    /**
     * NIP "Tournament Draw" (rev. 4): ladder `a` (while one is open), the
     * tournament `a`, one `p` per solo entrant, `draw`, `teams`, at least as
     * many `teamname` as teams, `format`, `name`, `alt`.
     *
     * @param  list<string>  $entrants
     * @return list<list<string>>
     */
    private function drawTags(Tournament $tournament, array $entrants, int $size): array
    {
        $tags = [];
        $ladder = Ladders::address($tournament->game, $tournament->mode);

        if ($ladder !== null) {
            $tags[] = ['a', $ladder, ''];
        }

        $tags[] = ['a', (string) $tournament->address(), ''];

        foreach ($entrants as $pubkey) {
            $tags[] = ['p', $pubkey, '', 'entrant'];
        }

        $tags[] = ['draw', (string) $tournament->draw_height, 'sha256-v1'];
        $tags[] = ['teams', (string) $size];

        foreach (MemeNames::for((string) $tournament->slug, max(1, intdiv(count($entrants), $size))) as $name) {
            $tags[] = ['teamname', $name];
        }

        $tags[] = ['format', $tournament->format->value];
        $tags[] = ['name', mb_substr($tournament->name, 0, 64)];
        $tags[] = ['alt', "Tournament draw: solo pool of {$tournament->name}, block {$tournament->draw_height}"];

        return $tags;
    }

    private function drawContent(Tournament $tournament, int $entrants, int $size): string
    {
        return "Solo pool of {$tournament->name}: {$entrants} players, teams of {$size}, drawn from the hash of block {$tournament->draw_height}. Players after the last full team are reserves.";
    }
}
