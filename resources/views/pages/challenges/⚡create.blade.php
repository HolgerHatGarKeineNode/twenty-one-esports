<?php

use App\Enums\InviteLinkType;
use App\Enums\SeriesStatus;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\Nostr\RejectedEvent;
use App\Support\PreSeason;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Challenge a clan, 1:1 from ChallengeCreate.dc.html (desktop) and
 * MobileChallenge.dc.html (a five-step flow on small screens, same state).
 *
 * Before Block 0 every series is casual: "Rated" is shown disabled with the
 * Block 0 countdown (States.dc.html "Locked until Block 0"); Elo, tier,
 * trust and "can mine" columns of the design are left out until P7 has
 * them. The match number is reserved when the challenge is prepared.
 */
new #[Title('New challenge')] #[Layout('layouts::app', ['section' => 'clans'])] class extends Component {
    #[Url(as: 'lineup', except: null)]
    public ?int $lineupId = null;

    #[Url(as: 'to', except: null)]
    public ?int $opponentId = null;

    public bool $rated = false;

    public int $bestOf = 3;

    public bool $now = false;

    /** @var list<array{date: string, time: string}> */
    public array $times = [];

    public string $replyDate = '';

    public string $replyTime = '';

    public string $search = '';

    public string $error = '';

    public function mount(): void
    {
        $zone = $this->zone();
        $first = CarbonImmutable::now($zone)->addDay()->setTime(20, 0);
        $this->times = [['date' => $first->format('Y-m-d'), 'time' => $first->format('H:i')]];
        $reply = $first->subHours(2);
        $this->replyDate = $reply->format('Y-m-d');
        $this->replyTime = $reply->format('H:i');

        $mine = $this->myLineups;

        if ($this->lineupId === null || ! $mine->contains('id', $this->lineupId)) {
            $this->lineupId = $mine->first(fn (Lineup $lineup) => $lineup->mode === '3v3')?->id ?? $mine->first()?->id;
        }
    }

    public function pickLineup(int $id): void
    {
        $this->lineupId = $id;
        $this->opponentId = null;
        unset($this->lineup, $this->opponents);
    }

    public function pickOpponent(int $id): void
    {
        $this->opponentId = $id;
        $this->error = '';
    }

    public function addTime(): void
    {
        if (count($this->times) < 3) {
            $last = end($this->times) ?: ['date' => '', 'time' => ''];
            $this->times[] = ['date' => $last['date'], 'time' => $last['time']];
        }
    }

    public function removeTime(int $index): void
    {
        if (count($this->times) > 1) {
            unset($this->times[$index]);
            $this->times = array_values($this->times);
        }
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareSend(): ?array
    {
        $draft = $this->draft();

        if ($draft === null) {
            return null;
        }

        try {
            return app(SeriesService::class)->prepareChallenge($this->user(), $draft)['templates'];
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return null;
        }
    }

    public function send(string $signed): void
    {
        $draft = $this->draft();

        if ($draft === null) {
            return;
        }

        try {
            $events = json_decode($signed, true);
            $match = app(SeriesService::class)->challenge($this->user(), $draft, is_array($events) && array_is_list($events) ? $events : [['malformed']]);
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();

            return;
        } catch (RejectedEvent) {
            $this->error = __('The signed event was refused. Please try again.');

            return;
        }

        session()->flash('status', __('Challenge :number sent to :clan.', ['number' => $match->label(), 'clan' => $match->challenged_name]));
        $this->redirectRoute('matches.room', $match);
    }

    private function draft(): ?ChallengeDraft
    {
        $this->error = '';

        if ($this->lineupId === null || $this->opponentId === null) {
            $this->error = __('Pick your lineup and an opponent first.');

            return null;
        }

        $schedule = $this->schedule();

        return $schedule === null ? null : new ChallengeDraft($this->lineupId, $this->opponentId, $this->bestOf, $this->rated, $schedule[0], $schedule[1]);
    }

    /**
     * Invite by link (P6b): the same challenge without an opponent. Whoever
     * opens the link and captains a ready lineup of this mode takes it; the
     * match number is reserved then, not now. Casual only: a rated challenge
     * names the other captains in its signed event.
     */
    public function createLink(InviteLinks $links): void
    {
        $this->error = '';

        if ($this->lineupId === null) {
            $this->error = __('Pick your lineup first.');

            return;
        }

        if ($this->rated) {
            $this->error = __('Invite links are for casual matches. Switch to casual to make one.');

            return;
        }

        $schedule = $this->schedule();

        if ($schedule === null) {
            return;
        }

        try {
            $link = $links->create($this->user(), InviteLinkType::Series, [
                'lineup_id' => $this->lineupId,
                'best_of' => $this->bestOf,
                'proposals' => $schedule[0],
                'respond_by' => $schedule[1],
                'uses' => 'once',
            ]);
        } catch (InviteLinkRefused $refused) {
            $this->error = $refused->getMessage();

            return;
        }

        $this->redirectRoute('invites.link', $link);
    }

    /**
     * The suggested starts and the reply deadline, as unix seconds.
     *
     * @return array{0: list<int>, 1: int}|null
     */
    private function schedule(): ?array
    {
        $zone = $this->zone();

        if ($this->now) {
            $start = now()->addMinutes((int) config('esports.series.now_minutes', 10))->startOfMinute()->getTimestamp();

            return [[$start], $start];
        }

        $proposals = [];

        foreach ($this->times as $row) {
            $time = $this->parse($row['date'], $row['time'], $zone);

            if ($time === null) {
                $this->error = __('Every suggested time needs a date and a time.');

                return null;
            }

            $proposals[] = $time;
        }

        $reply = $this->parse($this->replyDate, $this->replyTime, $zone);

        if ($reply === null) {
            $this->error = __('Set a reply deadline.');

            return null;
        }

        return [$proposals, $reply];
    }

    private function parse(string $date, string $time, string $zone): ?int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $zone)?->getTimestamp();
    }

    /**
     * The player's Rocket League lineups they captain.
     *
     * @return Collection<int, Lineup>
     */
    #[Computed]
    public function myLineups(): Collection
    {
        $user = $this->user();
        $clanId = $user->clanMember?->clan_id;

        if ($clanId === null) {
            return collect();
        }

        return Lineup::query()->with(['clan', 'seats.user.clanMember'])
            ->where('clan_id', $clanId)->where('game', 'rocket-league')
            ->get()
            ->filter(fn (Lineup $lineup) => $lineup->isActingCaptain($user))
            ->sortBy(fn (Lineup $lineup) => array_search($lineup->mode, ['3v3', '2v2', '1v1'], true))
            ->values();
    }

    #[Computed]
    public function lineup(): ?Lineup
    {
        return $this->myLineups->firstWhere('id', $this->lineupId);
    }

    /**
     * Other clans' lineups of the same mode, with why they can or cannot be
     * challenged right now.
     *
     * @return Collection<int, array{lineup: Lineup, state: string, note: string}>
     */
    #[Computed]
    public function opponents(): Collection
    {
        $mine = $this->lineup;

        if ($mine === null) {
            return collect();
        }

        $term = trim($this->search);
        $running = SeriesMatch::query()
            ->whereIn('status', [SeriesStatus::Open, SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed])
            ->where(fn ($query) => $query->where('challenger_lineup_id', $mine->id)->orWhere('challenged_lineup_id', $mine->id))
            ->get();

        return Lineup::query()->with(['clan.owner', 'seats.user.clanMember'])
            ->where('game', $mine->game)->where('mode', $mine->mode)->where('clan_id', '!=', $mine->clan_id)
            ->when($term !== '', fn ($query) => $query->whereHas('clan', fn ($query) => $query->whereLike('name', "%{$term}%")->orWhereLike('clantag', "%{$term}%")))
            ->get()
            ->sortBy('clan.name')
            ->map(function (Lineup $lineup) use ($running) {
                $open = $running->first(fn (SeriesMatch $match) => $match->challenger_lineup_id === $lineup->id || $match->challenged_lineup_id === $lineup->id);

                return match (true) {
                    $open !== null => ['lineup' => $lineup, 'state' => 'busy', 'note' => __(':number open', ['number' => $open->label()])],
                    ! $lineup->isReady() => ['lineup' => $lineup, 'state' => 'not_ready', 'note' => __('lineup not complete')],
                    default => ['lineup' => $lineup, 'state' => 'ok', 'note' => __('open for casual')],
                };
            })
            ->values();
    }

    private function zone(): string
    {
        return PreSeason::timezoneFor(auth()->user() instanceof User ? auth()->user() : null);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $me = auth()->user();
    $mine = $this->myLineups;
    $lineup = $this->lineup;
    $opponents = $this->opponents;
    $picked = $opponents->first(fn ($row) => $row['lineup']->id === $opponentId);
    $ladderOpen = $lineup !== null && Ladders::isOpen($lineup->game, $lineup->mode);
    // After Block 0 rated play also needs trust ranks (RatedTrustGate); without them it stays locked.
    $trustReady = app(RatedTrustGate::class)->isAvailable();
    $ratedOpen = $ladderOpen && $trustReady;
    $block0 = PreSeason::block0At();
    $card = 'rounded-lg bg-card px-4 py-5 lg:px-6';
    $choice = 'flex min-h-[88px] cursor-pointer flex-col gap-1.5 rounded-lg border bg-ground px-5 py-4 text-left lg:px-12';
    $steps = [__('Lineup'), __('Opponent'), __('Rated or casual'), __('Times'), __('Send')];
@endphp

<div class="flex grow flex-col gap-5 px-4 pt-5 pb-28 lg:mx-auto lg:w-full lg:max-w-[1232px] lg:px-4 lg:pt-8 lg:pb-10" data-test="challenge-create" x-data="{ step: 1 }">
    <div class="flex flex-col gap-1 lg:flex-row lg:items-baseline lg:gap-4">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]"><span class="lg:hidden">{{ __('Challenge a clan') }}</span><span class="max-lg:hidden">{{ __('New challenge') }}</span></h1>
        <span class="text-[13px] text-ink-2 max-lg:hidden">{{ __('Rocket League ladder, Pre-Season') }}</span>
        <span class="text-[13px] leading-normal text-ink-2 lg:hidden">{{ __('Rocket League. Pick your lineup and an opponent, then send it.') }}</span>
    </div>

    @if ($mine->isEmpty())
        <div class="{{ $card }}" data-test="no-lineup">
            <x-empty-state :heading="$me->clanMember ? __('You captain no Rocket League lineup') : __('You aren\'t in a clan yet')"
                           :text="$me->clanMember ? __('Only a lineup\'s captain sends challenges. Ask your captain, or set up a lineup.') : __('Start a clan and invite your friends, or accept an invite. Invites show up here as soon as a captain sends one.')">
                @if ($me->clanMember)
                    <x-button :href="route('clans.show', $me->clanMember->clan)">{{ __('Open your clan') }}</x-button>
                @else
                    <x-button :href="route('clans.create')">{{ __('Start a clan') }}</x-button>
                    <x-button variant="quiet" :href="route('clans.index')">{{ __('Browse clans') }}</x-button>
                @endif
            </x-empty-state>
        </div>
    @else
        {{-- Mobile stepper (MobileChallenge.dc.html) --}}
        <ol class="m-0 flex list-none flex-col p-0 lg:hidden" aria-label="{{ __('Steps') }}">
            @foreach ($steps as $index => $label)
                @php($n = $index + 1)
                <li class="grid grid-cols-[32px_minmax(0,1fr)] gap-x-3">
                    <span class="flex flex-col items-center">
                        <span class="flex size-8 items-center justify-center rounded-full border-2 text-[13px] font-bold"
                              :class="step > {{ $n }} ? 'border-btc bg-btc text-on-btc' : (step === {{ $n }} ? 'border-btc text-btc' : 'border-edge text-ink-3')">
                            <span x-show="step > {{ $n }}"><x-icon name="check" :size="14" /></span><span x-show="step <= {{ $n }}">{{ $n }}</span>
                        </span>
                        @if ($n < 5)<span class="min-h-5 w-0.5 grow" :class="step > {{ $n }} ? 'bg-btc' : 'bg-line'"></span>@endif
                    </span>
                    <span class="flex min-h-12 flex-col pb-3">
                        <b class="text-[15px]" :class="step >= {{ $n }} ? 'text-ink' : 'text-ink-3'">{{ $label }}</b>
                        <span x-show="step > {{ $n }}" class="text-xs text-ink-2">
                            @switch($n)
                                @case(1){{ $lineup ? $lineup->mode.', '.implode(', ', array_map(fn ($s) => $s->user->displayName(), $lineup->activeSeats())) : '' }}@break
                                @case(2){{ $picked ? $picked['lineup']->clan->name : '' }}@break
                                @case(3){{ ($rated ? __('Rated') : __('Casual')).', BO'.$bestOf }}@break
                                @case(4){{ $now ? __('Challenge now') : trans_choice(':count suggestion|:count suggestions', count($times)) }}@break
                            @endswitch
                        </span>
                        <span x-show="step === {{ $n }}" class="text-xs text-ink-2">{{ __('Step :n of 5', ['n' => $n]) }}</span>
                    </span>
                </li>
            @endforeach
        </ol>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
            <div class="flex min-w-0 flex-col gap-5">
                {{-- Match type --}}
                <section aria-labelledby="type-h" class="{{ $card }}" :class="step === 3 ? '' : 'max-lg:hidden'">
                    <span class="flex items-baseline justify-between pb-3"><h2 id="type-h" class="m-0 text-[15px] font-bold">{{ __('Match type') }}</h2><span class="text-xs text-ink-2 max-lg:hidden">{{ __('you can switch until you send') }}</span></span>
                    <div role="radiogroup" aria-labelledby="type-h" class="grid grid-cols-2 gap-3">
                        <button type="button" role="radio" wire:click="$set('rated', false)" aria-checked="{{ $rated ? 'false' : 'true' }}" data-test="type-casual"
                                @class([$choice, 'border-btc bg-btc-press' => ! $rated, 'border-line' => $rated])>
                            <b class="font-display text-lg lg:text-xl">{{ __('Casual') }}</b><span class="text-xs leading-normal text-ink-2 lg:text-[13px]">{{ __('Just for fun. No rating change, no connection needed. Open to every clan.') }}</span>
                        </button>
                        @if ($ratedOpen)
                            <button type="button" role="radio" wire:click="$set('rated', true)" aria-checked="{{ $rated ? 'true' : 'false' }}" @class([$choice, 'border-btc bg-btc-press' => $rated, 'border-line' => ! $rated])>
                                <b class="font-display text-lg lg:text-xl">{{ __('Rated') }}</b><span class="text-xs leading-normal text-ink-2 lg:text-[13px]">{{ __('Counts for Elo and clan Hashrate, and a win can mine a block. Needs a connection with their captain.') }}</span>
                            </button>
                        @else
                            <span role="radio" aria-checked="false" aria-disabled="true" class="{{ $choice }} cursor-not-allowed border-line opacity-60" data-test="type-rated-locked">
                                <b class="font-display text-lg lg:text-xl">{{ __('Rated') }}</b>
                                @if ($ladderOpen && ! $trustReady)
                                    <span class="text-xs leading-normal text-ink-2 lg:text-[13px]" data-test="rated-needs-trust">{{ __('Rated play opens once trust ranks are computed') }}</span>
                                @else
                                    <span class="text-xs leading-normal text-ink-2 lg:text-[13px]">{{ __('from Block 0') }}</span>
                                @endif
                                @if (! $ladderOpen && $block0 && $block0->isFuture())
                                    <span class="text-xs font-bold text-btc">{{ __('Block 0: :time', ['time' => $block0->copy()->timezone(PreSeason::timezoneFor($me))->locale(app()->getLocale())->translatedFormat('D M j, H:i')]) }}</span>
                                @endif
                            </span>
                        @endif
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-3 lg:hidden" role="radiogroup" aria-label="{{ __('Format') }}">
                        @foreach ([3, 5] as $bo)
                            <button type="button" role="radio" wire:click="$set('bestOf', {{ $bo }})" aria-checked="{{ $bestOf === $bo ? 'true' : 'false' }}"
                                    @class(['flex min-h-14 cursor-pointer flex-col justify-center rounded-lg border bg-ground px-5 text-left', 'border-btc bg-btc-press' => $bestOf === $bo, 'border-line' => $bestOf !== $bo])>
                                <b class="text-[15px]">Bo{{ $bo }}</b><span class="text-xs text-ink-2">{{ __('first to :n wins', ['n' => intdiv($bo, 2) + 1]) }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Your lineup --}}
                <section aria-labelledby="lu-h" class="{{ $card }} flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between" :class="step === 1 ? '' : 'max-lg:hidden'">
                    <h2 id="lu-h" class="m-0 text-[15px] font-bold">{{ __('Your lineup') }}</h2>
                    <div role="radiogroup" aria-labelledby="lu-h" class="grid grid-cols-3 gap-3 lg:w-[536px]">
                        @foreach ($mine as $option)
                            <button type="button" role="radio" wire:click="pickLineup({{ $option->id }})" aria-checked="{{ $lineupId === $option->id ? 'true' : 'false' }}" data-test="lineup-{{ $option->mode }}"
                                    @class(['flex h-[52px] cursor-pointer flex-col items-center justify-center rounded-md border bg-ground text-[13px] text-ink', 'border-btc' => $lineupId === $option->id, 'border-line' => $lineupId !== $option->id])>
                                <span><b>{{ $option->mode }}</b> <span class="text-ink-2">{{ $option->isReady() ? __('ready') : __('incomplete') }}</span></span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Opponent --}}
                <section aria-labelledby="op-h" class="{{ $card }} flex flex-col gap-3" :class="step === 2 ? '' : 'max-lg:hidden'">
                    <span class="flex items-baseline justify-between"><h2 id="op-h" class="m-0 text-[15px] font-bold">{{ __('Opponent in :mode', ['mode' => $lineup?->mode ?? '']) }}</h2><span class="text-xs text-ink-2">{{ __('same mode only') }}</span></span>
                    <label for="op-search" class="sr-only">{{ __('Search opponent') }}</label>
                    <input id="op-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search opponent') }}" class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-sm text-ink placeholder:text-ink-3 lg:max-w-[360px]">
                    <div role="radiogroup" aria-labelledby="op-h" class="flex flex-col">
                        <div class="hidden h-9 grid-cols-[52px_minmax(0,1fr)_100px_110px_180px] items-center gap-3 px-3 text-xs text-ink-2 md:grid">
                            <span></span><span>{{ __('Clan') }}</span><span>{{ __('Players') }}</span><span>{{ __('Captain') }}</span><span>{{ __('Status') }}</span>
                        </div>
                        @forelse ($opponents as $row)
                            @php($opp = $row['lineup'])
                            <button type="button" role="radio" wire:key="o-{{ $opp->id }}" wire:click="pickOpponent({{ $opp->id }})" aria-checked="{{ $opponentId === $opp->id ? 'true' : 'false' }}" @disabled($row['state'] !== 'ok') data-test="pick-opponent"
                                    @class(['grid min-h-12 cursor-pointer grid-cols-[52px_minmax(0,1fr)_auto] items-center gap-3 rounded-md border bg-transparent px-3 py-1.5 text-left text-[13px] text-ink disabled:cursor-not-allowed md:grid-cols-[52px_minmax(0,1fr)_100px_110px_180px]',
                                        'border-btc bg-btc-press' => $opponentId === $opp->id, 'border-transparent hover:bg-row-hover' => $opponentId !== $opp->id])>
                                <x-clan-tag :clan="$opp->clan" size="sm" />
                                <span class="truncate">{{ $opp->clan->name }}</span>
                                <span class="text-ink-2 max-md:hidden">{{ $opp->activeCount() }} / {{ $opp->gameMode()->teamSize }}</span>
                                <span class="truncate text-ink-2 max-md:hidden">{{ $opp->clan->owner?->displayName() }}</span>
                                <span @class(['text-xs', 'text-ink' => $row['state'] === 'ok', 'text-btc-hi' => $row['state'] !== 'ok'])>{{ $row['note'] }}</span>
                            </button>
                        @empty
                            <p class="m-0 px-3 py-3 text-[13px] text-ink-2">{{ __('No other clan has a :mode lineup yet.', ['mode' => $lineup?->mode ?? '']) }}</p>
                        @endforelse
                    </div>
                    <p class="m-0 border-t border-hairline pt-3 text-xs leading-normal text-ink-2">{{ __('Until Block 0 every series is casual: you can challenge any clan, no connection needed. Rated opens at Block 0, with a connection between both captains.') }}</p>
                </section>

                {{-- Format --}}
                <section aria-labelledby="fmt-h" class="{{ $card }} max-lg:hidden">
                    <h2 id="fmt-h" class="m-0 pb-3 text-[15px] font-bold">{{ __('Format') }}</h2>
                    <div role="radiogroup" aria-labelledby="fmt-h" class="grid grid-cols-2 gap-3">
                        @foreach ([3 => __('First to 2 games, up to 3 games, about 30 min'), 5 => __('First to 3 games, up to 5 games, about 50 min')] as $bo => $text)
                            <button type="button" role="radio" wire:click="$set('bestOf', {{ $bo }})" aria-checked="{{ $bestOf === $bo ? 'true' : 'false' }}" data-test="bo-{{ $bo }}"
                                    @class([$choice, 'border-btc bg-btc-press' => $bestOf === $bo, 'border-line' => $bestOf !== $bo])>
                                <b class="font-display text-xl">{{ __('Best of :n', ['n' => $bo]) }}</b><span class="text-[13px] text-ink-2">{{ $text }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>

                {{-- Suggested times --}}
                <section aria-labelledby="times-h" class="{{ $card }} flex flex-col gap-3" :class="step === 4 ? '' : 'max-lg:hidden'">
                    <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="times-h" class="m-0 text-[15px] font-bold">{{ __('Suggested times') }}</h2><span class="text-xs text-ink-2">{{ __('up to 3, :clan picks one', ['clan' => $picked['lineup']->clan->name ?? __('the other clan')]) }}</span></span>
                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-3 text-[13px]"><input type="checkbox" wire:model.live="now" class="size-4 accent-[#F7931A]" data-test="challenge-now"><span><b>{{ __('Challenge now') }}</b> <span class="text-ink-2">{{ __('start in :n minutes, they have until then to accept', ['n' => (int) config('esports.series.now_minutes', 10)]) }}</span></span></label>
                    @unless ($now)
                        @foreach ($times as $index => $row)
                            <div wire:key="t-{{ $index }}" class="grid grid-cols-[64px_minmax(0,1fr)_100px_44px] items-center gap-2 lg:grid-cols-[100px_minmax(0,1fr)_140px_44px] lg:gap-3">
                                <span class="text-[13px] text-ink-2">{{ __('Time :n', ['n' => $index + 1]) }}</span>
                                <input type="date" wire:model="times.{{ $index }}.date" aria-label="{{ __('Date of time :n', ['n' => $index + 1]) }}" class="h-11 min-w-0 rounded-lg border border-edge bg-ground px-3 text-sm text-ink">
                                <input type="time" wire:model="times.{{ $index }}.time" aria-label="{{ __('Hour of time :n', ['n' => $index + 1]) }}" class="h-11 min-w-0 rounded-lg border border-edge bg-ground px-3 text-sm text-ink">
                                @if (count($times) > 1)
                                    <button type="button" wire:click="removeTime({{ $index }})" aria-label="{{ __('Remove time :n', ['n' => $index + 1]) }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="close" :size="16" /></button>
                                @else
                                    <span></span>
                                @endif
                            </div>
                        @endforeach
                        @if (count($times) < 3)
                            <div><x-button variant="quiet" wire:click="addTime">{{ __('Add a time') }}</x-button></div>
                        @endif
                        <div class="grid grid-cols-[64px_minmax(0,1fr)_100px_44px] items-center gap-2 border-t border-hairline pt-3 lg:grid-cols-[100px_minmax(0,1fr)_140px_44px] lg:gap-3">
                            <span class="text-[13px] text-ink-2">{{ __('Reply by') }}</span>
                            <input type="date" wire:model="replyDate" aria-label="{{ __('Reply by, date') }}" class="h-11 min-w-0 rounded-lg border border-edge bg-ground px-3 text-sm text-ink">
                            <input type="time" wire:model="replyTime" aria-label="{{ __('Reply by, hour') }}" class="h-11 min-w-0 rounded-lg border border-edge bg-ground px-3 text-sm text-ink">
                            <x-icon name="clock" :size="18" class="justify-self-center text-ink-2" />
                        </div>
                        <p class="m-0 text-xs text-ink-2">{{ __('No reply by then and the challenge expires. The deadline has to be before the first suggested time.') }} {{ __('Times in :zone.', ['zone' => PreSeason::timezoneFor($me)]) }}</p>
                    @endunless
                </section>
            </div>

            {{-- Summary --}}
            <aside class="flex flex-col gap-4 self-start rounded-lg bg-card px-4 py-5 lg:sticky lg:top-6 lg:px-6" :class="step === 5 ? '' : 'max-lg:hidden'" data-test="challenge-summary" x-data="nostrAction({ pubkey: @js($me->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })">
                @if ($picked)
                    @php($opp = $picked['lineup'])
                    <span class="flex items-start gap-3">
                        <x-clan-tag :clan="$opp->clan" :tile="44" class="flex size-11 shrink-0 items-center justify-center rounded-md bg-btc-tint text-xs font-bold text-btc" />
                        <span class="flex min-w-0 grow flex-col gap-1"><b class="truncate text-[15px]">{{ $opp->clan->name }}, {{ $opp->mode }}</b><span class="text-xs text-ink-2">{{ __('captain :name', ['name' => $opp->clan->owner?->displayName() ?? '']) }}</span></span>
                        <span class="inline-flex h-7 items-center rounded-sm bg-btc-chip px-2.5 text-xs font-bold text-btc-hi shadow-[inset_0_0_0_1px_#B9640A]">{{ $rated ? __('Rated') : __('Casual') }}</span>
                    </span>
                @else
                    <span class="text-[13px] text-ink-2">{{ __('Pick an opponent on the left.') }}</span>
                @endif
                <div class="flex flex-col">
                    @foreach ([
                        [__('Match kind'), $rated ? __('Rated') : __('Casual')],
                        [__('Lineup'), $lineup ? $lineup->clan->name.' '.$lineup->mode : '–'],
                        [__('Opponent'), $picked ? $picked['lineup']->clan->name.' '.$picked['lineup']->mode : '–'],
                        [__('Format'), __('Best of :n', ['n' => $bestOf])],
                        [__('Times'), $now ? __('now, in :n min', ['n' => (int) config('esports.series.now_minutes', 10)]) : trans_choice(':count suggestion|:count suggestions', count($times))],
                        [__('Reply by'), $now ? __('before the start') : ($replyDate !== '' ? CarbonImmutable::parse($replyDate.' '.$replyTime)->locale(app()->getLocale())->translatedFormat('D M j, H:i') : '–')],
                        [__('At stake'), __('nothing, casual')],
                    ] as [$key, $value])
                        <div class="flex min-h-10 items-center justify-between gap-3 border-b border-hairline text-[13px]"><span class="text-ink-2">{{ $key }}</span><span class="text-right">{{ $value }}</span></div>
                    @endforeach
                </div>
                <div class="flex items-start gap-3 rounded-md bg-ground px-4 py-3 shadow-ring">
                    <x-icon name="lock" :size="16" class="mt-0.5 shrink-0 text-ink-2" />
                    <span class="flex flex-col gap-1"><b class="text-[13px]">{{ __('Casual until Block 0') }}</b><span class="text-xs leading-normal text-ink-2">{{ __('No rating, no reward, no block. It stays casual even if it ends after Block 0.') }}</span></span>
                </div>
                <button type="button" x-on:click="run('prepareSend', 'send')" :disabled="busy || {{ $picked ? 'false' : 'true' }}" @disabled(! $picked) data-test="send-challenge"
                        class="btn-p inline-flex h-[52px] cursor-pointer items-center justify-center gap-2.5 rounded-md border-0 bg-btc px-5 text-[15px] font-bold text-on-btc disabled:cursor-not-allowed disabled:opacity-50">
                    <x-icon name="shield-check" :size="18" />{{ __('Send challenge') }}
                </button>
                @if ($error)<p class="m-0 text-[13px] text-loss" role="alert" data-test="challenge-error">{{ $error }}</p>@endif
                <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
                <span class="text-xs leading-normal text-ink-2">{{ __(':clan\'s captains see it right away. You can withdraw it while it\'s open.', ['clan' => $picked['lineup']->clan->name ?? __('The other clan')]) }}</span>
                <div class="flex flex-col gap-2.5 border-t border-hairline pt-4" data-test="series-invite-link">
                    <b class="text-[13px]">{{ __('No opponent here yet?') }}</b>
                    <span class="text-xs leading-normal text-ink-2">{{ __('Invite by link: the first captain of a ready :mode lineup who opens it and accepts plays you, at one of your times.', ['mode' => $lineup?->mode ?? '']) }}</span>
                    <button type="button" wire:click="createLink" wire:loading.attr="disabled" data-test="create-series-link"
                            class="btn-w inline-flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] text-ink">
                        <x-icon name="link" :size="16" />{{ __('Invite by link') }}
                    </button>
                </div>
                <x-proof :rows="$rated ? [[__('Record'), __('challenge, kind 2150')], [__('Sent as'), $me->shortNpub().' ('.$me->displayName().')']] : [[__('Record'), __('a casual challenge stays with the league (no kind 2150)')], [__('Match number'), __('reserved when you send')], [__('Sent as'), $me->shortNpub().' ('.$me->displayName().')']]" />
            </aside>
        </div>

        {{-- Mobile step bar --}}
        <div class="fixed inset-x-0 bottom-0 z-20 flex gap-3 bg-bar px-4 py-3 shadow-[0_-1px_0_#2A2A30] lg:hidden">
            <button type="button" x-on:click="step = Math.max(1, step - 1)" :disabled="step === 1" class="btn-w h-[52px] w-[112px] cursor-pointer rounded-md border border-line bg-well text-sm text-ink disabled:opacity-50">{{ __('Back') }}</button>
            <button type="button" x-show="step < 5" x-on:click="step++" class="btn-p h-[52px] grow cursor-pointer rounded-md border-0 bg-btc text-sm font-bold text-on-btc" data-test="next-step">
                <span x-text="[@js(__('Next: Opponent')), @js(__('Next: Rated or casual')), @js(__('Next: Times')), @js(__('Next: Send'))][step - 1]"></span>
            </button>
        </div>
    @endif
</div>
