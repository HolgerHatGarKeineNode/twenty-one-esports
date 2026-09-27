<?php

use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentSignups;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * Sign-up (TournamentSignup.dc.html, P8b): a captain enters a lineup with
 * the players it fields (the team and up to two substitutes), everyone else
 * enters solo (in a team mode into the solo pool the draw turns into mix
 * teams), and an entry can be pulled out until sign-up closes. Every step
 * is signed (App\Support\Tournaments\TournamentSignups).
 *
 * The artboard's membership, prize share and preferred role rows belong to
 * the prize pool (P9) and are not built here.
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    public Tournament $tournament;

    public ?int $lineupId = null;

    /** @var list<int> */
    public array $members = [];

    public string $error = '';

    /** True right after this visit's own sign-up went through: the confirmation plays its burst once. */
    public bool $justEntered = false;

    public function mount(Tournament $tournament): void
    {
        abort_unless($tournament->isVisibleTo(auth()->user()), 404);

        $this->tournament = $tournament;
        $first = $this->lineups->first();

        if ($first !== null) {
            $this->pickLineup($first->id);
        }
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Register: :name', ['name' => $this->tournament->name]));
    }

    /**
     * The lineups of this game and mode the viewer captains.
     *
     * @return \Illuminate\Support\Collection<int, Lineup>
     */
    #[Computed]
    public function lineups(): \Illuminate\Support\Collection
    {
        $user = $this->user();

        if (! $this->tournament->profile()->entersTeams() || $user->clanMember === null) {
            return collect();
        }

        return Lineup::query()->with(['clan', 'seats.user.clanMember'])
            ->where('clan_id', $user->clanMember->clan_id)->where('game', $this->tournament->game)->where('mode', $this->tournament->mode)
            ->get()->filter(fn (Lineup $lineup): bool => $lineup->isActingCaptain($user))->values();
    }

    #[Computed]
    public function entry(): ?TournamentSignup
    {
        return app(TournamentSignups::class)->entryOf($this->tournament, $this->user());
    }

    public function pickLineup(int $lineupId): void
    {
        $lineup = $this->lineups->firstWhere('id', $lineupId);

        if ($lineup === null) {
            return;
        }

        $this->lineupId = $lineup->id;
        $this->members = array_slice(array_values(array_map(fn (LineupSeat $seat): int => $seat->user_id, $lineup->activeSeats())), 0, $this->tournament->maxLineupSize());
    }

    public function toggleMember(int $userId): void
    {
        $this->members = in_array($userId, $this->members, true)
            ? array_values(array_diff($this->members, [$userId]))
            : [...$this->members, $userId];
    }

    /** @return list<array<string, mixed>>|null */
    public function prepareSolo(): ?array
    {
        return $this->attempt(fn () => app(TournamentSignups::class)->prepareSolo($this->tournament, $this->user()));
    }

    public function enterSolo(string $signed): void
    {
        $this->justEntered = $this->attempt(fn () => app(TournamentSignups::class)->enterSolo($this->tournament, $this->user(), $this->decode($signed))) !== null;
    }

    /** @return list<array<string, mixed>>|null */
    public function prepareLineup(): ?array
    {
        return $this->attempt(fn () => app(TournamentSignups::class)->prepareLineup($this->tournament, $this->user(), (int) $this->lineupId, $this->members));
    }

    public function enterLineup(string $signed): void
    {
        $this->justEntered = $this->attempt(fn () => app(TournamentSignups::class)->enterLineup($this->tournament, $this->user(), (int) $this->lineupId, $this->members, $this->decode($signed))) !== null;
    }

    /** @return list<array<string, mixed>>|null */
    public function prepareWithdraw(): ?array
    {
        return $this->attempt(fn () => app(TournamentSignups::class)->prepareWithdraw($this->tournament, $this->user()));
    }

    public function withdraw(string $signed): void
    {
        $this->justEntered = false;
        $this->attempt(fn () => app(TournamentSignups::class)->withdraw($this->tournament, $this->user(), $this->decode($signed)));
    }

    public function prepareReconfirm(): ?array
    {
        return $this->attempt(fn () => app(TournamentSignups::class)->prepareReconfirm($this->tournament, $this->user()));
    }

    public function reconfirm(string $signed): void
    {
        $this->attempt(fn () => app(TournamentSignups::class)->reconfirm($this->tournament, $this->user(), $this->decode($signed)));
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return list<mixed>
     */
    private function decode(string $signed): array
    {
        $decoded = json_decode($signed, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function attempt(callable $action): mixed
    {
        $this->error = '';

        try {
            $result = $action();
            unset($this->entry);

            return $result ?? true;
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        } catch (RejectedEvent) {
            $this->error = __('The signed event was refused. Please try again.');
        }

        unset($this->entry);

        return null;
    }
}; ?>

@php
    $tournament = $this->tournament;
    $me = auth()->user();
    $zone = (string) ($me->timezone ?? config('esports.preseason.display_timezone'));
    $teams = $tournament->profile()->entersTeams();
    $open = $tournament->isSignupOpen();
    $entry = $this->entry;
    $landing = new \App\Support\Tournaments\TournamentLanding($tournament, $me);
    $places = $landing->places();
    $left = $landing->openSeats();
    $countdown = $landing->countdown($zone);
    $lineup = $this->lineups->firstWhere('id', $this->lineupId);
    $at = fn (\Carbon\CarbonInterface $moment): string => $moment->copy()->timezone($zone)->locale(app()->getLocale())->translatedFormat('D Y-m-d H:i');
    $gameLine = \App\Support\GameNames::full($tournament->game, $tournament->mode);

    // The arena row: who is in (their pictures), then the seat that is yours.
    $others = array_values(array_filter($landing->roster(), fn (array $row): bool => ! $row['you']));
    $faces = array_values(array_filter(array_map(fn (array $row) => $row['users'][0] ?? null, array_slice($others, 0, 6))));
    $moreIn = max(0, count($others) - count($faces));
    $yourSeed = $landing->yourSeed();
@endphp

<div class="flex flex-col gap-6 px-4 pb-12 lg:px-12" data-test="tournament-signup">
    <a href="{{ route('tournaments.show', $tournament) }}" class="inline-flex min-h-11 items-center gap-1.5 self-start text-[13px]"><x-icon name="prev" :size="16" />{{ $tournament->name }}</a>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
        <section aria-labelledby="signup-h" class="flex min-w-0 flex-col gap-6 rounded-card bg-card p-4 shadow-ring sm:p-6"
                 x-data="{ ...nostrAction({ pubkey: @js($me->pubkey), messages: @js(SignerMessages::labels()) }), who: 'solo' }">
            <div class="flex flex-col gap-2">
                <h1 id="signup-h" class="m-0 font-display text-[26px] leading-tight font-bold break-words lg:text-[34px]">{{ __('Register: :name', ['name' => $tournament->name]) }}</h1>
                @if ($tournament->signup_closes_at)
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Registration closes :time, :left. You can pull out until then.', ['time' => $at($tournament->signup_closes_at), 'left' => $tournament->signup_closes_at->diffForHumans()]) }}</p>
                @endif
            </div>

            {{-- The arena: everyone who is in, then your seat; your picture slides into it while you sign --}}
            @if ($open || $entry)
                <div class="flex flex-col gap-3" data-test="arena">
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($faces as $face)
                            <x-avatar :user="$face" :size="44" class="rounded-md" />
                        @endforeach
                        @if ($moreIn > 0)
                            <span class="flex size-11 items-center justify-center rounded-md bg-raised text-xs font-bold text-ink-2">+{{ $moreIn }}</span>
                        @endif
                        <span @class(['tl-seat-mine relative flex size-11 items-center justify-center overflow-hidden rounded-md', 'border border-dashed border-btc bg-btc-chip' => ! $entry, 'tl-burst' => $entry && $justEntered])
                              data-test="your-seat-slot" @if ($entry) data-taken @endif>
                            @if ($entry)
                                @if ($entry->lineup_id && $entry->lineup?->clan)
                                    <x-clan-tag :clan="$entry->lineup->clan" :tile="44" class="flex size-11 items-center justify-center bg-btc-tint text-[11px] font-bold text-btc" />
                                @else
                                    <x-avatar :user="$me" :size="44" class="rounded-md" />
                                @endif
                            @else
                                <span x-show="! busy" class="text-btc"><x-icon name="user" :size="18" /></span>
                                <span x-show="busy" x-cloak class="tl-slide-in absolute inset-0">
                                    @if ($lineup)
                                        <span x-show="who === 'lineup'"><x-clan-tag :clan="$lineup->clan" :tile="44" class="flex size-11 items-center justify-center bg-btc-tint text-[11px] font-bold text-btc" /></span>
                                        <span x-show="who !== 'lineup'"><x-avatar :user="$me" :size="44" class="rounded-md" /></span>
                                    @else
                                        <x-avatar :user="$me" :size="44" class="rounded-md" />
                                    @endif
                                </span>
                            @endif
                        </span>
                        @if (! $entry && $left > 1)
                            <span class="flex size-11 items-center justify-center rounded-md border border-dashed border-dash text-xs text-ink-3">+{{ $left - 1 }}</span>
                        @endif
                    </div>
                    @unless ($entry)
                        <p class="m-0 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[13px] text-ink-2">
                            <span><b class="text-ink">{{ trans_choice(':count spot left|:count spots left', $left) }}</b>, {{ __(':taken of :places spots taken', ['taken' => $places['taken'], 'places' => $places['places']]) }}</span>
                            @if ($countdown)
                                <span>{{ $countdown['label'] }} <b class="font-display text-ink tabular-nums" role="timer" x-data="countdown({ at: {{ $countdown['ms'] }}, days: @js(__(':count day|:count days')) })" x-text="text">{{ $countdown['text'] }}</b></span>
                            @endif
                        </p>
                    @endunless
                </div>
            @endif

            @if (! $open && ! $entry)
                <p class="m-0 text-[13px] text-ink-2" data-test="signup-closed">{{ __('Sign-up for this tournament is closed.') }}</p>
            @elseif ($entry)
                {{-- In: the confirmation, what comes next, and the invite --}}
                <div @class(['flex flex-col gap-2 rounded-md bg-win-tint p-4 shadow-ring-win sm:p-5', 'tl-burst' => $justEntered]) data-test="my-entry" @if ($justEntered) data-just-entered @endif role="status">
                    <h2 class="m-0 font-display text-2xl font-bold text-win sm:text-[28px]">{{ __('You’re in!') }}</h2>
                    <p class="m-0 text-[13px] leading-normal">
                        {{ $entry->lineup_id ? __(':name with :count players.', ['name' => $entry->name, 'count' => count($entry->members)]) : ($teams ? __('Solo: you are drawn into a mix team when registration closes.') : __('You play as :name.', ['name' => $entry->name])) }}
                        @if ($yourSeed !== null)
                            {{ __('Seed :seed if sign-up closed now.', ['seed' => $yourSeed]) }}
                        @endif
                    </p>
                    <p class="m-0 flex items-center gap-2 text-[13px] text-ink-2"><x-icon name="flag" :size="16" class="shrink-0 text-win" />{{ __('Round 1 starts :time.', ['time' => $at($tournament->starts_at)]) }}</p>
                </div>

                @include('pages.tournaments.partials.share', ['tournament' => $tournament, 'label' => __('Bring a friend: every spot filled is one more match'),
                    'text' => __('I’m in :tournament on TWENTY ONE Esports (:game). :spots. Join me:', ['tournament' => $tournament->name, 'game' => $gameLine, 'spots' => trans_choice(':count spot left|:count spots left', $left)])])

                <div class="flex flex-wrap items-center gap-3 border-t border-hairline pt-4">
                    @if ($open && ($entry->lineup_id === null || ($entry->lineup?->isActingCaptain($me) ?? false)))
                        <x-button variant="secondary" x-on:click="run('prepareWithdraw', 'withdraw')" ::disabled="busy" data-test="withdraw">{{ __('Pull out') }}</x-button>
                        <span class="text-xs text-ink-3">{{ __('You can pull out until sign-up closes.') }}</span>
                    @elseif ($open)
                        <p class="m-0 text-xs text-ink-3">{{ __('Your captain can pull the lineup out.') }}</p>
                    @endif
                </div>
                @if ($open && $entry->needsReconfirm())
                    {{-- A rules change since sign-up (TournamentEditor): the entry needs a consent to the current version. --}}
                    <div class="flex flex-col gap-2 rounded-md px-4 py-4 shadow-[inset_0_0_0_1px_#F7931A]" data-test="reconfirm">
                        <p class="m-0 text-[13px] font-bold">{{ __('The rules changed after you signed up') }}</p>
                        <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Read the tournament page, then confirm your entry for the current rules. An entry not confirmed by sign-up close is dropped.') }}</p>
                        @if ($entry->lineup_id === null || ($entry->lineup?->isActingCaptain($me) ?? false))
                            <div><x-button x-on:click="run('prepareReconfirm', 'reconfirm')" ::disabled="busy" data-test="reconfirm-button">{{ __('Confirm my entry') }}</x-button></div>
                        @else
                            <p class="m-0 text-xs text-ink-3">{{ __('Your captain confirms the lineup.') }}</p>
                        @endif
                    </div>
                @endif
            @else
                @if ($lineup)
                    <div class="flex flex-col gap-3 rounded-md bg-ground p-4 shadow-ring-hairline" data-test="lineup-entry">
                        <h2 class="m-0 text-[15px] font-bold">{{ __('Bring your lineup') }}</h2>
                        <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                            {{ __('Lineup') }}
                            <select class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" x-on:change="$wire.pickLineup(Number($event.target.value))">
                                @foreach ($this->lineups as $option)
                                    <option value="{{ $option->id }}" @selected($option->id === $this->lineupId)>{{ $option->clan->name }}, {{ $option->mode }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="m-0 text-xs text-ink-2">{{ __('You are captain of :clan, so you register this lineup. Pick the team and up to :subs substitutes.', ['clan' => $lineup->clan->name, 'subs' => \App\Models\Tournament::SUBSTITUTES]) }}</p>
                        <ul class="m-0 grid list-none gap-1.5 p-0 sm:grid-cols-2">
                            @foreach ($lineup->activeSeats() as $seat)
                                <li wire:key="seat-{{ $seat->user_id }}">
                                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md bg-card px-3 text-[13px] shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)]">
                                        <input type="checkbox" class="size-4 accent-[#F7931A]" @checked(in_array($seat->user_id, $this->members, true)) wire:click="toggleMember({{ $seat->user_id }})" data-test="member">
                                        <x-avatar :user="$seat->user" :size="24" />
                                        <span class="grow truncate">{{ $seat->user->displayName() }}</span>
                                        <span class="text-xs text-ink-3">{{ $seat->role->label() }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        <p class="m-0 text-xs text-ink-2">{{ trans_choice(':count player picked|:count players picked', count($this->members)) }}</p>
                        <div>
                            <button type="button" x-on:click="who = 'lineup'; run('prepareLineup', 'enterLineup')" x-bind:disabled="busy" data-test="enter-lineup"
                                    class="btn-p tl-go inline-flex min-h-14 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-base font-bold text-on-btc disabled:cursor-wait disabled:opacity-80">
                                <x-icon name="shield-check" :size="20" />
                                <span x-show="! (busy && who === 'lineup')">{{ __('Confirm registration') }}</span>
                                <span x-show="busy && who === 'lineup'" x-cloak>{{ __('Signing…') }}</span>
                            </button>
                        </div>
                    </div>
                @endif

                <div class="flex flex-col gap-3 rounded-md bg-ground p-4 shadow-ring-hairline" data-test="solo-entry">
                    <h2 class="m-0 text-[15px] font-bold">{{ $teams ? __('You play solo, we draw you into a mix team.') : __('Take your seat') }}</h2>
                    <p class="m-0 text-[13px] leading-normal text-ink-2">
                        {{ $teams
                            ? __('When registration closes, all solo players are drawn into teams of :size from the hash of the next Bitcoin block. Every mix team gets a meme name. The draw is public and anyone can re-check it. Players left over wait as substitutes.', ['size' => $tournament->teamSize()])
                            : __('Seeding is by Elo when registration closes; equal Elo goes to whoever signed up first.') }}
                    </p>
                    @if ($lineup)
                        <p class="m-0 rounded-sm px-3 py-2 text-xs text-btc-hi shadow-[inset_0_0_0_1px_#5A3A12]">{{ __('You are captain of :clan. If you go solo, :clan can’t field you in this tournament.', ['clan' => $lineup->clan->name]) }}</p>
                    @endif
                    <div>
                        <button type="button" x-on:click="who = 'solo'; run('prepareSolo', 'enterSolo')" x-bind:disabled="busy" data-test="enter-solo"
                                @class(['inline-flex min-h-14 cursor-pointer items-center justify-center gap-2.5 rounded-md px-6 font-display text-base font-bold disabled:cursor-wait disabled:opacity-80',
                                    'btn-s border border-edge text-ink' => $lineup, 'btn-p tl-go bg-btc text-on-btc' => ! $lineup])>
                            <x-icon name="shield-check" :size="20" />
                            <span x-show="! (busy && who === 'solo')">{{ $teams ? __('Enter solo') : __('Confirm registration') }}</span>
                            <span x-show="busy && who === 'solo'" x-cloak>{{ __('Signing…') }}</span>
                        </button>
                    </div>
                </div>
                <p class="m-0 text-xs leading-normal text-ink-3">{{ __('You sign with your Nostr key; the league keeps the signature as your consent and never publishes it. By registering you accept the tournament rules. You can pull out until registration closes.') }}</p>
            @endif

            @if ($error !== '')
                <p class="m-0 text-[13px] text-loss" role="alert" data-test="signup-error">{{ $error }}</p>
            @endif
            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>
        </section>

        <aside class="flex flex-col gap-4 self-start">
            @include('pages.tournaments.partials.cover', ['tournament' => $tournament, 'class' => 'w-full'])
            <dl class="m-0 grid grid-cols-2 gap-2 text-[13px]">
                <div class="flex flex-col gap-0.5 rounded-md bg-card px-3.5 py-3"><dt class="text-xs text-ink-3">{{ __('Format') }}</dt><dd class="m-0">{{ $tournament->format->label() }}</dd></div>
                <div class="flex flex-col gap-0.5 rounded-md bg-card px-3.5 py-3"><dt class="text-xs text-ink-3">{{ __('Places') }}</dt><dd class="m-0 tabular-nums" data-test="places">{{ $places['taken'] }} / {{ $places['places'] }}</dd></div>
                <div class="col-span-2 flex flex-col gap-0.5 rounded-md bg-card px-3.5 py-3"><dt class="text-xs text-ink-3">{{ __('Starts') }}</dt><dd class="m-0">{{ $at($tournament->starts_at) }}</dd></div>
            </dl>
            <span class="flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                <a href="{{ route('tournaments.show', $tournament) }}">{{ __('Tournament page') }}</a>
                <a href="{{ route('tournaments.index') }}">{{ __('All tournaments') }}</a>
            </span>
        </aside>
    </div>
</div>
