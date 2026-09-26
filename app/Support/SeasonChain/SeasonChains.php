<?php

namespace App\Support\SeasonChain;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Models\ChessGame;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonParameterChange;
use App\Models\SeriesMatch;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Board;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The season chain of the app (P7c): every rated result inside a live chain
 * season becomes a League Attestation (`2154`), signed by the league key, in
 * attestation order. A result with a winner is a block candidate; the
 * consensus rules of `season-chain-v1` (ConsensusRules) decide, with the
 * parameters in force at its attestation, whether it mines. A block carries
 * `["block", "<height>", "<previous block id>"]`, a win that does not mine
 * `["block", "", "<tip id>"]` and the rule that rejected it is stored.
 *
 * The chain is rebuilt from season_attestations for every attestation (a
 * replay through BlockChain, as a reader of the relays would do it), under a
 * lock on the season row, so two results cannot race for the same height.
 * Called inside the transaction that writes the result and its rating
 * change, so result, rating and block commit together or not at all.
 */
final class SeasonChains
{
    public const ATTESTATION = 2154;

    public const GENESIS = 2156;

    public const PARAMETER_CHANGE = 2158;

    /** The chain of a season, replayed from its stored attestations. */
    public function chain(Season $season): BlockChain
    {
        $chain = new BlockChain($season->chainParameters(), new ConsensusRules($season->minimum_trust));

        foreach ($season->attestations()->whereNotNull('candidate')->orderBy('id')->cursor() as $row) {
            $candidate = $row->toCandidate();

            if ($candidate !== null) {
                $chain->attest($candidate);
            }
        }

        return $chain;
    }

    /**
     * Attest a finished rated series. Null for a casual series, a series
     * without a result, or outside a live season (no ladder is open then,
     * and nothing belongs to a chain).
     */
    public function attestSeries(SeriesMatch $match): ?SeasonAttestation
    {
        if (! $match->rated || ! $match->status->hasResult() || $match->resolution === null) {
            return null;
        }

        $live = Seasons::live();
        $ladder = Ladders::address($match->game, $match->mode);

        if ($live === null || $ladder === null) {
            return null;
        }

        // One writer at a time per season: heights and links follow the lock order.
        $season = Season::query()->whereKey($live->id)->lockForUpdate()->firstOrFail();

        $existing = $season->attestations()->where(['source' => SeasonAttestation::SERIES, 'source_id' => $match->id, 'board' => 1])->first();

        if ($existing !== null) {
            return $existing;
        }

        $league = LeagueKey::required();
        $match->loadMissing(['reports.event', 'reports.responseEvent', 'challengeEvent', 'answerEvent', 'latestReport']);
        $attestedAt = $this->nextAttestationTime($season);
        // Tournament matches never mine (user, 2026-09-26: "die Chain gehört zur Season").
        $candidate = $match->tournament_match_id === null ? $this->seriesCandidate($match, $attestedAt) : null;

        $row = [
            'season_id' => $season->id,
            'source' => SeasonAttestation::SERIES,
            'source_id' => $match->id,
            'board' => 1,
            'match_number' => $match->number,
            'label' => $match->label(),
            'game' => $match->game,
            'mode' => $match->mode,
            'ladder_address' => $ladder,
            'attested_at' => $attestedAt,
            'candidate' => $candidate?->toArray(),
        ];
        $block = null;

        if ($candidate !== null) {
            $verdict = $this->chain($season)->attest($candidate);
            $tip = $this->tip($season);

            $row += [
                'height' => $verdict->mines() ? ($tip['height'] ?? 0) + 1 : null,
                'rule' => $verdict->rule?->value,
                'reason' => $verdict->reason,
                'subject' => $verdict->subject === null ? null : mb_substr($verdict->subject, 0, 200),
                'era' => $verdict->era,
                'reward_per_player' => $verdict->rewardPerPlayer,
                'reward' => $verdict->mines() ? $verdict->reward : 0,
                'link_event_id' => $tip['id'],
            ];
            $block = ['block', $verdict->mines() ? (string) $row['height'] : '', $tip['id']];
        }

        $event = $league->publish(self::ATTESTATION, $this->seriesTags($match, $season, $ladder, $block), $this->publicReason($match), $attestedAt->getTimestamp());

        return SeasonAttestation::query()->create($row + ['event_id' => $event->event_id, 'nostr_event_id' => $event->id]);
    }

