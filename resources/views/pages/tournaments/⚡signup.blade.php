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
        $this->attempt(fn () => app(TournamentSignups::class)->enterSolo($this->tournament, $this->user(), $this->decode($signed)));
    }

    /** @return list<array<string, mixed>>|null */
    public function prepareLineup(): ?array
    {
        return $this->attempt(fn () => app(TournamentSignups::class)->prepareLineup($this->tournament, $this->user(), (int) $this->lineupId, $this->members));
    }

    public function enterLineup(string $signed): void
    {
        $this->attempt(fn () => app(TournamentSignups::class)->enterLineup($this->tournament, $this->user(), (int) $this->lineupId, $this->members, $this->decode($signed)));
    }

    /** @return list<array<string, mixed>>|null */
    public function prepareWithdraw(): ?array
    {
        return $this->attempt(fn () => app(TournamentSignups::class)->prepareWithdraw($this->tournament, $this->user()));
    }

    public function withdraw(string $signed): void
    {
        $this->attempt(fn () => app(TournamentSignups::class)->withdraw($this->tournament, $this->user(), $this->decode($signed)));
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
    $zone = (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    $teams = $tournament->profile()->entersTeams();
    $open = $tournament->isSignupOpen();
    $entry = $this->entry;
    $places = app(TournamentSignups::class)->places($tournament);
    $lineup = $this->lineups->firstWhere('id', $this->lineupId);
@endphp

<div class="flex flex-col gap-5 px-4 pt-8 pb-10 lg:px-12" data-test="tournament-signup">
    <div class="flex flex-col gap-2 lg:flex-row lg:items-baseline lg:gap-4">
        <h1 class="m-0 font-display text-[26px] font-bold break-words lg:text-[34px]">{{ __('Register: :name', ['name' => $tournament->name]) }}</h1>
        <p class="m-0 text-[13px] text-ink-2">
            @if ($tournament->signup_closes_at)
                {{ __('Registration closes :time, :left. You can pull out until then.', ['time' => $tournament->signup_closes_at->copy()->timezone($zone)->format('D Y-m-d H:i'), 'left' => $tournament->signup_closes_at->diffForHumans()]) }}
            @endif
        </p>
    </div>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_400px]">
        <section class="flex min-w-0 flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6"
                 x-data="nostrAction({ pubkey: @js(auth()->user()->pubkey), messages: @js(SignerMessages::labels()) })">
            @if (! $open)
                <p class="m-0 text-[13px] text-ink-2" data-test="signup-closed">{{ __('Sign-up for this tournament is closed.') }}</p>
            @elseif ($entry)
                <div class="flex flex-col gap-2" data-test="my-entry">
                    <h2 class="m-0 text-[15px] font-bold">{{ __('You are signed up') }}</h2>
                    <p class="m-0 text-[13px] text-ink-2">{{ $entry->lineup_id ? __(':name with :count players.', ['name' => $entry->name, 'count' => count($entry->members)]) : ($teams ? __('Solo: you are drawn into a mix team when registration closes.') : __('You play as :name.', ['name' => $entry->name])) }}</p>
                </div>
                @if ($entry->lineup_id === null || ($entry->lineup?->isActingCaptain(auth()->user()) ?? false))
                    <div><x-button variant="secondary" x-on:click="run('prepareWithdraw', 'withdraw')" ::disabled="busy" data-test="withdraw">{{ __('Pull out') }}</x-button></div>
                @else
                    <p class="m-0 text-xs text-ink-3">{{ __('Your captain can pull the lineup out.') }}</p>
                @endif
            @else
                @if ($lineup)
                    <div class="flex flex-col gap-3" data-test="lineup-entry">
                        <label class="flex flex-col gap-1.5 text-xs text-ink-2">
                            {{ __('Lineup') }}
                            <select class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" x-on:change="$wire.pickLineup(Number($event.target.value))">
                                @foreach ($this->lineups as $option)
                                    <option value="{{ $option->id }}" @selected($option->id === $this->lineupId)>{{ $option->clan->name }}, {{ $option->mode }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="m-0 text-xs text-ink-2">{{ __('You are captain of :clan, so you register this lineup. Pick the team and up to :subs substitutes.', ['clan' => $lineup->clan->name, 'subs' => \App\Models\Tournament::SUBSTITUTES]) }}</p>
                        <ul class="m-0 flex list-none flex-col p-0">
                            @foreach ($lineup->activeSeats() as $seat)
                                <li class="border-t border-hairline" wire:key="seat-{{ $seat->user_id }}">
                                    <label class="flex min-h-11 cursor-pointer items-center gap-3 text-[13px]">
                                        <input type="checkbox" class="size-4 accent-[#F7931A]" @checked(in_array($seat->user_id, $this->members, true)) wire:click="toggleMember({{ $seat->user_id }})" data-test="member">
                                        <span class="grow truncate">{{ $seat->user->displayName() }}</span>
                                        <span class="text-xs text-ink-3">{{ $seat->role->label() }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        <p class="m-0 text-xs text-ink-2">{{ trans_choice(':count player picked|:count players picked', count($this->members)) }}</p>
                        <div><x-button icon="shield-check" x-on:click="run('prepareLineup', 'enterLineup')" ::disabled="busy" data-test="enter-lineup">{{ __('Confirm registration') }}</x-button></div>
                    </div>
                @endif

                <div class="flex flex-col gap-3 rounded-md bg-ground px-4 py-4 shadow-ring" data-test="solo-entry">
                    <h2 class="m-0 text-[15px] font-bold">{{ $teams ? __('You play solo, we draw you into a mix team.') : __('Sign up to play') }}</h2>
                    <p class="m-0 text-[13px] leading-normal text-ink-2">
                        {{ $teams
                            ? __('When registration closes, all solo players are drawn into teams of :size from the hash of the next Bitcoin block. Every mix team gets a meme name. The draw is public and anyone can re-check it. Players left over wait as substitutes.', ['size' => $tournament->teamSize()])
                            : __('Seeding is by Elo when registration closes; equal Elo goes to whoever signed up first.') }}
                    </p>
                    @if ($lineup)
                        <p class="m-0 rounded-sm px-3 py-2 text-xs text-btc-hi shadow-[inset_0_0_0_1px_#5A3A12]">{{ __('You are captain of :clan. If you go solo, :clan can’t field you in this tournament.', ['clan' => $lineup->clan->name]) }}</p>
                    @endif
                    <div><x-button :variant="$lineup ? 'secondary' : 'primary'" icon="shield-check" x-on:click="run('prepareSolo', 'enterSolo')" ::disabled="busy" data-test="enter-solo">{{ $teams ? __('Enter solo') : __('Confirm registration') }}</x-button></div>
                </div>
                <p class="m-0 text-xs text-ink-3">{{ __('By registering you accept the tournament rules. You can pull out until registration closes.') }}</p>
            @endif

            @if ($error !== '')
                <p class="m-0 text-[13px] text-loss" role="alert" data-test="signup-error">{{ $error }}</p>
            @endif
            <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
        </section>

        <aside class="flex flex-col gap-3 self-start rounded-lg bg-card px-4 py-5 lg:px-6">
            <span class="flex items-baseline justify-between gap-3">
                <h2 class="m-0 text-[15px] font-bold">{{ $tournament->name }}</h2>
                <a href="{{ route('tournaments.index') }}" class="text-xs">{{ __('All tournaments') }}</a>
            </span>
            <dl class="m-0 flex flex-col text-[13px]">
                <div class="flex justify-between gap-3 border-b border-hairline py-2"><dt class="text-ink-2">{{ __('Format') }}</dt><dd class="m-0 text-right">{{ $tournament->format->label() }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-hairline py-2"><dt class="text-ink-2">{{ __('Starts') }}</dt><dd class="m-0 text-right">{{ $tournament->starts_at->copy()->timezone($zone)->format('D Y-m-d H:i') }}</dd></div>
                <div class="flex justify-between gap-3 border-b border-hairline py-2"><dt class="text-ink-2">{{ __('Places') }}</dt><dd class="m-0 text-right" data-test="places">{{ $places['taken'] }} / {{ $places['places'] }}</dd></div>
            </dl>
            <a href="{{ route('tournaments.show', $tournament) }}" class="text-[13px]">{{ __('Tournament page') }}</a>
        </aside>
    </div>
</div>
