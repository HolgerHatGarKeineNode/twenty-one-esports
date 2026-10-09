<?php

use App\Models\HyperMatch;
use App\Models\HyperTable;
use App\Models\HyperTableSeat;
use App\Models\User;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\Hyper\HyperSeason;
use App\Support\Hyper\HyperTeams;
use App\Support\Settings\LeagueSettings;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The Hyperbitcoinization lobby on /hyperbitcoinization (plan "Hyperbitcoinization", P3), over
 * App\Support\Hyper\HyperLobby: a new table (seats 2 to 6, live or correspondence, no round limit or 20),
 * the player's own table (seats, a faction per seat that nobody else at the table has, the table's link to
 * invite a friend, bots for the free seats, getting up), the match it became, and the open tables to join.
 *
 * A full table starts at once; the match opens in a new tab (resources/js/hyperLobby.js: right after the
 * click that filled it, or by push for the other players), and the "Open match" button stays for a tab the
 * browser blocked. Every change re-renders every open lobby (`hyper.lobby`). `focus`: the table whose link
 * was opened (HyperMatchController::index), shown first.
 *
 * Format (P4): everyone for themselves, or clan against clan (`clans`, 4 or 6 seats): the table shows two
 * sides, each with its clan's logo and name (a clan linked to a meetup shows as that meetup, HyperTeams),
 * the seats alternating between them.
 *
 * Rating (P5c, user 2026-10-09): a new table may be a friendly match (`friendly`), never rated; any other table
 * is rated when its match starts without bots in a live season. Every table shows "Rated" or "Unrated".
 */
new class extends Component {
    /** Seconds between asks without a websocket (resources/js/hyperLobby.js). */
    public const FALLBACK_SECONDS = 10;

    /** How long the match a table became stays on top of the lobby. */
    public const STARTED_MINUTES = 30;

    /** The round limits a table offers: none, or 20 for an evening. */
    public const LIMITS = [0, 21];

    #[Locked]
    public ?string $focus = null;

    public int $seats = 4;

    public string $mode = HyperMatch::LIVE;

    public int $limit = 0;

    /** Clan against clan (P4) instead of everyone for themselves. */
    public bool $clans = false;

    /** A friendly match (P5c): never rated, even without bots in a live season. */
    public bool $friendly = false;

    /** Bots take the free seats (after the wait, or on the creator's word); off by default (user 2026-10-09). */
    public bool $bots = false;

    public ?string $error = null;

    public function openTable(HyperLobby $lobby): void
    {
        $this->act(fn (User $me): HyperTable => $lobby->open($me, max(2, min(6, $this->seats)), $this->mode, in_array($this->limit, self::LIMITS, true) ? $this->limit : 0, clans: $this->clans, friendly: $this->friendly, bots: $this->bots));
    }

    /** Clan tables seat 2v2 or 3v3: switching the format moves an odd seat count to the next team size. */
    public function updatedClans(): void
    {
        if ($this->clans && ! in_array($this->seats, HyperTeams::SEATS, true)) {
            $this->seats = $this->seats <= 4 ? 4 : 6;
        }
    }

    public function join(string $table, HyperLobby $lobby): void
    {
        $this->act(fn (User $me): HyperTable => $lobby->join($this->table($table), $me));
    }

    /** `''` = drawn at the start. */
    public function pick(string $faction, HyperLobby $lobby): void
    {
        $this->act(fn (User $me): HyperTable => $lobby->pick($this->ownTable(), $me, $faction === '' ? null : $faction));
    }

    public function fillWithBots(HyperLobby $lobby): void
    {
        $this->act(fn (User $me): HyperTable => $lobby->fillBots($this->ownTable(), $me));
    }

    public function leave(HyperLobby $lobby): void
    {
        $this->act(fn (User $me): HyperTable => $lobby->leave($this->ownTable(), $me));
    }

    /**
     * The open tables, the focused one first, the viewer's own left out (it has its own card).
     *
     * @return Collection<int, HyperTable>
     */
    #[Computed]
    public function tables(): Collection
    {
        $mine = $this->mine?->id;

        return app(HyperLobby::class)->openTables()
            ->reject(fn (HyperTable $table): bool => $table->id === $mine)
            ->sortBy(fn (HyperTable $table): int => $table->ulid === $this->focus ? 0 : 1)
            ->values();
    }

    #[Computed]
    public function mine(): ?HyperTable
    {
        $me = $this->viewer();

        return $me === null ? null : app(HyperLobby::class)->tableOf($me)?->load('takenSeats.user');
    }

    /**
     * The viewer's lobby table that became a match in the last half hour, while the match runs.
     */
    #[Computed]
    public function started(): ?HyperTable
    {
        $me = $this->viewer();

        return $me === null ? null : HyperTable::query()->where('status', HyperTable::STARTED)->whereNull('rematch_of')
            ->where('started_at', '>=', now()->subMinutes(self::STARTED_MINUTES))
            ->whereHas('takenSeats', fn ($seats) => $seats->where('user_id', $me->id))
            ->whereHas('match', fn ($match) => $match->where('status', 'active'))
            ->with('match')->latest('started_at')->first();
    }

    /**
     * Runs a lobby change for the signed-in viewer; a refusal becomes the line under the cards. A table
     * that started opens its match in a new tab (hyperLobby.js listens for `hyper-open`).
     *
     * @param  Closure(User): HyperTable  $change
     */
    private function act(Closure $change): void
    {
        $me = $this->viewer();

        if ($me === null) {
            $this->redirectRoute('login');

            return;
        }

        $this->error = null;

        try {
            $table = $change($me);
        } catch (HyperRuleViolation $refusal) {
            $this->error = $this->message($refusal->reason);

            return;
        }

        unset($this->mine, $this->tables, $this->started);

        if ($table->status === HyperTable::STARTED && $table->hyper_match_id !== null) {
            $this->dispatch('hyper-open', url: route('hyper.match', HyperMatch::query()->findOrFail($table->hyper_match_id)));
        }
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            'table_closed' => __('This table is closed.'),
            'table_full' => __('This table is full.'),
            'faction_taken' => __('Somebody at this table plays that faction.'),
            'already_seated' => __('You sit at this table already.'),
            'seated_elsewhere' => __('You wait at another table already.'),
            'not_creator' => __('Only who opened the table adds bots.'),
            'not_seated' => __('You do not sit at this table.'),
            'no_clan' => __('Clan tables are for clan members. Join or found a clan first.'),
            'not_your_clan' => __('Both sides of this table belong to other clans.'),
            'side_full' => __('Your clan’s side is full.'),
            'bad_table' => __('A clan table has 4 or 6 seats.'),
            default => __('That is not allowed right now.'),
        };
    }

    private function table(string $ulid): HyperTable
    {
        return HyperTable::query()->where('ulid', $ulid)->first() ?? throw new HyperRuleViolation('table_closed', 'No such table.');
    }

    private function ownTable(): HyperTable
    {
        return $this->mine ?? throw new HyperRuleViolation('not_seated', 'No table of yours.');
    }

    private function viewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}; ?>

