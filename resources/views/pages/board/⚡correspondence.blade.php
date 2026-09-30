<?php

use App\Enums\BoardGameStatus;
use App\Games\GameRegistry;
use App\Models\BoardChallenge;
use App\Models\BoardGame;
use App\Models\Rating;
use App\Models\User;
use App\Support\Board\BoardChallenges;
use App\Support\Board\BoardRuleViolation;
use App\Support\Board\RatedBoard;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\PageMeta;
use App\Support\Rating\Ratings;
use App\Support\SeasonChain\RatedTrustGate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Correspondence games of one board game (plan "Mühle und Dame", P8): nine
 * men's morris or checkers, one move a day, as daily chess. One page for
 * everything around them, next to the board game's lobby: the challenges
 * received (answer), the player's correspondence games of this board game
 * (theirs to move first, soonest deadline first), the challenges sent
 * (withdraw), and the form to challenge a player.
 *
 * Casual by default. While the rated queue of the board games is offered
 * (RatedBoard::offered()) the form offers "Rated", disabled with the reason
 * while rated play is closed for this player; with an opponent picked who
 * does not list each other with them, the P57 notice says so and puts the
 * fix ("Add as opponent") next to it (BoardChallenges::ratedRefusal()).
 *
 * `?to=` picks the opponent: a user id (the list's pick) or an npub (the
 * challenge of "Your follows here" in the lobby, plan
 * brettspiel-chat-und-follows, P2). It only fills in the form, nothing is
 * sent before "Challenge <name>". An npub that is none, the player's own,
 * one without an account, or a player with an open challenge between the
 * two is said in the form (toProblem); a guest's "Log in" comes back here.
 *
 * The route exists only while `esports.board_games.enabled` is on
 * (routes/board.php); a board game whose own switch is off is a 404.
 */
