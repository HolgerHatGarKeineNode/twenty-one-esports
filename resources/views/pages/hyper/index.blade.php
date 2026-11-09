{{--
    Hyperbitcoinization's lobby through the one lobby template T1 (x-game.page; HyperIndex.dc.html, rules R5, R6, R19):
    game bar, hero with "Gegen Bots spielen", then the open tables (components/⚡hyper-lobby: a new table or the
    player's own, then the open tables; a table's own link `tables/{ulid}` shows that table first), the viewer's
    running matches and moments; the right column holds the game's chat (R6), the quick match against bots, the game
    in short, season and cups, and the music. The quick start is a plain form: posting into a new tab is no popup a
    browser could block (HyperMatchController::quick() sends it on). Every match opens in a new tab.
--}}
@php
    $labels = ['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => __('ECB'), 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'];
    $portrait = \App\Support\Hyper\HyperGame::FACTIONS;
    // Indexed, with its own link preview (P6): the cover, the game in one line, running and played matches.
    app(\App\Support\PageMeta::class)->describe('Hyperbitcoinization', __('Hyperbitcoinization, the strategy game of the TWENTY ONE esports league: Risk with currency spaces for 2 to 6 players, live or by correspondence, alone or clan against clan. Topple the central banks, collect sats as loot.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('hyper'));
    $chip = 'flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-well font-bold text-ink-2 shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:text-ink has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-btc';
@endphp
<x-layouts::app :title="'Hyperbitcoinization'" :scripts="['resources/js/gameChannel.js']">
    <x-game.page :game="\App\Games\Hyperbitcoinization::SLUG" :mode="__('Live')" :play-label="$viewer !== null ? __('Play') : __('Log in to play')" :sticky-rail="false" data-test="hyper-index"
                 :lede="implode(' · ', [__('2 to 6 players'), __('10 currency spaces'), __('Topple central banks'), __(':seconds s per turn', ['seconds' => (int) config('esports.hyper.turn_seconds', 90)])])">
        <x-slot:primary>
            @if ($viewer !== null)
                <button type="submit" form="hyper-quick-form" class="rv-b" data-test="game-primary">{{ __('Play') }}</button>
            @else
                <a href="{{ route('login') }}" class="rv-b" data-test="game-primary">{{ __('Log in to play') }}</a>
            @endif
        </x-slot:primary>
        <x-slot:heroAside>
            @if ($viewer !== null)
                <button type="submit" form="hyper-quick-form" class="rv-b h-[52px] px-4" data-test="hyper-hero-bots">{{ __('Play against bots') }}</button>
            @else
                <a href="{{ route('login') }}" class="rv-b h-[52px] px-4" data-test="hyper-hero-bots">{{ __('Log in to play') }}</a>
            @endif
        </x-slot:heroAside>

        {{-- A new table and the open tables (components/⚡hyper-lobby carries its own "Offene Tische" heading). --}}
        <x-slot:lead>
            <section class="gp-panel flex flex-col gap-3 p-4" aria-label="{{ __('Open tables') }}" data-test="hyper-tables">
                <livewire:hyper-lobby :focus="$focus" />
            </section>
        </x-slot:lead>

        @if ($viewer !== null)
            <section class="flex flex-col gap-3" aria-labelledby="hyper-running-h" data-test="hyper-running">
                <h2 id="hyper-running-h" class="gp-h">{{ __('Your running matches') }}</h2>
                @if ($running->isEmpty())
                    <p class="gp-panel m-0 px-4 py-4 text-[13px] text-ink-2">{{ __('No match running. Start one above.') }}</p>
                @else
                    <ul class="m-0 grid list-none gap-3 p-0 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($running as $match)
                            @php
                                $mine = $match->seatOf($viewer);
                                $round = (int) ($match->state['round'] ?? 1);
                                $turn = $match->seats->firstWhere('seat', $match->current_seat);
                                $myTurn = $turn !== null && $mine !== null && $turn->seat === $mine->seat;
                            @endphp
                            <li>
                                <a href="{{ route('hyper.match', $match) }}" target="_blank" class="flex items-center gap-3 gp-panel p-3 text-ink hover:bg-row-hover hover:text-ink" data-test="hyper-running-match">
                                    <img src="/hyper/art/por-{{ $portrait[$mine?->faction ?? 'bitcoiner'] }}.jpg?v=1" alt="" width="44" height="44" class="size-11 shrink-0 rounded-full object-cover shadow-ring" loading="lazy">
                                    <span class="flex min-w-0 grow flex-col gap-0.5">
                                        <b class="truncate text-sm">{{ __('Round :round · :seats seats', ['round' => $round, 'seats' => $match->seats->count()]) }}@if ($match->isCorrespondence()) · {{ __('Correspondence') }}@endif</b>
                                        <span @class(['truncate text-xs', 'font-bold text-btc-hi' => $myTurn, 'text-ink-2' => ! $myTurn])>{{ $myTurn ? __('Your turn') : __('Started :time', ['time' => $match->created_at?->diffForHumans()]) }}</span>
                                    </span>
                                    <x-icon name="expand" :size="16" class="shrink-0 text-ink-2" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        @if ($moments !== [])
            {{-- The viewer's latest wins and loot (P6, HyperMoments): each one to post on Nostr or to save as the match's card. --}}
            <section id="hyper-moments" class="flex scroll-mt-24 flex-col gap-3" aria-labelledby="hyper-moments-h" data-test="hyper-moments">
                <h2 id="hyper-moments-h" class="gp-h">{{ __('Your moments') }}</h2>
                <ul class="m-0 grid list-none gap-3 p-0 lg:grid-cols-2">
                    @foreach ($moments as ['match' => $match, 'moment' => $moment])
                        @php
                            $sats = rtrim(rtrim(number_format($moment['sats'], 1, '.', ''), '0'), '.');
                        @endphp
                        <li class="flex min-w-0 flex-col gap-3 gp-panel p-3" data-test="hyper-moment" data-kind="{{ $moment['kind'] }}" wire:key="moment-{{ $match->ulid }}">
                            <a href="{{ route('hyper.match', $match) }}" target="_blank" class="flex min-w-0 items-center gap-3 text-ink hover:text-ink">
                                <x-game-cover :game="\App\Games\Hyperbitcoinization::SLUG" size="thumb" class="w-16 shrink-0 rounded-tag" />
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="truncate text-sm">{{ match ($moment['kind']) { 'team' => __(':team wins the :size', ['team' => (string) $moment['team'], 'size' => $moment['size']]), 'win' => __('You won against :count', ['count' => $moment['opponents']]), default => __(':sats M sats loot', ['sats' => '+'.$sats]) } }}</b>
                                    <span class="truncate text-xs text-ink-2">{{ ($match->isCorrespondence() ? __('Correspondence') : __('Live')).' · '.__('Round :round', ['round' => (int) ($match->state['round'] ?? 1)]).' · '.$match->ended_at?->diffForHumans() }}</span>
                                </span>
                            </a>
                            <livewire:share-button type="hyper" :moment="$match->ulid" :label="$moment['kind'] === 'sats' ? __('Post the loot') : __('Post the win')" :wire:key="'hyper-share-'.$match->ulid" />
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <x-slot:chat>
            {{-- The game's public chat (plan "Proof of Pong", P5): first in the right column (rule R6). --}}
            <livewire:game-channel :game="\App\Games\Hyperbitcoinization::SLUG" wire:key="game-channel-hyperbitcoinization" />

            @if ($viewer !== null)
            {{-- Straight into a live match against bots, no table: the P2 quick start, into a new tab --}}
                <section class="gp-panel flex flex-col gap-3 p-4" aria-labelledby="hyper-quick-h">
                    <h2 id="hyper-quick-h" class="gp-h">{{ __('Quick match against bots') }}</h2>
                    <form id="hyper-quick-form" method="post" action="{{ route('hyper.quick') }}" target="_blank" class="flex flex-col gap-4" data-test="hyper-quick">
                        @csrf
                        <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                            <legend class="mb-2 text-[14px] text-ink">{{ __('Your faction') }}</legend>
                            <div class="grid grid-cols-4 gap-2">
                                @foreach ($factions as $faction)
                                    <label class="{{ $chip }} min-h-[76px] flex-col gap-1 px-1 text-[11px]">
                                        <input type="radio" name="faction" value="{{ $faction }}" class="sr-only" @checked(old('faction', 'bitcoiner') === $faction)>
                                        <img src="/hyper/art/por-{{ $portrait[$faction] }}.jpg?v=1" alt="" width="40" height="40" class="size-10 rounded-full object-cover shadow-ring" loading="lazy">
                                        <span class="max-w-full truncate">{{ $labels[$faction] }}</span>
                                    </label>
                                @endforeach
                                <label class="{{ $chip }} min-h-[76px] flex-col gap-1 px-1 text-[11px]">
                                    <input type="radio" name="faction" value="" class="sr-only" @checked(old('faction') === '')>
                                    <span class="grid size-10 place-items-center rounded-full bg-ground text-xl shadow-ring" aria-hidden="true">🎲</span>
                                    <span>{{ __('Random') }}</span>
                                </label>
                            </div>
                        </fieldset>
                        <div class="flex flex-wrap gap-x-6 gap-y-4">
                            <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                                <legend class="mb-2 text-[14px] text-ink">{{ __('Bots') }}</legend>
                                <div class="flex gap-1.5">
                                    @foreach (range(1, 5) as $bots)
                                        <label class="{{ $chip }} min-w-11 px-3 text-[13px]"><input type="radio" name="bots" value="{{ $bots }}" class="sr-only" @checked((int) old('bots', 3) === $bots)>{{ $bots }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                                <legend class="mb-2 text-[14px] text-ink">{{ __('Round limit') }}</legend>
                                <div class="flex gap-1.5">
                                    @foreach ($limits as $limit)
                                        <label class="{{ $chip }} min-w-11 px-3 text-[13px]"><input type="radio" name="limit" value="{{ $limit }}" class="sr-only" @checked((int) old('limit', 0) === $limit)>{{ $limit === 0 ? '∞' : $limit }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                        </div>
                        @if ($errors->any())
                            <p class="m-0 text-[13px] text-loss" role="alert">{{ $errors->first() }}</p>
                        @endif
                        <div class="flex flex-col gap-3">
                            <button type="submit" class="rv-b w-full" data-test="hyper-play-bots">
                                {{ __('Play against bots') }}
                            </button>
                            <span class="text-xs text-ink-2">{{ __('Opens in a new tab, full-screen.') }}</span>
                        </div>
                    </form>
                </section>
            @endif

            <section class="gp-panel flex flex-col gap-2 p-4" aria-labelledby="hyper-short-h" data-test="hyper-short">
                <h2 id="hyper-short-h" class="gp-h">{{ __('The game in short') }}</h2>
                <dl class="m-0 flex flex-col">
                    @foreach ([[__('Players'), __('2 to 6 players')], [__('Map'), __('10 currency spaces')], [__('Goal'), __('Topple central banks')], [__('Turn time'), __('Live :seconds s, correspondence :hours h', ['seconds' => (int) config('esports.hyper.turn_seconds', 90), 'hours' => (int) config('esports.hyper.correspondence_hours', 24)])]] as [$term, $value])
                        <div class="flex flex-col gap-0.5 border-b border-hairline py-2.5"><dt class="rv-m">{{ $term }}</dt><dd class="m-0 text-[14px]">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            {{-- The season ladder and the weekend cup (P5). --}}
            <nav class="gp-panel flex flex-col gap-2 p-4" aria-labelledby="hyper-season-h">
                <h2 id="hyper-season-h" class="gp-h">{{ __('Season and cups') }}</h2>
                <a href="{{ route('hyper.ladder') }}" @navigate(route('hyper.ladder')) class="rv-lk self-start text-[14px]" data-test="hyper-index-ladder">{{ __('Season ladder') }}</a>
                @if ($cup !== null)
                    <a href="{{ route('tournaments.show', $cup) }}" @navigate(route('tournaments.show', $cup)) class="rv-lk self-start text-[14px]" data-test="hyper-index-cup">{{ $cup->name }}</a>
                @endif
                <span class="rv-m">{{ \App\Support\SeasonChain\Seasons::live() === null ? __('Rated matches start with Block 0') : __(':season is live', ['season' => \App\Support\Badges\BadgeCopy::season(\App\Support\SeasonChain\Seasons::live()->slug)]) }}</span>
            </nav>

            {{--
                The start page's theme (user, 2026-10-09: "soll schon hier spielen und steuerbar sein, wie bei Blockfill"):
                it starts on load where the browser allows sound, otherwise on the first tap or key; a switch and a volume of
                ten steps as on Blockfill (pages/stacker/partials/sound-control), kept with the game's music switch
                (`hb-settings.music`, which the match page reads too) and `hb-settings.startVolume`. It stops when the page goes.
            --}}
            <section class="gp-panel flex flex-col gap-3 p-4" aria-labelledby="hyper-music-h"
                 x-data="{
                     on: true, volume: 50, playing: false, audio: null,
                     init() {
                         try { const s = JSON.parse(localStorage.getItem('hb-settings') || '{}'); this.on = s.music !== false; if (Number.isInteger(s.startVolume)) this.volume = Math.max(0, Math.min(100, s.startVolume)); } catch (e) {}
                         const first = (event) => { if (! event.target.closest('[data-test=hyper-index-sound]') && this.on && ! this.playing) this.play(); };
                         addEventListener('pointerdown', first, { capture: true });
                         addEventListener('keydown', first, { capture: true });
                         addEventListener('pagehide', () => this.audio?.pause());
                         document.addEventListener('livewire:navigating', () => this.audio?.pause());
                         if (this.on) this.play();
                     },
                     gain() { return (this.volume / 100) ** 2 * 0.7; },
                     play() {
                         this.audio ??= Object.assign(new Audio('/hyper/m/6af9906cf895bf9784f61751e7502d98251601e93ccffeea5223c6755a4803d2.mp3?v=1'), { loop: true });
                         this.audio.volume = this.gain();
                         this.audio.play().then(() => { this.playing = true; }).catch(() => {});
                     },
                     save() { try { const s = JSON.parse(localStorage.getItem('hb-settings') || '{}'); s.music = this.on; s.startVolume = this.volume; localStorage.setItem('hb-settings', JSON.stringify(s)); } catch (e) {} },
                     toggle() { this.on = ! this.on; this.save(); if (this.on) { this.play(); } else { this.audio?.pause(); this.playing = false; } },
                     setVolume(value) { this.volume = Number(value); if (this.audio) this.audio.volume = this.gain(); if (! this.on && this.volume > 0) { this.on = true; this.play(); } this.save(); },
                 }">
                <h2 id="hyper-music-h" class="gp-h">{{ __('Music') }}</h2>
                    {{-- Music as on Blockfill: a switch with its icon and a volume of ten steps. --}}
                    <div class="flex items-center gap-2" role="group" aria-label="{{ __('Music') }}" data-test="hyper-index-sound">
                        <button type="button" x-on:click="toggle()" x-bind:aria-pressed="on ? 'true' : 'false'"
                                class="inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border px-2.5 text-[13px] font-bold sm:px-3"
                                x-bind:class="on ? 'border-btc-ring bg-btc-chip text-btc' : 'border-line text-ink-3 hover:text-ink-2'" data-test="hyper-index-music">
                            <span x-show="on" class="inline-flex"><x-icon name="music" :size="18" /></span>
                            <span x-show="! on" x-cloak class="inline-flex"><x-icon name="music-off" :size="18" /></span>
                            <span class="sr-only sm:not-sr-only">{{ __('Music') }}</span>
                        </button>
                        <input type="range" min="0" max="100" step="10" class="blockfill-volume h-11 w-full min-w-0"
                               x-bind:value="volume" x-on:input="setVolume($event.target.value)"
                               x-bind:style="`--fill: ${volume}%`" x-bind:data-off="on ? null : ''"
                               aria-label="{{ __('Volume: :channel', ['channel' => __('Music')]) }}" x-bind:aria-valuetext="volume + ' %'" data-test="hyper-index-volume">
                    </div>

            </section>
        </x-slot:chat>
    </x-game.page>
</x-layouts::app>