@php
    $viewer = auth()->user();
    $mine = $this->mine;
    $started = $this->started;
    $tables = $this->tables;
    $labels = ['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => __('ECB'), 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'];
    $portrait = HyperGame::FACTIONS;
    $chip = 'flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-well px-3 text-[13px] font-bold text-ink-2 shadow-ring aria-pressed:bg-btc-chip aria-pressed:text-ink aria-pressed:shadow-[inset_0_0_0_1px_var(--color-btc)] disabled:cursor-not-allowed disabled:opacity-40';
    // The faction picker's chips: narrow padding and small type of their own, never the chip's px-3 / 13 px beside them
    // (both in one class list let the 13 px win; measured at 390 px, P6: "Shitcoiner" cut to 66 of 78 px).
    $factionChip = str_replace(['px-3 ', 'text-[13px] '], '', $chip);
    $tag = 'inline-flex h-6 items-center rounded-tag bg-ground/70 px-2 text-xs whitespace-nowrap text-ink-2 shadow-ring';
    $modeLabel = fn (string $mode): string => $mode === HyperMatch::CORRESPONDENCE ? __('Correspondence · :hours h', ['hours' => (int) config('esports.hyper.correspondence_hours', 24)]) : __('Live · :seconds s', ['seconds' => (int) config('esports.hyper.turn_seconds', 90)]);
    $limitLabel = fn (int $limit): string => $limit === 0 ? __('No round limit') : __(':rounds rounds', ['rounds' => $limit]);
    // Rated once it starts without bots, in a live season, unless it is a friendly match (P5c).
    $seasonLive = HyperSeason::seasonFor() !== null;
    $ratedTag = fn (HyperTable $table): string => ! $table->friendly && $seasonLive
        ? '<span class="'.$tag.' font-bold text-ink" data-test="hyper-lobby-rated" data-rated="1">'.e(__('Rated')).'</span>'
        : '<span class="'.$tag.'" data-test="hyper-lobby-rated" data-rated="0">'.e(__('Unrated')).'</span>';
