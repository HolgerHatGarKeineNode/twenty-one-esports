{{--
    Proof of Pong's lobby through the one lobby template T1 (x-game.page; GameLobby.dc.html "Proof-of-Pong-Lobby",
    rules R1, R5, R6, R7): game bar, hero, "Spielarten" (the player's figure, the game against one of the cast's five
    bots, the live 1v1), the viewer's latest win, the Elo ladder's top five, the next tournament | the secondary slot;
    the chat in the right column. The figure (pong/partials/figures in its lobby form, resources/js/pong/picker.js)
    stays in the browser, so it also plays a live match; the bot game opens in a new tab, full-screen
    (PongController::bot(); a guest logs in first); the live 1v1 is components/⚡pong-lobby (Looking to play, who is
    online, invites, the running match). The game's rules live on the rules page (tab "Regeln").
--}}
@php
    app(\App\Support\PageMeta::class)->describe('Proof of Pong', __('Proof of Pong, the arcade game of the TWENTY ONE esports league: classic Pong to 21 in Bitcoin meme culture. Play a bot or a live 1v1 for Elo, with all nine meme events in every 21 rallies.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('pong'));
    $firstBot = $bots[0];
    $slug = \App\Games\ProofOfPong::SLUG;
    $standings = \App\Support\Pong\PongLadder::standings(5);
@endphp
<x-layouts::app :title="'Proof of Pong'" :scripts="['resources/css/pong-picker.css', 'resources/js/pong/picker.js', 'resources/js/gameChannel.js']">
    <x-game.page :game="$slug" :mode="__('Live 1v1')" data-test="pong-index"
                 :lede="__('Pong to :points, all nine meme events in every :points rallies. Opens full-screen in a new tab.', ['points' => $rules->pointsToWin])"
                 :stat="$rules->pointsToWin" :stat-label="__('points to win')">
        <x-slot:primary>
            <button type="submit" form="pong-play-form" class="rv-b" data-test="game-primary">{{ __('Play') }}<x-icon name="expand" :size="14" /></button>
        </x-slot:primary>

        <x-slot:lead>
        <section class="gp-panel gp-modes" aria-labelledby="pong-play-h" data-test="pong-play">
            <h2 id="pong-play-h" class="gp-h">{{ __('Ways to play') }}</h2>
            @if ($liveFirst)
                {{-- Something waits in the live 1v1 for the viewer (an invite, the running match, Looking to play): it leads (P9). --}}
                <div id="pong-way-live" class="pb-2">
                    <livewire:pong-lobby />
                </div>
            @endif
            <div class="gp-mode" data-test="pong-figure-row">
                <span class="gp-mode-l"><b>{{ __('Your paddle') }}</b></span>
                <span class="gp-mode-m"></span>
                <span class="min-w-0">@include('pong.partials.figures', ['figures' => $figures, 'selected' => null, 'lobby' => true, 'form' => 'pong-play-form'])</span>
            </div>

            {{-- The game against a bot: the five of the cast, the easiest first, the picked one named in the row. --}}
            <form id="pong-play-form" method="get" action="{{ route('pong.bot') }}" target="_blank" aria-labelledby="pong-bot-h"
                  class="gp-mode max-md:grid-cols-1 md:!grid-cols-[minmax(0,1fr)_auto_auto]" x-data="{ bot: @js($firstBot['id']) }" data-test="pong-play-form">
                <span class="gp-mode-l">
                    <b id="pong-bot-h">{{ __('Vs bot') }}</b>
                    @foreach ($bots as $bot)
                        <span x-show="bot === @js($bot['id'])" @if (! $loop->first) style="display: none" @endif data-test="pong-bot-detail">{{ __('Right away, no Elo') }}. {{ __('Opponent: :name, :level', ['name' => $bot['name'], 'level' => match ($bot['id']) { 'lagarde' => __('End boss'), 'schnabel' => __('Pre-boss'), default => __('Level :level', ['level' => $bot['level']]) }]) }}</span>
                    @endforeach
                </span>
                <fieldset class="gp-mode-m m-0 flex min-w-0 gap-1 border-0 p-0">
                    <legend class="sr-only">{{ __('Your opponent') }}</legend>
                    @foreach ($bots as $bot)
                        <label class="relative flex size-11 shrink-0 cursor-pointer items-end justify-center overflow-hidden rounded-[4px] bg-well shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:shadow-[inset_0_0_0_2px_var(--color-btc)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-btc-hi" title="{{ $bot['name'] }}" data-test="pong-bot-{{ $bot['id'] }}">
                            <input type="radio" name="bot" value="{{ $bot['id'] }}" x-model="bot" class="sr-only" @checked($loop->first)>
                            <img src="/pong/art/por-{{ $bot['id'] }}.webp" alt="{{ $bot['name'] }}" width="44" height="44" class="size-full object-contain object-bottom">
                        </label>
                    @endforeach
                </fieldset>
                <button type="submit" class="gp-mode-a rv-b2 max-md:col-auto max-md:row-auto max-md:justify-self-start" title="{{ auth()->check() ? __('Opens in a new tab, full-screen.') : __('You log in first, then the game opens in a new tab.') }}" data-test="pong-play-bot">{{ __('Play vs bot') }}</button>
            </form>

            @unless ($liveFirst)
                {{-- The live 1v1 (P2): Looking to play, who is online, invites, the running match; opens in a new tab. --}}
                <div id="pong-way-live" class="pt-2">
                    <livewire:pong-lobby />
                </div>
            @endunless
        </section>
        </x-slot:lead>

        <section class="flex flex-col gap-3" aria-labelledby="pong-win-h" data-test="pong-yours">
            <div class="gp-head"><h2 id="pong-win-h" class="gp-h">{{ __('Your running games') }}</h2></div>
            @if ($lastWin !== null)
                {{-- The viewer's latest won live match (P4): a moment to post on Nostr (SharePosts `pong`). --}}
                <div class="gp-panel flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3" data-test="pong-last-win">
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <b class="text-[14px]">{{ __('Your latest win') }}</b>
                        <span class="text-[13px] text-ink-2">{{ __(':score against :name', ['score' => max($lastWin->score()).':'.min($lastWin->score()), 'name' => $lastWin->player(1 - (int) $lastWin->sideOf(auth()->user()))?->displayName() ?? __('Deleted account')]) }}</span>
                    </span>
                    <livewire:share-button type="pong" :moment="$lastWin->ulid" :label="__('Post the win')" :wire:key="'pong-share-'.$lastWin->ulid" />
                    {{-- Its meme (plan "KI-Stimme der Liga", P5, MemeShares): only an upset, comeback, deuce, first or close win has one; else the button renders nothing. --}}
                    <livewire:share-button type="meme" :moment="'pong-'.$lastWin->ulid" :label="__('Post the meme')" :downloads="false" :wire:key="'pong-meme-'.$lastWin->ulid" />
                </div>
            @else
                <x-empty-state :heading="__('No win yet. Your latest win shows here, with a card to share.')" data-test="pong-yours-empty">
                    <button type="submit" form="pong-play-form" class="rv-b2">{{ __('Practise against the bot') }}</button>
                </x-empty-state>
            @endif
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="pong-ladder-h" data-test="pong-about">
            <div class="gp-head">
                <h2 id="pong-ladder-h" class="gp-h">{{ __('Elo ladder') }}</h2>
                <a href="{{ route('pong.ladder') }}" @navigate(route('pong.ladder')) class="rv-lk inline-flex min-h-11 items-center" data-test="pong-ladder-link">{{ __('Full ladder') }}</a>
            </div>
            @if ($standings === [])
                <x-empty-state :heading="__('Nobody on the Pong ladder yet. The first live match starts it.')" data-test="pong-ladder-empty"><a href="#pong-way-live" class="rv-b2">{{ __('Find opponent') }}</a></x-empty-state>
            @else
                <div class="gp-panel p-2">
                    <x-ladder.table row-test="pong-lobby-ladder-row" :rows="array_map(fn (array $row): array => ['rank' => $row['rank'], 'user' => $row['user'], 'value' => (int) $row['rating'], 'key' => $row['rank'],
                        'mark' => $row['provisional'] ? __('provisional') : null, 'record' => __(':w W, :l L', ['w' => $row['wins'], 'l' => $row['losses']])], $standings)" />
                </div>
            @endif
        </section>

        <x-slot:next>
            @php
                $pongNext = \App\Models\Tournament::query()->special()->where('status', \App\Enums\TournamentStatus::Signup)->where('signup_closes_at', '>', now())->where('game', $slug)->orderBy('signup_closes_at')->first();
            @endphp
            @if ($pongNext)
                <x-tournaments.poster :tournament="$pongNext" heading-id="lobby-next-h" />
            @else
                <x-tournaments.next-empty :game="$slug" heading-id="lobby-next-h" />
            @endif
        </x-slot:next>

        <x-slot:chat>
            {{-- The game's public chat (P5): the right column, sticky (rule R6). --}}
            <livewire:game-channel :game="$slug" wire:key="game-channel-proof-of-pong" />
        </x-slot:chat>
    </x-game.page>
</x-layouts::app>
