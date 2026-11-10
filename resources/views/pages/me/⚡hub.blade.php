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
    $results = array_slice($hub->results(), 0, 6);
    $clan = $hub->clan();
    $looking = $hub->looking();
    $steps = $hub->steps();
    $stepsDone = count(array_filter($steps, fn (array $step): bool => $step['done']));
    $fresh = $stepsDone === 0 && $needs === [] && $going === [] && $later === [] && $tournaments === [];
    $nowMs = (int) now()->getTimestampMs();
    $soon = __('any moment');
    $outcomes = ['win' => __('Win'), 'loss' => __('Loss'), 'draw' => __('Draw')];
    // What waits on the player is the action dock's (ActionDock.dc.html): one line here names it and opens the dock.
    $needItems = array_values(array_filter(array_column($needs, 'item')));
    $needStates = collect($needItems)->pluck('state')->unique()->take(3)->implode(', ');
    // A tournament match the league decides on its own (P18, no dock entry) stays in the list with its countdown.
    $waits = array_values(array_filter($needs, fn (array $need): bool => $need['item'] === null));
    $since = $user->created_at?->year;
@endphp

{{--
    "Your page", /me (Profile.dc.html, ProfilePhone.dc.html; template T7; rules R4, R13): the face and name with one data
    line, the tabs of the player's pages, then on the left what waits (one line, the dock opens it), what runs and what
    comes, the ratings as one table and the last six results; on the right Looking to play, the clan and the settings.
--}}
<div class="flex flex-col gap-6 px-4 pt-4 pb-10 lg:gap-8 lg:px-12 lg:pt-8 lg:pb-14" data-test="me-hub">
    <div class="flex flex-col gap-6">
        {{-- Who: the face and name, and the way to the public player page. --}}
        <div class="flex items-center gap-4" data-test="me-identity">
            <x-avatar :user="$user" :size="64" class="size-14! rounded-full lg:size-16!" />
            <div class="flex min-w-0 flex-col gap-1">
                <h1 class="rv-h1 [overflow-wrap:anywhere] max-lg:text-[24px]">{{ $user->displayName() }}</h1>
                <span class="flex flex-wrap items-center gap-x-4 text-[13px] text-ink-2">
                    <span>{{ $since ? __('Player since :year', ['year' => $since]) : '' }}@if (! $clan['clan']), {{ __('no clan') }}@endif</span>
                    @if ($clan['clan'])
                        <a href="{{ route('clans.show', $clan['clan']) }}" class="inline-flex min-h-11 items-center gap-2 text-ink-2 hover:text-ink" data-test="me-identity-clan"><x-clan-tag :clan="$clan['clan']" size="sm" />{{ $clan['clan']->name }}</a>
                    @endif
                    <a href="{{ route('players.show', $user->npub) }}" class="rv-lk inline-flex min-h-11 items-center gap-1.5" data-test="me-public-page">{{ __('Your public page') }}</a>
                </span>
            </div>
        </div>
        <x-account.tabs active="overview" />
    </div>

    {{-- First steps: a brand-new player's page starts here. --}}
    @if ($fresh)
        @include('pages.me.partials.steps', ['steps' => $steps, 'done' => $stepsDone, 'lead' => true])
    @endif

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:gap-6">
        <div class="flex min-w-0 flex-col gap-8 lg:col-span-8">
            {{-- Needs you now: one line with the count and what it is, the dock opens it. --}}
            <section aria-labelledby="me-needs-h" class="flex flex-col gap-3" data-test="me-needs">
                <h2 id="me-needs-h" class="sr-only">{{ __('Needs you now') }}</h2>
                @if ($needItems === [])
                    <p class="m-0 flex min-h-11 flex-wrap items-center gap-x-4 gap-y-2 text-[13px] text-ink-2" data-test="me-needs-empty">
                        {{ __('Nothing waits for you.') }}
                        @unless ($fresh)
                            <a href="{{ route('chess.lobby') }}" class="inline-flex min-h-11 items-center gap-1.5 font-bold text-ink hover:text-ink"><x-icon name="bolt" :size="16" class="text-btc" />{{ __('Play live') }}</a>
                        @endunless
                    </p>
                @else
                    <div class="rv-card flex flex-wrap items-center gap-3 p-4">
                        <span class="rv-n is-r" data-test="me-needs-count">{{ count($needItems) }}</span>
                        <span class="min-w-0 grow text-[14px]">{{ trans_choice(':count action needs you: :what.|:count actions need you: :what.', count($needItems), ['what' => $needStates]) }}</span>
                        <button type="button" class="rv-b h-11" x-data x-on:click="window.dispatchEvent(new CustomEvent('dock-open'))" data-test="me-needs-open">{{ __('Open actions') }}</button>
                    </div>
                    {{-- Each one with its clock (and a tournament game's countdown to the league's decision): the banner alone hid when it runs out. --}}
                    <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2">
                        @foreach (array_filter($needs, fn (array $need): bool => $need['item'] !== null) as $need)
                            <li wire:key="need-{{ $need['item']->key }}">
                                @include('pages.me.partials.open-card', ['item' => $need['item'], 'wait' => $need['wait'], 'needs' => true])
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if (! $fresh && $stepsDone < count($steps))
                @include('pages.me.partials.steps', ['steps' => $steps, 'done' => $stepsDone, 'lead' => false])
            @endif

            {{-- Running and upcoming --}}
            <section aria-labelledby="me-going-h" class="flex min-w-0 flex-col gap-3" data-test="me-going">
                <h2 id="me-going-h" class="rv-h3">{{ __('Running and upcoming') }}</h2>
                @if ($going === [] && $later === [] && $tournaments === [] && $waits === [])
                    <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-going-empty">{{ __('No match or tournament ahead.') }} <a href="{{ route('tournaments.index') }}" class="inline-flex min-h-11 items-center font-bold text-ink hover:text-ink">{{ __('Find a tournament') }}</a></p>
                    {{-- The next open tournament as the picture (P53); the link above stays the one call to action. --}}
                    <x-tournaments.open-picture class="max-w-md" />
                @else
                    <ul class="rv-card m-0 flex list-none flex-col p-0">
                        @foreach ($waits as $need)
                            <li class="border-b border-hairline last:border-b-0" wire:key="wait-{{ $need['wait']->matchId }}">
                                @include('pages.me.partials.open-row', ['item' => null, 'wait' => $need['wait']])
                            </li>
                        @endforeach
                        @foreach ($tournaments as $entry)
                            @php($tournament = $entry['tournament'])
                            <li class="border-b border-hairline last:border-b-0" wire:key="t-{{ $tournament->id }}">
                                <a href="{{ route('tournaments.show', $tournament) }}" class="grid min-h-14 grid-cols-[64px_minmax(0,1fr)] items-center gap-3 px-3 py-2 text-ink hover:bg-row-hover hover:text-ink sm:grid-cols-[72px_minmax(0,1fr)_auto]" data-test="me-tournament" data-state="{{ $entry['state'] }}">
                                    <x-game-cover :game="$tournament->game" size="thumb" class="w-16 rounded-[2px] sm:w-[72px]" />
                                    <span class="flex min-w-0 flex-col gap-0.5">
                                        <b class="truncate text-[14px]">{{ $tournament->title() }}</b>
                                        <span class="truncate text-xs text-ink-2">{{ \App\Support\GameNames::full($tournament->game, $tournament->mode) }}, <x-tournaments.start :tournament="$tournament" /></span>
                                    </span>
                                    <span class="col-start-2 flex items-center gap-2 text-xs sm:col-start-3 sm:justify-end">
                                        @if ($entry['state'] === 'running')
                                            <span class="rv-live h-5"><i></i>{{ __('Being played now') }}</span>
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
                                <a href="{{ $row['href'] }}" class="flex min-h-14 items-center gap-3 px-3 py-2 text-ink hover:bg-row-hover hover:text-ink" data-test="me-later">
                                    @include('pages.me.partials.face', ['face' => $row['face'], 'clan' => $row['clan'], 'tag' => $row['tag'], 'size' => 40])
                                    <span class="flex min-w-0 grow flex-col gap-0.5">
                                        <b class="truncate text-[14px]">{{ $row['name'] }}</b>
                                        <span class="truncate text-xs text-ink-2">{{ $row['title'] }}</span>
                                    </span>
                                    <span class="rv-tag shrink-0">{{ $row['state'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- My ratings, one table (Profile.dc.html "Deine Ratings"). --}}
            <section aria-labelledby="me-ratings-h" class="flex min-w-0 flex-col gap-3" data-test="me-ratings">
                <h2 id="me-ratings-h" class="rv-h3">{{ __('Your ratings') }}</h2>
                @if ($ratings === [])
                    <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-ratings-empty">{{ __('Your rating shows here after your first result.') }}</p>
                @else
                    <div class="rv-card overflow-x-auto p-0">
                        <table class="rv-tb">
                            <thead><tr><th class="w-[72px]"><span class="sr-only">{{ __('Cover') }}</span></th><th>{{ __('Game') }}</th><th class="is-r">{{ __('Elo') }}</th><th class="max-sm:hidden">{{ __('Win record') }}</th><th><span class="sr-only">{{ __('Ladder') }}</span></th></tr></thead>
                            <tbody>
                                @foreach ($ratings as $standing)
                                    @php($rating = $standing['rating'])
                                    <tr wire:key="rating-{{ $standing['game'] }}-{{ $standing['mode'] }}-{{ $standing['lineup']?->id }}" data-test="me-rating" data-pool="{{ $rating['pool'] }}">
                                        <td><x-game-cover :game="$standing['game']" size="thumb" class="h-6 w-[43px] rounded-[2px] object-cover" /></td>
                                        <td class="whitespace-normal">{{ $standing['label'] }}@if ($standing['lineup']), {{ $standing['lineup']->clan->clantag }}@endif
                                            <span class="block text-xs text-ink-3">
                                                @if ($rating['pool'] === 'rated')
                                                    @php($badge = \App\Support\Rating\Ratings::badge($rating['tier']))
                                                    <x-rank-badge :tier="$badge['tier']" :level="$badge['level']" />
                                                @else
                                                    {{ __('Casual') }}@if ($rating['provisional']), {{ __('provisional') }}@endif
                                                @endif
                                            </span>
                                        </td>
                                        <td class="is-r"><b class="font-display text-[18px] tabular-nums" data-test="me-rating-value">{{ \App\Support\Formats::number($rating['rating']) }}</b></td>
                                        <td class="text-ink-2 tabular-nums max-sm:hidden">{{ __(':w W, :d D, :l L', ['w' => $rating['wins'], 'd' => $rating['draws'], 'l' => $rating['losses']]) }}</td>
                                        <td class="is-r"><a href="{{ $standing['href'] }}" class="rv-lk inline-flex min-h-11 items-center">{{ __('Ladder') }}</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Recent results: the last six, newest first (Profile.dc.html "Letzte Ergebnisse"). --}}
            <section aria-labelledby="me-results-h" class="flex flex-col gap-3" data-test="me-results">
                <h2 id="me-results-h" class="rv-h3">{{ __('Recent results') }}</h2>
                @if ($results === [])
                    <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-results-empty">{{ __('No results yet. Your last games show here.') }}</p>
                @else
                    <ol class="m-0 grid list-none grid-cols-3 gap-2 p-0 sm:grid-cols-6 lg:gap-3" data-test="me-chain">
                        @foreach ($results as $result)
                            <li class="min-w-0" wire:key="{{ $result['key'] }}">
                                <a href="{{ $result['href'] }}" @class(['me-tile', 'is-win' => $result['outcome'] === 'win', 'is-loss' => $result['outcome'] === 'loss'])
                                   data-test="me-result" data-outcome="{{ $result['outcome'] }}"
                                   aria-label="{{ __(':outcome against :name, :score, :game', ['outcome' => $outcomes[$result['outcome']], 'name' => $result['opponent'], 'score' => $result['score'], 'game' => $result['game']]) }}">
                                    @include('pages.me.partials.face', ['face' => $result['face'], 'clan' => $result['clan'], 'tag' => $result['tag'], 'size' => 40])
                                    <span class="w-full truncate text-xs text-ink-2">{{ $result['opponent'] }}</span>
                                    <b @class(['font-display text-[18px] leading-none whitespace-nowrap tabular-nums', 'text-win' => $result['outcome'] === 'win', 'text-loss' => $result['outcome'] === 'loss'])>{{ $result['score'] }}</b>
                                    <span class="flex items-center gap-1 text-[11px] text-ink-3">
                                        {{ $outcomes[$result['outcome']] }}
                                        @if ($result['delta'] !== null)
                                            <span class="tabular-nums" data-test="me-result-delta">{{ $result['delta'] > 0 ? '+'.$result['delta'] : ($result['delta'] < 0 ? '−'.abs($result['delta']) : '±0') }}</span>
                                        @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <div class="flex min-w-0 flex-col gap-8 lg:col-span-4">
            {{-- Looking to play, per game --}}
            <section aria-labelledby="me-looking-h" class="flex min-w-0 flex-col gap-3" data-test="me-looking">
                <h2 id="me-looking-h" class="rv-h3">{{ __('Looking to play') }}</h2>
                <ul class="rv-card m-0 flex list-none flex-col p-0">
                    @foreach ($looking as $row)
                        <li class="border-b border-hairline last:border-b-0">
                            <a href="{{ $row['href'] }}" class="flex min-h-12 items-center gap-3 px-3 py-2 text-[14px] text-ink hover:bg-row-hover hover:text-ink" data-test="me-looking-row" data-game="{{ $row['game'] }}" data-on="{{ $row['on'] ? 'true' : 'false' }}">
                                <x-game-cover :game="$row['game']" size="thumb" class="h-6 w-[43px] shrink-0 rounded-[2px] object-cover" />
                                <span class="min-w-0 grow truncate">{{ $row['name'] }}</span>
                                @if ($row['on'])
                                    <span class="inline-flex shrink-0 items-center gap-1.5 text-[13px] font-bold text-win"><span class="size-2 rounded-full bg-win" aria-hidden="true"></span>{{ __('On') }}</span>
                                @else
                                    <span class="shrink-0 text-[13px] text-ink-3">{{ __('Off') }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
                <p class="m-0 text-xs leading-normal text-ink-3">{{ __('You look for one game at a time. Switch it where the game is played.') }}</p>
            </section>

            {{-- Clan and lineups --}}
            <section aria-labelledby="me-clan-h" class="flex min-w-0 flex-col gap-3" data-test="me-clan">
                <h2 id="me-clan-h" class="rv-h3">{{ __('Your clan') }}</h2>
                @if ($clan['clan'])
                    <div class="rv-card flex flex-col p-0">
                        <a href="{{ route('clans.show', $clan['clan']) }}" class="flex min-h-14 items-center gap-3 px-3 py-2 text-ink hover:bg-row-hover hover:text-ink" data-test="me-clan-link">
                            <x-clan-tag :clan="$clan['clan']" :tile="40" class="flex size-10 shrink-0 items-center justify-center rounded-[4px] bg-btc-tint text-[11px] font-bold text-btc" />
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <b class="truncate text-[14px]">{{ $clan['clan']->name }}</b>
                                <span class="text-xs text-ink-2">{{ trans_choice(':count member|:count members', $clan['clan']->members_count) }}</span>
                            </span>
                        </a>
                        @foreach ($clan['lineups'] as $lineup)
                            <a href="{{ route('clans.show', $clan['clan']) }}#lineups" class="flex min-h-12 items-center gap-3 border-t border-hairline px-3 py-2 text-ink hover:bg-row-hover hover:text-ink" data-test="me-lineup" wire:key="lineup-{{ $lineup->id }}">
                                <span class="w-16 shrink-0 text-xs text-ink-2">{{ (app(\App\Games\GameRegistry::class)->find($lineup->game)?->assets()->shortLabel ?? $lineup->game).' '.$lineup->mode }}</span>
                                <span class="flex min-w-0 grow -space-x-1.5" aria-label="{{ trans_choice(':count player|:count players', $lineup->seats->count()) }}">
                                    @foreach ($lineup->seats->take(6) as $seat)
                                        <x-avatar :user="$seat->user" :size="28" class="rounded-full shadow-[0_0_0_2px_var(--color-card)]" />
                                    @endforeach
                                </span>
                            </a>
                        @endforeach
                        @if ($clan['captain'])
                            <a href="{{ route('clans.manage', $clan['clan']) }}" class="flex min-h-11 items-center gap-2 border-t border-hairline px-3 text-[13px] text-ink-2 hover:text-ink" data-test="me-clan-manage"><x-icon name="settings" :size="16" />{{ __('Manage clan') }}</a>
                        @endif
                    </div>
                @elseif ($clan['invite'])
                    <a href="{{ route('invites.show', $clan['invite']) }}" class="rv-card flex min-h-14 items-center gap-3 border-btc-ring bg-btc-chip px-3 py-2 text-ink hover:text-ink" data-test="me-clan-invite">
                        <x-clan-tag :clan="$clan['invite']->clan" :tile="40" class="flex size-10 shrink-0 items-center justify-center rounded-[4px] bg-btc-tint text-[11px] font-bold text-btc" />
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <b class="truncate text-[14px]">{{ $clan['invite']->clan->name }}</b>
                            <span class="text-xs text-btc-hi">{{ __('Invited you') }}</span>
                        </span>
                        <span class="rv-b is-sm shrink-0">{{ __('Answer') }}</span>
                    </a>
                @else
                    <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="me-clan-empty">{{ __('Series are played by clan lineups.') }}</p>
                    <a href="{{ route('clans.index') }}" @navigate(route('clans.index')) class="rv-b2 self-start" data-test="me-clan-find">{{ __('Find a clan') }}</a>
                @endif
            </section>

            {{-- Settings shortcuts --}}
            <section aria-labelledby="me-settings-h" class="flex min-w-0 flex-col gap-3" data-test="me-settings">
                <h2 id="me-settings-h" class="rv-h3">{{ __('Settings') }}</h2>
                <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 min-[400px]:grid-cols-2 lg:grid-cols-1">
                    @foreach ([
                        ['bolt', __('Name and Lightning'), route('settings.profile'), 'me-settings-profile'],
                        ['bell', __('Notifications'), route('settings.notifications'), 'me-settings-notifications'],
                        ['user', __('Gamer tags'), route('gaming.edit'), 'me-settings-tags'],
                        ['clans', __('Opponents'), route('settings.opponents'), 'me-settings-opponents'],
                        ['award', __('Badges and sharing'), route('settings.badges'), 'me-settings-badges'],
                    ] as [$icon, $label, $href, $test])
                        <li class="min-w-0">
                            <a href="{{ $href }}" @navigate($href) class="rv-card flex h-full min-h-14 items-center gap-2.5 px-3 py-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink" data-test="{{ $test }}">
                                <x-icon :name="$icon" :size="16" class="shrink-0 text-ink-2" /><span class="min-w-0 [overflow-wrap:anywhere]" data-test="me-settings-label">{{ $label }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Rule R7: the secondary features, last in the right column. P47: who of the player's Nostr follows plays here. --}}
            <livewire:follows-here context="me" />
        </div>
    </div>

    {{-- The invite module is built for the full width (its heading and line stay on one line each): below both columns. --}}
    <livewire:invite-link place="me" />
</div>