@endphp
<div class="flex flex-col gap-4" data-test="hyper-lobby"
     x-data="hyperLobby(@js(['userId' => $viewer?->id, 'fallback' => $this::FALLBACK_SECONDS]))"
     x-on:hyper-open.window="open($event.detail.url)">

    @if ($started)
        <section class="flex flex-wrap items-center gap-3 rounded-lg bg-btc-chip p-4 shadow-[inset_0_0_0_1px_var(--color-btc)]" data-test="hyper-lobby-started" wire:key="started-{{ $started->ulid }}">
            <span class="flex min-w-0 grow flex-col">
                <b class="text-sm">{{ __('Your match is on') }}</b>
                <span class="text-xs text-ink-2">{{ $modeLabel($started->mode) }}</span>
            </span>
            <x-button variant="primary" :href="route('hyper.match', $started->match)" target="_blank" icon="expand" class="h-12 px-6 text-[15px]" data-test="hyper-lobby-open-match">{{ __('Open match') }}</x-button>
        </section>
    @endif

    @if ($viewer === null)
        <div class="flex flex-wrap items-center gap-3">
            <x-button variant="primary" :href="route('login')" class="h-12 px-6 text-[15px]" data-test="hyper-login">{{ __('Log in to play') }}</x-button>
        </div>
    @elseif ($mine)
        {{-- The viewer's own table: seats, factions, invite, bots, getting up --}}
        @php
            $own = $mine->seatOf($viewer);
            $creator = (int) $mine->created_by === (int) $viewer->id;
            $taken = $mine->takenSeats->keyBy('seat');
            $others = $mine->takenSeats->reject(fn (HyperTableSeat $seat): bool => $seat->id === $own?->id)->pluck('faction')->filter()->all();
        @endphp
        <section class="flex flex-col gap-4 rounded-lg bg-card p-4 shadow-ring" aria-labelledby="hl-mine-h" data-test="hyper-lobby-mine" data-table="{{ $mine->ulid }}" wire:key="mine-{{ $mine->ulid }}">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="hl-mine-h" class="m-0 mr-1 font-display text-lg font-bold">{{ __('Your table') }}</h2>
                <span class="{{ $tag }}">{{ $modeLabel($mine->mode) }}</span>
                <span class="{{ $tag }}">{{ $limitLabel($mine->round_limit) }}</span>
                {!! $ratedTag($mine) !!}
                @if ($mine->isTeamTable())
                    <span class="{{ $tag }} font-bold text-ink" data-test="hyper-lobby-format">{{ __('Clan vs clan · :size', ['size' => intdiv($mine->seats, 2).'v'.intdiv($mine->seats, 2)]) }}</span>
                @endif
                <span class="{{ $tag }} font-bold text-btc-hi" data-test="hyper-lobby-count">{{ __(':taken/:seats seats', ['taken' => $mine->takenSeats->count(), 'seats' => $mine->seats]) }}</span>
            </div>

            {{-- The table's actions come first: on a phone two stacked clan sides would push them below the fold. --}}
            <div class="flex flex-wrap items-center gap-2">
                @if ($creator && $mine->bots)
                    <x-button variant="primary" wire:click="fillWithBots" icon="play" class="h-12 px-5 text-[15px]" data-test="hyper-lobby-fill">{{ __('Fill with bots and start') }}</x-button>
                @endif
                {{-- A component attribute compiles {{ }}, not directives: the link goes in through Js::from, never @js. --}}
                <span x-data="{ copied: false }" class="inline-flex">
                    <x-button variant="secondary" icon="link" class="h-12" data-test="hyper-lobby-invite" x-on:click="navigator.clipboard?.writeText({{ \Illuminate\Support\Js::from(route('hyper.table', $mine)) }}).then(() => { copied = true; setTimeout(() => copied = false, 1500) })">
                        <span x-text="copied ? @js(__('Link copied')) : @js(__('Copy invite link'))">{{ __('Copy invite link') }}</span>
                    </x-button>
                </span>
                <x-button variant="quiet" wire:click="leave" class="h-12" data-test="hyper-lobby-leave">{{ $creator ? __('Close table') : __('Leave table') }}</x-button>
            </div>
            @if ($mine->isTeamTable())
                {{-- Clan against clan: two sides, the seats alternating between them --}}
                <div class="grid gap-3 md:grid-cols-[1fr_auto_1fr] md:items-stretch" data-test="hyper-lobby-sides">
                    @foreach (HyperTeams::sides($mine->team_clans) as $side)
                        @if ($side['side'] === 1)
                            <span class="self-center justify-self-center font-display text-sm font-bold tracking-[0.2em] text-ink-3 uppercase" aria-hidden="true">{{ __('vs') }}</span>
                        @endif
                        <section class="flex min-w-0 flex-col gap-2 rounded-md bg-well p-3 shadow-ring" data-test="hyper-lobby-side" data-side="{{ $side['side'] }}" aria-label="{{ $side['name'] }}">
                            @include('pages.hyper.partials.side-head', ['side' => $side, 'open' => $side['clan_id'] === null])
                            <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 min-[420px]:grid-cols-3" aria-label="{{ __('Seats') }}">
                                @foreach (range($side['side'], $mine->seats - 1, 2) as $index)
                                    @include('pages.hyper.partials.lobby-seat', ['seat' => $taken->get($index), 'index' => $index])
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
            @else
                <ul class="m-0 grid list-none grid-cols-2 gap-2 p-0 sm:grid-cols-3 lg:grid-cols-6" aria-label="{{ __('Seats') }}">
                    @foreach (range(0, $mine->seats - 1) as $index)
                        @include('pages.hyper.partials.lobby-seat', ['seat' => $taken->get($index), 'index' => $index])
                    @endforeach
                </ul>
            @endif

            <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Your faction') }}</legend>
                <div class="grid grid-cols-3 gap-2 min-[480px]:grid-cols-4 sm:grid-cols-7" role="group">
                    @foreach (array_keys($portrait) as $faction)
                        <button type="button" wire:click="pick('{{ $faction }}')" aria-pressed="{{ $own?->faction === $faction ? 'true' : 'false' }}" @disabled(in_array($faction, $others, true))
                                class="{{ $factionChip }} min-h-[68px] flex-col gap-1 px-1 text-[11px]" data-test="hyper-lobby-faction" data-faction="{{ $faction }}">
                            <img src="/hyper/art/por-{{ $portrait[$faction] }}.jpg?v=1" alt="" width="36" height="36" class="size-9 rounded-full object-cover shadow-ring" loading="lazy">
                            <span class="max-w-full truncate">{{ $labels[$faction] }}</span>
                        </button>
                    @endforeach
                    <button type="button" wire:click="pick('')" aria-pressed="{{ $own?->faction === null ? 'true' : 'false' }}" class="{{ $factionChip }} min-h-[68px] flex-col gap-1 px-1 text-[11px]" data-test="hyper-lobby-faction" data-faction="">
                        <span class="grid size-9 place-items-center rounded-full bg-ground text-lg shadow-ring" aria-hidden="true">🎲</span>
                        <span>{{ __('Random') }}</span>
                    </button>
                </div>
            </fieldset>

            @if ($mine->mode === HyperMatch::LIVE && $mine->fill_at !== null)
                {{-- A countdown that ticks every second (P6): the seconds left as the server sees them, counted on the page's clock. --}}
                @php($fillIn = max(0, $mine->fill_at->getTimestamp() - now()->getTimestamp()))
                <p class="m-0 text-[13px] font-bold text-ink-2 tabular-nums" data-test="hyper-lobby-autofill" data-seconds="{{ $fillIn }}" wire:key="autofill-{{ $mine->fill_at->getTimestamp() }}"
                   x-data="{ end: Date.now() + {{ $fillIn }} * 1000, left: {{ $fillIn }}, timer: null, clock(s) { return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); } }"
                   x-init="timer = setInterval(() => { left = Math.max(0, Math.ceil((end - Date.now()) / 1000)); if (left === 0) clearInterval(timer); }, 1000)"
                   x-on:remove="clearInterval(timer)"
                   x-text="left > 0 ? @js(__('Bots fill the free seats in :time.')).replace(':time', clock(left)) : @js(__('Bots take the free seats now …'))">{{ $fillIn > 0 ? __('Bots fill the free seats in :time.', ['time' => intdiv($fillIn, 60).':'.str_pad((string) ($fillIn % 60), 2, '0', STR_PAD_LEFT)]) : __('Bots take the free seats now …') }}</p>
            @endif
        </section>
    @else
        {{-- A new table --}}
        <form wire:submit="openTable" class="flex flex-col gap-4" data-test="hyper-lobby-new">
            <div class="flex flex-wrap gap-x-6 gap-y-4">
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Format') }}</legend>
                    <div class="flex gap-1.5" role="group">
                        <button type="button" wire:click="$set('clans', false)" aria-pressed="{{ $clans ? 'false' : 'true' }}" class="{{ $chip }}" data-test="hyper-lobby-format-option" data-format="ffa">{{ __('Everyone for themselves') }}</button>
                        <button type="button" wire:click="$set('clans', true)" aria-pressed="{{ $clans ? 'true' : 'false' }}" class="{{ $chip }}" data-test="hyper-lobby-format-option" data-format="clans">{{ __('Clan vs clan') }}</button>
                    </div>
                </fieldset>
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Seats') }}</legend>
                    <div class="flex gap-1.5" role="group">
                        @foreach ($clans ? HyperTeams::SEATS : range(2, 6) as $count)
                            <button type="button" wire:click="$set('seats', {{ $count }})" aria-pressed="{{ $seats === $count ? 'true' : 'false' }}" class="{{ $chip }} min-w-11" data-test="hyper-lobby-seats" data-seats="{{ $count }}">{{ $clans ? intdiv($count, 2).'v'.intdiv($count, 2) : $count }}</button>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Mode') }}</legend>
                    <div class="flex gap-1.5" role="group">
                        <button type="button" wire:click="$set('mode', 'live')" aria-pressed="{{ $mode === 'live' ? 'true' : 'false' }}" class="{{ $chip }}" data-test="hyper-lobby-mode" data-mode="live">{{ __('Live') }}</button>
                        <button type="button" wire:click="$set('mode', 'correspondence')" aria-pressed="{{ $mode === 'correspondence' ? 'true' : 'false' }}" class="{{ $chip }}" data-test="hyper-lobby-mode" data-mode="correspondence">{{ __('Correspondence') }}</button>
                    </div>
                </fieldset>
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Round limit') }}</legend>
                    <div class="flex gap-1.5" role="group">
                        @foreach ($this::LIMITS as $option)
                            <button type="button" wire:click="$set('limit', {{ $option }})" aria-pressed="{{ $limit === $option ? 'true' : 'false' }}" class="{{ $chip }} min-w-11" data-test="hyper-lobby-limit" data-limit="{{ $option }}">{{ $option === 0 ? '∞' : $option }}</button>
                        @endforeach
                    </div>
                </fieldset>
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Season rating') }}</legend>
                    <div class="flex gap-1.5" role="group">
                        <button type="button" wire:click="$toggle('friendly')" aria-pressed="{{ $friendly ? 'true' : 'false' }}" class="{{ $chip }}" data-test="hyper-lobby-friendly">
                            <span @class(['grid size-4 place-items-center rounded-xs text-[11px] shadow-ring', 'bg-btc text-on-btc' => $friendly, 'bg-ground' => ! $friendly]) aria-hidden="true">{{ $friendly ? '✓' : '' }}</span>{{ __('Friendly match (unrated)') }}
                        </button>
                    </div>
                </fieldset>
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Bots') }}</legend>
                    <div class="flex gap-1.5" role="group">
                        <button type="button" wire:click="$toggle('bots')" aria-pressed="{{ $bots ? 'true' : 'false' }}" class="{{ $chip }}" data-test="hyper-lobby-bots">
                            <span @class(['grid size-4 place-items-center rounded-xs text-[11px] shadow-ring', 'bg-btc text-on-btc' => $bots, 'bg-ground' => ! $bots]) aria-hidden="true">{{ $bots ? '✓' : '' }}</span>{{ __('Fill free seats with bots') }}
                        </button>
                    </div>
                </fieldset>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-p inline-flex min-h-12 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc" data-test="hyper-lobby-open">
                    <x-icon name="flag" :size="18" />{{ __('Open a table') }}
                </button>
                <span class="text-xs text-ink-2" data-test="hyper-lobby-bots-note">{{ match (true) {
                    ! $bots => __('The table starts once every seat is taken.'),
                    $mode === HyperMatch::LIVE => __('Bots take free seats after :minutes min.', ['minutes' => max(1, (int) round((int) LeagueSettings::get('esports.hyper.lobby_fill_seconds') / 60))]),
                    default => __('You fill free seats with bots when you like.'),
                } }}{{ $mode === HyperMatch::LIVE ? '' : ' '.__('One turn a day.') }}</span>
                <span class="basis-full text-xs text-ink-2" data-test="hyper-lobby-rating-note">{{ $friendly ? __('A friendly match is never rated.') : ($seasonLive ? __('Rated in the season when no bot plays.') : __('No season is live: matches are unrated.')) }}</span>
                @if ($clans)
                    <span class="basis-full text-xs text-ink-2">{{ __('Your clan takes one side; the first player of another clan takes the other. A clan linked to a meetup plays as that meetup.') }}</span>
                @endif
            </div>
        </form>
    @endif

    @if ($error)
        <p class="m-0 text-[13px] text-loss" role="alert" data-test="hyper-lobby-error">{{ $error }}</p>
    @endif

    {{-- The open tables --}}
    <section class="flex flex-col gap-3" aria-labelledby="hl-open-h" data-test="hyper-lobby-tables">
        <h2 id="hl-open-h" class="m-0 font-display text-xl font-bold">{{ __('Open tables') }}</h2>
        @if ($tables->isEmpty())
            <p class="m-0 rounded-lg bg-card px-4 py-4 text-[13px] text-ink-2 shadow-ring" data-test="hyper-lobby-empty">{{ __('No open table. Open one above.') }}</p>
        @else
            <ul class="m-0 grid list-none gap-3 p-0 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($tables as $table)
                    @php($bySeat = $table->takenSeats->keyBy('seat'))
                    <li wire:key="table-{{ $table->ulid }}" @class(['flex min-w-0 flex-col gap-3 rounded-lg bg-card p-3 shadow-ring', 'shadow-[inset_0_0_0_1px_var(--color-btc)]' => $table->ulid === $focus]) data-test="hyper-lobby-table" data-table="{{ $table->ulid }}">
                        @if ($table->isTeamTable())
                            @php($sides = HyperTeams::sides($table->team_clans))
                            <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2" data-test="hyper-lobby-table-sides">
                                @include('pages.hyper.partials.side-head', ['side' => $sides[0], 'small' => true])
                                <span class="text-[11px] font-bold tracking-[0.2em] text-ink-3 uppercase" aria-hidden="true">{{ __('vs') }}</span>
                                @include('pages.hyper.partials.side-head', ['side' => $sides[1], 'small' => true, 'open' => $table->team_clans[1] === null])
                            </div>
                        @endif
                        <div class="flex flex-wrap items-center gap-1.5">
                            <b class="mr-1 min-w-0 truncate text-sm">{{ __(':name’s table', ['name' => $table->creator?->displayName() ?? __('A player')]) }}</b>
                            <span class="{{ $tag }}">{{ $modeLabel($table->mode) }}</span>
                            <span class="{{ $tag }}">{{ $table->round_limit === 0 ? '∞' : __(':rounds rounds', ['rounds' => $table->round_limit]) }}</span>
                            {!! $ratedTag($table) !!}
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="flex min-w-0 grow -space-x-1.5" aria-label="{{ __(':taken/:seats seats', ['taken' => $table->takenSeats->count(), 'seats' => $table->seats]) }}">
                                @foreach (range(0, $table->seats - 1) as $index)
                                    @php($seat = $bySeat->get($index))
                                    @if ($seat?->faction)
                                        <img src="/hyper/art/por-{{ $portrait[$seat->faction] }}.jpg?v=1" alt="{{ $labels[$seat->faction] }}" title="{{ $seat->user?->displayName() }}" width="32" height="32" class="size-8 shrink-0 rounded-full object-cover shadow-[0_0_0_2px_var(--color-card)]" loading="lazy">
                                    @elseif ($seat?->user)
                                        <x-avatar :user="$seat->user" :size="32" class="shrink-0 shadow-[0_0_0_2px_var(--color-card)]" />
                                    @else
                                        <span class="size-8 shrink-0 rounded-full border border-dashed border-line bg-ground" aria-hidden="true"></span>
                                    @endif
                                @endforeach
                            </span>
                            <span class="shrink-0 text-xs font-bold text-ink-2">{{ $table->takenSeats->count() }}/{{ $table->seats }}</span>
                            @if ($viewer !== null && $mine === null)
                                <x-button variant="primary" wire:click="join('{{ $table->ulid }}')" class="shrink-0" data-test="hyper-lobby-join">{{ __('Join') }}</x-button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