new #[Layout('layouts::app')] class extends Component {
    #[Locked]
    public string $slug;

    #[Url(as: 'q')]
    public string $search = '';

    /** The chosen opponent: a user id, or an npub from a link (`?to=npub1…`). */
    #[Url(as: 'to')]
    public string $to = '';

    public string $color = 'random';

    public bool $rated = false;

    public string $message = '';

    public string $error = '';

    public string $status = '';

    public function mount(string $board): void
    {
        // A board game without the correspondence mode (a test fixture) has no such page.
        abort_unless(app(GameRegistry::class)->isBoard($board) && app(GameRegistry::class)->mode($board, BoardGame::CORRESPONDENCE) !== null, 404);

        $this->slug = $board;

        // A guest who came with an opponent picked lands here again after the login (NostrLoginController).
        if (auth()->guest() && $this->to !== '') {
            session()->put('url.intended', request()->fullUrl());
        }
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $name = GameNames::game($this->slug);
        $view->title(__(':game correspondence', ['game' => $name]));
        app(PageMeta::class)->describe($name, __('Play :game by correspondence against Bitcoiners: one move a day, a reminder before your deadline. Challenge a player; the server checks every move.', ['game' => $name]));
    }

    public function pick(int $id): void
    {
        $this->to = (string) $id;
        $this->error = '';
        unset($this->named, $this->opponent, $this->openWithOpponent, $this->toProblem, $this->players, $this->ratedRefusal);
    }

    public function send(): void
    {
        $this->validate([
            'color' => ['required', 'in:'.implode(',', BoardChallenges::COLORS)],
            'message' => ['nullable', 'string', 'max:140'],
        ]);

        $opponent = $this->opponent;

        if ($opponent === null || $this->openWithOpponent) {
            $this->error = $opponent === null ? __('Pick an opponent first.') : __('There is already an open challenge between the two of you.');

            return;
        }

        $this->attempt(function (User $user) use ($opponent): void {
            $challenge = app(BoardChallenges::class)->challenge($user, $opponent, $this->slug, $this->color, $this->rated, $this->message);
            $this->status = __('Challenge sent to :name. They have :hours h to accept.', ['name' => $challenge->challenged->displayName(), 'hours' => (int) config('esports.board_games.correspondence.challenge_hours')]);
            $this->reset('to', 'message', 'rated');
            unset($this->named, $this->opponent, $this->openWithOpponent, $this->toProblem, $this->players);
        });
    }

    public function accept(int $id): void
    {
        $this->attempt(fn (User $user) => $this->redirectRoute('board.show', ['boardGame' => app(BoardChallenges::class)->accept(BoardChallenge::query()->findOrFail($id), $user)]));
    }

    public function decline(int $id): void
    {
        $this->attempt(fn (User $user) => app(BoardChallenges::class)->close(BoardChallenge::query()->findOrFail($id), $user));
    }

    /** P57: an add from the "you do not list each other" notice; the refusal it answered may be gone. */
    #[On('opponent-list-changed')]
    public function opponentListChanged(): void
    {
        unset($this->ratedRefusal);
    }

    /** Whether the page offers rated challenges at all (the rated queue switch, P6). */
    #[Computed]
    public function ratedOffered(): bool
    {
        return RatedBoard::offered();
    }

    /**
     * Why this player cannot send the picked opponent a rated challenge now,
     * or null (BoardChallenges::ratedRefusal()).
     *
     * @return array{reason: string, message: string}|null
     */
    #[Computed]
    public function ratedRefusal(): ?array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return ['reason' => 'rated_unavailable', 'message' => __('Log in to play rated games.')];
        }

        $own = app(RatedBoard::class)->refusal($user, $this->slug, BoardGame::CORRESPONDENCE);

        if ($own !== null || $this->opponent === null) {
            return $own === null ? null : ['reason' => 'rated_unavailable', 'message' => $own];
        }

        return app(BoardChallenges::class)->ratedRefusal($user, $this->opponent, $this->slug);
    }

    /**
     * The players to pick from; with no search the picked one first, so a
     * player picked by a link shows as picked.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function players(): Collection
    {
        $term = trim($this->search);
        $first = $term === '' ? $this->opponent : null;

        $found = User::query()
            ->when(auth()->id() !== null, fn ($query) => $query->whereKeyNot(auth()->id()))
            ->when($first !== null, fn ($query) => $query->whereKeyNot($first->id))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('npub', 'like', $term.'%')))
            ->latest('updated_at')
            ->limit($first !== null ? 7 : 8)
            ->get();

        return $first !== null ? $found->prepend($first)->values() : $found;
    }

    /** The account `to` names (a user id or an npub), never the player's own. */
    #[Computed]
    public function opponent(): ?User
    {
        $named = $this->named;

        return $named !== null && $named->id !== auth()->id() ? $named : null;
    }

    /** Whoever `to` names, the player included: by user id or by npub, or null. */
    #[Computed]
    public function named(): ?User
    {
        $to = trim($this->to);

        if (ctype_digit($to)) {
            return User::query()->find((int) $to);
        }

        $hex = $to === '' ? null : NostrKeys::toHex($to);

        return $hex === null ? null : User::query()->where('pubkey', $hex)->first();
    }

    /**
     * Why the opponent `to` names cannot be challenged from this form, or
     * null: said in the form, before anything is sent.
     */
    #[Computed]
    public function toProblem(): ?string
    {
        $to = trim($this->to);

        return match (true) {
            $to === '' || auth()->guest() => null,
            ! ctype_digit($to) && NostrKeys::toHex($to) === null => __('That is not an npub. Pick your opponent from the list.'),
            $this->named === null => ctype_digit($to) ? __('No player found.') : __('Nobody in the league has that key. Pick your opponent from the list.'),
            $this->named?->id === auth()->id() => __('You cannot challenge yourself.'),
            $this->openWithOpponent => __('There is already an open challenge between the two of you.'),
            default => null,
        };
    }

    /** Whether a challenge between this player and the picked opponent is open already (the rule BoardChallenges enforces). */
    #[Computed]
    public function openWithOpponent(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $this->opponent !== null && app(BoardChallenges::class)->openBetween($user, $this->opponent, $this->slug);
    }

    /**
     * @return Collection<int, BoardChallenge>
     */
    #[Computed]
    public function incoming(): Collection
    {
        $user = auth()->user();

        return $user instanceof User ? app(BoardChallenges::class)->incoming($user, $this->slug) : collect();
    }

    /**
     * @return Collection<int, BoardChallenge>
     */
    #[Computed]
    public function outgoing(): Collection
    {
        $user = auth()->user();

        return $user instanceof User ? app(BoardChallenges::class)->outgoing($user, $this->slug) : collect();
    }

    /**
     * The player's running correspondence games of this board game: those
     * waiting for their move first, then by deadline.
     *
     * @return Collection<int, BoardGame>
     */
    #[Computed]
    public function games(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        return BoardGame::query()->correspondence()->where('game', $this->slug)->where('status', BoardGameStatus::Active)->playedBy($user)
            ->with(['white', 'black'])->orderBy('deadline_ms')->get()
            ->sortBy(fn (BoardGame $game): int => $game->turn === $game->colorOf($user) ? 0 : 1)->values();
    }

    /**
     * @param  Closure(User): mixed  $action
     */
    private function attempt(Closure $action): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
        }

        $this->error = '';
        $this->status = '';

        try {
            $action($user);
        } catch (BoardRuleViolation $violation) {
            $this->error = match ($violation->reason) {
                'challenge_self' => __('You cannot challenge yourself.'),
                'challenge_open' => __('There is already an open challenge between the two of you.'),
                'challenge_closed' => __('That challenge is no longer open.'),
                'challenge_limit' => __('You sent as many challenges as a day allows. Try again in :time.', [
                    'time' => $this->opponent === null ? '' : now()->addSeconds(BoardChallenges::availableIn($user, $this->opponent))->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE),
                ]),
                'rated_unavailable' => str_contains($violation->getMessage(), ' ') ? $violation->getMessage() : __('Rated play is not open for this game right now.'),
                'rated_pair' => RatedTrustGate::message($violation->getMessage()),
                default => __('That did not work, please try again.'),
            };
        }

        unset($this->incoming, $this->outgoing, $this->games, $this->ratedRefusal, $this->openWithOpponent, $this->toProblem);
    }
}; ?>