    /**
     * Attest a finished rated chess game (P7d): one `2154` on the player
     * ladder of its mode with one `board` row (NIP "League Attestation",
     * "Chess"). Null for a casual or unfinished game, or outside a live
     * season. A decisive game is a block candidate; a draw is not.
     *
     * The game was played on the league's server, which checked every move,
     * and no player signed a report or a response for it: the resolution is
     * `admin` and the content says so. When a player's final record (`64`)
     * exists already, it is referenced.
     */
    public function attestChessGame(ChessGame $game): ?SeasonAttestation
    {
        if (! $game->rated || $game->status !== ChessGameStatus::Finished || ! in_array($game->result, ['1-0', '0-1', '1/2-1/2'], true)) {
            return null;
        }

        $live = Seasons::live();
        $ladder = Ladders::address('chess', $game->mode);

        if ($live === null || $ladder === null) {
            return null;
        }

        $season = Season::query()->whereKey($live->id)->lockForUpdate()->firstOrFail();
        $existing = $season->attestations()->where(['source' => SeasonAttestation::CHESS, 'source_id' => $game->id, 'board' => 1])->first();

        if ($existing !== null) {
            return $existing;
        }

        $league = LeagueKey::required();
        $game->loadMissing(['white', 'black', 'recordEvent']);
        $attestedAt = $this->nextAttestationTime($season);
        // Tournament games never mine (user, 2026-09-26: "die Chain gehört zur Season").
        $candidate = $game->tournament_match_id === null ? $this->chessCandidate($game, $attestedAt) : null;

        $row = [
            'season_id' => $season->id,
            'source' => SeasonAttestation::CHESS,
            'source_id' => $game->id,
            'board' => 1,
            'match_number' => $game->number,
            'label' => '#'.$game->number,
            'game' => 'chess',
            'mode' => $game->mode,
            'ladder_address' => $ladder,
            'attested_at' => $attestedAt,
            'candidate' => $candidate?->toArray(),
        ];
        $block = null;

        if ($candidate !== null) {
            $verdict = $this->chain($season)->attest($candidate);
            $tip = $this->tip($season);

            $row += [
                'height' => $verdict->mines() ? ($tip['height'] ?? 0) + 1 : null,
                'rule' => $verdict->rule?->value,
                'reason' => $verdict->reason,
                'subject' => $verdict->subject === null ? null : mb_substr($verdict->subject, 0, 200),
                'era' => $verdict->era,
                'reward_per_player' => $verdict->rewardPerPlayer,
                'reward' => $verdict->mines() ? $verdict->reward : 0,
                'link_event_id' => $tip['id'],
            ];
            $block = ['block', $verdict->mines() ? (string) $row['height'] : '', $tip['id']];
        }

        $content = $game->end_reason === ChessEndReason::Director
            ? 'Entered by the tournament director; not played on the league server and not confirmed by the players.'
            : 'Played on the league server, which checked every move; no signed report or response.';
        $event = $league->publish(self::ATTESTATION, $this->chessTags($game, $season, $ladder, $block), $content, $attestedAt->getTimestamp());

        return SeasonAttestation::query()->create($row + ['event_id' => $event->event_id, 'nostr_event_id' => $event->id]);
    }

    /**
     * The block candidate of a decisive rated game, or null (a draw). White
     * is the challenger, as in the rating (RatingService).
     */
    private function chessCandidate(ChessGame $game, CarbonImmutable $attestedAt): ?Candidate
    {
        if ($game->result === '1/2-1/2') {
            return null;
        }

        [$winner, $loser] = $game->result === '1-0' ? [$game->white->pubkey, $game->black->pubkey] : [$game->black->pubkey, $game->white->pubkey];
        $pin = GatePin::fromArray($game->gate_at_accept);
        $clans = $this->clans([$winner, $loser], $game->clans_at_accept);

        return new Candidate(
            '#'.$game->number,
            'chess:'.$game->id,
            'chess',
            'chess/'.$game->mode,
            $attestedAt,
            Resolution::Admin,
            intdiv($game->ply + 1, 2),
            [$winner],
            [$loser],
            $clans[$winner],
            [$game->white->pubkey, $game->black->pubkey],
            [$game->white->pubkey, $game->black->pubkey],
            $pin->connected ?? false,
            $pin?->ranks([$winner, $loser]) ?? [],
            $clans,
            $pin?->anchors([$winner, $loser]) ?? [],
        );
    }

