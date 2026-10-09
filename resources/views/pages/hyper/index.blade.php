{{--
    Hyperbitcoinization's start page in the league's shell (plan "Hyperbitcoinization", P2/P3): the title art over
    the lobby (components/⚡hyper-lobby: a new table or the player's own, then the open tables; a table's own link
    `tables/{ulid}` shows that table first), the quick start against bots, and the viewer's running matches. The
    quick start is a plain form: posting into a new tab is no popup a browser could block
    (HyperMatchController::quick() sends it on). Every match opens in a new tab.
--}}
@php
    $labels = ['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => __('ECB'), 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'];
    $portrait = \App\Support\Hyper\HyperGame::FACTIONS;
    // Indexed, with its own link preview (P6): the cover, the game in one line, running and played matches.
    app(\App\Support\PageMeta::class)->describe('Hyperbitcoinization', __('Hyperbitcoinization, the strategy game of the TWENTY ONE esports league: Risk with currency spaces for 2 to 6 players, live or by correspondence, alone or clan against clan. Topple the central banks, collect sats as loot.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('hyper'));
    $chip = 'flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-well font-bold text-ink-2 shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:text-ink has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-btc';
@endphp
<x-layouts::app :title="'Hyperbitcoinization'">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="hyper-index">
        {{--
            The start page's theme (user, 2026-10-09: "soll schon hier spielen und steuerbar sein, wie bei Blockfill"):
            it starts on load where the browser allows sound, otherwise on the first tap or key; a switch and a volume of
            ten steps as on Blockfill (pages/stacker/partials/sound-control), kept with the game's music switch
            (`hb-settings.music`, which the match page reads too) and `hb-settings.startVolume`. It stops when the page goes.
        --}}
        <section class="relative isolate overflow-hidden rounded-lg bg-card shadow-ring" aria-labelledby="hyper-h"
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
            <img src="/hyper/art/key-title.jpg?v=1" alt="" width="1600" height="900" class="absolute inset-0 -z-10 size-full object-cover object-[50%_35%]" fetchpriority="high">
            <div class="absolute inset-0 -z-10 bg-[linear-gradient(180deg,rgb(3_9_20/0.35),rgb(3_9_20/0.55)_45%,rgb(3_9_20/0.92))] lg:bg-[linear-gradient(90deg,rgb(3_9_20/0.94),rgb(3_9_20/0.72)_48%,rgb(3_9_20/0.15))]"></div>
            <div class="flex max-w-[960px] flex-col gap-5 p-5 pt-28 sm:p-8 sm:pt-40 lg:pt-10">
                <div class="flex flex-col gap-2">
                    <span class="text-[11px] font-bold tracking-[0.3em] text-btc-hi uppercase">TWENTY ONE esports</span>
                    <h1 id="hyper-h" class="m-0 font-display text-[clamp(14px,4.9vw,40px)] leading-none font-extrabold whitespace-nowrap">HYPER<span class="text-btc">₿</span>ITCOINIZATION</h1>
                    <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs text-ink-2" aria-label="{{ __('The game in short') }}">
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('2 to 6 players') }}</li>
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('10 currency spaces') }}</li>
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('Topple central banks') }}</li>
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __(':seconds s per turn', ['seconds' => (int) config('esports.hyper.turn_seconds', 90)]) }}</li>
                    </ul>
                    <div class="flex flex-wrap items-center gap-2">
                    {{-- The season ladder and the weekend cup (P5). --}}
                    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Season and cups') }}">
                        <a href="{{ route('hyper.ladder') }}" class="inline-flex min-h-11 items-center gap-2 rounded-md bg-ground/80 px-3 text-[13px] font-bold text-ink shadow-ring hover:text-ink" data-test="hyper-index-ladder"><x-icon name="trophy" :size="16" class="text-btc" />{{ __('Season ladder') }}</a>
                        @if ($cup !== null)
                            <a href="{{ route('tournaments.show', $cup) }}" class="inline-flex min-h-11 items-center gap-2 rounded-md bg-ground/80 px-3 text-[13px] font-bold text-ink shadow-ring hover:text-ink" data-test="hyper-index-cup"><x-icon name="trophy" :size="16" class="text-btc" />{{ $cup->name }}</a>
                        @endif
                    </nav>
                    {{-- Music as on Blockfill: a switch with its icon and a volume of ten steps. --}}
                    <div class="flex w-full max-w-[340px] items-center gap-2 rounded-md bg-ground/80 p-1 pr-3 shadow-ring" role="group" aria-label="{{ __('Music') }}" data-test="hyper-index-sound">
                        <button type="button" x-on:click="toggle()" x-bind:aria-pressed="on ? 'true' : 'false'"
                                class="inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border px-3 text-[13px] font-bold"
                                x-bind:class="on ? 'border-btc-ring bg-btc-chip text-btc' : 'border-line text-ink-3 hover:text-ink-2'" data-test="hyper-index-music">
                            <span x-show="on" class="inline-flex"><x-icon name="music" :size="18" /></span>
                            <span x-show="! on" x-cloak class="inline-flex"><x-icon name="music-off" :size="18" /></span>
                            <span>{{ __('Music') }}</span>
                        </button>
                        <input type="range" min="0" max="100" step="10" class="blockfill-volume h-11 w-full min-w-0"
                               x-bind:value="volume" x-on:input="setVolume($event.target.value)"
                               x-bind:style="`--fill: ${volume}%`" x-bind:data-off="on ? null : ''"
                               aria-label="{{ __('Volume: :channel', ['channel' => __('Music')]) }}" x-bind:aria-valuetext="volume + ' %'" data-test="hyper-index-volume">
                    </div>
                    </div>
                </div>

                <livewire:hyper-lobby :focus="$focus" />
            </div>
        </section>

        @if ($viewer !== null)
            {{-- Straight into a live match against bots, no table: the P2 quick start, into a new tab --}}
            <section class="flex flex-col gap-3 rounded-lg bg-card p-4 shadow-ring" aria-labelledby="hyper-quick-h">
                <h2 id="hyper-quick-h" class="m-0 font-display text-xl font-bold">{{ __('Quick match against bots') }}</h2>
                <form method="post" action="{{ route('hyper.quick') }}" target="_blank" class="flex flex-col gap-4" data-test="hyper-quick">
                    @csrf
                    <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                        <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Your faction') }}</legend>
                        <div class="grid grid-cols-3 gap-2 min-[480px]:grid-cols-4 sm:grid-cols-7">
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
                            <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Bots') }}</legend>
                            <div class="flex gap-1.5">
                                @foreach (range(1, 5) as $bots)
                                    <label class="{{ $chip }} min-w-11 px-3 text-[13px]"><input type="radio" name="bots" value="{{ $bots }}" class="sr-only" @checked((int) old('bots', 3) === $bots)>{{ $bots }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                        <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                            <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Round limit') }}</legend>
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
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="btn-p inline-flex min-h-12 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc" data-test="hyper-play-bots">
                            <x-icon name="play" :size="18" />{{ __('Play against bots') }}
                        </button>
                        <span class="text-xs text-ink-2">{{ __('Opens in a new tab, full-screen.') }}</span>
                    </div>
                </form>
            </section>
        @endif

        @if ($moments !== [])
            {{-- The viewer's latest wins and loot (P6, HyperMoments): each one to post on Nostr or to save as the match's card. --}}
            <section id="hyper-moments" class="flex scroll-mt-24 flex-col gap-3" aria-labelledby="hyper-moments-h" data-test="hyper-moments">
                <h2 id="hyper-moments-h" class="m-0 font-display text-xl font-bold">{{ __('Your moments') }}</h2>
                <ul class="m-0 grid list-none gap-3 p-0 lg:grid-cols-2">
                    @foreach ($moments as ['match' => $match, 'moment' => $moment])
                        @php
                            $sats = rtrim(rtrim(number_format($moment['sats'], 1, '.', ''), '0'), '.');
                        @endphp
                        <li class="flex min-w-0 flex-col gap-3 rounded-lg bg-card p-3 shadow-ring" data-test="hyper-moment" data-kind="{{ $moment['kind'] }}" wire:key="moment-{{ $match->ulid }}">
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

        @if ($viewer !== null)
            <section class="flex flex-col gap-3" aria-labelledby="hyper-running-h" data-test="hyper-running">
                <h2 id="hyper-running-h" class="m-0 font-display text-xl font-bold">{{ __('Your running matches') }}</h2>
                @if ($running->isEmpty())
                    <p class="m-0 rounded-lg bg-card px-4 py-4 text-[13px] text-ink-2 shadow-ring">{{ __('No match running. Start one above.') }}</p>
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
                                <a href="{{ route('hyper.match', $match) }}" target="_blank" class="flex items-center gap-3 rounded-lg bg-card p-3 text-ink shadow-ring hover:bg-row-hover hover:text-ink" data-test="hyper-running-match">
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
    </div>
</x-layouts::app>