@php
    $me = auth()->user();
    $name = GameNames::game($slug);
    $opponent = $this->opponent;
    $hours = (int) config('esports.board_games.correspondence.challenge_hours');
    $colorLabel = ['random' => __('Random'), 'white' => __('White'), 'black' => __('Black')];
    $nowMs = (int) now()->getTimestampMs();
    $rating = $me ? Ratings::forUser($me->id, $slug, BoardGame::CORRESPONDENCE, Rating::CASUAL) : null;
    $ratedRefusal = $this->ratedOffered ? $this->ratedRefusal : null;
    $ownRefusal = $ratedRefusal !== null && $ratedRefusal['reason'] === 'rated_unavailable';
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:mx-auto lg:w-full lg:max-w-[1232px] lg:px-4 lg:pb-10" data-test="board-correspondence">
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
            <h1 class="m-0 font-display text-[28px] font-bold lg:text-[32px]">{{ __(':game correspondence', ['game' => $name]) }}</h1>
            <a href="{{ route('board.lobby', $slug) }}" class="text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="correspondence-lobby-link">{{ __('Lobby') }}</a>
        </div>
        <p class="m-0 max-w-[70ch] text-[13px] leading-normal text-ink-2">{{ __('One move a day: each move has 24 hours, a reminder comes before your deadline, and a missed deadline loses the game. The server checks every move.') }}</p>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
            @if ($rating)
                <x-rating :rating="$rating" class="text-ink-2" data-test="correspondence-rating" />
            @endif
            <a href="{{ route('rules') }}#{{ $slug }}" class="text-ink underline decoration-edge underline-offset-4 hover:decoration-btc">{{ __('Rules of :game', ['game' => $name]) }}</a>
            <a href="{{ route('ladder.show', [$slug, 'correspondence']) }}" class="text-ink underline decoration-edge underline-offset-4 hover:decoration-btc" data-test="correspondence-ladder">{{ __('Ladder') }}</a>
        </div>
    </div>

    @if ($error)
        <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="correspondence-error">{{ $error }}</p>
    @endif
    @if ($status)
        <p role="status" class="m-0 rounded-lg bg-card px-4 py-3 text-[13px] text-win shadow-ring" data-test="correspondence-status">{{ $status }}</p>
    @endif

    @guest
        <section class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
            <p class="m-0 text-[13px] text-ink-2">{{ __('Log in to challenge a player and play your correspondence games.') }}</p>
            <div><x-button :href="route('login')" data-test="correspondence-login">{{ __('Log in to play') }}</x-button></div>
        </section>
    @else
        {{-- Challenges received: answer them first. --}}
        @foreach ($this->incoming as $challenge)
            <div wire:key="in-{{ $challenge->id }}" class="flex flex-col gap-3 rounded-lg bg-card p-3 shadow-ring-btc lg:flex-row lg:items-center lg:px-4" data-test="incoming-challenge">
                <span class="flex min-w-0 grow items-center gap-3">
                    <x-player-link :user="$challenge->challenger" class="shrink-0"><x-avatar :user="$challenge->challenger" :size="40" class="rounded-md" /></x-player-link>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <b class="truncate text-[15px]">{{ __(':name challenges you', ['name' => $challenge->challenger->displayName()]) }}</b>
                        <span class="text-xs text-ink-2">{{ __(':kind · you play :color · 1 move a day', ['kind' => $challenge->rated ? __('Rated') : __('Casual'), 'color' => match ($challenge->color) { 'white' => __('Black'), 'black' => __('White'), default => __('a random colour') }]) }}</span>
                        @if ($challenge->message)<span class="text-xs text-ink [overflow-wrap:anywhere]">“{{ $challenge->message }}”</span>@endif
                    </span>
                </span>
                <span class="grid grid-cols-2 gap-2 lg:flex">
                    <x-button variant="quiet" wire:click="decline({{ $challenge->id }})" data-test="decline-challenge">{{ __('Decline') }}</x-button>
                    <x-button icon="shield-check" wire:click="accept({{ $challenge->id }})" data-test="accept-challenge">{{ __('Accept') }}</x-button>
                </span>
            </div>
        @endforeach

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
            {{-- Your games, then the challenges you sent. --}}
            <div class="flex min-w-0 flex-col gap-5">
                <section aria-labelledby="corr-games-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="correspondence-games">
                    <h2 id="corr-games-h" class="m-0 text-[15px] font-bold">{{ __('Your correspondence games') }}</h2>
                    @forelse ($this->games as $game)
                        @php
                            $mine = $game->turn === $game->colorOf($me);
                            $left = max(0, (int) $game->deadline_ms - $nowMs);
                        @endphp
                        <a wire:key="g-{{ $game->id }}" href="{{ route('board.show', $game) }}" data-test="correspondence-game" data-mine="{{ $mine ? 'true' : 'false' }}"
                           @class(['flex min-h-12 min-w-0 items-center justify-between gap-3 rounded-md px-3 text-[13px] text-ink hover:text-ink', 'bg-btc-press' => $mine, 'bg-well' => ! $mine])>
                            <span class="flex min-w-0 items-center gap-2">
                                <x-avatar :user="$game->opponentOf($me)" :size="24" class="rounded-sm" />
                                <span class="truncate">{{ $game->opponentOf($me)?->displayName() ?? __('Deleted player') }}</span>
                                @if ($game->rated)<span class="shrink-0 rounded-sm bg-raised px-1.5 py-0.5 text-[11px] font-bold">{{ __('Rated') }}</span>@endif
                            </span>
                            <span @class(['shrink-0 tabular-nums', 'font-bold text-btc-hi' => $mine, 'text-ink-2' => ! $mine])>
                                {{ $mine ? __('Your move · :h h :m min left', ['h' => intdiv($left, 3_600_000), 'm' => intdiv($left % 3_600_000, 60_000)]) : __('Their move · move :n', ['n' => intdiv($game->ply, 2) + 1]) }}
                            </span>
                        </a>
                    @empty
                        <p class="m-0 text-[13px] text-ink-2">{{ __('No correspondence game yet. Challenge a player to start one.') }}</p>
                    @endforelse
                </section>

                @if ($this->outgoing->isNotEmpty())
                    <section aria-labelledby="corr-out-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="outgoing-challenges">
                        <h2 id="corr-out-h" class="m-0 text-[15px] font-bold">{{ __('Challenges you sent') }}</h2>
                        @foreach ($this->outgoing as $challenge)
                            <div wire:key="out-{{ $challenge->id }}" class="flex min-w-0 items-center justify-between gap-3 text-[13px]" data-test="outgoing-challenge">
                                <span class="min-w-0 truncate">{{ __(':name · :kind · open until :time', ['name' => $challenge->challenged->displayName(), 'kind' => $challenge->rated ? __('Rated') : __('Casual'), 'time' => $challenge->expires_at->timezone($me->timezone ?? config('app.timezone'))->isoFormat('ddd HH:mm')]) }}</span>
                                <x-button variant="secondary" wire:click="decline({{ $challenge->id }})" data-test="withdraw-challenge">{{ __('Withdraw') }}</x-button>
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>

            {{-- Challenge a player. --}}
            <section aria-labelledby="corr-new-h" class="flex flex-col gap-4 self-start rounded-lg bg-card px-4 py-5 lg:px-6" data-test="correspondence-challenge">
                <h2 id="corr-new-h" class="m-0 text-[15px] font-bold">{{ __('Challenge a player') }}</h2>

                <div class="flex flex-col gap-2">
                    <label for="corr-search" class="text-xs text-ink-2">{{ __('Opponent') }}</label>
                    <input id="corr-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search players') }}" autocomplete="off" data-test="player-search"
                           class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-sm text-ink placeholder:text-ink-3">
                    <div role="radiogroup" aria-label="{{ __('Opponent') }}" class="flex max-h-[280px] flex-col overflow-y-auto">
                        @forelse ($this->players as $player)
                            <button type="button" role="radio" wire:key="p-{{ $player->id }}" wire:click="pick({{ $player->id }})" aria-checked="{{ $opponent?->is($player) ? 'true' : 'false' }}" data-test="pick-player"
                                    @class(['flex min-h-11 min-w-0 cursor-pointer items-center gap-2 rounded-md border bg-transparent px-3 text-left text-[13px] text-ink',
                                        'border-btc bg-btc-press' => $opponent?->is($player), 'border-transparent hover:bg-row-hover' => ! $opponent?->is($player)])>
                                <x-avatar :user="$player" :size="20" class="rounded-sm" />
                                <span class="truncate">{{ $player->displayName() }}</span>
                            </button>
                        @empty
                            <p class="m-0 px-3 py-3 text-[13px] text-ink-2">{{ __('No player found.') }}</p>
                        @endforelse
                    </div>
                    @if ($this->toProblem)
                        <p role="alert" class="m-0 text-[13px] leading-normal text-loss" data-test="challenge-to-problem">{{ $this->toProblem }}</p>
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <span id="corr-color-h" class="text-xs text-ink-2">{{ __('Your color') }}</span>
                    <div role="radiogroup" aria-labelledby="corr-color-h" class="grid grid-cols-3 gap-2">
                        @foreach ($colorLabel as $value => $label)
                            <button type="button" role="radio" wire:click="$set('color', '{{ $value }}')" aria-checked="{{ $color === $value ? 'true' : 'false' }}" data-test="color-{{ $value }}"
                                    @class(['h-11 cursor-pointer rounded-md border bg-ground text-[13px] text-ink', 'border-btc' => $color === $value, 'border-line' => $color !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Rated (P6/P8): only while the rated queue is offered; disabled with the reason while it is closed for this player. --}}
                @if ($this->ratedOffered)
                    <div class="flex flex-col gap-2" data-test="correspondence-rated" data-rated-open="{{ $ownRefusal ? 'false' : 'true' }}">
                        <span id="corr-type-h" class="text-xs text-ink-2">{{ __('Game type') }}</span>
                        <div role="radiogroup" aria-labelledby="corr-type-h" class="grid grid-cols-2 gap-2">
                            <button type="button" role="radio" wire:click="$set('rated', false)" aria-checked="{{ $rated ? 'false' : 'true' }}" data-test="type-casual"
                                    @class(['h-11 cursor-pointer rounded-md border bg-ground text-[13px] text-ink', 'border-btc' => ! $rated, 'border-line' => $rated])>{{ __('Casual') }}</button>
                            <button type="button" role="radio" wire:click="$set('rated', true)" aria-checked="{{ $rated ? 'true' : 'false' }}" @disabled($ownRefusal) data-test="type-rated"
                                    @class(['h-11 cursor-pointer rounded-md border bg-ground text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-50', 'border-btc' => $rated, 'border-line' => ! $rated])>{{ __('Rated') }}</button>
                        </div>
                        <span class="text-xs leading-normal text-ink-2" data-test="rated-why">{{ $ownRefusal ? $ratedRefusal['message'] : __('Rated needs a Trusted player you list each other with. A win can mine a season block.') }}</span>
                    </div>
                    {{-- P57: the picked opponent and this player do not list each other; say so, and put the fix next to it. --}}
                    @if ($rated && $opponent && $ratedRefusal !== null && $ratedRefusal['message'] === RatedTrustGate::NOT_CONNECTED)
                        <x-opponents.needs-mutual :players="[$opponent]" :heading="__('You and :name do not list each other as opponents yet, so this challenge cannot be rated.', ['name' => $opponent->displayName()])">
                            <x-button variant="quiet" wire:click="$set('rated', false)" data-test="needs-mutual-casual">{{ __('Challenge casual instead') }}</x-button>
                        </x-opponents.needs-mutual>
                    @endif
                @endif

                <div class="flex flex-col gap-2">
                    <span class="flex flex-wrap items-baseline justify-between gap-2"><label for="corr-message" class="text-xs text-ink-2">{{ __('Message') }}</label><span class="text-xs text-ink-3">{{ __('optional, up to 140 characters') }}</span></span>
                    <textarea id="corr-message" wire:model="message" maxlength="140" rows="2" data-test="challenge-message"
                              class="w-full resize-none rounded-lg border border-edge bg-ground px-3.5 py-3 text-sm text-ink placeholder:text-ink-3"></textarea>
                    @error('message')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
                </div>

                {{-- A name without a break wraps inside the button (a 41-character one ran 39 px past a 320 px phone), the verb stays visible in both languages. --}}
                <button type="button" wire:click="send" @disabled(! $opponent || $this->openWithOpponent) data-test="send-challenge"
                        class="btn-p inline-flex min-h-[52px] cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-5 py-2 text-[15px] font-bold text-on-btc disabled:cursor-not-allowed disabled:opacity-50">
                    <x-icon name="shield-check" :size="18" class="shrink-0" /><span class="min-w-0 text-center wrap-anywhere">{{ $opponent ? __('Challenge :name', ['name' => $opponent->displayName()]) : __('Send challenge') }}</span>
                </button>
                <span class="text-xs leading-normal text-ink-2">{{ __('The other player has :hours h to accept. You can withdraw the challenge while it is open.', ['hours' => $hours]) }}</span>
                {{-- As chess's challenge page offers blitz with a friend: the lobby's "Online now", where a player who looks for a game can be invited. --}}
                <a href="{{ route('board.lobby', $slug) }}#online-now" class="inline-flex min-h-11 items-center gap-1.5 self-start text-[13px] text-ink underline decoration-edge underline-offset-4 hover:decoration-btc" data-test="correspondence-blitz">
                    <x-icon name="bolt" :size="14" class="shrink-0" />{{ __('Blitz 5+3 now: invite a player who is looking to play') }}
                </a>
            </section>
        </div>
    @endguest
</div>