    /**
     * The `2154` of a solo chess game: the players as challenger (White) and
     * challenged, one `board` row, `elo` per player, and the gate pinned at
     * the pairing.
     *
     * @param  list<string>|null  $block
     * @return list<list<string>>
     */
    private function chessTags(ChessGame $game, Season $season, string $ladder, ?array $block): array
    {
        $white = $game->white->pubkey;
        $black = $game->black->pubkey;
        $tags = [];

        if ($game->recordEvent !== null) {
            $tags[] = ['e', $game->recordEvent->event_id, '', $game->recordEvent->pubkey];
        }

        $tags[] = ['a', $ladder, ''];
        $tags[] = ['p', $white, '', 'challenger'];
        $tags[] = ['p', $black, '', 'challenged'];
        $tags[] = ['board', '1', $white, $black, (string) $game->result];
        $tags[] = ['resolution', Resolution::Admin->value];
        $tags[] = ['winner', match ($game->result) {
            '1-0' => 'challenger',
            '0-1' => 'challenged',
            default => 'draw',
        }];

        $players = [$game->white_id => $white, $game->black_id => $black];
        $changes = RatingChange::query()->with('rating')->where('source', RatingChange::CHESS)->where('source_id', $game->id)->orderBy('id')->get();

        foreach ($changes as $change) {
            $userId = $change->rating->user_id;

            if ($userId !== null && isset($players[$userId])) {
                $tags[] = ['elo', $players[$userId], (string) $change->before, (string) $change->after];
            }
        }

        $previous = $season->attestations()->where('ladder_address', $ladder)->orderByDesc('id')->value('event_id');

        if (is_string($previous)) {
            $tags[] = ['prev', $previous];
        }

        $tags[] = ['match', (string) $game->number];

        foreach (GatePin::fromArray($game->gate_at_accept)?->tags([$white, $black]) ?? [] as $tag) {
            $tags[] = $tag;
        }

        foreach ($this->clans([$white, $black], $game->clans_at_accept) as $pubkey => $clan) {
            if ($clan !== null) {
                $tags[] = ['clan', $pubkey, $clan];
            }
        }

        array_push($tags, ...$this->tournamentTags($game->tournament_match_id));

        if ($block !== null) {
            $tags[] = $block;
        }

        $tags[] = ['alt', "Esports league attestation: match #{$game->number}, chess {$game->mode} {$game->result}"];

        return $tags;
    }

