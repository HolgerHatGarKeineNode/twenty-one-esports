<?php

use App\Models\User;
use App\Support\Engagement\PlayerHub;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Your page", /me (P30): the player's own hub. Order by what it asks of
 * them: what needs them now (the match dock's own list, with P18's
 * countdown to the league's automatic decision on a tournament match),
 * then what runs and what comes (live and waiting matches, scheduled
 * series, sent challenges, tournaments with their start), then where they
 * stand (ratings per game and mode, the last ten results as a chain of
 * faces), and last who they play with and how the league reaches them
 * (clan and lineups, Looking to play, settings, the invite link).
 *
 * A brand-new player sees the three first steps at the top instead of an
 * empty page; each step ticks off once it is done, and the list goes once
 * all three are.
 *
 * Private: no PageMeta::describe(), so it stays noindex like the settings.
 *
 * Read-only apart from the countdowns: at zero they ask for one fresh render
 * (resources/js/autoDecision.js), so what the league decided shows.
 */
new #[Title('Your page')] #[Layout('layouts::app')] class extends Component {
    #[Computed]
    public function hub(): PlayerHub
    {
        return new PlayerHub($this->user());
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
};
?>

@php
    $user = auth()->user();
    $hub = $this->hub;
    $needs = $hub->needs();
    $going = $hub->going();
    $later = $hub->later();
    $tournaments = $hub->tournaments();
    $ratings = $hub->ratings();
    $results = $hub->results();
    $clan = $hub->clan();
    $looking = $hub->looking();
    $steps = $hub->steps();
    $stepsDone = count(array_filter($steps, fn (array $step): bool => $step['done']));
    $fresh = $stepsDone === 0 && $needs === [] && $going === [] && $later === [] && $tournaments === [];
    $nowMs = (int) now()->getTimestampMs();
    $soon = __('any moment');
    $outcomes = ['win' => __('Win'), 'loss' => __('Loss'), 'draw' => __('Draw')];
@endphp

<div class="flex flex-col gap-8 px-4 pb-10 lg:gap-12 lg:px-12 lg:pb-14" data-test="me-hub">
    {{-- Who: the face and name, and the way to the public player page. --}}
    <div class="flex items-center gap-3 lg:gap-4" data-test="me-identity">
        <x-avatar :user="$user" :size="56" class="size-12! rounded-lg lg:size-14!" />
        <div class="flex min-w-0 flex-col gap-1">
            <h1 class="m-0 font-display text-[22px] leading-tight font-bold [overflow-wrap:anywhere] lg:text-[28px]">{{ $user->displayName() }}</h1>
            <span class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px] text-ink-2">
                @if ($clan['clan'])
                    <a href="{{ route('clans.show', $clan['clan']) }}" class="inline-flex min-h-11 items-center gap-2 text-ink-2 hover:text-ink" data-test="me-identity-clan"><x-clan-tag :clan="$clan['clan']" size="sm" />{{ $clan['clan']->name }}</a>
                @endif
                <a href="{{ route('players.show', $user->npub) }}" class="inline-flex min-h-11 items-center gap-1.5 text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc" data-test="me-public-page"><x-icon name="user" :size="14" />{{ __('Your public page') }}</a>
            </span>
        </div>
    </div>

    {{-- First steps: a brand-new player's page starts here. --}}
    @if ($fresh)
        @include('pages.me.partials.steps', ['steps' => $steps, 'done' => $stepsDone, 'lead' => true])
    @endif

    {{-- Needs you now --}}
    <section aria-labelledby="me-needs-h" class="flex flex-col gap-4" data-test="me-needs">
        <h2 id="me-needs-h" class="m-0 flex items-center gap-3 font-display text-lg font-bold lg:text-xl">
            {{ __('Needs you now') }}
            @if ($needs !== [])
                <span class="inline-flex h-6 min-w-6 items-center justify-center rounded-tag bg-btc px-1.5 font-sans text-[13px] font-bold text-on-btc" data-test="me-needs-count">{{ count($needs) }}</span>
            @endif
        </h2>
        @if ($needs === [])
            <p class="m-0 flex min-h-11 flex-wrap items-center gap-x-4 gap-y-2 text-[13px] text-ink-2" data-test="me-needs-empty">
                {{ __('Nothing waits for you.') }}
                @unless ($fresh)
                    <a href="{{ route('chess.lobby') }}" class="inline-flex min-h-11 items-center gap-1.5 font-bold text-ink hover:text-ink"><x-icon name="bolt" :size="16" class="text-btc" />{{ __('Play blitz') }}</a>
                @endunless
            </p>
        @else
            <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($needs as $need)
                    <li wire:key="need-{{ $need['item']?->key ?? 'wait-'.$need['wait']->matchId }}">
                        @include('pages.me.partials.open-card', ['item' => $need['item'], 'wait' => $need['wait'], 'needs' => true])
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if (! $fresh && $stepsDone < count($steps))
        @include('pages.me.partials.steps', ['steps' => $steps, 'done' => $stepsDone, 'lead' => false])
    @endif

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:gap-6">
        {{-- Running and upcoming --}}
        <section aria-labelledby="me-going-h" class="flex min-w-0 flex-col gap-4 lg:col-span-7" data-test="me-going">
            <h2 id="me-going-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Running and upcoming') }}</h2>
            @if ($going === [] && $later === [] && $tournaments === [])
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-going-empty">{{ __('No match or tournament ahead.') }} <a href="{{ route('tournaments.index') }}" class="inline-flex min-h-11 items-center font-bold text-ink hover:text-ink">{{ __('Find a tournament') }}</a></p>
            @else
                <ul class="m-0 flex list-none flex-col rounded-card bg-card p-0 shadow-ring">
                    @foreach ($tournaments as $entry)
                        @php($tournament = $entry['tournament'])
                        <li class="border-b border-hairline last:border-b-0" wire:key="t-{{ $tournament->id }}">
                            <a href="{{ route('tournaments.show', $tournament) }}" class="grid min-h-16 grid-cols-[64px_minmax(0,1fr)] items-center gap-3 px-3 py-2.5 text-ink hover:bg-row-hover hover:text-ink sm:grid-cols-[80px_minmax(0,1fr)_auto]" data-test="me-tournament" data-state="{{ $entry['state'] }}">
                                <x-game-cover :game="$tournament->game" size="thumb" class="w-16 rounded-tag sm:w-20" />
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="truncate text-[13px]">{{ $tournament->name }}</b>
                                    <span class="truncate text-xs text-ink-2">{{ \App\Support\GameNames::full($tournament->game, $tournament->mode) }}, <x-league-time :at="$tournament->starts_at" /></span>
                                </span>
                                <span class="col-start-2 flex items-center gap-2 text-xs sm:col-start-3 sm:justify-end">
                                    @if ($entry['state'] === 'running')
                                        <span class="inline-flex items-center gap-1.5 font-bold text-btc-hi"><span class="size-1.5 animate-live rounded-full bg-btc" aria-hidden="true"></span>{{ __('Being played now') }}</span>
                                    @elseif ($entry['startsIn'] !== null)
                                        <span class="text-ink-2">{{ $entry['state'] === 'drawing' ? __('Draw pending, starts in') : __('Signed up, starts in') }}</span>
                                        <b class="font-bold text-ink tabular-nums" role="timer" data-test="me-tournament-clock"
                                           x-data="autoDecision({ at: {{ $entry['startsIn']['ms'] }}, now: {{ $nowMs }}, soon: @js($soon) })" x-text="text">{{ \App\Support\Engagement\PlayerHub::clock($entry['startsIn']['ms'] - $nowMs) }}</b>
                                    @else
                                        <span class="text-ink-2">{{ $entry['state'] === 'drawing' ? __('Waiting for the draw') : __('Signed up') }}</span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                    @foreach ($going as $open)
                        <li class="border-b border-hairline last:border-b-0" wire:key="going-{{ $open['item']?->key ?? 'wait-'.$open['wait']->matchId }}">
                            @include('pages.me.partials.open-row', ['item' => $open['item'], 'wait' => $open['wait']])
                        </li>
                    @endforeach
                    @foreach ($later as $row)
                        <li class="border-b border-hairline last:border-b-0" wire:key="{{ $row['key'] }}">
                            <a href="{{ $row['href'] }}" class="flex min-h-16 items-center gap-3 px-3 py-2.5 text-ink hover:bg-row-hover hover:text-ink" data-test="me-later">
                                @include('pages.me.partials.face', ['face' => $row['face'], 'clan' => $row['clan'], 'tag' => $row['tag'], 'size' => 40])
                                <span class="flex min-w-0 grow flex-col gap-0.5">
                                    <b class="truncate text-[13px]">{{ $row['name'] }}</b>
                                    <span class="truncate text-xs text-ink-2">{{ $row['title'] }}</span>
                                </span>
                                <span class="shrink-0 text-right text-xs text-ink-2">{{ $row['state'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- My ratings --}}
        <section aria-labelledby="me-ratings-h" class="flex min-w-0 flex-col gap-4 lg:col-span-5" data-test="me-ratings">
            <h2 id="me-ratings-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Your ratings') }}</h2>
            @if ($ratings === [])
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-ratings-empty">{{ __('Your rating shows here after your first result.') }}</p>
            @else
                <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2">
                    @foreach ($ratings as $standing)
                        @php($rating = $standing['rating'])
                        <li wire:key="rating-{{ $standing['game'] }}-{{ $standing['mode'] }}-{{ $standing['lineup']?->id }}">
                            <a href="{{ $standing['href'] }}" class="flex h-full min-h-24 flex-col gap-2 rounded-card bg-card p-3 text-ink shadow-ring hover:bg-row-hover hover:text-ink lg:p-4" data-test="me-rating" data-pool="{{ $rating['pool'] }}">
                                <span class="flex min-w-0 items-center gap-2 text-xs text-ink-2">
                                    <x-game-cover :game="$standing['game']" size="thumb" class="w-8 shrink-0 rounded-xs" />
                                    <span class="truncate">{{ $standing['label'] }}@if ($standing['lineup']), {{ $standing['lineup']->clan->clantag }}@endif</span>
                                </span>
                                <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <b class="font-display text-2xl leading-none font-bold tabular-nums" data-test="me-rating-value">{{ $rating['rating'] }}</b>
                                    @if ($rating['pool'] === 'rated')
                                        @php($badge = \App\Support\Rating\Ratings::badge($rating['tier']))
                                        <x-rank-badge :tier="$badge['tier']" :level="$badge['level']" />
                                    @else
                                        <span class="text-xs text-ink-2">{{ __('Casual') }}@if ($rating['provisional']), {{ __('provisional') }}@endif</span>
                                    @endif
                                </span>
                                <span class="text-xs text-ink-3">{{ __(':w won, :d drawn, :l lost', ['w' => $rating['wins'], 'd' => $rating['draws'], 'l' => $rating['losses']]) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- Recent results: a chain of the last ten, newest first --}}
    <section aria-labelledby="me-results-h" class="flex flex-col gap-4" data-test="me-results">
        <h2 id="me-results-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Recent results') }}</h2>
        @if ($results === [])
            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-results-empty">{{ __('No results yet. Your last ten games show here.') }}</p>
        @else
            {{-- Below lg the chain scrolls sideways inside itself, like the game chips; from lg all ten stand in one row. --}}
            <ol class="me-chain m-0 flex list-none gap-3 overflow-x-auto p-0 pb-1 [scrollbar-width:none] max-lg:-mr-4 max-lg:snap-x max-lg:pr-4 lg:grid lg:grid-cols-10 lg:overflow-visible" data-test="me-chain">
                @foreach ($results as $result)
                    <li class="w-24 min-w-0 shrink-0 snap-start lg:w-auto" wire:key="{{ $result['key'] }}">
                        <a href="{{ $result['href'] }}" @class(['me-block relative flex h-full min-w-0 flex-col items-center gap-1.5 rounded-card bg-card px-2 pt-3 pb-2.5 text-center text-ink hover:bg-row-hover hover:text-ink', 'me-block-win' => $result['outcome'] === 'win', 'me-block-loss' => $result['outcome'] === 'loss', 'me-block-draw' => $result['outcome'] === 'draw'])
                           data-test="me-result" data-outcome="{{ $result['outcome'] }}"
                           aria-label="{{ __(':outcome against :name, :score, :game', ['outcome' => $outcomes[$result['outcome']], 'name' => $result['opponent'], 'score' => $result['score'], 'game' => $result['game']]) }}">
                            @include('pages.me.partials.face', ['face' => $result['face'], 'clan' => $result['clan'], 'tag' => $result['tag'], 'size' => 44])
                            <span class="w-full truncate text-xs text-ink-2">{{ $result['opponent'] }}</span>
                            <b class="font-display text-base leading-none font-bold whitespace-nowrap tabular-nums">{{ $result['score'] }}</b>
                            <span class="flex items-center gap-1 text-[11px] leading-4">
                                <span @class(['font-bold', 'text-win' => $result['outcome'] === 'win', 'text-loss' => $result['outcome'] === 'loss', 'text-ink-2' => $result['outcome'] === 'draw'])>{{ $outcomes[$result['outcome']] }}</span>
                                @if ($result['delta'] !== null)
                                    <span class="text-ink-3 tabular-nums" data-test="me-result-delta">{{ $result['delta'] > 0 ? '+'.$result['delta'] : ($result['delta'] < 0 ? '−'.abs($result['delta']) : '±0') }}</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3 lg:gap-6">
        {{-- Clan and lineups --}}
        <section aria-labelledby="me-clan-h" class="flex min-w-0 flex-col gap-4" data-test="me-clan">
            <h2 id="me-clan-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Your clan') }}</h2>
            @if ($clan['clan'])
                <div class="flex flex-col rounded-card bg-card shadow-ring">
                    <a href="{{ route('clans.show', $clan['clan']) }}" class="flex min-h-16 items-center gap-3 px-3 py-2.5 text-ink hover:bg-row-hover hover:text-ink" data-test="me-clan-link">
                        <x-clan-tag :clan="$clan['clan']" :tile="40" class="flex size-10 shrink-0 items-center justify-center rounded-md bg-btc-tint text-[11px] font-bold text-btc" />
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <b class="truncate text-[13px]">{{ $clan['clan']->name }}</b>
                            <span class="text-xs text-ink-2">{{ trans_choice(':count member|:count members', $clan['clan']->members_count) }}</span>
                        </span>
                    </a>
                    @foreach ($clan['lineups'] as $lineup)
                        <a href="{{ route('clans.show', $clan['clan']) }}#lineups" class="flex min-h-12 items-center gap-3 border-t border-hairline px-3 py-2 text-ink hover:bg-row-hover hover:text-ink" data-test="me-lineup" wire:key="lineup-{{ $lineup->id }}">
                            <span class="w-16 shrink-0 text-xs text-ink-2">{{ (app(\App\Games\GameRegistry::class)->find($lineup->game)?->assets()->shortLabel ?? $lineup->game).' '.$lineup->mode }}</span>
                            <span class="flex min-w-0 grow -space-x-1.5" aria-label="{{ trans_choice(':count player|:count players', $lineup->seats->count()) }}">
                                @foreach ($lineup->seats->take(6) as $seat)
                                    <x-avatar :user="$seat->user" :size="28" class="rounded-tag shadow-[0_0_0_2px_var(--color-card)]" />
                                @endforeach
                            </span>
                        </a>
                    @endforeach
                    @if ($clan['captain'])
                        <a href="{{ route('clans.manage', $clan['clan']) }}" class="flex min-h-11 items-center gap-2 border-t border-hairline px-3 text-[13px] text-ink-2 hover:text-ink" data-test="me-clan-manage"><x-icon name="settings" :size="16" />{{ __('Manage clan') }}</a>
                    @endif
                </div>
            @elseif ($clan['invite'])
                <a href="{{ route('invites.show', $clan['invite']) }}" class="flex min-h-16 items-center gap-3 rounded-card bg-btc-chip px-3 py-2.5 text-ink shadow-ring-btc hover:text-ink" data-test="me-clan-invite">
                    <x-clan-tag :clan="$clan['invite']->clan" :tile="40" class="flex size-10 shrink-0 items-center justify-center rounded-md bg-btc-tint text-[11px] font-bold text-btc" />
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <b class="truncate text-[13px]">{{ $clan['invite']->clan->name }}</b>
                        <span class="text-xs text-btc-hi">{{ __('Invited you') }}</span>
                    </span>
                    <span class="inline-flex h-8 shrink-0 items-center rounded-control bg-btc px-3 text-xs font-bold text-on-btc">{{ __('Answer') }}</span>
                </a>
            @else
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-clan-empty">{{ __('Series are played by clan lineups.') }}</p>
                <span class="flex flex-wrap gap-3">
                    <x-button :href="route('clans.index')" variant="secondary">{{ __('Find a clan') }}</x-button>
                    <x-button :href="route('clans.create')" variant="quiet">{{ __('Start a clan') }}</x-button>
                </span>
            @endif
        </section>

        {{-- Looking to play, per game --}}
        <section aria-labelledby="me-looking-h" class="flex min-w-0 flex-col gap-4" data-test="me-looking">
            <h2 id="me-looking-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Looking to play') }}</h2>
            <ul class="m-0 flex list-none flex-col rounded-card bg-card p-0 shadow-ring">
                @foreach ($looking as $row)
                    <li class="border-b border-hairline last:border-b-0">
                        <a href="{{ $row['href'] }}" class="flex min-h-12 items-center gap-3 px-3 py-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink" data-test="me-looking-row" data-game="{{ $row['game'] }}" data-on="{{ $row['on'] ? 'true' : 'false' }}">
                            <x-game-cover :game="$row['game']" size="thumb" class="w-10 shrink-0 rounded-xs" />
                            <span class="min-w-0 grow truncate">{{ $row['name'] }}</span>
                            @if ($row['on'])
                                <span class="inline-flex shrink-0 items-center gap-1.5 font-bold text-win"><span class="size-2 rounded-full bg-win" aria-hidden="true"></span>{{ __('On') }}</span>
                            @else
                                <span class="shrink-0 text-ink-3">{{ __('Off') }}</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="m-0 text-xs leading-normal text-ink-3">{{ __('You look for one game at a time. Switch it where the game is played.') }}</p>
        </section>

        {{-- Settings shortcuts --}}
        <section aria-labelledby="me-settings-h" class="flex min-w-0 flex-col gap-4" data-test="me-settings">
            <h2 id="me-settings-h" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Settings') }}</h2>
            <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2">
                @foreach ([
                    ['bell', __('Notifications'), route('settings.notifications'), 'me-settings-notifications'],
                    ['user', __('Gamer tags'), route('gaming.edit'), 'me-settings-tags'],
                    ['clans', __('Opponents'), route('settings.opponents'), 'me-settings-opponents'],
                    ['award', __('Badges and sharing'), route('settings.badges'), 'me-settings-badges'],
                ] as [$icon, $label, $href, $test])
                    <li>
                        <a href="{{ $href }}" class="flex h-full min-h-12 items-center gap-2.5 rounded-card bg-card px-3 py-2.5 text-[13px] text-ink shadow-ring hover:bg-row-hover hover:text-ink sm:min-h-16" data-test="{{ $test }}">
                            <x-icon :name="$icon" :size="18" class="shrink-0 text-ink-2" /><span class="min-w-0" data-test="me-settings-label">{{ $label }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    {{-- Bring a friend --}}
    <livewire:invite-link place="me" />
</div>
