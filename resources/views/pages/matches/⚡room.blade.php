<?php

use App\Enums\Platform;
use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\ChatMute;
use App\Models\LineupSeat;
use App\Models\SeriesInvite;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Nostr\RejectedEvent;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\Opponents;
use App\Support\Series\CasualChallenges;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualLobby;
use App\Support\Series\CasualMatches;
use App\Support\Series\RoomMembers;
use App\Support\Series\SeriesPresenter;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\TournamentGameEnd;
use Carbon\CarbonInterface;
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
 *
 * A chess team match (`boards`, plan "Schach Rapid und Clan", P4) shares the
 * head, the answer and the chat; after the accept its step 2 is the lineup
 * (<livewire:team-lineup>), and the lobby, score sheet, who played, report
 * and no-show of a series are left out: the league plays and counts its
 * boards (SeriesService refuses them for a team match).
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

    /** The scoreboard row whose last save was refused: its error sits at that row, not at the top. */
    public ?int $errorRow = null;

    /** Fingerprint of what the last render showed; an unchanged room skips the render on sync(). */
    #[Locked]
    public string $shown = '';

    /** Unix time of the last render: a tick renders at least every RENDER_AT_LEAST seconds, whatever the fingerprint says. */
    #[Locked]
    public int $renderedAt = 0;

    /** Safety net for whatever the fingerprint does not see. */
    public const RENDER_AT_LEAST = 60;

    /** The client's fallback sync (resources/js/matchRoom.js roomSync): without a live websocket, and with one. */
    public const POLL_SECONDS = 30;

    public const POLL_SECONDS_WITH_SOCKET = 120;

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
     * The page's sync (resources/js/matchRoom.js roomSync: a push for this
     * series, a clock edge, the fallback poll): pull the live sheet written
     * by the other captain. Most syncs find nothing new; they answer without
     * a render (P5g: the full room was ~57 KB per tick) and the page stays as
     * it is.
     */
    public function sync(): void
    {
        $this->readSheet();

        // A team match's lineup lives outside the match row: its step renders on every sync of the room.
        if ($this->fresh()->isTeamMatch()) {
            $this->dispatch('team-lineup-sync');
        }

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
        // A refused game shows its reason at its own row of the scoreboard (rule "inline errors at the row").
        $this->errorRow = $this->error === '' ? null : $index;
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

    /**
     * A casual cup with the league's lobby (LeagueLobby, P5): the host created it in the game and says so. Nothing
     * travels through the chat; the league stores the same flag as for a shared card (CasualMatches::shareLobby()).
     */
    public function casualLeagueLobbyOpen(): void
    {
        $this->attempt(function (): void {
            abort_unless(\App\Support\Tournaments\LeagueLobby::isLeagueMade($this->fresh()), 404);
            app(CasualMatches::class)->shareLobby($this->match, $this->user());
        });
    }

    /**
     * One step of a goal field (the scoreboard's −/+, MatchRoom-3-Ergebnis.dc.html): an empty field counts as 0,
     * the value stays within 0 to 99, and the row saves like a typed score once both fields hold one.
     */
    public function stepGoal(int $index, string $box, int $delta): void
    {
        if (! isset($this->sheet[$index]) || ! in_array($box, ['c', 'd'], true) || ! in_array($delta, [-1, 1], true)) {
            return;
        }

        $current = $this->sheet[$index][$box];
        $this->sheet[$index][$box] = max(0, min(99, (is_numeric($current) ? (int) $current : 0) + $delta));
        $this->updatedSheet($this->sheet[$index][$box], "{$index}.{$box}");
    }

    /** "Winner only" for one game (the scoreboard's switch): the goals are dropped, a winner is picked instead. */
    public function toggleWinnerOnly(int $index): void
    {
        if (! isset($this->sheet[$index]) || ! $this->match->hasGoals()) {
            return;
        }

        $this->sheet[$index]['unknown'] = ! $this->sheet[$index]['unknown'];
        $this->updatedSheet($this->sheet[$index]['unknown'], "{$index}.unknown");
    }

    /** The winner of a winner-only game (the scoreboard's two "… wins" buttons). */
    public function pickWinner(int $index, string $side): void
    {
        if (! isset($this->sheet[$index]) || ! in_array($side, SeriesMatch::SIDES, true)) {
            return;
        }

        $this->sheet[$index]['winner'] = $side;
        $this->sheet[$index]['unknown'] = true;
        $this->updatedSheet($side, "{$index}.winner");
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

        // A team match has no report to draft (NIP rev. 9.22): the league counts its boards.
        if (! $match->isTeamMatch() && in_array($match->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true)) {
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
        // The room's members with their side, as "Your chats" reads them too (RoomMembers).
        $members = RoomMembers::of($match);

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
            'members' => $members,
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
        $this->errorRow = null;

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
     * The next moment the room changes by the clock alone, in server ms: the
     * clock edges fingerprint() compares against now (kick-off, the no-show
     * window, a casual match's deadlines). The client syncs just after it
     * (roomSync), so the page turns on time without a tick every 8 s.
     */
    public function nextEdgeMs(): ?int
    {
        $match = $this->fresh();
        $edges = [$match->start_at, $match->noshowReportableAt()];

        // A team match turns at its lineup lock.
        if ($match->isTeamMatch()) {
            $edges[] = ChessTeamMatches::lockAt($match);
        }

        if ($match->isCasualPairing()) {
            array_push($edges, $match->casualLobbyDueAt(), $match->casualJoinDueAt(), $match->casualNextDeadline()['at'] ?? null);
        }

        $future = array_filter($edges, fn (mixed $edge): bool => $edge instanceof CarbonInterface && $edge->isFuture());

        return $future === [] ? null : min(array_map(fn (CarbonInterface $edge): int => (int) $edge->getTimestampMs(), $future));
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
    // A chess team match (P4): no sheet, report or no-show of a series; its lineup step replaces them.
    $team = $m->isTeamMatch();
    $editable = ! $team && ! $directorEntered && $captainSide !== null && in_array($m->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true) && ! $m->start_at?->isFuture();
    $toAnswer = ! $directorEntered && $m->status === SeriesStatus::Reported && $report?->status === ReportStatus::Open && $captainSide !== null && $captainSide !== $report->side;
    $playing = count(array_filter($this->sheet, fn ($g) => $g['winner'] !== null));
    $captainOf = fn (string $side) => $m->lineup($side)?->clan?->owner?->displayName() ?? '';
    $noshowFrom = $m->noshowReportableAt();
    $checks = ($report !== null && $report->status !== ReportStatus::Superseded ? 1 : 0) + ($m->status->hasResult() ? 1 : 0);
    $status = match ($m->status) {
        SeriesStatus::Open => [__('Waiting for an answer'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Accepted => $m->start_at?->isFuture() ? [__('Starts :time', ['time' => SeriesPresenter::time($m->start_at, $viewer, 'D H:i')]), 'bg-well text-ink-2'] : ($team ? [__('Boards playing'), 'bg-btc-press text-btc-hi'] : [__('Score to submit'), 'bg-btc-press text-btc-hi']),
        SeriesStatus::Reported => [__('Not confirmed yet'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Disputed => [__('Disputed'), 'bg-loss-tint text-loss'],
        SeriesStatus::Confirmed, SeriesStatus::Resolved => [__('Result saved'), 'bg-win-tint text-win'],
        default => [$chip['label'], 'bg-well text-ink-2'],
    };
    $sideColor = ['challenger' => '#F7931A', 'challenged' => '#3A3A42'];
    $sideInk = ['challenger' => 'text-on-btc', 'challenged' => 'text-ink'];
    $seriesMeta = $team ? implode(', ', [trans_choice(':count board|:count boards', $m->boards), $m->mode, $m->rated ? __('Rated') : __('Friendly')]) : implode(', ', array_filter([
        'BO'.$m->best_of,
        $m->mode,
        ! $m->status->hasResult() && $games !== [] ? __('provisional') : null,
        $m->status === SeriesStatus::Accepted && ! $m->start_at?->isFuture() ? __('game :n playing', ['n' => min($m->best_of, $playing + 1)]) : null,
    ]));
    // A casual 1v1 (P23): its steps are the way into the game, the lobby travels as a card in the chat.
    $casual = $m->isCasualPairing();
    // A tournament series on the league's deadlines (P18, slice 5): the countdown to the automatic decision. A casual cup's series has it in its steps.
    $wait = ! $casual && $m->deadlines !== null && ! $m->status->hasResult() ? \App\Support\Tournaments\TournamentWaits::ofPlay($m) : null;
    // The clock of the step that runs, top right of the head (MatchRoom boards: "Lobby bis 20:15", Min / Sek).
    // A time on another day carries its day ("Check-in ab Mo. 9.11., 01:45"): a bare 01:45 a day ahead read as tonight.
    $viewerZone = \App\Support\PreSeason::timezoneFor($viewer);
    $clockTime = fn ($time): string => $time->copy()->timezone($viewerZone)->isSameDay(now()->timezone($viewerZone)) ? SeriesPresenter::time($time, $viewer, 'H:i') : SeriesPresenter::time($time, $viewer);
    $casualDeadline = $casual ? $m->casualNextDeadline() : null;
    $roomClock = match (true) {
        $casualDeadline !== null && $casualDeadline['kind'] === 'checkin' && $m->awaitsCheckIn() && $m->checkInOpensAt()?->isFuture() => ['at' => $m->checkInOpensAt(), 'label' => __('Check-in from :time', ['time' => $clockTime($m->checkInOpensAt())])],
        $casualDeadline !== null => ['at' => $casualDeadline['at'], 'label' => match ($casualDeadline['kind']) {
            'ready' => __('Ready by :time', ['time' => $clockTime($casualDeadline['at'])]),
            'checkin' => __('Check-in until :time', ['time' => $clockTime($casualDeadline['at'])]),
            'lobby' => __('Lobby until :time', ['time' => $clockTime($casualDeadline['at'])]),
            'join' => __('Join until :time', ['time' => $clockTime($casualDeadline['at'])]),
            'report' => __('Result until :time', ['time' => $clockTime($casualDeadline['at'])]),
            default => __('Answer until :time', ['time' => $clockTime($casualDeadline['at'])]),
        }],
        $wait?->decidesAt !== null && $wait->decidesAt->isFuture() => ['at' => $wait->decidesAt, 'label' => __('Decision at :time', ['time' => $clockTime($wait->decidesAt)])],
        $m->status === SeriesStatus::Accepted && $m->start_at?->isFuture() === true => ['at' => $m->start_at, 'label' => __('Start at :time', ['time' => $clockTime($m->start_at)])],
        default => null,
    };
    if ($roomClock !== null && ! $roomClock['at']->isFuture()) {
        $roomClock = null;
    }
    // The header's chip for this step (Header.dc.html "Kopfzeile, Frist läuft"): a short word for the countdown under 15 minutes.
    if ($roomClock !== null) {
        $roomClock['short'] = match (true) {
            $casualDeadline === null => $wait?->decidesAt !== null ? __('Decision') : __('Match start'),
            $casualDeadline['kind'] === 'ready' => __('Ready'),
            $casualDeadline['kind'] === 'checkin' => __('Check-in'),
            $casualDeadline['kind'] === 'lobby' => __('Lobby'),
            $casualDeadline['kind'] === 'join' => __('Join'),
            $casualDeadline['kind'] === 'report' => __('Result'),
            default => __('Reply'),
        };
    }
    $steps = [
        [__('Challenge'), SeriesPresenter::time($m->created_at ?? now(), $viewer, 'D H:i'), __('sent by :clan', ['clan' => $m->challenger_name]), true],
        [__('Accepted'), $m->answered_at && $m->start_at ? SeriesPresenter::time($m->answered_at, $viewer, 'D H:i') : '', $m->answered_at && $m->start_at ? __('by :clan', ['clan' => $m->challenged_name]) : __('waiting'), $m->start_at !== null],
        [__('Result'), $report ? SeriesPresenter::time($report->created_at ?? now(), $viewer, 'H:i') : __('now'), $report ? __(':clan submitted, 1 of 2', ['clan' => $m->sideName($report->side)]) : __('a captain submits, 1 of 2'), $report !== null],
        [__('Their OK'), $m->finished_at && $m->status->hasResult() ? SeriesPresenter::time($m->finished_at, $viewer, 'H:i') : '', $m->status === SeriesStatus::Disputed ? __('problem reported') : __('the other captain, 2 of 2'), $m->status->hasResult()],
    ];
    $lobbyPinned = ! $casual && $m->lobby_name !== null && $m->status->isRunning();
    // Whoever can enter the games can submit them (user, 2026-10-05: "ich kann die Ergebnisse nicht melden! kein Button da"):
    // a casual 1v1 played without both ticking "I'm in" had the score sheet but no submit button. The server never required it.
    $canSubmit = $editable;
    // A 1v1 side is one player whose name is the side's name: the player chips under the versus would only repeat it.
    $showPlayers = collect(SeriesMatch::SIDES)->contains(fn (string $side): bool => count($rosters[$side]) !== 1 || $rosters[$side][0]['seat']->user->displayName() !== $m->sideName($side));
    // The step a series stands at (2 get in, 3 play and report); a casual 1v1 reads its own steps below.
    $flowNow = match (true) {
        ! $m->status->isRunning() => null,
        $casual => $m->joined_at === null && $m->status === SeriesStatus::Accepted ? 2 : 3,
        $m->status === SeriesStatus::Accepted && ($m->start_at?->isFuture() || ($m->tournament_match_id !== null && $captainSide !== null && $m->readyAt($captainSide) === null)) => 2,
        default => 3,
    };
    /*
     * One view per step (plan "Canvas and live alignment", P3; boards MatchRoom-1a … 4c, user 2026-11-07: "code follows
     * the board"). The room shows the step that runs and its one primary action; everything else (rules, facts,
     * timeline, the season line, Nostr) waits in one closed Details block under it.
     */
    $view = match (true) {
        $m->status->hasResult() => 'done',
        $m->status === SeriesStatus::Disputed => 'problem',
        $toAnswer => 'check',
        $m->status === SeriesStatus::Reported => 'reported',
        $m->status === SeriesStatus::Open => 'answer',
        $team => 'lineup',
        $casual && $m->awaitsReady() => 'ready',
        $casual && $m->awaitsCheckIn() => 'checkin',
        $casual => $m->joined_at === null || $m->noshow_reported_at !== null ? 'lobby' : 'result',
        default => $flowNow === 2 ? 'lobby' : 'result',
    };
    // A casual 1v1's sheet belongs to its result step; before it a quiet "Enter the result now" opens it (user, 2026-10-05:
    // a match played without both pressing "I am in the lobby" still has to be reportable).
    $sheetFirst = ! $casual || ! in_array($view, ['ready', 'checkin', 'lobby'], true);
    // The step's clock stands in its card on a phone (boards *-Phone), in the head from sm.
    $cardClock = $casual && $casualDeadline !== null && in_array($view, ['ready', 'checkin', 'lobby'], true);
    // The phone's action bar (boards *-Phone): the step's actions on the floor above the tab bar; "Chat" where the board has it.
    $barChat = in_array($view, ['checkin', 'lobby', 'reported', 'answer', 'lineup'], true);
    $mine = $captainSide ?? $mySide ?? 'challenger';
    // Who played is a choice only when a lineup has more seats than the mode plays; a 1v1's one player per side is no choice.
    $rosterChoice = ! $team && count($rosters[$mine]) > $m->gameMode()->teamSize;
@endphp

<div class="flex grow flex-col gap-4 px-4 pt-0 pb-40 lg:mx-auto lg:w-full lg:max-w-[1232px] lg:gap-5 lg:px-4 lg:pb-10" data-test="match-room" data-view="{{ $view }}"
     x-data="roomSync(@js(['userId' => $viewer->id, 'number' => $m->number, 'poll' => $this::POLL_SECONDS, 'pollWithSocket' => $this::POLL_SECONDS_WITH_SOCKET]))"
     data-server-now="{{ (int) now()->getTimestampMs() }}" data-next-edge="{{ $this->nextEdgeMs() ?? 0 }}">

    {{-- The step's deadline for the header's chip (Header.dc.html "Kopfzeile, Frist läuft", resources/js/shellNav.js dockCount). --}}
    @if ($roomClock !== null)
        <span hidden data-test="room-deadline" data-short="{{ $roomClock['short'] }}" data-until="{{ $roomClock['label'] }}" data-ends="{{ $roomClock['at']->getTimestampMs() }}"></span>
    @elseif ($m->status === SeriesStatus::Disputed)
        <span hidden data-test="room-deadline" data-short="" data-until="{{ __('Admin checks') }}" data-ends=""></span>
    @endif

    {{-- 1. Who vs who, and where it stands: the tournament first (user, 2026-10-03), the versus, then status and deadline in one line. --}}
    <div class="flex flex-col gap-2 lg:gap-4" data-flow="1" data-test="room-head">

        <x-tournaments.game-banner :banner="$m->tournament_match_id !== null ? TournamentGameEnd::banner($m) : null" />

        @include('pages.matches.partials.room-head')

        {{-- Both lineups, each name opens the player card (MatchRoom, P10a); a 1v1 side is its one player, already named in the head. --}}
        @if ($showPlayers)
            <div class="hidden gap-6 lg:flex lg:justify-between" data-test="series-players">
                @foreach (['challenger', 'challenged'] as $cell)
                    <span role="group" aria-label="{{ __(':name players', ['name' => $m->sideName($cell)]) }}" @class(['flex min-w-0 flex-row flex-wrap gap-1.5', 'justify-end' => $cell === 'challenged'])>
                        @foreach ($rosters[$cell] as ['seat' => $seat])
                            <x-player-link :user="$seat->user" class="relative inline-flex h-8 max-w-full min-w-0 items-center gap-1.5 rounded-[4px] bg-card pr-2 pl-1 text-xs whitespace-nowrap after:absolute after:inset-x-0 after:-inset-y-1.5">
                                <x-avatar :user="$seat->user" :size="24" class="rounded-sm" /><span class="truncate">{{ $seat->user->displayName() }}</span>
                            </x-player-link>
                        @endforeach
                    </span>
                @endforeach
            </div>
        @endif

        {{-- The four steps, right under the head (every room board). --}}
        @include('pages.matches.partials.room-stepper')

        <x-tournament-night-pause :series="$m" />
        @if ($wait?->decidesAt !== null)
            <p class="m-0 flex flex-col gap-1 rounded-md bg-card px-4 py-3 text-[13px] leading-normal shadow-[inset_0_0_0_1px_#F7931A]" role="status" data-test="room-auto-decision">
                <x-tournaments.auto-decision :wait="$wait" class="text-ink" />
                @if ($viewer !== null && $wait->waitsOn($viewer->id) && $wait->action !== null)
                    <span class="text-ink-2">{{ $wait->actionText() }}</span>
                @endif
            </p>
        @endif
    </div>

    @if ($error && $errorRow === null)
        <p class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert" data-test="room-error">{{ $error }}</p>
    @endif

    {{--
        One view per step in the main column, the chat column right of it from lg (R1, R6). Below lg one column: the
        step, the chat, then the closed Details block.
    --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_400px] lg:grid-rows-[auto_1fr] lg:gap-5">
    <div class="flex min-w-0 flex-col gap-4 lg:col-start-1 lg:row-start-1 lg:gap-5" data-test="room-main" data-view="{{ $view }}"
         x-data="{ fix: false, early: false }" x-bind:class="{ 'is-early': early }" x-on:room-fix.window="fix = true">
        {{-- The result, once there is one, is where it stands: right under the head. Win moment (Overlays.dc.html "Win, once the other captain accepts") --}}
        @if ($m->status->hasResult())
            @php($won = $m->winner === $mySide)
            <section aria-label="{{ __('Result') }}" class="grid grid-cols-1 items-center gap-4 rounded-lg px-5 py-6 lg:grid-cols-[minmax(0,1.4fr)_auto_minmax(0,1fr)] lg:px-8" style="background: linear-gradient(90deg, {{ $won ? '#111A14' : '#17171B' }}, #121215 60%); box-shadow: inset 0 0 0 1px {{ $won ? '#1F5A34' : '#2A2A30' }}" data-test="win-moment">
                <span class="flex items-center gap-4">
                    @if ($m->winner === 'challenger' || $m->winner === 'challenged')
                        <x-clan-tag :clan="$m->sideClan($m->winner)" :tag="$m->sideTag($m->winner)" :tile="56" class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md font-display text-[13px] font-extrabold {{ $sideInk[$m->winner] }}" style="background: {{ $sideColor[$m->winner] }}" />
                        <span class="flex min-w-0 flex-col gap-1"><b class="line-clamp-2 font-display text-[22px] leading-tight [overflow-wrap:anywhere]" title="{{ $m->sideName($m->winner) }}">{{ $m->sideName($m->winner) }}</b><span class="inline-flex items-center gap-1 text-[13px] text-win"><x-icon name="check" :size="14" />{{ __('Win') }}</span></span>
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
                    @if ($m->resolution_reason)<span>{{ __('Admin decision') }}: {{ \App\Support\Series\SeriesPresenter::resolutionReason($m->resolution_reason) }}</span>@endif
                    <a href="{{ route('matches.show', $m) }}" class="inline-flex min-h-11 items-center">{{ __('Open the match page') }}</a>
                </span>
                {{-- The games of the series, short (board MatchRoom-4b: the rows under the result). --}}
                @if ($games !== [])
                    <ol class="m-0 flex list-none flex-wrap gap-x-6 gap-y-1 p-0 text-[13px] text-ink-2 lg:col-span-3" data-test="win-games">
                        @foreach ($games as $index => $game)
                            <li class="whitespace-nowrap"><span>{{ __('Game :n', ['n' => $index + 1]) }}</span> <b class="text-ink tabular-nums">{{ ($game['challenger'] ?? null) !== null ? $game['challenger'].' : '.$game['challenged'] : __(':tag win', ['tag' => $m->sideTag((string) $game['winner'])]) }}</b></li>
                        @endforeach
                    </ol>
                @endif
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
                                <x-button variant="quiet" wire:click="casualDeclineRematch" class="max-lg:hidden" data-room-secondary data-test="casual-rematch-decline">{{ __('Decline') }}</x-button>
                                <x-button icon="check" wire:click="casualAcceptRematch({{ $rematchIn->id }})" class="max-lg:hidden" data-room-primary data-test="casual-rematch-incoming">{{ __('Accept rematch') }}</x-button>
                            </span>
                        @elseif ($rematchOut)
                            <button type="button" wire:click="casualWithdrawRematch" class="inline-flex h-11 cursor-pointer items-center justify-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss" data-test="casual-rematch-waiting">{{ __('Withdraw') }}</button>
                        @else
                            <x-button icon="retry" wire:click="casualRematch" class="min-h-12 shrink-0 px-6 font-display text-base font-bold max-lg:hidden" data-room-primary data-test="casual-rematch">{{ __('Rematch') }}</x-button>
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

    {{-- Get into the game: answer the challenge, or the lobby and the check-in. --}}
    @if ($m->status === SeriesStatus::Open)
        <div data-flow="2">
            @if ($casual)
                @include('pages.matches.partials.casual-challenge')
            @else
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
        </div>
    @elseif ($casual && in_array($view, ['ready', 'checkin', 'lobby', 'result'], true))
        {{-- A casual 1v1: its steps are the way in (ready or check-in, lobby card, joined); in the result step only the folded lobby. --}}
        <div data-flow="2">
            @include('pages.matches.partials.casual-steps')
        </div>
    @elseif ($team && $m->status->isRunning())
        {{-- A team match: the captains pick their players, hidden from each other until the lock. --}}
        <div data-flow="2">
            <livewire:team-lineup :match="$m" :key="'team-lineup-'.$m->id" />
        </div>
    @elseif (! $casual && $m->status->isRunning())
        <div data-flow="2">
            {{--
                The lobby the league made (a tournament match, LeagueLobby) or a captain set (a lineup series,
                SeriesService::setLobby()), pinned under the steps while the series runs (2026-10-02: "die Lobby Karte ...
                darf nicht irgendwo im Chat unlesbar verschwinden"; boards MatchRoom-2c, TournamentMatchRoom; R20).
            --}}
            @if ($lobbyPinned)
                @include('pages.matches.partials.room-lobby')
            @endif
            <div @class(['flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6', 'mt-4' => $lobbyPinned]) data-test="get-in">
                @unless ($lobbyPinned)
                    <span class="flex items-baseline justify-between gap-2"><h2 id="lobby-h" class="m-0 shrink-0 text-[15px] font-bold">{{ __('Private lobby') }}</h2><span class="min-w-0 text-right text-xs text-ink-2 [overflow-wrap:anywhere]">{{ __('host :clan', ['clan' => $m->challenger_name]) }}</span></span>
                @endunless
                @if ($editLobby)
                    <form wire:submit="saveLobby" class="flex flex-col gap-3">
                        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Name') }}<input wire:model="lobbyName" maxlength="32" required data-test="lobby-name-input" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink"></label>
                        <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Password') }}<input wire:model="lobbyPassword" maxlength="32" autocomplete="off" data-test="lobby-password-input" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink"></label>
                        {{-- The region as a row of chips, never a native select (rule "No native form controls"). --}}
                        <div role="radiogroup" aria-labelledby="lobby-region-l" class="flex flex-col gap-1">
                            <span id="lobby-region-l" class="text-xs text-ink-2">{{ __('Region') }}</span>
                            <span class="flex flex-wrap gap-2">
                                @foreach (config('esports.series.regions') as $region)
                                    <button type="button" role="radio" aria-checked="{{ $lobbyRegion === $region ? 'true' : 'false' }}" wire:click="$set('lobbyRegion', @js($region))" data-test="lobby-region-{{ \Illuminate\Support\Str::slug($region) }}"
                                            @class(['rv-b2 is-sm h-11', 'border-btc! text-btc-hi!' => $lobbyRegion === $region])>{{ $region }}</button>
                                @endforeach
                            </span>
                        </div>
                        <div class="flex gap-3"><x-button type="submit" data-test="save-lobby">{{ __('Save lobby') }}</x-button><x-button variant="quiet" wire:click="$set('editLobby', false)">{{ __('Cancel') }}</x-button></div>
                    </form>
                @elseif ($m->lobby_name === null)
                    <p class="m-0 text-[13px] text-ink-2">{{ $m->status->isRunning() ? __('No lobby yet. The host sets name and password here.') : __('The lobby opens once the challenge is accepted.') }}</p>
                @endif
                @unless ($lobbyPinned)
                    <p class="m-0 flex items-center gap-2 py-1 text-xs text-ink-2"><x-icon name="lock" :size="14" class="text-win" />{{ __('Only the two lineups see this. It is never published.') }}</p>
                @endunless
                @if ($captainSide !== null && $m->status->isRunning() && ! $editLobby)
                    <div><x-button variant="quiet" icon="brush" wire:click="openLobbyEditor" data-test="change-lobby">{{ $m->lobby_name === null ? __('Set lobby') : __('Change lobby') }}</x-button></div>
                @endif
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
                                    <li class="grid grid-cols-[3rem_minmax(0,1fr)] gap-x-2"><b class="text-ink tabular-nums">{{ SeriesPresenter::time($m->doubleNoshowAt(), $viewer, 'H:i') }}</b><span>{{ __('Nobody in: the double no-show rule decides the match.') }}</span></li>
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
            </div>
        </div>
    @endif

    {{--
        A reported problem (board MatchRoom-4c-Problem): the reason in its own words, who decides, and the corrected
        result as the one action; the sheet opens under it on "Send corrected result".
    --}}
    @if ($view === 'problem' && $report !== null)
        @php($disputerSide = SeriesMatch::otherSide($report->side))
        @php($evidenceIn = $m->evidence()->exists())
        <section aria-labelledby="problem-h" class="rv-card flex flex-col gap-3 px-4 py-4 [overflow-wrap:anywhere] lg:px-6 lg:py-5" data-test="room-problem">
            <h2 id="problem-h" class="rv-h3">{{ __('Problem reported') }}</h2>
            <p class="m-0 text-[15px] leading-normal" data-test="room-problem-reason"><span class="text-ink-2">{{ $disputerSide === $mySide ? __('You') : $m->sideName($disputerSide) }}:</span> {{ (string) $report->response_reason }}</p>
            <span class="flex flex-wrap items-center gap-2">
                @if ($evidenceIn)
                    <span class="rv-tag h-7"><x-icon name="upload" :size="12" />{{ __('Evidence uploaded, only admins see it') }}</span>
                @endif
                <span class="rv-tag is-btc h-7" data-test="room-problem-admin">{{ __('An admin decides') }}</span>
            </span>
            @if ($m->new_report_requested_at)
                <p class="m-0 text-[13px] leading-normal text-btc-hi">{{ __('An admin asked for a new report.') }}</p>
            @endif
            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Until then either side can send a corrected result.') }}</p>
            @if ($canSubmit)
                <div x-show="! fix"><x-button icon="retry" x-on:click="fix = true; $nextTick(() => document.querySelector('[data-test=games]')?.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }))" class="min-h-[52px] px-6 max-lg:hidden" data-room-primary data-test="room-problem-fix">{{ __('Send corrected result') }}</x-button></div>
            @endif
        </section>
    @endif

    {{--
        Play and enter the results: the sheet, and right under it the report and its state, in one card (user,
        2026-10-04: "Ergebnis melden ist auf einmal ganz wo anders zu finden, als die Ergebnisse, die man einträgt").
        The result step's view; a casual 1v1 before it opens it on "Enter the result now", a reported problem on
        "Send corrected result". A finished match keeps its games in the result card and in Details.
    --}}
    @if (! $team && $view !== 'done' && $view !== 'answer')
    <div class="flex flex-col gap-4 lg:gap-5" data-flow="3"
         @if ($view === 'problem') x-show="fix" x-cloak @elseif (! $sheetFirst) x-show="early" x-cloak @endif>
        <div class="flex flex-col rounded-lg bg-card">
            {{-- The check step names the sheet after itself (board MatchRoom-4a: "Ergebnis prüfen", who reported). --}}
            @include('pages.matches.partials.room-scoreboard', ['sheet' => $this->sheet] + ($toAnswer ? ['sheetTitle' => __('Check the result'), 'sheetHint' => __(':name reported', ['name' => $report->user?->displayName() ?? $m->sideName($report->side)])] : []))
            @php($reportState = $directorEntered || $canSubmit || $toAnswer || ($m->status === SeriesStatus::Reported && $report !== null) || $m->status === SeriesStatus::Disputed
                || ($m->status === SeriesStatus::Accepted && ! $casual && ($captainSide === null || $m->start_at?->isFuture())))
            @if ($reportState)
                <div id="report" class="flex flex-col gap-3 border-t border-hairline px-4 pt-4 pb-5 lg:px-6" x-intersect:enter="reportInView = true" x-intersect:leave="reportInView = false" data-test="report-block">
                    @if ($directorEntered)
                        <p class="m-0 flex items-center gap-2 text-[13px] text-ink-2" data-test="director-entered">
                            <x-icon name="shield-check" :size="16" />{{ __('Results are entered by the tournament directors.') }}
                        </p>
                    @endif
                    @if ($toAnswer)
                        {{--
                            Check the result (the other side; boards MatchRoom-4a-Pruefen and its phone board): the games above,
                            the reported score in one line, "Report a problem | Accept result" right under it. "Report a problem"
                            opens the reason and the evidence first, and only "Send report" files it (R18).
                        --}}
                        @php($reportWins = $report->score())
                        <section id="check" aria-labelledby="check-h" class="flex flex-col gap-4 [overflow-wrap:anywhere]" x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })" data-test="check-result">
                            <h2 id="check-h" class="sr-only">{{ __('Check the result from :clan', ['clan' => $m->sideName($report->side)]) }}</h2>
                            <p class="m-0 sr-only" data-test="reported-score">
                                {{ __(':name submitted :a : :b for :clan', ['name' => $report->user?->displayName() ?? '', 'a' => $reportWins[$report->side], 'b' => $reportWins[SeriesMatch::otherSide($report->side)], 'clan' => $m->sideName($report->side)]) }}:
                                @foreach ($report->games as $index => $game){{ $index > 0 ? ', ' : '' }}{{ $game['challenger'] !== null ? $game['challenger'].' : '.$game['challenged'] : __(':tag win', ['tag' => $m->sideTag($game['winner'])]) }}@endforeach.
                            </p>
                            @php($checkLead = $reportWins['challenged'] > $reportWins['challenger'] ? ['challenged', 'challenger'] : ['challenger', 'challenged'])
                            <div x-data="{ dispute: false }" x-on:room-dispute.window="dispute = true; $nextTick(() => $refs.reason?.focus())" class="flex flex-col gap-4">
                                <span class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:gap-4">
                                    <b class="rv-sum grow">{{ $m->sideName($checkLead[0]) }} <span class="whitespace-nowrap">{{ $reportWins[$checkLead[0]] }} : {{ $reportWins[$checkLead[1]] }}</span> {{ $m->sideName($checkLead[1]) }}</b>
                                    <span class="flex shrink-0 gap-3 max-lg:hidden">
                                        <x-button variant="secondary" icon="flag" x-on:click="dispute = true; $nextTick(() => $refs.reason?.focus())" ::disabled="busy" class="h-[52px] px-5" data-room-secondary data-test="open-dispute">{{ __('Report a problem') }}</x-button>
                                        <x-button icon="shield-check" x-on:click="run('prepareResponse', 'respond', 'confirmed')" ::disabled="busy" class="h-[52px] px-6" data-room-primary data-test="accept-result">{{ __('Accept result') }}</x-button>
                                    </span>
                                </span>
                                @if ($casual && $roomClock !== null)
                                    <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="check-deadline">{{ __(':deadline, otherwise the result stands.', ['deadline' => $roomClock['label']]) }}</p>
                                @endif
                                <p class="m-0 text-xs text-ink-2">{{ __('Played') }}: {{ implode(', ', array_column($report->roster, 'name')) }}</p>
                                <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
                                <div x-show="dispute" x-cloak class="flex min-w-0 flex-col gap-2 border-t border-hairline pt-4" data-test="dispute-form">
                                    <label for="reason" class="text-xs text-ink-2">{{ __('What went wrong? Shown publicly, up to :max characters', ['max' => SeriesService::REASON_MAX]) }}</label>
                                    <textarea id="reason" x-ref="reason" wire:model="reason" maxlength="{{ SeriesService::REASON_MAX }}" rows="3" data-test="dispute-reason" class="w-full resize-none rounded-lg border border-edge bg-ground px-3.5 py-3 text-[13px] text-ink"></textarea>
                                    @error('reason')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Evidence for a dispute: a screenshot of the end screen or the in-game match history. Only admins see it.') }}
                                        <input type="file" wire:model="shots" multiple accept="image/*" class="max-w-full text-xs text-ink-2 file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-well file:px-3 file:text-ink">
                                    </label>
                                    @error('shots.*')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                                    <span class="flex flex-wrap gap-3">
                                        <x-button variant="quiet" x-on:click="dispute = false" data-test="cancel-dispute">{{ __('Cancel') }}</x-button>
                                        <button type="button" x-on:click="run('prepareResponse', 'respond', 'disputed')" :disabled="busy" data-test="send-dispute" class="inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-loss px-5 text-[13px] font-bold text-on-btc">{{ __('Send report') }}</button>
                                    </span>
                                </div>
                            </div>
                        </section>
                    @elseif ($m->status === SeriesStatus::Reported && $report !== null)
                        <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="waiting-for-ok">{{ __(':clan submitted the final score. Waiting for :other to accept it or report a problem.', ['clan' => $m->sideName($report->side), 'other' => $m->sideName(SeriesMatch::otherSide($report->side))]) }}</p>
                    @endif
                    @if ($canSubmit)
                        {{-- The summary line and the send action, right under the games it reports (board "Ergebnis: A 2 : 0 B"); after a dispute either captain sends the corrected score here. --}}
                        @php($sumLead = $wins['challenged'] > $wins['challenger'] ? ['challenged', 'challenger'] : ['challenger', 'challenged'])
                        <span class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:gap-4" data-test="report-summary">
                            <span class="rv-sum grow">{{ __('Result: :a :x : :y :b', ['a' => $m->sideName($sumLead[0]), 'x' => $wins[$sumLead[0]], 'y' => $wins[$sumLead[1]], 'b' => $m->sideName($sumLead[1])]) }}</span>
                            <button type="button" x-on:click="submit = true" class="rv-b h-[52px] shrink-0 px-6" data-room-primary data-test="open-submit">{{ __('Send result') }}</button>
                        </span>
                    @elseif ($m->status === SeriesStatus::Accepted && ! $directorEntered && $captainSide === null)
                        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Your captain enters the games and submits the final score.') }}</p>
                    @elseif ($m->status === SeriesStatus::Accepted && ! $directorEntered && ! $casual && $m->start_at?->isFuture())
                        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Once the match starts at :time, you enter the games and submit the final score here.', ['time' => SeriesPresenter::time($m->start_at, $viewer, 'H:i')]) }}</p>
                    @endif
                </div>
            @endif
        </div>

        @if ($rosterChoice)
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
                <p class="m-0 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-xs leading-normal text-ink-2 [overflow-wrap:anywhere]" data-test="opponent-roster">{{ $m->sideName(SeriesMatch::otherSide($mine)) }}:
                    @foreach (array_filter($rosters[SeriesMatch::otherSide($mine)], fn ($r) => $r['on']) as ['seat' => $seat])
                        <span class="inline-flex max-w-full min-w-0 items-center gap-1"><x-player-link :user="$seat->user" class="inline-flex min-h-6 min-w-0 items-center" /><x-copy-npub :npub="$seat->user->npub" :name="$seat->user->displayName()" /></span>
                    @endforeach
                </p>
                <p class="m-0 text-xs text-ink-3">{{ __('their captain sets this') }}</p>
                <p class="m-0 text-xs leading-normal text-ink-2">{{ __('This list goes out with your result.') }}</p>
            </section>
        @endif
    </div>
    @endif

    {{-- A no-show report the player can file right now stays with the step; otherwise it waits in Details. --}}
    @php($noshowNow = ! $casual && ! $team && $m->status === SeriesStatus::Accepted && $noshowFrom !== null && ! $noshowFrom->isFuture() && $captainSide !== null && $games === [] && $m->noshow_reported_at === null)
    @if ($noshowNow)
        <div data-flow="4">@include('pages.matches.partials.room-problems')</div>
    @endif
    </div>

    {{--
        The chat, with the lobby beside it (user, 2026-10-04: "Die Lobby Daten müssen auch neben dem Chat da stehen").
        From lg a column right of steps 2 to 5 that stays in view; below lg it follows the play step.
    --}}
    <div class="flex flex-col gap-5 lg:sticky lg:top-[var(--spacing-below-shell)] lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:self-start" data-test="room-side">
        {{-- The lobby sits pinned under the steps (room-lobby); the rail is the chat alone (R6). --}}

            {{--
                A fixed height (.room-chat): the room never grows with the chat, the messages scroll inside, newest at the
                bottom, older ones drawn when scrolled to the top (resources/js/roomChat.js). The height leaves room for the
                sticky header, the phone's tab bar and the match dock, so the field stays clear of them.
            --}}
            <section aria-labelledby="chat-h" class="room-chat flex flex-col rounded-lg bg-card" x-data="roomChat(@js($this->chatConfig()))" x-on:casual-room.window="casualUpdate($event.detail.state)" x-on:casual-compose.window="cardKinds.includes($event.detail) && openComposer($event.detail)" x-on:lobby-pin.window="pinAction($event.detail)" x-effect="publishPin()" data-test="room-chat" wire:ignore>
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
                                <span><span x-text="m.name + ', '"></span><span class="whitespace-nowrap" x-text="time(m.at)"></span></span>
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

    {{--
        Details (boards MatchRoom-*: none of this on the step's screen): the lobby rules, what to do when something goes
        wrong, the games of a finished series, who played, the facts, the timeline with its proof, the season line and
        the match on Nostr. Closed by default; wire:ignore.self keeps it open across the room's renders (a poll's morph
        shut an open <details> once, plan "Refactor und Design-Revamp" P5).
    --}}
    <div class="flex min-w-0 flex-col gap-5 lg:col-start-1 lg:row-start-2">
    <details class="group rounded-lg bg-card" data-test="room-details" data-flow="5" wire:ignore.self>
        <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-[15px] font-bold lg:px-6 [&::-webkit-details-marker]:hidden" data-test="room-details-toggle">
            <span>{{ __('Match details') }}</span>
            <x-icon name="chevron-down" :size="16" class="shrink-0 text-ink-2 transition-transform duration-200 ease-out group-open:rotate-180 motion-reduce:transition-none" />
        </summary>
        <div class="flex flex-col gap-5 border-t border-hairline px-4 pt-4 pb-5 lg:px-6">
        @include('pages.matches.partials.lobby-rules')

        @if (! $casual && ! $team && $m->status->isRunning() && ! $noshowNow)
            @include('pages.matches.partials.room-problems')
        @endif

        @if ($view === 'done' && ! $team)
            <div class="flex flex-col rounded-lg shadow-ring">
                @include('pages.matches.partials.room-scoreboard', ['sheet' => $this->sheet])
            </div>
        @endif

        @if (! $team && ! $rosterChoice)
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
                    <p class="m-0 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-xs leading-normal text-ink-2 [overflow-wrap:anywhere]" data-test="opponent-roster">{{ $m->sideName(SeriesMatch::otherSide($mine)) }}:
                        @foreach (array_filter($rosters[SeriesMatch::otherSide($mine)], fn ($r) => $r['on']) as ['seat' => $seat])
                            <span class="inline-flex max-w-full min-w-0 items-center gap-1"><x-player-link :user="$seat->user" class="inline-flex min-h-6 min-w-0 items-center" /><x-copy-npub :npub="$seat->user->npub" :name="$seat->user->displayName()" /></span>
                        @endforeach
                    </p>
                    <p class="m-0 text-xs text-ink-3">{{ __('their captain sets this') }}</p>
                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('This list goes out with your result.') }}</p>
                </section>
        @endif

            {{-- Facts (the score lives in the versus, its submit under the sheet) --}}
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
                    @foreach ([
                        [__('Challenge'), __(':from to :to, :time', ['from' => $m->created_by_id === $viewer->id ? __('you') : $m->challenger_name, 'to' => $m->challenged_name, 'time' => SeriesPresenter::time($m->created_at ?? now(), $viewer, 'D H:i')])],
                        [__('Start'), $m->start_at ? SeriesPresenter::time($m->start_at, $viewer, 'D H:i') : __('one of :n suggested times', ['n' => count($m->proposals)])],
                        [__('Format'), \App\Support\GameNames::game($m->game).', '.$m->mode.', '.($team ? trans_choice(':count board|:count boards', $m->boards) : 'BO'.$m->best_of)],
                        [__('Ladder'), $m->rated ? $m->mode : __('none, casual until Block 0')],
                    ] as [$key, $value])
                        <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[150px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span class="[overflow-wrap:anywhere]">{{ $value }}</span></div>
                    @endforeach
                </div>
                {{-- A casual 1v1 is never rated (CasualMatches): no Elo to show; a team match has no team Elo (NIP rev. 9.22). --}}
                @unless ($casual || $team)
                <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
                        @foreach ([[__('Elo before'), $elo['before'].($elo['casual'] ? ' · '.__('casual') : '')], [__('Expected'), $elo['expected']], [__('At stake'), $elo['stake']]] as [$key, $value])
                            <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[150px_minmax(0,1fr)]" data-test="elo-fact"><span class="text-ink-2">{{ $key }}</span><span class="text-ink-2">{{ $value }}</span></div>
                        @endforeach
                </div>
                @endunless
            </div>

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
                <x-proof :rows="SeriesPresenter::proofRows($m)" />
            </section>
            @endunless

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
        </div>
    </details>
    </div>
    </div>

    {{--
        The phone's action bar (boards MatchRoom-*-Phone): on the floor above the tab bar, never under the dock or the
        tab bar: `data-page-bar` lifts it by the tab bar (app.css), `bottom: --floor-h` by the floor band, together
        --floor-top (P1). The result step keeps its score bar: the summary line over a full-width "Send result"
        (MatchRoom-3-Ergebnis-Phone), hidden while the report block under the sheet is on screen (one "Send result" in
        view, never two). Every other step mirrors its own buttons (`data-room-primary`, `data-room-secondary` in the
        step's view, hidden below lg), plus "Chat" where the board has it (resources/js/matchRoom.js roomBar). Each
        branch is keyed by the step: a poll that changes the step replaces the bar instead of morphing it, so Alpine starts
        a fresh roomBar scope (a morphed result bar never got one: empty bar, "shown is not defined").
    --}}
    @if ($view === 'result' && $canSubmit)
        @php($barLead = $wins['challenged'] > $wins['challenger'] ? ['challenged', 'challenger'] : ['challenger', 'challenged'])
        <div wire:key="room-bar-result" x-show="! reportInView" class="fixed inset-x-0 bottom-[var(--floor-h)] z-20 flex flex-col gap-2 bg-bar px-4 py-3 shadow-[0_-1px_0_#2A2A30] lg:hidden" data-test="room-bar" data-page-bar data-room-bar data-floor-owner>
            <b class="rv-sum line-clamp-2 text-center text-[18px]">{{ $m->sideName($barLead[0]) }} <span class="whitespace-nowrap">{{ $wins[$barLead[0]] }} : {{ $wins[$barLead[1]] }}</span> {{ $m->sideName($barLead[1]) }}</b>
            <button type="button" x-on:click="submit = true" class="rv-b h-[52px] w-full"><x-icon name="shield-check" :size="18" />{{ __('Send result') }}</button>
        </div>
    @elseif ($view !== 'result')
        <div wire:key="room-bar-{{ $view }}" x-data="roomBar" x-show="shown" x-cloak class="fixed inset-x-0 bottom-[var(--floor-h)] z-20 flex gap-3 bg-bar px-4 py-3 shadow-[0_-1px_0_#2A2A30] lg:hidden" data-test="room-bar" data-page-bar data-room-bar data-floor-owner>
            @if ($barChat)
                <button type="button" x-on:click="chat()" x-bind:class="primary || secondary ? 'shrink-0' : 'grow'" class="rv-b2 h-[52px] px-4" data-chat data-test="room-bar-chat"><x-icon name="chat" :size="16" />{{ __('Chat') }}</button>
            @endif
            <button type="button" x-show="secondary" x-on:click="press('secondary')" x-bind:disabled="secondaryDisabled" class="rv-b2 h-[52px] min-w-0 grow basis-0 px-3" data-test="room-bar-secondary"><span class="truncate" x-text="secondary"></span></button>
            <button type="button" x-show="primary" x-on:click="press('primary')" x-bind:disabled="primaryDisabled" x-bind:class="primaryDemoted ? 'rv-b2' : 'rv-b'" class="h-[52px] min-w-0 grow basis-0 px-3" data-test="room-bar-primary"><span class="truncate" x-text="primary"></span></button>
        </div>
    @endif

    {{-- Submit dialog (Overlays.dc.html) --}}
    @if ($editable)
        <div x-show="submit" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-[rgba(10,10,11,.7)] lg:items-center" x-on:keydown.escape.window="submit = false">
            <section role="dialog" aria-modal="true" aria-labelledby="submit-h" class="flex max-h-[90svh] w-full max-w-[560px] flex-col gap-4 overflow-y-auto rounded-t-2xl bg-card px-5 py-6 shadow-ring lg:rounded-lg lg:px-6"
                     x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })" x-on:click.outside="submit = false" data-test="submit-dialog">
                <span class="flex items-center gap-3"><span class="flex size-10 items-center justify-center rounded-md bg-win-tint text-win"><x-icon name="shield-check" :size="20" /></span><h2 id="submit-h" class="m-0 text-lg font-bold">{{ __('Send result') }}</h2></span>
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
                        <x-button icon="shield-check" x-on:click="run('prepareReport', 'report').then(() => { if (! error) submit = false })" ::disabled="busy" data-test="confirm-submit">{{ __('Send result') }}</x-button>
                    </div>
                @else
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="draft-error">{{ $draftError }}</p>
                    <div class="flex justify-end"><x-button variant="quiet" x-on:click="submit = false">{{ __('Back') }}</x-button></div>
                @endif
            </section>
        </div>
    @endif
</div>