    /**
     * A Parameter Change (`2158`) of the live season by a board admin: in
     * force for every attestation from `effective` on, never before (NIP
     * "Parameter changes"; the core refuses an `effective` at or before the
     * latest attestation). Only the parameters given change; the rest stay.
     *
     * @param  array{weights?: array<string, int>, shares?: array<string, int>, daily?: array<string, int>, pairlimit?: array{0: int, 1: int}|null, subtree?: int|null, moves?: int|null}  $changes
     *
     * @throws SeasonReleaseRefused
     */
    public function changeParameters(User $admin, array $changes, string $reason, CarbonImmutable $effective): SeasonParameterChange
    {
        if (! Board::contains($admin->pubkey)) {
            throw new SeasonReleaseRefused(__('Only a board member on the public admin list can change the chain rules.'));
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > SeasonRelease::MESSAGE_MAX) {
            throw new SeasonReleaseRefused(__('Say why you change it, up to :max characters. It is shown in the public change log.', ['max' => SeasonRelease::MESSAGE_MAX]));
        }

        if (array_diff(array_keys($changes), ['weights', 'shares', 'daily', 'pairlimit', 'subtree', 'moves']) !== []) {
            throw new SeasonReleaseRefused(__('Only weights, share caps, daily limits, pairing limits, the trust circle and the minimum moves can change during a season.'));
        }

        $changes = array_filter($changes, fn (mixed $value): bool => $value !== null && $value !== []);

        if ($changes === []) {
            throw new SeasonReleaseRefused(__('Nothing changed.'));
        }

        $league = LeagueKey::fromConfig() ?? throw new SeasonReleaseRefused(__('The league key is not set on this server, so the rules cannot change.'));

        return DB::transaction(function () use ($admin, $changes, $reason, $effective, $league): SeasonParameterChange {
            $live = Seasons::live() ?? throw new SeasonReleaseRefused(__('No season is live. Rules change only during a season.'));
            $season = Season::query()->whereKey($live->id)->lockForUpdate()->firstOrFail();
            $effective = CarbonImmutable::createFromTimestamp(max($effective->getTimestamp(), now()->getTimestamp()));
            $this->validateChanges($season, $changes);

            $change = new ParameterChange(CarbonImmutable::createFromTimestamp(now()->getTimestamp()), $effective, $admin->pubkey, $reason,
                $changes['weights'] ?? [], $changes['shares'] ?? [], $changes['daily'] ?? [], $changes['pairlimit'] ?? null, $changes['subtree'] ?? null, $changes['moves'] ?? null);

            try {
                $this->chain($season)->changeParameters($change);
            } catch (ChainViolation $violation) {
                throw new SeasonReleaseRefused(__('A rule change is never retroactive: it can only take effect after the latest saved result, and before the season ends.'), 0, $violation);
            }

            $adminList = app(SeasonRelease::class)->adminList($league);
            $tip = $this->tip($season);
            $tags = [
                ['e', $season->genesisId(), '', $season->league_pubkey],
                ['e', $adminList->event_id, '', $adminList->pubkey],
                ['effective', (string) $effective->getTimestamp()],
                ['tip', $tip['id']],
            ];

            foreach ($changes['weights'] ?? [] as $key => $milli) {
                $tags[] = ['weight', (string) $key, SeasonRelease::factor($milli)];
            }

            foreach ($changes['shares'] ?? [] as $game => $percent) {
                $tags[] = ['share', (string) $game, (string) $percent];
            }

            foreach ($changes['daily'] ?? [] as $game => $blocks) {
                $tags[] = ['daily', (string) $game, (string) $blocks];
            }

            if (isset($changes['pairlimit'])) {
                $tags[] = ['pairlimit', (string) $changes['pairlimit'][0], (string) $changes['pairlimit'][1]];
            }

            foreach (['subtree', 'moves'] as $name) {
                if (isset($changes[$name])) {
                    $tags[] = [$name, (string) $changes[$name]];
                }
            }

            $tags[] = ['p', $admin->pubkey, '', 'change'];
            $tags[] = ['alt', 'Esports season parameter change: '.$season->slug.', effective '.gmdate('Y-m-d H:i', $effective->getTimestamp()).' UTC'];

            $event = $league->publish(self::PARAMETER_CHANGE, $tags, $reason, $change->createdAt->getTimestamp());

            // A new version of every ladder with the standings so far (P7d). Without the
            // trust key there is no `trust` to repeat, and the ladders stay as they are.
            $trust = LeagueKey::trust();

            if ($trust !== null) {
                app(LadderEvents::class)->publish($season, $league, $trust->pubkey());
            }

            return SeasonParameterChange::query()->create([
                'season_id' => $season->id,
                'signed_at' => $change->createdAt,
                'effective_at' => $effective,
                'changed_by_id' => $admin->id,
                'changed_by_pubkey' => $admin->pubkey,
                'reason' => $reason,
                'parameters' => $changes,
                'tip_event_id' => $tip['id'],
                'nostr_event_id' => $event->id,
            ]);
        });
    }

    /**
     * The NIP ranges of each changeable parameter; weights only for a game
     * and mode of the genesis.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws SeasonReleaseRefused
     */
    private function validateChanges(Season $season, array $changes): void
    {
        $in = fn (mixed $value, int $min, int $max): bool => is_int($value) && $value >= $min && $value <= $max;
        $games = array_keys($season->parameters['shares'] + $season->parameters['daily']);
        $ok = true;

        foreach ((array) ($changes['weights'] ?? []) as $key => $milli) {
            $ok = $ok && array_key_exists($key, $season->parameters['weights']) && $in($milli, 0, 10_000);
        }

        foreach ((array) ($changes['shares'] ?? []) as $game => $percent) {
            $ok = $ok && in_array($game, $games, true) && $in($percent, 1, 100);
        }

        foreach ((array) ($changes['daily'] ?? []) as $game => $blocks) {
            $ok = $ok && in_array($game, $games, true) && $in($blocks, 1, 100);
        }

        if (isset($changes['pairlimit'])) {
            $pair = (array) $changes['pairlimit'];
            $ok = $ok && count($pair) === 2 && $in($pair[0] ?? null, 1, 100) && $in($pair[1] ?? null, 1, 1000);
        }

        $ok = $ok && (! isset($changes['subtree']) || $in($changes['subtree'], 1, 101)) && (! isset($changes['moves']) || $in($changes['moves'], 1, 200));

        if (! $ok) {
            throw new SeasonReleaseRefused(__('A value is out of range. Check the limits next to each field.'));
        }
    }

