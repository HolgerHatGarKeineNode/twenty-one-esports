<?php

use App\Enums\Platform;
use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\ChatMute;
use App\Models\LineupSeat;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\Opponents;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualLobby;
use App\Support\Series\CasualMatches;
use App\Support\Series\SeriesPresenter;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\TournamentGameEnd;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * The match room of the two lineups, 1:1 from MatchRoom.dc.html and
 * MobileMatchRoom.dc.html, with the Submit dialog and the win moment of
 * Overlays.dc.html. Only players of the two lineups get in; everyone else is
 * sent to the public match page, so the lobby never reaches them.
 *
 * Casual until Block 0: no Elo, no mining, no league check; the "can mine"
 * line of the design is the "Casual until Block 0" line of States.dc.html.
 * Signed steps go through nostrAction (resources/js/nostrSign.js): for a
 * casual match every prepare returns no template and nothing is signed.
 */
new #[Title('Match room')] #[Layout('layouts::app', ['section' => 'matches', 'scripts' => ['resources/js/matchRoom.js']])] class extends Component
{
    use WithFileUploads;

    public SeriesMatch $match;

    /** @var list<array{c: int|string|null, d: int|string|null, unknown: bool, winner: string|null}> */
    public array $sheet = [];

    public ?int $pickedStart = null;

    /** A scheduled casual 1v1 (P23 S4): the platform the challenged player answers with. */
    public string $casualPlatform = '';

    public bool $casualCrossplay = true;

    public string $reason = '';

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $shots = [];

    public bool $editLobby = false;

    public string $lobbyName = '';

    public string $lobbyPassword = '';

    public ?string $lobbyRegion = null;

    public string $error = '';

    /** Fingerprint of what the last render showed; an unchanged room skips the render on sync(). */
    #[Locked]
    public string $shown = '';

    /** Unix time of the last render: a tick renders at least every RENDER_AT_LEAST seconds, whatever the fingerprint says. */
    #[Locked]
    public int $renderedAt = 0;

    /** Safety net for whatever the fingerprint does not see. */
    public const RENDER_AT_LEAST = 60;

    /** The match with every relation the room reads, loaded once per request (fresh()). */
    private ?SeriesMatch $current = null;

    public function mount(SeriesMatch $match): mixed
    {
        $this->match = $match;

        if ($this->fresh()->participantSideOf($this->user()) === null) {
            return $this->redirectRoute('matches.show', $match);
        }

        $this->pickedStart = $match->proposals[0] ?? null;
        $this->casualPlatform = $this->user()->platform?->value ?? Platform::Pc->value;
        $this->readSheet();

        return null;
    }

    /**
     * The 8 s tick of the page: pull the live sheet written by the other
     * captain. Most ticks find nothing new; they answer without a render
     * (P5g: the full room was ~57 KB per tick) and the page stays as it is.
     */
    public function sync(): void
    {
        $this->readSheet();

        if ($this->fingerprint() === $this->shown && now()->getTimestamp() - $this->renderedAt < self::RENDER_AT_LEAST) {
            $this->skipRender();
        }
    }

    public function rendering(): void
    {
        $this->shown = $this->fingerprint();
        $this->renderedAt = now()->getTimestamp();

        // The chat sits in wire:ignore: hand it the casual state (host, flags) on every render.
        if (($state = $this->casualState()) !== null) {
            $this->dispatch('casual-room', state: $state);
        }
    }

    /** Rebuild the sheet from the stored live games. */
    private function readSheet(): void
    {
        $match = $this->fresh();
        $live = $match->live_games ?? [];
        // A game without goals (Age of Empires II) has only a winner per game: every row is a winner-only row.
        $winnersOnly = ! $match->hasGoals();
        $this->sheet = [];

        for ($i = 0; $i < $this->match->best_of; $i++) {
            $game = $live[$i] ?? null;
            $this->sheet[] = [
                'c' => $game['challenger'] ?? null,
                'd' => $game['challenged'] ?? null,
                'unknown' => $winnersOnly || ($game !== null && ($game['winner'] ?? null) !== null && ($game['challenger'] ?? null) === null),
                'winner' => $game['winner'] ?? null,
            ];
        }

        unset($this->room);
    }

    public function updatedSheet(mixed $value, string $key): void
    {
        $index = (int) explode('.', $key)[0];
        $row = $this->sheet[$index] ?? null;

        if ($row === null) {
            return;
        }

        // A game without goals never sends any, whatever the form holds.
        $row['unknown'] = $row['unknown'] || ! $this->match->hasGoals();
        $goal = fn (mixed $v): ?int => $v === null || $v === '' ? null : (is_numeric($v) ? (int) $v : -1);
        $c = $row['unknown'] ? null : $goal($row['c']);
        $d = $row['unknown'] ? null : $goal($row['d']);

        // Half a score is still being typed: wait for the other box.
        if (! $row['unknown'] && ($c === null) !== ($d === null)) {
            return;
        }

        $this->attempt(fn () => app(SeriesService::class)->saveLiveGame($this->match, $this->user(), $index, $c, $d, $row['unknown'] ? $row['winner'] : null));
        $this->readSheet();
    }

    public function toggleRoster(int $userId): void
    {
        $match = $this->fresh();
        $side = $match->captainSideOf($this->user());

        if ($side === null) {
            return;
        }

        $current = array_map(fn (LineupSeat $seat) => $seat->user_id, app(SeriesService::class)->rosterSeats($match, $side));
        $next = in_array($userId, $current, true) ? array_values(array_diff($current, [$userId])) : [...$current, $userId];

        $this->attempt(fn () => app(SeriesService::class)->setRoster($match, $this->user(), $next));
        unset($this->room);
    }

    public function openLobbyEditor(): void
    {
        $match = $this->fresh();

        if ($match->captainSideOf($this->user()) === null) {
            return;
        }

        $this->lobbyName = (string) $match->lobby_name;
        $this->lobbyPassword = (string) $match->lobby_password;
        $this->lobbyRegion = $match->lobby_region ?? 'EU';
        $this->editLobby = true;
    }

    public function saveLobby(): void
    {
        if ($this->attempt(fn () => app(SeriesService::class)->setLobby($this->match, $this->user(), $this->lobbyName, $this->lobbyPassword, $this->lobbyRegion))) {
            $this->editLobby = false;
            $this->reset('lobbyName', 'lobbyPassword');
        }
    }

    public function checkInLobby(): void
    {
        $this->attempt(fn () => app(SeriesService::class)->checkInLobby($this->match, $this->user()));
    }

    public function reportNoShow(): void
    {
        $this->attempt(fn () => app(SeriesService::class)->reportNoShow($this->match, $this->user()));
    }

    /* Two-phase actions for nostrAction: prepare returns the templates (none when casual). */

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareAnswer(string $status, ?int $start = null): ?array
    {
        return $this->attempt(fn () => app(SeriesService::class)->prepareAnswer($this->match, $this->user(), $status, $start));
    }

    public function answer(string $status, ?int $start, string $signed): void
    {
        $this->attempt(fn () => app(SeriesService::class)->answer($this->match, $this->user(), $status, $start, $this->decode($signed)));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareReport(): ?array
    {
        return $this->attempt(fn () => app(SeriesService::class)->prepareReport($this->match, $this->user()));
    }

    public function report(string $signed): void
    {
        $this->attempt(fn () => app(SeriesService::class)->report($this->match, $this->user(), $this->decode($signed)));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareResponse(string $status): ?array
    {
        if ($status === 'disputed') {
            $this->validate(['reason' => ['required', 'string', 'max:'.SeriesService::REASON_MAX], 'shots' => ['array', 'max:3'], 'shots.*' => ['image', 'max:5120']]);
        }

        return $this->attempt(fn () => app(SeriesService::class)->prepareResponse($this->match, $this->user(), $status, $this->reason));
    }

    public function respond(string $status, string $signed): void
    {
        $done = $this->attempt(fn () => app(SeriesService::class)->respond($this->match, $this->user(), $status, $this->reason, $this->decode($signed)));

        if ($done !== null && $status === 'disputed') {
            foreach ($this->shots as $shot) {
                app(SeriesService::class)->addEvidence($this->match, $this->user(), $shot);
            }

            $this->reset('reason', 'shots');
        }
    }

    /* Casual 1v1 (P23): the steps of the room, each a CasualMatches action. */

    /* Scheduled casual 1v1 (P23 S4): answer the challenge, check in. */

    public function casualAccept(): void
    {
        $this->attempt(fn () => app(CasualChallenges::class)->accept($this->match, $this->user(), (int) $this->pickedStart,
            Platform::tryFrom($this->casualPlatform) ?? throw CasualMatches::refuse('platforms_incompatible'), $this->casualCrossplay));
    }

    public function casualDecline(): void
    {
        $this->attempt(fn () => app(CasualChallenges::class)->decline($this->match, $this->user()));
    }

    public function casualWithdraw(): void
    {
        $this->attempt(fn () => app(CasualChallenges::class)->withdraw($this->match, $this->user()));
    }

    public function casualCheckIn(): void
    {
        $this->attempt(fn () => app(CasualMatches::class)->checkIn($this->match, $this->user()));
    }

    public function casualReady(): void
    {
        $this->attempt(fn () => app(CasualMatches::class)->ready($this->match, $this->user()));
    }

    public function casualJoined(): void
    {
        $this->attempt(fn () => app(CasualMatches::class)->markJoined($this->match, $this->user()));
    }

    public function casualSwapHost(): void
    {
        $this->attempt(fn () => app(CasualMatches::class)->swapHost($this->match, $this->user()));
    }

    public function casualClaimNoShow(): void
    {
        $this->attempt(fn () => app(CasualMatches::class)->claimNoShow($this->match, $this->user()));
    }

    public function casualContestNoShow(): void
    {
        $this->attempt(fn () => app(CasualMatches::class)->contestNoShow($this->match, $this->user()));
    }

    /**
     * "Rematch" after a result (P23 S3): a direct invite to the opponent,
     * open for `esports.casual.rematch_seconds` (CasualInvites::rematch()).
     * Accepted, both get the ready prompt of the new match.
     */
    public function casualRematch(): void
    {
        $this->attempt(fn () => app(CasualInvites::class)->rematch($this->fresh(), $this->user()));
    }

    public function casualWithdrawRematch(): void
    {
        $this->attempt(fn () => app(CasualInvites::class)->withdrawOutgoing($this->user()));
    }

    public function casualAcceptRematch(int $inviteId): void
    {
        $this->attempt(function (): void {
            $invite = $this->rematchInvites()['incoming'] ?? throw CasualMatches::refuse('invite_closed');
            $side = $this->fresh()->participantSideOf($this->user()) ?? 'challenged';
            $choice = (array) ($this->fresh()->casual['queue'][$side] ?? []);
            $settings = app(CasualLobby::class)->settings($this->user(), $invite->game);
            app(CasualInvites::class)->accept($invite, $this->user(),
                Platform::tryFrom((string) ($choice['platform'] ?? '')) ?? $settings['platform'], (bool) ($choice['crossplay'] ?? $settings['crossplay']));
        });

        // The ready prompt of every page shows the new match at once.
        $this->dispatch('casual-changed');
    }

    public function casualDeclineRematch(): void
    {
        $this->attempt(function (): void {
            $invite = $this->rematchInvites()['incoming'];

            if ($invite !== null) {
                app(CasualInvites::class)->close($invite, $this->user());
            }
        });
    }

    /**
     * The open rematch invites between the two players of this finished
     * casual match: the one this player sent, the one they received.
     *
     * @return array{outgoing: SeriesInvite|null, incoming: SeriesInvite|null}
     */
    public function rematchInvites(): array
    {
        $match = $this->fresh();
        $side = $match->participantSideOf($this->user());
        $opponent = $side === null ? null : User::query()->find($match->rosterSide(SeriesMatch::otherSide($side))[0] ?? 0);

        if (! $match->isCasualPairing() || ! $match->status->hasResult() || $opponent === null) {
            return ['outgoing' => null, 'incoming' => null];
        }

        $lobby = app(CasualLobby::class);

        return ['outgoing' => $lobby->openInvite($this->user(), $opponent, $match->game), 'incoming' => $lobby->openInvite($opponent, $this->user(), $match->game)];
    }

    /**
     * The host's chat got `OK true` for the card's wrap to the opponent
     * (resources/js/roomChat.js). No arguments: the league learns that a
     * card went out, nothing about it (NIP "Telling the league").
     *
     * @return array{ok: bool, reason?: string, message?: string}
     */
    public function casualLobbyShared(): array
    {
        return $this->flag(fn () => app(CasualMatches::class)->shareLobby($this->match, $this->user()));
    }

    /**
     * The guest's chat opened a valid card from the host. Same shape as
     * casualLobbyShared(); `no_lobby_yet` makes the chat ask again.
     *
     * @return array{ok: bool, reason?: string, message?: string}
     */
    public function casualLobbySeen(): array
    {
        return $this->flag(fn () => app(CasualMatches::class)->seeLobby($this->match, $this->user()));
    }

    /**
     * A flag from the chat: answered to the chat, not on the room's error line.
     *
     * @param  callable(): SeriesMatch  $action
     * @return array{ok: bool, reason?: string, message?: string}
     */
    private function flag(callable $action): array
    {
        try {
            $action();

            return ['ok' => true];
        } catch (SeriesRuleViolation $refused) {
            return ['ok' => false, 'reason' => $refused->reason, 'message' => $refused->getMessage()];
        } finally {
            $this->forget();
        }
    }

    /**
     * What the chat of a casual 1v1 needs from the league, null in any other
     * room: the host, the two flags, whether the match runs, and `A + D` for
     * the NIP-40 expiration (SeriesMatch::casualChatExpiresFrom()).
     *
     * @return array{game: string, isHost: bool, hostPubkey: string|null, started: bool, underWay: bool, open: bool, shared: bool, seen: bool, expiresFrom: int|null}|null
     */
    private function casualState(): ?array
    {
        $match = $this->fresh();

        if (! $match->isCasualPairing()) {
            return null;
        }

        $mySide = $match->participantSideOf($this->user());
        $hostId = $match->rosterSide((string) $match->host_side)[0] ?? null;

        return [
            'game' => $match->game,
            'isHost' => $mySide !== null && $mySide === $match->host_side,
            'hostPubkey' => $hostId === null ? null : User::query()->whereKey($hostId)->value('pubkey'),
            'started' => $match->status === SeriesStatus::Accepted && $match->start_at !== null,
            // A scheduled match has its `start_at` from the accept on, but lobby and seen flags count only after both checked in.
            'underWay' => $match->casualUnderWay(),
            'open' => ! $match->status->hasResult(),
            'shared' => $match->lobby_shared_at !== null,
            'seen' => $match->lobby_seen_at !== null,
            'expiresFrom' => $match->casualChatExpiresFrom()?->getTimestamp(),
        ];
    }

    public function setMuted(string $pubkey, bool $mute): bool
    {
        $user = $this->user();
        $members = array_column($this->chatConfig()['members'], 'pubkey');

        // Only players of this room, never oneself.
        if ($pubkey === $user->pubkey || ! in_array($pubkey, $members, true)) {
            return false;
        }

        if ($mute) {
            ChatMute::query()->firstOrCreate(['user_id' => $user->id, 'muted_pubkey' => $pubkey]);
        } else {
            ChatMute::query()->where('user_id', $user->id)->where('muted_pubkey', $pubkey)->delete();
        }

        return true;
    }

    /**
     * Everything the view needs, read once per render.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function room(): array
    {
        $match = $this->fresh();
        $user = $this->user();
        $series = app(SeriesService::class);
        $draft = null;
        $draftError = null;

        if (in_array($match->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true)) {
            try {
                $draft = $series->draftReport($match, $match->captainSideOf($user));
            } catch (SeriesRuleViolation $violation) {
                $draftError = $violation->getMessage();
            }
        }

        $rosters = [];

        foreach (SeriesMatch::SIDES as $side) {
            $chosen = array_map(fn (LineupSeat $seat) => $seat->user_id, $series->rosterSeats($match, $side));
            // A rated match offers the players pinned at the accept (NIP condition 3), a casual one the active seats.
            $rosters[$side] = array_map(fn (LineupSeat $seat) => ['seat' => $seat, 'on' => in_array($seat->user_id, $chosen, true)], $series->rosterChoices($match, $side));
        }

        return [
            'match' => $match,
            'mySide' => $match->participantSideOf($user),
            'captainSide' => $match->captainSideOf($user),
            'report' => $match->latestReport,
            'draft' => $draft,
            'draftError' => $draftError,
            'rosters' => $rosters,
        ];
    }

    /**
     * Config for the room chat (resources/js/roomChat.js): every active
     * player of both lineups, the chat relays, the viewer's mutes.
     *
     * @return array<string, mixed>
     */
    public function chatConfig(): array
    {
        $match = $this->room['match'];
        $members = [];

        foreach (SeriesMatch::SIDES as $side) {
            foreach ($match->lineup($side)?->activeSeats() ?? [] as $seat) {
                $members[$seat->user->pubkey] = ['pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side];
            }

            // A tournament's roster side (mix team, RL 1v1 player) has no lineup (P8b).
            foreach (\App\Models\User::query()->whereIn('id', $match->rosterSide($side))->get() as $player) {
                $members[$player->pubkey] = ['pubkey' => $player->pubkey, 'name' => $player->displayName(), 'side' => $side];
            }
        }

        $casual = $this->casualState();

        if ($casual !== null) {
            $casual += [
                // Prefills of the composer, for this player only: a lobby name, and the EA ID from the private gamer tags.
                'lobbyName' => \App\Support\LobbyWords::matchName($match->number),
                'eaId' => (string) ($this->user()->gamer_tags['ea'] ?? ''),
                // The league's lobby defaults, one English line in the lobby card's text (LobbyRules, P9).
                'lobbyRules' => \App\Support\Series\LobbyRules::line($match->game, 'en'),
            ];

            // Age of Empires II: the player's own Steam and Xbox names from the private gamer tags, sent only on Send card.
            if ($match->game === 'age-of-empires-2') {
                $casual['accounts'] = array_map(fn (string $service): string => (string) ($this->user()->gamer_tags[$service] ?? ''), ['steam' => 'steam', 'xbox' => 'xbox']);
            }
        }

        return [
            'me' => $this->user()->pubkey,
            'members' => array_values($members),
            'casual' => $casual,
            'match' => $match->number,
            // A series runs for days: the chat reads back to the challenge (gameChat.js does the same per game).
            'since' => $match->created_at?->getTimestamp(),
            // An opponent's reply from another NIP-17 client (no `match` tag) counts until the result plus a grace (nostrChat.js dmReplies).
            'settled' => $match->finished_at?->getTimestamp(),
            'relays' => array_values(config('esports.chat.relays', [])),
            // Where the browser looks up the members' DM relays (10050): the chat and the profile relays, as the server does.
            'lookupRelays' => \App\Support\Notifications\DmRelays::lookupRelays(),
            'muted' => $this->user()->mutedPubkeys(),
            'labels' => [
                'you' => __('you'),
                'mute' => __('Mute'),
                'muted' => __('Muted'),
                'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
                'notSent' => __('The message did not reach any relay. Please try again.'),
                'failed' => __('That did not work. Please try again.'),
                'copy' => __('Copy'),
                'lobbyTitle' => $match->game === 'age-of-empires-2' ? __('Age of Empires II lobby') : __('Rocket League private match'),
                'accountTitle' => $match->game === 'age-of-empires-2' ? __('Steam or Xbox name to find each other') : __('EA ID for a friend request'),
                'cardName' => __('Name'),
                'cardPassword' => __('Password'),
                'cardEaId' => __('EA ID'),
                'cardReplaced' => __('Replaced by a newer card'),
                'lobbyClosed' => __('Lobby closed'),
                'accountWithdrawn' => $match->game === 'age-of-empires-2' ? __('Name withdrawn') : __('EA ID withdrawn'),
                'cardNotSent' => __('The card did not reach your opponent\'s relays. Please try again.'),
                'cardInvalid' => __('Every field needs 1 to 64 characters, without line breaks.'),
                'cardOneOpponent' => __('A card goes to exactly one opponent, and this room has more players.'),
                'viaDm' => __('via Nostr DM'),
            ],
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|null
     */
    /** P57: an add from the "you do not list each other" notice; the refusal it answered is gone. */
    #[On('opponent-list-changed')]
    public function opponentListChanged(): void
    {
        $this->error = '';
    }

    private function attempt(callable $action): mixed
    {
        $this->error = '';

        try {
            $result = $action();
            $this->forget();

            return $result ?? true;
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();
        } catch (RejectedEvent) {
            $this->error = __('The signed event was refused. Please try again.');
        }

        $this->forget();

        return null;
    }

    /** After a write: the next fresh() reads the match again, and the view data is rebuilt. */
    private function forget(): void
    {
        $this->current = null;
        unset($this->room);
    }

    /**
     * Everything a render depends on that can change while the page is open:
     * the match row with all loaded relations (every write of SeriesService
     * touches the row: sheet, rosters, lobby, report, answer, decision; the
     * clan owners, who carry the captain lines), the ratings of both lineups
     * as the Elo block reads them (Ratings::forSeries: rating rows and the
     * series' rating changes, which live outside the match row), the two
     * clock edges the view compares against now (kick-off, no-show window),
     * a tournament's pause and status, and the error line. Whatever this misses, sync() still renders once
     * every RENDER_AT_LEAST seconds.
     */
    private function fingerprint(): string
    {
        $match = $this->fresh();
        $noshowFrom = $match->noshowReportableAt();

        // A casual match: the open rematch invites and which deadline runs (a claim opens when one passes).
        $casual = $match->isCasualPairing()
            ? [array_map(fn (?SeriesInvite $invite) => $invite?->only(['id', 'status']), $this->rematchInvites()), $match->casualNextDeadline()['kind'] ?? null, $match->casualLobbyDueAt()?->isPast(), $match->casualJoinDueAt()?->isPast()]
            : null;

        // A tournament series: the tournament's pause and status (the auto-decision line) live outside the match row.
        $tournament = $match->tournament_match_id === null ? null
            : DB::table('tournament_matches')->join('tournaments', 'tournaments.id', '=', 'tournament_matches.tournament_id')
                ->where('tournament_matches.id', $match->tournament_match_id)->first(['tournaments.paused_at', 'tournaments.status']);

        return hash('xxh128', (string) json_encode([$match->toArray(), Ratings::forSeries($match), $match->start_at?->isFuture(), $noshowFrom?->isFuture(), $this->error, $casual, $tournament]));
    }

    /**
     * @return list<mixed>
     */
    private function decode(string $signed): array
    {
        $events = json_decode($signed, true);

        return is_array($events) && array_is_list($events) ? $events : [['malformed']];
    }

    private function fresh(): SeriesMatch
    {
        return $this->current ??= SeriesMatch::query()
            ->with(['challengerLineup.clan.owner', 'challengerLineup.seats.user.clanMember', 'challengedLineup.clan.owner', 'challengedLineup.seats.user.clanMember',
                'latestReport.event', 'latestReport.responseEvent', 'latestReport.user', 'challengeEvent', 'answerEvent', 'createdBy', 'answeredBy'])
            ->findOrFail($this->match->id);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    [
        'match' => $m, 'mySide' => $mySide, 'captainSide' => $captainSide, 'report' => $report,
        'draft' => $draft, 'draftError' => $draftError, 'rosters' => $rosters,
    ] = $this->room;
    $viewer = auth()->user();
    $other = $mySide === null ? 'challenged' : SeriesMatch::otherSide($mySide);
    $games = $m->currentGames();
    $wins = SeriesMatch::seriesScore($games);
    // Age of Empires II has no goals: the sheet asks for the winner of each game only.
    $hasGoals = $m->hasGoals();
    $chip = SeriesPresenter::chip($m);
    $elo = SeriesPresenter::ratingFacts($m);
    // A tournament whose directors enter the results: the players report and accept nothing (P8b).
    $directorEntered = SeriesService::isDirectorEntered($m);
    $editable = ! $directorEntered && $captainSide !== null && in_array($m->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true) && ! $m->start_at?->isFuture();
    $toAnswer = ! $directorEntered && $m->status === SeriesStatus::Reported && $report?->status === ReportStatus::Open && $captainSide !== null && $captainSide !== $report->side;
    $playing = count(array_filter($this->sheet, fn ($g) => $g['winner'] !== null));
    $captainOf = fn (string $side) => $m->lineup($side)?->clan?->owner?->displayName() ?? '';
    $noshowFrom = $m->noshowReportableAt();
    $checks = ($report !== null && $report->status !== ReportStatus::Superseded ? 1 : 0) + ($m->status->hasResult() ? 1 : 0);
    $status = match ($m->status) {
        SeriesStatus::Open => [__('Waiting for an answer'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Accepted => $m->start_at?->isFuture() ? [__('Starts :time', ['time' => SeriesPresenter::time($m->start_at, $viewer, 'D H:i')]), 'bg-well text-ink-2'] : [__('Score to submit'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Reported => [__('Not confirmed yet'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Disputed => [__('Disputed'), 'bg-loss-tint text-loss'],
        SeriesStatus::Confirmed, SeriesStatus::Resolved => [__('Result saved'), 'bg-win-tint text-win'],
        default => [$chip['label'], 'bg-well text-ink-2'],
    };
    $sideColor = ['challenger' => '#F7931A', 'challenged' => '#3A3A42'];
    $sideInk = ['challenger' => 'text-on-btc', 'challenged' => 'text-ink'];
    $seriesMeta = implode(', ', array_filter([
        'BO'.$m->best_of,
        $m->mode,
        ! $m->status->hasResult() && $games !== [] ? __('provisional') : null,
        $m->status === SeriesStatus::Accepted && ! $m->start_at?->isFuture() ? __('game :n playing', ['n' => min($m->best_of, $playing + 1)]) : null,
    ]));
    // A casual 1v1 (P23): the steps and the chat come first, with the lobby as a card in the chat.
    $casual = $m->isCasualPairing();
    $casualFirst = $casual && ! $m->status->hasResult();
    // A tournament series on the league's deadlines (P18, slice 5): the countdown to the automatic decision. A casual cup's series has it in its steps.
    $wait = ! $casual && $m->deadlines !== null && ! $m->status->hasResult() ? \App\Support\Tournaments\TournamentWaits::ofPlay($m) : null;
    $steps = [
        [__('Challenge'), SeriesPresenter::time($m->created_at ?? now(), $viewer, 'D H:i'), __('sent by :clan', ['clan' => $m->challenger_name]), true],
        [__('Accepted'), $m->answered_at && $m->start_at ? SeriesPresenter::time($m->answered_at, $viewer, 'D H:i') : '', $m->answered_at && $m->start_at ? __('by :clan', ['clan' => $m->challenged_name]) : __('waiting'), $m->start_at !== null],
        [__('Result'), $report ? SeriesPresenter::time($report->created_at ?? now(), $viewer, 'H:i') : __('now'), $report ? __(':clan submitted, 1 of 2', ['clan' => $m->sideName($report->side)]) : __('a captain submits, 1 of 2'), $report !== null],
        [__('Their OK'), $m->finished_at && $m->status->hasResult() ? SeriesPresenter::time($m->finished_at, $viewer, 'H:i') : '', $m->status === SeriesStatus::Disputed ? __('problem reported') : __('the other captain, 2 of 2'), $m->status->hasResult()],
    ];
@endphp

<div class="flex grow flex-col gap-5 px-4 pt-5 pb-28 lg:mx-auto lg:w-full lg:max-w-[1232px] lg:px-4 lg:pt-8 lg:pb-10" data-test="match-room"
     x-data="{ submit: false }"
     x-init="setInterval(() => { if (! document.activeElement?.matches('input, textarea, select') && ! submit) $wire.sync() }, 8000)">

    {{-- A tournament series says so first, above everything else, the casual steps included (user, 2026-10-03). --}}
    <x-tournaments.game-banner :banner="$m->tournament_match_id !== null ? TournamentGameEnd::banner($m) : null" class="-order-10" />

    {{-- Header --}}
    <div @class(['flex flex-wrap items-center gap-x-3 gap-y-2', 'max-lg:-order-4 lg:-order-2' => $casualFirst])>
        <a href="{{ \App\Support\GameNames::page($m->game) }}" class="shrink-0" title="{{ \App\Support\GameNames::game($m->game) }}" aria-label="{{ \App\Support\GameNames::game($m->game) }}"><x-game-cover :game="$m->game" size="thumb" class="w-16 rounded-sm shadow-ring lg:w-24" data-test="room-game-cover" /></a>
        <h1 class="m-0 font-display text-[26px] font-bold lg:text-[34px]"><span class="lg:hidden">{{ __('Match room') }}</span><span class="max-lg:hidden">{{ __('Match') }}</span></h1>
        <span class="font-display text-xl font-bold text-ink-2 max-lg:hidden lg:text-[28px]">{{ $m->label() }}</span>
        <span class="inline-flex h-[26px] items-center rounded-sm bg-btc-chip px-2.5 text-xs font-bold text-btc-hi shadow-[inset_0_0_0_1px_#B9640A]">{{ $m->rated ? __('Rated') : ($m->tournament_match_id !== null ? __('Tournament') : __('Casual')) }}</span>
        <span class="grow"></span>
        <span class="inline-flex h-[34px] items-center gap-2 rounded-md px-3.5 text-[13px] font-bold {{ $status[1] }}" data-test="room-status"><span class="size-[7px] animate-live rounded-full bg-current"></span>{{ $status[0] }}</span>
        <span class="text-[13px] text-ink-2 max-lg:hidden">{{ __(':n of 2 checks', ['n' => $checks]) }}</span>
        <span class="w-full text-[13px] text-ink-2 lg:hidden">{{ __('Match :number, :game', ['number' => $m->label(), 'game' => \App\Support\GameNames::game($m->game)]) }}</span>
    </div>

    @if ($wait?->decidesAt !== null)
        <p class="m-0 flex flex-col gap-1 rounded-md bg-card px-4 py-3 text-[13px] shadow-[inset_0_0_0_1px_#F7931A]" role="status" data-test="room-auto-decision">
            <x-tournaments.auto-decision :wait="$wait" class="text-ink" />
            @if ($viewer !== null && $wait->waitsOn($viewer->id) && $wait->action !== null)
                <span class="text-ink-2">{{ $wait->actionText() }}</span>
            @endif
        </p>
    @endif

    {{--
        The lobby a captain set (a lineup or tournament series, SeriesService::setLobby()), pinned right above the
        score while the series runs (2026-10-02: "die Lobby Karte ... darf nicht irgendwo im Chat unlesbar
        verschwinden"). The room is the two lineups' only; the section further down keeps the editor.
    --}}
    @php($lobbyPinned = ! $casual && $m->lobby_name !== null && $m->status->isRunning())
    @if ($lobbyPinned)
        <section aria-labelledby="room-lobby-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-3 shadow-[inset_0_0_0_1px_#B9640A] lg:px-6" x-data="{ show: false, copied: '' }" data-test="room-lobby-pin">
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <h2 id="room-lobby-h" class="m-0 flex min-w-0 items-center gap-1.5 text-[13px] font-bold"><x-icon name="key" :size="14" class="shrink-0 text-btc-hi" />{{ __('Join this lobby in :game', ['game' => \App\Support\GameNames::game($m->game)]) }}</h2>
                <span class="min-w-0 text-[11px] text-ink-2 [overflow-wrap:anywhere]">{{ __('host :clan', ['clan' => $m->challenger_name]) }}{{ $m->lobby_region ? ' · '.$m->lobby_region : '' }}</span>
            </span>
            <div class="grid min-h-11 grid-cols-[72px_minmax(0,1fr)_auto] items-center gap-2 border-t border-hairline pt-2 text-[13px]">
                <span class="text-xs text-ink-2">{{ __('Name') }}</span><b class="min-w-0 font-mono break-all" data-test="room-lobby-name">{{ $m->lobby_name }}</b>
                <button type="button" x-on:click="navigator.clipboard?.writeText(@js($m->lobby_name)); copied = 'name'" aria-label="{{ __('Copy lobby name') }}" data-test="room-lobby-copy-name" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><span x-show="copied !== 'name'"><x-icon name="copy" :size="16" /></span><span x-show="copied === 'name'" x-cloak class="text-win"><x-icon name="check" :size="16" /></span></button>
            </div>
            <div class="grid min-h-11 grid-cols-[72px_minmax(0,1fr)_auto_auto] items-center gap-2 border-t border-hairline pt-2 text-[13px]">
                <span class="text-xs text-ink-2">{{ __('Password') }}</span>
                <span class="min-w-0"><span x-show="! show">••••••••</span><b x-show="show" x-cloak class="font-mono break-all" data-test="room-lobby-password">{{ $m->lobby_password ?? '–' }}</b></span>
                <button type="button" x-on:click="show = ! show" :aria-pressed="show ? 'true' : 'false'" aria-label="{{ __('Show password') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="eye" :size="16" /></button>
                <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) $m->lobby_password)); copied = 'password'" aria-label="{{ __('Copy password') }}" data-test="room-lobby-copy-password" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><span x-show="copied !== 'password'"><x-icon name="copy" :size="16" /></span><span x-show="copied === 'password'" x-cloak class="text-win"><x-icon name="check" :size="16" /></span></button>
            </div>
        </section>
    @endif

    {{-- Versus --}}
    <section aria-label="{{ __('Series') }}" @class(['grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 rounded-lg px-4 py-4 lg:grid-cols-[minmax(0,1fr)_280px_minmax(0,1fr)] lg:px-8 lg:py-5', '-order-2' => $casualFirst]) style="background: linear-gradient(90deg, #1E1A12, #121215 38%, #121215 62%, #17171B)">
        @foreach (['challenger', 'score', 'challenged'] as $cell)
            @if ($cell === 'score')
                <span class="flex flex-col items-center gap-1 text-center">
                    <b class="font-display text-[34px] leading-[1.1] font-extrabold lg:text-[56px]" data-test="series-score">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b>
                    <span class="text-xs text-ink-2 max-lg:hidden lg:text-[13px]" data-test="series-meta">{{ $seriesMeta }}</span>
                </span>
            @else
                {{-- Below lg the cell is a size container: a four-letter tag ("WWWW", Clan::TAG_PATTERN) scales down to the cell instead of running into the score at 320 and 375. --}}
                <span @class(['flex items-center gap-3 max-lg:@container lg:gap-4', 'flex-row-reverse text-right' => $cell === 'challenged'])>
                    <x-clan-tag :clan="$m->sideClan($cell)" :tag="$m->sideTag($cell)" :tile="64" class="cube hidden size-16 shrink-0 items-center justify-center font-display text-[15px] font-extrabold lg:mt-2.5 lg:flex {{ $sideInk[$cell] }}" style="background: {{ $sideColor[$cell] }}" />
                    <span @class(['flex min-w-0 flex-col gap-1', 'lg:ml-2.5' => $cell === 'challenger', 'items-end lg:mr-6' => $cell === 'challenged'])>
                        <b @class(['max-w-full font-display font-extrabold whitespace-nowrap lg:hidden', 'text-[26px]' => mb_strlen($m->sideTag($cell)) < 4, 'text-[min(26px,calc(100cqi/5.6))]' => mb_strlen($m->sideTag($cell)) >= 4]) style="color: {{ $cell === 'challenger' ? '#F7931A' : '#ADADB0' }}" data-test="side-tag-{{ $cell }}">{{ $m->sideTag($cell) }}</b>
                        {{-- max-w-full: the right side's column aligns its items to the end, so a long name grew past its cell over the score (375 px, "Velit Consequatur"); below lg a long name wraps to two lines instead of losing most of it. The captain line below does the same (a 37-character player name without a space ran into the score at 320 and 375). --}}
                        <b class="max-w-full text-[13px] max-lg:line-clamp-2 max-lg:[overflow-wrap:anywhere] lg:truncate lg:font-display lg:text-xl">{{ $m->sideName($cell) }}</b>
                        <span class="max-w-full text-xs text-ink-2 max-lg:line-clamp-2 max-lg:[overflow-wrap:anywhere] lg:truncate lg:text-[13px]">{{ $captainSide === $cell ? __('you are captain') : __('captain :name', ['name' => $captainOf($cell)]) }}</span>
                    </span>
                </span>
            @endif
        @endforeach
        <span class="col-span-3 text-center text-xs text-ink-2 lg:hidden">{{ $seriesMeta }}</span>
        {{-- Both lineups, each name opens the player card (MatchRoom, P10a) --}}
        <span class="col-span-3 mt-2 grid grid-cols-2 gap-3 border-t border-white/6 pt-3 lg:mt-4 lg:flex lg:justify-between lg:gap-6" data-test="series-players">
            @foreach (['challenger', 'challenged'] as $cell)
                <span role="group" aria-label="{{ __(':name players', ['name' => $m->sideName($cell)]) }}" @class(['flex min-w-0 flex-col gap-1.5 lg:flex-row lg:flex-wrap', 'items-end lg:justify-end' => $cell === 'challenged'])>
                    @foreach ($rosters[$cell] as ['seat' => $seat])
                        <x-player-link :user="$seat->user" class="relative inline-flex h-11 max-w-full min-w-0 items-center gap-1.5 rounded-md bg-[rgba(10,10,11,.45)] pr-2 pl-1 text-xs whitespace-nowrap lg:h-8 lg:after:absolute lg:after:inset-x-0 lg:after:-inset-y-1.5">
                            <x-avatar :user="$seat->user" :size="24" class="rounded-sm" /><span class="truncate">{{ $seat->user->displayName() }}</span>
                        </x-player-link>
                    @endforeach
                </span>
            @endforeach
        </span>
    </section>

    {{-- Casual until Block 0 (the design's "can mine" line, States.dc.html "Locked until Block 0") --}}
    <section aria-label="{{ __('Season chain') }}" class="flex min-h-14 items-center gap-3.5 rounded-lg bg-card px-5 py-3 shadow-[inset_0_0_0_1px_#2A2A30]" data-test="casual-line">
        <x-icon name="lock" :size="18" class="shrink-0 text-ink-2" />
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <b class="text-sm leading-[1.4]">{{ $m->rated ? __('Rated series') : __('Casual until Block 0 · casual Elo only, no reward') }}</b>
            <span class="text-xs leading-normal text-ink-2">{{ __('Rated play and mining start at Block 0. Until then every series is casual: it moves only the casual Elo, never a rank, Block Height or Clan Hashrate, and it stays casual even if it ends later.') }}</span>
        </span>
        <a href="{{ route('rules') }}" class="inline-flex min-h-11 shrink-0 items-center text-xs whitespace-nowrap max-lg:hidden">{{ __('How mining works') }}</a>
    </section>

    {{-- P45: the match on Nostr, and a direct message to the other side outside the room chat --}}
    <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::match($m, 'room')" />

    @if ($error)
        <p @class(['m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss', 'max-lg:-order-4 lg:-order-2' => $casualFirst]) role="alert" data-test="room-error">{{ $error }}</p>
    @endif

    {{-- Win moment (Overlays.dc.html "Win, once the other captain accepts") --}}
    @if ($m->status->hasResult())
        @php($won = $m->winner === $mySide)
        <section aria-label="{{ __('Result') }}" class="grid grid-cols-1 items-center gap-4 rounded-lg px-5 py-6 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:px-8" style="background: linear-gradient(90deg, {{ $won ? '#111A14' : '#17171B' }}, #121215 60%); box-shadow: inset 0 0 0 1px {{ $won ? '#1F5A34' : '#2A2A30' }}" data-test="win-moment">
            <span class="flex items-center gap-4">
                @if ($m->winner === 'challenger' || $m->winner === 'challenged')
                    <x-clan-tag :clan="$m->sideClan($m->winner)" :tag="$m->sideTag($m->winner)" :tile="56" class="flex size-14 shrink-0 items-center justify-center rounded-md font-display text-[15px] font-extrabold {{ $sideInk[$m->winner] }}" style="background: {{ $sideColor[$m->winner] }}" />
                    <span class="flex flex-col gap-1"><b class="font-display text-[22px]">{{ $m->sideName($m->winner) }}</b><span class="inline-flex items-center gap-1 text-[13px] text-win"><x-icon name="check" :size="14" />{{ __('Win') }}</span></span>
                @else
                    <b class="font-display text-[22px]">{{ __('No winner') }}</b>
                @endif
            </span>
            <span class="flex flex-col items-center gap-2">
                <span class="inline-flex h-7 items-center gap-1.5 rounded-sm bg-win-tint px-2.5 text-xs font-bold text-win shadow-[inset_0_0_0_1px_#1F5A34]"><x-icon name="check" :size="14" />{{ $m->resolution?->label() ?? __('Accepted') }}</span>
                {{-- The score reads from the named winner's side (2 : 1 next to the winner), never challenger first. --}}
                @php($lead = $m->winner === 'challenged' ? ['challenged', 'challenger'] : ['challenger', 'challenged'])
                <b class="font-display text-[56px] leading-none font-extrabold lg:text-[72px]" data-test="win-score">{{ $wins[$lead[0]] }} : {{ $wins[$lead[1]] }}</b>
                <span class="text-[13px] text-ink-2">BO{{ $m->best_of }}, {{ $m->mode }}, {{ __('match :number', ['number' => $m->label()]) }}, {{ $m->rated ? __('saved') : __('casual, saved') }}</span>
            </span>
            <span class="flex flex-col gap-1 text-xs leading-normal text-ink-2 lg:items-end lg:text-right">
                <span>{{ $m->rated ? __('The league record follows.') : __('Casual: casual Elo only, no Hashrate, no block. Rated series start at Block 0.') }}</span>
                @if ($m->resolution_reason)<span>{{ __('Admin decision') }}: {{ $m->resolution_reason }}</span>@endif
                <a href="{{ route('matches.show', $m) }}" class="inline-flex min-h-11 items-center">{{ __('Open the match page') }}</a>
            </span>
        </section>

        {{-- A tournament series: what comes next in the tournament instead of a rematch or another series (user, 2026-10-03). --}}
        @php($tournamentPanel = TournamentGameEnd::of($m, $viewer))
        @if ($tournamentPanel)
            <x-tournaments.game-end :panel="$tournamentPanel" wire:key="tournament-end-{{ $m->id }}" />
        @endif

        {{-- A casual 1v1 (P23 S3): the same opponent again, as a direct invite. --}}
        @if ($casual && $tournamentPanel === null && $mySide !== null && $m->resolution !== \App\Enums\SeriesResolution::Void)
            @php(['outgoing' => $rematchOut, 'incoming' => $rematchIn] = $this->rematchInvites())
            @php($rematchOpen = $m->finished_at !== null && $m->finished_at->gte(now()->subMinutes((int) config('esports.casual.rematch_minutes'))))
            @if ($rematchIn || $rematchOut || $rematchOpen)
                <section aria-labelledby="rematch-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 shadow-ring-btc sm:flex-row sm:items-center sm:gap-6 lg:px-6" data-test="casual-rematch-card"
                         x-data="{ now: Date.now(), skew: {{ (int) now()->getTimestampMs() }} - Date.now() }" x-init="setInterval(() => now = Date.now(), 1000)">
                    <span class="flex min-w-0 grow flex-col gap-1">
                        <h2 id="rematch-h" class="m-0 font-display text-base leading-[1.25] font-bold">{{ $rematchIn ? __(':name wants a rematch', ['name' => $m->sideName($other)]) : __('Rematch') }}</h2>
                        <span class="text-[13px] leading-normal text-ink-2">{{ $rematchOut ? __('Sent. Waiting for :name.', ['name' => $m->sideName($other)]) : __('Same opponent, same platforms, a new ready check.') }}</span>
                    </span>
                    @if ($rematchIn || $rematchOut)
                        @php($ends = ($rematchIn ?? $rematchOut)->expires_at->getTimestampMs())
                        <b class="font-display text-xl tabular-nums" role="timer" x-text="(() => { const s = Math.max(0, Math.ceil(({{ $ends }} - now - skew) / 1000)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); })()"></b>
                    @endif
                    @if ($rematchIn)
                        <span class="grid grid-cols-2 gap-2 sm:flex">
                            <x-button variant="quiet" wire:click="casualDeclineRematch" data-test="casual-rematch-decline">{{ __('Decline') }}</x-button>
                            <x-button icon="check" wire:click="casualAcceptRematch({{ $rematchIn->id }})" data-test="casual-rematch-incoming">{{ __('Accept rematch') }}</x-button>
                        </span>
                    @elseif ($rematchOut)
                        <button type="button" wire:click="casualWithdrawRematch" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss" data-test="casual-rematch-waiting">{{ __('Withdraw') }}</button>
                    @else
                        <x-button icon="retry" wire:click="casualRematch" class="min-h-12 shrink-0 px-6 font-display text-base font-bold" data-test="casual-rematch">{{ __('Rematch') }}</x-button>
                    @endif
                </section>
            @endif
        @endif

        {{-- The next series: a captain challenges the same lineup again (the challenge form, prefilled) --}}
        @php($ownLineup = $captainSide !== null ? $m->lineup($captainSide) : null)
        @php($theirLineup = $captainSide !== null ? $m->lineup(SeriesMatch::otherSide($captainSide)) : null)
        @if ($ownLineup !== null && $theirLineup !== null && $tournamentPanel === null)
            <section aria-labelledby="again-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 sm:flex-row sm:items-center sm:gap-6 lg:px-6" data-test="series-again">
                <span class="flex min-w-0 grow flex-col gap-1">
                    <h2 id="again-h" class="m-0 font-display text-base leading-[1.25] font-bold">{{ __('Another series') }}</h2>
                    <span class="text-[13px] leading-normal text-ink-2">{{ __('Challenge :clan to the next series: same lineups, new times.', ['clan' => $m->sideName(SeriesMatch::otherSide($captainSide))]) }}</span>
                </span>
                <x-button :href="route('challenges.create', ['lineup' => $ownLineup->id, 'to' => $theirLineup->id, 'game' => $m->game])" icon="retry" class="shrink-0" data-test="series-challenge-again">{{ __('Challenge again') }}</x-button>
            </section>
        @endif
    @endif

    {{-- Challenge, still open: answer, withdraw or wait --}}
    @if ($m->status === SeriesStatus::Open && $casual)
        @include('pages.matches.partials.casual-challenge')
    @elseif ($m->status === SeriesStatus::Open)
        <section aria-labelledby="answer-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })" data-test="answer-card">
            <h2 id="answer-h" class="m-0 text-[15px] font-bold">{{ __('Challenge from :clan', ['clan' => $m->challenger_name]) }}</h2>
            @if ($m->message)<p class="m-0 text-[13px] text-ink-2">“{{ $m->message }}”</p>@endif
            <span class="text-xs text-ink-2">{{ __('Answer by :time', ['time' => SeriesPresenter::time($m->respond_by, $viewer)]) }}</span>
            @if ($captainSide === 'challenged')
                {{-- P57: a rated accept needs the answering captain and the sender to list each other (RatedTrustGate::forAccept). --}}
                @if ($m->rated && $m->createdBy !== null && $m->createdBy->id !== $viewer->id && ! app(Opponents::class)->listEachOther($viewer, $m->createdBy))
                    <x-opponents.needs-mutual :players="[$m->createdBy]" :heading="__('You and :name do not list each other as opponents yet, so you cannot accept this rated challenge.', ['name' => $m->createdBy->displayName()])">
                        <x-button variant="quiet" :href="route('challenges.create', ['to' => $m->challenger_lineup_id, 'game' => $m->game])" data-test="needs-mutual-casual">{{ __('Challenge them to a casual match instead') }}</x-button>
                    </x-opponents.needs-mutual>
                @endif
                <div role="radiogroup" aria-label="{{ __('Suggested times') }}" class="flex flex-wrap gap-2">
                    @foreach ($m->proposals as $proposal)
                        <button type="button" role="radio" wire:click="$set('pickedStart', {{ $proposal }})" aria-checked="{{ $pickedStart === $proposal ? 'true' : 'false' }}"
                                @class(['h-11 cursor-pointer rounded-md border bg-ground px-4 text-[13px] text-ink', 'border-btc' => $pickedStart === $proposal, 'border-line' => $pickedStart !== $proposal])>{{ SeriesPresenter::time(now()->setTimestamp($proposal), $viewer) }}</button>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-button icon="shield-check" x-on:click="run('prepareAnswer', 'answer', 'accepted', $wire.pickedStart)" ::disabled="busy" data-test="accept-challenge">{{ __('Accept challenge') }}</x-button>
                    <x-button variant="quiet" x-on:click="run('prepareAnswer', 'answer', 'declined', null)" ::disabled="busy" data-test="decline-challenge">{{ __('Decline') }}</x-button>
                </div>
            @elseif ($captainSide === 'challenger')
                <p class="m-0 text-[13px] text-ink-2">{{ __(':clan\'s captains see it right away. You can withdraw it while it\'s open.', ['clan' => $m->challenged_name]) }}</p>
                <div><x-button variant="quiet" x-on:click="run('prepareAnswer', 'answer', 'withdrawn', null)" ::disabled="busy" data-test="withdraw-challenge">{{ __('Withdraw challenge') }}</x-button></div>
            @else
                <p class="m-0 text-[13px] text-ink-2">{{ __('Your captain answers this challenge.') }}</p>
            @endif
            <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
        </section>
    @endif

    {{-- Facts --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Challenge'), __(':from to :to, :time', ['from' => $m->created_by_id === $viewer->id ? __('you') : $m->challenger_name, 'to' => $m->challenged_name, 'time' => SeriesPresenter::time($m->created_at ?? now(), $viewer, 'D H:i')])],
                [__('Start'), $m->start_at ? SeriesPresenter::time($m->start_at, $viewer, 'D H:i') : __('one of :n suggested times', ['n' => count($m->proposals)])],
                [__('Format'), \App\Support\GameNames::game($m->game).', '.$m->mode.', BO'.$m->best_of],
                [__('Ladder'), $m->rated ? $m->mode : __('none, casual until Block 0')],
            ] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[150px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span class="[overflow-wrap:anywhere]">{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            {{-- A casual 1v1 is never rated (CasualMatches): no Elo to show, only the status. --}}
            @unless ($casual)
                @foreach ([[__('Elo before'), $elo['before'].($elo['casual'] ? ' · '.__('casual') : '')], [__('Expected'), $elo['expected']], [__('At stake'), $elo['stake']]] as [$key, $value])
                    <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-sm lg:grid-cols-[150px_minmax(0,1fr)]" data-test="elo-fact"><span class="text-ink-2">{{ $key }}</span><span class="text-ink-2">{{ $value }}</span></div>
                @endforeach
            @endunless
            <div class="flex min-h-14 flex-wrap items-center gap-3 py-2 text-sm">
                <span class="w-[110px] text-ink-2 lg:w-[150px]">{{ __('Status') }}</span>
                <span class="text-btc-hi">{{ $wins['challenger'] }} : {{ $wins['challenged'] }} {{ $m->status->hasResult() ? '' : __('so far') }}</span>
                <span class="grow"></span>
                @if ($editable)
                    <button type="button" x-on:click="submit = true" data-test="open-submit" class="btn-w max-lg:hidden inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-raised px-4 text-sm font-bold text-ink"><x-icon name="shield-check" :size="18" />{{ __('Submit final score') }}</button>
                @endif
            </div>
        </div>
    </div>

    @if ($directorEntered)
        <p class="m-0 flex items-center gap-2 rounded-md bg-card px-4 py-3 text-[13px] text-ink-2" data-test="director-entered">
            <x-icon name="shield-check" :size="16" />{{ __('Results are entered by the tournament directors.') }}
        </p>
    @endif

    {{-- Timeline + Proof (a casual 1v1 has its own, in the steps) --}}
    @unless ($casual)
    <section aria-labelledby="tl-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6">
        <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="tl-h" class="m-0 text-[15px] font-bold">{{ __('Timeline') }}</h2><span class="text-xs text-ink-2">{{ __('your result and their OK make 2 of 2') }}</span></span>
        <ol class="m-0 grid list-none grid-cols-2 gap-y-4 p-0 lg:grid-cols-4">
            @foreach ($steps as $index => [$label, $when, $who, $done])
                <li @class(['relative flex min-w-0 flex-col items-center gap-1 text-center', 'lg:after:absolute lg:after:top-[27px] lg:after:left-1/2 lg:after:h-0.5 lg:after:w-full' => $index < 3, 'lg:after:bg-ink' => $index < 3 && ($steps[$index + 1][3] ?? false), 'lg:after:bg-line' => $index < 3 && ! ($steps[$index + 1][3] ?? false)])>
                    <span class="h-4 text-[11px] text-ink-3">{{ $when }}</span>
                    <span @class(['relative z-10 size-3.5 rounded-full border-2', 'border-ink bg-ink' => $done, 'border-btc bg-btc' => ! $done && ($steps[$index - 1][3] ?? true), 'border-edge bg-transparent' => ! $done && ! ($steps[$index - 1][3] ?? true)])></span>
                    <b class="text-xs">{{ $label }}</b>
                    {{-- A clan name without a space ("by …") breaks anywhere instead of widening the page at 320 and 375. --}}
                    <span class="max-w-full text-[11px] text-ink-2 [overflow-wrap:anywhere]">{{ $who }}</span>
                </li>
            @endforeach
        </ol>
        <p class="m-0 flex items-start gap-2 text-xs leading-normal text-ink-2"><x-icon name="retry" :size="14" class="mt-0.5 shrink-0" />{{ __('If a result is disputed, either side can submit again. The new one replaces the old; the timeline keeps both.') }}</p>
        <x-proof :rows="SeriesPresenter::proofRows($m)" />
    </section>
    @endunless

    {{-- Games + Who played --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section aria-labelledby="games-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="games">
            <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="games-h" class="m-0 text-[15px] font-bold">{{ __('Games in this series') }}</h2><span class="text-xs text-ink-2">{{ $hasGoals ? __('after each game, enter the team goals from the end screen') : __('after each game, pick its winner') }}</span></span>
            @if ($hasGoals)
            <div class="grid grid-cols-[64px_56px_12px_56px_minmax(0,1fr)] items-center gap-2 text-xs text-ink-3 lg:grid-cols-[72px_60px_12px_60px_minmax(0,1fr)_130px]">
                <span>{{ __('Game #') }}</span><span class="text-center">{{ $m->challenger_tag }}</span><span></span><span class="text-center">{{ $m->challenged_tag }}</span><span>{{ __('Winner') }}</span><span class="max-lg:hidden"></span>
            </div>
            @endif
            @foreach ($this->sheet as $index => $row)
                @php($decided = $index > 0 && max(SeriesMatch::seriesScore(array_slice(array_map(fn ($r) => ['winner' => $r['winner']], $this->sheet), 0, $index))) >= intdiv($m->best_of, 2) + 1)
                @php($current = $row['winner'] === null && ! $decided && ($index === 0 || $this->sheet[$index - 1]['winner'] !== null))
                <div wire:key="g-{{ $index }}" @class(['grid items-center gap-2 border-b border-hairline py-2 text-[13px]', 'grid-cols-[64px_56px_12px_56px_minmax(0,1fr)] lg:grid-cols-[72px_60px_12px_60px_minmax(0,1fr)_130px]' => $hasGoals, 'grid-cols-[64px_minmax(0,1fr)_auto] lg:grid-cols-[72px_minmax(0,1fr)_auto]' => ! $hasGoals, 'opacity-50' => $decided]) data-test="game-row">
                    <b>{{ __('Game :n', ['n' => $index + 1]) }}</b>
                    @foreach ($hasGoals ? ['c', 'd'] : [] as $box)
                        <input type="number" inputmode="numeric" min="0" max="99" wire:model.live.blur="sheet.{{ $index }}.{{ $box }}" @disabled(! $editable || $row['unknown'] || $decided)
                               aria-label="{{ __('Goals of :clan in game :n', ['clan' => $m->sideTag($box === 'c' ? 'challenger' : 'challenged'), 'n' => $index + 1]) }}" data-test="goals-{{ $index }}-{{ $box }}"
                               @class(['h-11 w-full rounded-md border bg-ground px-2 text-center text-[15px] text-ink disabled:opacity-60', 'border-btc' => $current && $editable, 'border-edge' => ! ($current && $editable)])>
                        @if ($box === 'c')<span class="text-center text-ink-3">:</span>@endif
                    @endforeach
                    <span @class(['text-[13px]', 'text-win' => $row['winner'] !== null && $row['winner'] === ($mySide ?? 'challenger'), 'text-loss' => $row['winner'] !== null && $row['winner'] !== ($mySide ?? 'challenger'), 'text-btc' => $row['winner'] === null && $current && ! $m->start_at?->isFuture(), 'text-ink-3' => $row['winner'] === null && ! $current])>
                        @if ($row['winner'] !== null)
                            {{ __(':tag win', ['tag' => $m->sideTag($row['winner'])]) }}
                        @elseif ($decided)
                            {{ __('not needed') }}
                        @else
                            {{ $current && $m->status === SeriesStatus::Accepted && ! $m->start_at?->isFuture() ? __('playing now') : __('not played') }}
                        @endif
                    </span>
                    <span @class(['flex flex-wrap items-center gap-3', 'col-span-5 lg:col-span-1' => $hasGoals])>
                        @if ($editable && ! $decided)
                            @if ($hasGoals)
                                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-xs text-ink-2"><input type="checkbox" wire:model.live="sheet.{{ $index }}.unknown" class="size-4 accent-[#F7931A]">{{ __('Goals unknown') }}</label>
                            @endif
                            @if ($row['unknown'])
                                <select wire:model.live="sheet.{{ $index }}.winner" aria-label="{{ __('Winner of game :n', ['n' => $index + 1]) }}" class="h-9 rounded-md border border-edge bg-ground px-2 text-xs text-ink">
                                    <option value="">{{ __('winner?') }}</option>
                                    @foreach (SeriesMatch::SIDES as $side)<option value="{{ $side }}">{{ $m->sideTag($side) }}</option>@endforeach
                                </select>
                            @endif
                        @elseif ($row['unknown'] && $hasGoals)
                            <span class="text-xs text-ink-3">{{ __('Goals unknown') }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
            <div class="flex flex-wrap items-center gap-x-6 gap-y-1 pt-1 text-xs text-ink-2">
                <span>{{ __('Series after game :n', ['n' => $playing]) }} <b class="text-ink">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b></span>
                <span>{{ __('shown publicly as provisional until both captains confirm') }}</span>
            </div>
            @if ($hasGoals)
                <p class="m-0 flex items-start gap-2 text-xs leading-normal text-ink-3"><x-icon name="alert" :size="14" class="mt-0.5 shrink-0" />{{ __('Forgot the goals? Tick "Goals unknown" and just pick the winner. The game counts for the series but not for goal stats.') }}</p>
            @endif
        </section>

        <section aria-labelledby="who-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="who-played">
            @php($mine = $captainSide ?? $mySide ?? 'challenger')
            @php($set = count(array_filter($rosters[$mine], fn ($r) => $r['on'])))
            <span class="flex items-baseline justify-between"><h2 id="who-h" class="m-0 text-[15px] font-bold">{{ __('Who played') }}</h2><span @class(['text-xs', 'text-win' => $set >= $m->gameMode()->teamSize, 'text-btc-hi' => $set < $m->gameMode()->teamSize])>{{ __(':n of :size set', ['n' => $set, 'size' => $m->gameMode()->teamSize]) }}</span></span>
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Regulars are preselected. If a sub played, switch them on and a regular off.') }}</p>
            @foreach ($rosters[$mine] as ['seat' => $seat, 'on' => $on])
                <div wire:key="r-{{ $seat->id }}" class="flex min-h-12 items-center justify-between gap-3 border-b border-hairline py-1">
                    <span class="flex min-w-0 flex-col">
                        <span class="flex items-center gap-2 text-[13px]"><span class="truncate">{{ $seat->user->displayName() }}</span>@if ($seat->user->is_member)<x-member-badge />@endif</span>
                        <span class="text-[11px] text-ink-3">{{ $seat->role->label() }}@if ($seat->user_id === $viewer->id), {{ __('you') }}@endif</span>
                    </span>
                    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ __(':name played', ['name' => $seat->user->displayName()]) }}"
                            @if ($editable) wire:click="toggleRoster({{ $seat->user_id }})" @else disabled @endif
                            @class(['relative h-7 w-[46px] shrink-0 cursor-pointer rounded-full border-0 disabled:cursor-default', 'bg-btc' => $on, 'bg-raised' => ! $on])>
                        <span @class(['absolute top-1 size-5 rounded-full transition-all', 'left-[22px] bg-on-btc' => $on, 'left-1 bg-ink-2' => ! $on])></span>
                    </button>
                </div>
            @endforeach
            {{-- A clan or player name without a space breaks anywhere: a 37-character name widened the page at 320. --}}
            <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-xs leading-normal text-ink-2 [overflow-wrap:anywhere]" data-test="opponent-roster">{{ $m->sideName(SeriesMatch::otherSide($mine)) }}:
                @foreach (array_filter($rosters[SeriesMatch::otherSide($mine)], fn ($r) => $r['on']) as ['seat' => $seat])
                    <span class="inline-flex max-w-full min-w-0 items-center gap-1"><x-player-link :user="$seat->user" class="inline-flex min-h-6 min-w-0 items-center" /><x-copy-npub :npub="$seat->user->npub" :name="$seat->user->displayName()" /></span>
                @endforeach
            </p>
            <p class="m-0 text-xs text-ink-3">{{ __('their captain sets this') }}</p>
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('This list goes out with your result.') }}</p>
        </section>
    </div>

    {{--
        Lobby + Chat. A running casual 1v1 below lg: the grid dissolves (contents), so the steps with the pinned
        lobby card come right under the header, above the score, and the chat after the score (2026-10-02: the
        pin and its action above the fold at 375).
    --}}
    <div @class(['grid grid-cols-1 gap-5 lg:grid-cols-2', '-order-1 max-lg:contents' => $casualFirst])>
        @if ($casual)
            {{-- An open scheduled challenge has no steps yet: its answer card says what happens. --}}
            @if ($m->status !== \App\Enums\SeriesStatus::Open)
                @include('pages.matches.partials.casual-steps')
            @endif
        @else
        <section aria-labelledby="lobby-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="lobby">
            <span class="flex items-baseline justify-between gap-2"><h2 id="lobby-h" class="m-0 shrink-0 text-[15px] font-bold">{{ __('Private lobby') }}</h2><span class="min-w-0 text-right text-xs text-ink-2 [overflow-wrap:anywhere]">{{ __('host :clan', ['clan' => $m->challenger_name]) }}</span></span>
            @if ($editLobby)
                <form wire:submit="saveLobby" class="flex flex-col gap-3">
                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Name') }}<input wire:model="lobbyName" maxlength="32" required data-test="lobby-name-input" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink"></label>
                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Password') }}<input wire:model="lobbyPassword" maxlength="32" autocomplete="off" data-test="lobby-password-input" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink"></label>
                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Region') }}
                        <select wire:model="lobbyRegion" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink">@foreach (config('esports.series.regions') as $region)<option value="{{ $region }}">{{ $region }}</option>@endforeach</select>
                    </label>
                    <div class="flex gap-3"><x-button type="submit" data-test="save-lobby">{{ __('Save lobby') }}</x-button><x-button variant="quiet" wire:click="$set('editLobby', false)">{{ __('Cancel') }}</x-button></div>
                </form>
            @elseif ($m->lobby_name === null)
                <p class="m-0 text-[13px] text-ink-2">{{ $m->status->isRunning() ? __('No lobby yet. The host sets name and password here.') : __('The lobby opens once the challenge is accepted.') }}</p>
            {{-- Also beside the chat while it runs (user, 2026-10-04: "Die Lobby Daten müssen auch neben dem Chat da stehen"), not only pinned under the score. --}}
            @else
                <div x-data="{ show: false }" class="flex flex-col">
                    <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)_auto] items-center gap-2 border-b border-hairline text-sm">
                        <span class="text-ink-2">{{ __('Name') }}</span><b data-test="lobby-name">{{ $m->lobby_name }}</b>
                        <button type="button" x-on:click="navigator.clipboard?.writeText(@js($m->lobby_name))" aria-label="{{ __('Copy lobby name') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="copy" :size="16" /></button>
                    </div>
                    <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)_auto_auto] items-center gap-2 border-b border-hairline text-sm">
                        <span class="text-ink-2">{{ __('Password') }}</span>
                        <span><span x-show="! show">••••••••</span><b x-show="show" x-cloak data-test="lobby-password">{{ $m->lobby_password ?? '–' }}</b></span>
                        <button type="button" x-on:click="show = ! show" :aria-pressed="show ? 'true' : 'false'" aria-label="{{ __('Show password') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="eye" :size="16" /></button>
                        <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) $m->lobby_password))" aria-label="{{ __('Copy password') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="copy" :size="16" /></button>
                    </div>
                    <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)] items-center gap-2 border-b border-hairline text-sm"><span class="text-ink-2">{{ __('Region') }}</span><span>{{ $m->lobby_region ?? '–' }}</span></div>
                </div>
            @endif
            <p class="m-0 flex items-center gap-2 py-1 text-xs text-ink-2"><x-icon name="lock" :size="14" class="text-win" />{{ __('Only the two lineups see this. It is never published.') }}</p>
            @if ($captainSide !== null && $m->status->isRunning() && ! $editLobby)
                <div><x-button variant="quiet" icon="brush" wire:click="openLobbyEditor" data-test="change-lobby">{{ $m->lobby_name === null ? __('Set lobby') : __('Change lobby') }}</x-button></div>
            @endif
            @include('pages.matches.partials.lobby-rules')
            @if ($m->status === SeriesStatus::Accepted && $m->tournament_match_id !== null && ! $m->isCasualPairing() && $m->start_at !== null)
                {{--
                    Lobby check-in (user, 2026-10-04): a signal for both sides and the direction. The rule sentence names the
                    clock time of SeriesMatch::autoNoshowAt() and only where SeriesService::autoNoShow() can act (league
                    deadlines, players enter the results); a director tournament has no automatic no-show.
                --}}
                @php($autoNoshowAt = SeriesService::isDirectorEntered($m) ? null : $m->autoNoshowAt())
                @php($myCheckIn = $captainSide !== null ? $m->readyAt($captainSide) : null)
                <div class="mt-2 flex flex-col gap-3 border-t border-hairline pt-3" data-test="lobby-checkin">
                    <h3 class="m-0 text-[13px] font-bold">{{ __('Lobby check-in') }}</h3>
                    <ul class="m-0 flex list-none flex-col gap-2 p-0 text-[13px]">
                        @foreach (\App\Models\SeriesMatch::SIDES as $checkSide)
                            @php($checkedAt = $m->readyAt($checkSide))
                            <li class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3" data-test="checkin-{{ $checkSide }}">
                                <span class="min-w-0 leading-snug font-bold [overflow-wrap:anywhere]">{{ $checkSide === 'challenger' ? $m->challenger_name : $m->challenged_name }}@if ($checkSide === $captainSide) <span class="font-normal text-ink-3">{{ __('(you)') }}</span>@endif</span>
                                @if ($checkedAt)
                                    <span class="inline-flex items-center gap-1 text-xs whitespace-nowrap text-win"><x-icon name="check" :size="14" class="shrink-0" />{{ __('in since :time', ['time' => SeriesPresenter::time($checkedAt, $viewer, 'H:i')]) }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-xs whitespace-nowrap text-ink-3"><x-icon name="clock" :size="14" class="shrink-0" />{{ __('not in yet') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($autoNoshowAt !== null)
                        {{-- The two clock times of SeriesService::autoNoShow(), time first like the live page's deadlines. --}}
                        <div class="flex flex-col gap-1.5 text-xs leading-normal text-ink-2" data-test="checkin-rule">
                            <span>{{ __('If no game is entered:') }}</span>
                            <ol class="m-0 flex list-none flex-col gap-1.5 p-0">
                                <li class="grid grid-cols-[3rem_minmax(0,1fr)] gap-x-2"><b class="text-ink tabular-nums">{{ SeriesPresenter::time($autoNoshowAt, $viewer, 'H:i') }}</b><span>{{ __('Only one side in: the other counts as a no-show and has :response minutes to answer.', ['response' => $m->responseMinutes()]) }}</span></li>
                                <li class="grid grid-cols-[3rem_minmax(0,1fr)] gap-x-2"><b class="text-ink tabular-nums">{{ SeriesPresenter::time($autoNoshowAt->copy()->addMinutes((int) $m->responseMinutes()), $viewer, 'H:i') }}</b><span>{{ __('Nobody in: the double no-show rule decides the match.') }}</span></li>
                            </ol>
                        </div>
                    @endif
                    @if ($captainSide !== null && $myCheckIn === null)
                        <span class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <x-button icon="check" wire:click="checkInLobby" :disabled="$m->start_at->isFuture()" class="disabled:cursor-not-allowed disabled:opacity-50" data-test="checkin-lobby">{{ __('I\'m in the lobby') }}</x-button>
                            <span class="text-xs text-ink-2">{{ $m->start_at->isFuture() ? __('Opens at :time, when the match starts.', ['time' => SeriesPresenter::time($m->start_at, $viewer, 'H:i')]) : __('Press it once you are in the game lobby.') }}</span>
                        </span>
                    @elseif ($myCheckIn !== null)
                        <p class="m-0 inline-flex items-center gap-1.5 text-xs font-bold text-win" data-test="checkin-done"><x-icon name="check" :size="14" class="shrink-0" />{{ __('You are checked in.') }}</p>
                    @endif
                </div>
            @endif
            @if ($m->status === SeriesStatus::Accepted && $noshowFrom)
                <div class="mt-2 flex flex-col gap-2 border-t border-hairline pt-3">
                    <b class="text-[13px]">{{ __('Opponent not in the lobby?') }}</b>
                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('From :time, :minutes minutes after start, you can report it. An admin checks and scores the match as a forfeit.', ['time' => SeriesPresenter::time($noshowFrom, $viewer, 'H:i'), 'minutes' => $m->noshowMinutes()]) }}</p>
                    <span class="flex flex-wrap items-center gap-3">
                        <x-button variant="secondary" icon="user" wire:click="reportNoShow" class="disabled:cursor-not-allowed disabled:opacity-50" :disabled="$captainSide === null || $noshowFrom->isFuture() || $games !== [] || $m->noshow_reported_at !== null" data-test="report-noshow">{{ __('Opponent didn\'t show') }}</x-button>
                        <span class="text-xs text-ink-3">{{ $m->noshow_reported_at ? __('reported, an admin decides') : ($games !== [] ? __('not needed, games are entered') : '') }}</span>
                    </span>
                </div>
            @endif
        </section>
        @endif

        {{--
            A fixed height (.room-chat): the room never grows with the chat, the messages scroll inside, newest at the
            bottom, older ones drawn when scrolled to the top (resources/js/roomChat.js). The height leaves room for the
            sticky header, the phone's tab bar and the match dock, so the field stays clear of them.
        --}}
        <section aria-labelledby="chat-h" @class(['room-chat flex flex-col rounded-lg bg-card', 'max-lg:-order-1' => $casualFirst]) x-data="roomChat(@js($this->chatConfig()))" x-on:casual-room.window="casualUpdate($event.detail.state)" x-on:casual-compose.window="cardKinds.includes($event.detail) && openComposer($event.detail)" x-on:lobby-pin.window="pinAction($event.detail)" x-effect="publishPin()" data-test="room-chat" wire:ignore>
            <span class="flex shrink-0 items-center justify-between gap-2 border-b border-hairline px-4 py-3 lg:px-6"><h2 id="chat-h" class="m-0 text-[15px] font-bold">{{ __('Chat') }}</h2><span class="inline-flex items-center gap-1.5 text-xs text-ink-2"><x-icon name="lock" :size="14" />{{ $casual ? __('private to both players') : __('private to both lineups') }}</span></span>
            <p x-show="status === 'live'" class="m-0 shrink-0 border-b border-hairline px-4 py-2 text-xs leading-normal text-ink-2 lg:px-6" data-test="chat-hint">{{ __('End-to-end encrypted over Nostr: the league server never receives or stores these messages.') }}</p>
            <div class="relative flex min-h-0 grow flex-col">
            <ol x-ref="list" x-effect="arrived(messages)" x-on:scroll.passive="onScroll()" aria-live="polite" class="m-0 flex min-h-0 grow list-none flex-col gap-3 overflow-y-auto overscroll-contain px-4 py-3 text-[13px] leading-normal lg:px-6" data-test="chat-messages">
                {{-- Pushes a short list to the bottom; a long one scrolls (justify-end would cut off its top). --}}
                <li aria-hidden="true" class="mt-auto"></li>
                <li x-show="hasOlder" class="self-center" data-test="chat-older">
                    <button type="button" x-on:click="loadOlder()" class="btn-w inline-flex min-h-11 cursor-pointer items-center rounded-md border border-line bg-transparent px-3 text-xs text-ink-2">{{ __('Earlier messages') }}</button>
                </li>
                <template x-for="m in visible" :key="m.id">
                    <li class="flex max-w-[85%] flex-col gap-1 rounded-md px-3 py-2" :class="[m.from === 'me' ? 'self-end bg-btc-press' : 'self-start bg-well', m.card && ! m.mutedCard && m.card.state === 'open' ? 'w-[260px] shadow-[inset_0_0_0_1px_#B9640A]' : '']" :data-from="m.from">
                        <span class="flex items-center gap-2 text-[11px]" :class="m.from === 'me' ? 'text-btc-hi' : 'text-ink-2'">
                            <span x-text="m.name + ', ' + time(m.at)"></span>
                            <span x-show="m.viaDm" class="text-ink-3" data-test="via-dm" x-text="t.viaDm"></span>
                            <button type="button" x-show="m.from !== 'me'" x-on:click="toggleMute(m.pubkey)" class="btn-w inline-flex h-6 cursor-pointer items-center rounded-sm border border-line bg-transparent px-2 text-[10px] text-ink-2" x-text="isMuted(m.pubkey) ? t.muted : t.mute"></button>
                        </span>
                        {{-- A card is drawn from its tags, as text; never from `content`, never as HTML (NIP "Rendering"). --}}
                        <template x-if="m.card && m.mutedCard">
                            <button type="button" x-on:click="reveal(m.id)" class="btn-w inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-transparent px-3 text-left text-xs text-ink-2" data-test="muted-card"><x-icon name="mute" :size="14" />{{ __('Lobby card from a muted player') }}</button>
                        </template>
                        <template x-if="m.card && ! m.mutedCard">
                            <div class="flex flex-col gap-1" data-test="chat-card" :data-kind="m.card.kind" :data-state="m.card.state">
                                <b class="flex items-center gap-1.5 text-[13px]"><x-icon name="key" :size="14" class="shrink-0" /><span x-text="m.card.title"></span></b>
                                <template x-for="f in (m.card.state === 'open' ? m.card.fields : [])" :key="f.label">
                                    <span class="grid grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-2 text-[13px]">
                                        <span class="text-ink-2" x-text="f.label"></span>
                                        <b class="font-mono break-all" x-text="f.value" data-test="card-value"></b>
                                        <button type="button" x-on:click="copy(f.value)" :aria-label="t.copy + ': ' + f.label" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-transparent text-ink-2"><x-icon name="copy" :size="16" /></button>
                                    </span>
                                </template>
                                <span x-show="m.card.note" class="text-xs text-ink-2" x-text="m.card.note"></span>
                            </div>
                        </template>
                        <template x-if="! m.card">
                            <span class="break-words" x-text="m.text"></span>
                        </template>
                    </li>
                </template>
                <li x-show="status === 'live' && messages.length === 0" class="text-ink-3">{{ __('No messages yet. Say hello.') }}</li>
                <li x-show="status === 'starting'" class="text-ink-3">{{ __('Connecting to the chat …') }}</li>
                <li x-show="status === 'needs-signer'" class="flex flex-col items-start gap-2 text-ink-2">
                    <span>{{ __('End-to-end encrypted over Nostr: the league server never receives or stores these messages.') }} {{ __('Open it to read and write messages.') }}</span>
                    <x-button variant="quiet" icon="chat" x-on:click="connect()">{{ __('Open chat') }}</x-button>
                </li>
                <li x-show="status === 'no-nip44'" class="text-ink-2">{{ __('Your signer cannot encrypt messages (NIP-44), so the chat is off. A Nostr extension or signer app with NIP-44 turns it on; the match itself works as usual.') }}</li>
                <li x-show="status === 'no-relays'" class="text-ink-2">{{ __('The chat has no relay here, so it is off.') }}</li>
                <li x-show="error" class="text-loss" role="alert" x-text="error"></li>
            </ol>
            {{-- Scrolled up while messages arrive: they wait below, counted, until the player comes back down. --}}
            <button type="button" x-show="unseen > 0" x-cloak x-on:click="toBottom()" data-test="chat-new-pill"
                    class="absolute bottom-3 left-1/2 inline-flex h-9 -translate-x-1/2 cursor-pointer items-center gap-1.5 rounded-full border-0 bg-btc px-4 text-xs font-bold whitespace-nowrap text-on-btc shadow-[0_8px_16px_rgba(10,10,11,.6)]">
                <span>{{ __('New messages') }}</span><span x-text="'(' + unseen + ')'"></span><span aria-hidden="true">↓</span>
            </button>
            </div>
            @if ($casual)
                @include('pages.matches.partials.card-composer')
            @endif
            <form x-show="status === 'live'" x-on:submit.prevent="send()" class="flex shrink-0 gap-2 border-t border-hairline px-4 pt-3 pb-4 lg:px-6" data-test="chat-form">
                <label for="roomchat" class="sr-only">{{ __('Message to both lineups') }}</label>
                <input id="roomchat" x-model="input" placeholder="{{ __('Message') }}" autocomplete="off" maxlength="500" class="h-11 min-w-0 grow rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
                <button type="submit" aria-label="{{ __('Send message') }}" :disabled="sending" class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:opacity-50"><x-icon name="send" :size="16" /></button>
            </form>
        </section>
    </div>

    {{-- Check the result (the other captain) --}}
    @if ($toAnswer)
        @php($reportWins = $report->score())
        <section id="check" aria-labelledby="check-h" class="grid grid-cols-1 gap-5 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#3A2A12] lg:grid-cols-2 lg:px-6" x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })" data-test="check-result">
            <div class="flex flex-col gap-3">
                <h2 id="check-h" class="m-0 text-[15px] font-bold">{{ __('Check the result from :clan', ['clan' => $m->sideName($report->side)]) }}</h2>
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="reported-score">
                    {{ __(':name submitted :a : :b for :clan', ['name' => $report->user?->displayName() ?? '', 'a' => $reportWins[$report->side], 'b' => $reportWins[SeriesMatch::otherSide($report->side)], 'clan' => $m->sideName($report->side)]) }}:
                    @foreach ($report->games as $index => $game){{ $index > 0 ? ', ' : '' }}{{ $game['challenger'] !== null ? $game['challenger'].' : '.$game['challenged'] : __(':tag win', ['tag' => $m->sideTag($game['winner'])]) }}@endforeach.
                </p>
                <p class="m-0 text-xs text-ink-2">{{ __('Played') }}: {{ implode(', ', array_column($report->roster, 'name')) }}</p>
                <div class="flex flex-wrap gap-3">
                    <x-button variant="quiet" icon="shield-check" x-on:click="run('prepareResponse', 'respond', 'confirmed')" ::disabled="busy" data-test="accept-result">{{ __('Accept result') }}</x-button>
                    <button type="button" x-on:click="$refs.reason.focus()" class="inline-flex h-11 cursor-pointer items-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Report a problem') }}</button>
                </div>
                <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
            </div>
            <div class="flex flex-col gap-2">
                <label for="reason" class="text-xs text-ink-2">{{ __('What went wrong? Shown publicly, up to :max characters', ['max' => SeriesService::REASON_MAX]) }}</label>
                <textarea id="reason" x-ref="reason" wire:model="reason" maxlength="{{ SeriesService::REASON_MAX }}" rows="3" data-test="dispute-reason" class="w-full resize-none rounded-lg border border-edge bg-ground px-3.5 py-3 text-[13px] text-ink"></textarea>
                @error('reason')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Evidence for a dispute: a screenshot of the end screen or the in-game match history. Only admins see it.') }}
                    <input type="file" wire:model="shots" multiple accept="image/*" class="text-xs text-ink-2 file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-well file:px-3 file:text-ink">
                </label>
                @error('shots.*')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                <div><button type="button" x-on:click="run('prepareResponse', 'respond', 'disputed')" :disabled="busy" data-test="send-dispute" class="inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-loss px-5 text-[13px] font-bold text-on-btc">{{ __('Send report') }}</button></div>
            </div>
        </section>
    @elseif ($m->status === SeriesStatus::Reported && $report !== null)
        <p class="m-0 rounded-md bg-card px-4 py-3 text-[13px] text-ink-2" data-test="waiting-for-ok">{{ __(':clan submitted the final score. Waiting for :other to accept it or report a problem.', ['clan' => $m->sideName($report->side), 'other' => $m->sideName(SeriesMatch::otherSide($report->side))]) }}</p>
    @elseif ($m->status === SeriesStatus::Disputed)
        <p class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="disputed-note">
            {{ __('A problem was reported: “:reason” An admin decides, or either captain submits a corrected score.', ['reason' => (string) $report?->response_reason]) }}
            @if ($m->new_report_requested_at) {{ __('An admin asked for a new report.') }}@endif
        </p>
    @endif

    {{-- Sticky score bar (MobileMatchRoom); a casual 1v1 only once both are in, before that its steps carry the action. --}}
    @if ($editable && (! $casual || $m->joined_at !== null))
        <div class="fixed inset-x-0 bottom-0 z-20 flex items-center gap-3 bg-bar px-4 py-3 shadow-[0_-1px_0_#2A2A30] lg:hidden" data-page-bar data-room-bar>
            <span class="flex flex-col"><b class="font-display text-[22px]">{{ $wins['challenger'] }}:{{ $wins['challenged'] }}</b><span class="text-[11px] text-ink-2">{{ $wins['challenger'] === $wins['challenged'] ? __('level') : __(':clan lead the series', ['clan' => $m->sideName($wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged')]) }}</span></span>
            <span class="grow"></span>
            <button type="button" x-on:click="submit = true" class="btn-p inline-flex h-[52px] cursor-pointer items-center gap-2 rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc"><x-icon name="shield-check" :size="18" />{{ __('Submit final score') }}</button>
        </div>
    @endif

    {{-- Submit dialog (Overlays.dc.html) --}}
    @if ($editable)
        <div x-show="submit" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-[rgba(10,10,11,.7)] lg:items-center" x-on:keydown.escape.window="submit = false">
            <section role="dialog" aria-modal="true" aria-labelledby="submit-h" class="flex max-h-[90svh] w-full max-w-[560px] flex-col gap-4 overflow-y-auto rounded-t-2xl bg-card px-5 py-6 shadow-ring lg:rounded-lg lg:px-6"
                     x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })" x-on:click.outside="submit = false" data-test="submit-dialog">
                <span class="flex items-center gap-3"><span class="flex size-10 items-center justify-center rounded-md bg-win-tint text-win"><x-icon name="shield-check" :size="20" /></span><h2 id="submit-h" class="m-0 text-lg font-bold">{{ __('Submit final score') }}</h2></span>
                @if ($draft !== null)
                    @php($draftWins = SeriesMatch::seriesScore($draft['games']))
                    @php($leader = $draftWins['challenger'] > $draftWins['challenged'] ? 'challenger' : 'challenged')
                    <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('You report that :winner beat :loser :a : :b. They accept it or report a problem. You can\'t edit it after this.', ['winner' => $m->sideName($leader), 'loser' => $m->sideName(SeriesMatch::otherSide($leader)), 'a' => max($draftWins), 'b' => min($draftWins)]) }}</p>
                    <div class="flex flex-col rounded-md px-4 shadow-ring">
                        @foreach ([
                            [__('Match'), $m->label().', '.$m->challenger_name.' vs '.$m->challenged_name.', '.$m->mode],
                            [__('Match kind'), ($m->rated ? __('Rated') : __('Casual')).', '.\App\Support\GameNames::game($m->game).' '.$m->mode],
                            [__('Games in this series'), implode(', ', array_map(fn ($g) => $g['challenger'] !== null ? $g['challenger'].' : '.$g['challenged'] : __(':tag win', ['tag' => $m->sideTag($g['winner'])]), $draft['games']))],
                            [__('Played'), implode(', ', array_column(array_filter($draft['roster'], fn ($r) => $r['side'] === ($captainSide ?? 'challenger')), 'name'))],
                            [__('Rating'), $m->rated ? __('with the league record') : __('casual Elo only, no rank')],
                            [__('Result'), __('Series :a : :b for :clan', ['a' => max($draftWins), 'b' => min($draftWins), 'clan' => $m->sideName($leader)])],
                        ] as [$key, $value])
                            <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-[13px] last:border-0"><span class="text-ink-2">{{ $key }}</span><span class="[overflow-wrap:anywhere]">{{ $value }}</span></div>
                        @endforeach
                    </div>
                    <x-proof :rows="$m->rated ? [[__('Record'), __('kind 2152 result, with score and roster')], [__('From'), $viewer->shortNpub()]] : [[__('Record'), __('casual: no Nostr event, league data only')], [__('From'), $viewer->shortNpub()]]" />
                    <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
                    <div class="flex justify-end gap-3">
                        <x-button variant="quiet" x-on:click="submit = false">{{ __('Back') }}</x-button>
                        <x-button icon="shield-check" x-on:click="run('prepareReport', 'report').then(() => { if (! error) submit = false })" ::disabled="busy" data-test="confirm-submit">{{ __('Submit final score') }}</x-button>
                    </div>
                @else
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="draft-error">{{ $draftError }}</p>
                    <div class="flex justify-end"><x-button variant="quiet" x-on:click="submit = false">{{ __('Back') }}</x-button></div>
                @endif
            </section>
        </div>
    @endif
</div>