    /**
     * The newest block (or the genesis): its height and the id a `block` tag names.
     *
     * @return array{height: ?int, id: string}
     */
    public function tip(Season $season): array
    {
        $last = $season->attestations()->whereNotNull('height')->orderByDesc('height')->first();

        return $last === null ? ['height' => null, 'id' => $season->genesisId()] : ['height' => $last->height, 'id' => $last->event_id];
    }

    /**
     * Whole seconds, never before the previous attestation: the chain only
     * grows forward (BlockChain refuses anything older).
     */
    private function nextAttestationTime(Season $season): CarbonImmutable
    {
        $now = CarbonImmutable::createFromTimestamp(now()->getTimestamp());
        $latest = $season->attestations()->max('attested_at');

        if ($latest === null) {
            return $now;
        }

        $latest = CarbonImmutable::parse((string) $latest);

        return $now->lt($latest) ? $latest : $now;
    }

    /**
     * The block candidate of a series with a winner, or null (void, no winner).
     */
    private function seriesCandidate(SeriesMatch $match, CarbonImmutable $attestedAt): ?Candidate
    {
        if (! in_array($match->winner, SeriesMatch::SIDES, true) || $match->resolution === SeriesResolution::Void) {
            return null;
        }

        $roster = $this->roster($match);
        $winners = array_values(array_map(fn (array $entry): string => $entry['pubkey'], array_filter($roster, fn (array $entry): bool => $entry['side'] === $match->winner)));
        $losers = array_values(array_map(fn (array $entry): string => $entry['pubkey'], array_filter($roster, fn (array $entry): bool => $entry['side'] !== $match->winner)));
        // Rule 1 and 7 read the gate pinned at the accept, never live trust
        // facts (NIP "Nothing after the accept undoes the gate"). Without a
        // pin every player is unranked and nobody is connected: no block.
        $pin = GatePin::fromArray($match->gate_at_accept);
        $gatekeepers = $pin->gatekeepers ?? [(string) $match->createdBy?->pubkey, (string) $match->answeredBy?->pubkey];

        return new Candidate(
            $match->label(),
            'series:'.$match->id,
            $match->game,
            $match->game.'/'.$match->mode,
            $attestedAt,
            match ($match->resolution) {
                SeriesResolution::Confirmed => Resolution::Confirmed,
                SeriesResolution::Forfeit => Resolution::Forfeit,
                default => Resolution::Admin,
            },
            null,
            $winners,
            $losers,
            self::subjects($match)[$match->winner === 'challenger' ? 'challenger' : 'challenged'],
            [self::subjects($match)['challenger'], self::subjects($match)['challenged']],
            $gatekeepers,
            $pin->connected ?? false,
            $pin?->ranks([...$winners, ...$losers]) ?? [],
            $this->clans([...$winners, ...$losers], $match->clans_at_accept),
            $pin?->anchors([...$winners, ...$losers]) ?? [],
        );
    }

    /**
     * The roster the result counts: an admin decision's, else the latest
     * report's, as signed.
     *
     * @return list<array{user_id: int, pubkey: string, name: string, side: string, role: string}>
     */
    private function roster(SeriesMatch $match): array
    {
        return $match->countedRoster();
    }

    /**
     * Each player's clan address at the accept (stored on the series or game
     * then), null without a clan. A player the accept did not see has none:
     * a rated roster only lists players of the accept (SeriesService), and
     * the clan now is never read, so a clan change after the accept cannot
     * move a result in or out of rule 3.
     *
     * @param  list<string>  $pubkeys
     * @param  array<string, string>|null  $atAccept
     * @return array<string, ?string>
     */
    private function clans(array $pubkeys, ?array $atAccept): array
    {
        $clans = [];

        foreach ($pubkeys as $pubkey) {
            $clans[$pubkey] = $atAccept[$pubkey] ?? null;
        }

        return $clans;
    }

    /**
     * The `2154` of a series (NIP "League Attestation"), with the `trust` tag
     * and `gate` rows pinned at the accept.
     *
     * @param  list<string>|null  $block
     * @return list<list<string>>
     */
    private function seriesTags(SeriesMatch $match, Season $season, string $ladder, ?array $block): array
    {
        $tags = [];

        foreach ([$match->challengeEvent, $match->answerEvent] as $event) {
            if ($event !== null) {
                $tags[] = ['e', $event->event_id, '', $event->pubkey];
            }
        }

        foreach ($match->reports as $report) {
            foreach ([$report->event, $report->responseEvent] as $event) {
                if ($event !== null) {
                    $tags[] = ['e', $event->event_id, '', $event->pubkey];
                }
            }
        }

        $tags[] = ['a', $ladder, ''];
        $tags[] = ['a', $match->challenger_lineup_address, '', 'challenger'];
        $tags[] = ['a', $match->challenged_lineup_address, '', 'challenged'];

        foreach ($this->roster($match) as $entry) {
            $tags[] = ['p', $entry['pubkey'], '', $entry['side'], $entry['role']];
        }

        foreach ($match->result_games ?? [] as $index => $game) {
            $score = ['score', (string) ($index + 1), $game['winner']];

            if ($game['challenger'] !== null && $game['challenged'] !== null) {
                $score[] = (string) $game['challenger'];
                $score[] = (string) $game['challenged'];
            }

            $tags[] = $score;
        }

        $tags[] = ['resolution', $match->resolution->value];
        $tags[] = ['winner', (string) $match->winner];

        $subjects = self::subjects($match);
        $entities = [$subjects['challenger'] => $match->challenger_lineup_address, $subjects['challenged'] => $match->challenged_lineup_address];
        $changes = RatingChange::query()->with('rating')->where('source', RatingChange::SERIES)->where('source_id', $match->id)->orderBy('id')->get();

        foreach ($changes as $change) {
            $subject = $change->rating->subject;

            if (isset($entities[$subject])) {
                $tags[] = ['elo', $entities[$subject], (string) $change->before, (string) $change->after];
            }
        }

        $previous = $season->attestations()->where('ladder_address', $ladder)->orderByDesc('id')->value('event_id');

        if (is_string($previous)) {
            $tags[] = ['prev', $previous];
        }

        $tags[] = ['match', (string) $match->number];

        foreach (GatePin::fromArray($match->gate_at_accept)?->tags(array_column($this->roster($match), 'pubkey')) ?? [] as $tag) {
            $tags[] = $tag;
        }

        foreach ($this->clans(array_column($this->roster($match), 'pubkey'), $match->clans_at_accept) as $pubkey => $clan) {
            if ($clan !== null) {
                $tags[] = ['clan', $pubkey, $clan];
            }
        }

        array_push($tags, ...$this->tournamentTags($match->tournament_match_id));

        if ($block !== null) {
            $tags[] = $block;
        }

        $tags[] = ['alt', "Esports league attestation: match #{$match->number}, {$match->game} {$match->mode}, ".($match->resolution->value)];

        return $tags;
    }

    /**
     * The two rated entities of a series: pinned at a rated accept, else the
     * lineups it names now.
     *
     * @return array{challenger: string, challenged: string}
     */
    private static function subjects(SeriesMatch $match): array
    {
        $pinned = $match->rated_subjects ?? [];

        return [
            'challenger' => $pinned['challenger'] ?? 'lineup:'.$match->challenger_lineup_id,
            'challenged' => $pinned['challenged'] ?? 'lineup:'.$match->challenged_lineup_id,
        ];
    }

    /**
     * The tournament `a` of an attestation whose match a tournament paired
     * (NIP rev. 4), and for a result the tournament directors entered the
     * `entered-by` tag with the director's pubkey (open question 11, proposal
     * of the plan; for the nostr-specialist to confirm).
     *
     * @return list<list<string>>
     */
    private function tournamentTags(?int $tournamentMatchId): array
    {
        $match = $tournamentMatchId === null ? null : TournamentMatch::query()->with('tournament.event')->find($tournamentMatchId);

        if ($match === null) {
            return [];
        }

        $tags = [];
        $address = $match->tournament->address();

        if ($address !== null) {
            $tags[] = ['a', $address, ''];
        }

        if ($match->isDirectorResult()) {
            $pubkey = User::query()->whereKey((int) ($match->result['corrected']['user_id'] ?? $match->result['user_id'] ?? 0))->value('pubkey');

            if (is_string($pubkey)) {
                $tags[] = ['entered-by', $pubkey];
            }
        }

        return $tags;
    }

    /** NIP: with `admin`, `forfeit` or `void` the content states the public reason. */
    private function publicReason(SeriesMatch $match): string
    {
        return $match->resolution === SeriesResolution::Confirmed ? '' : (string) $match->resolution_reason;
    }
}
