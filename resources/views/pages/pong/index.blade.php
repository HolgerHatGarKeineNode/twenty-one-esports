{{--
    Proof of Pong's start page in the league's shell (plan "Proof of Pong", P1): the game in short and a game against
    a bot, picked from the cast's five (Nocoiner Uncle to Madame Brrr Lagarde, the end boss), with the player's figure
    (P3, pong/partials/figures; resources/js/pong/picker.js keeps the pick in the browser). The form opens the game in a new
    tab, full-screen (PongController::bot()); a guest logs in first and lands in the game. Under it the live 1v1 (P2,
    components/⚡pong-lobby): who is online and looking to play, invites, the running match. Indexed with its own link
    preview since P4, with the Elo ladder one link away and the viewer's latest win to share.
--}}
@php
    app(\App\Support\PageMeta::class)->describe('Proof of Pong', __('Proof of Pong, the arcade game of the TWENTY ONE esports league: classic Pong to 21 in Bitcoin meme culture. Play a bot or a live 1v1 for Elo, with meme events every 21st rally.'))
        ->card(fn () => \App\Support\Cards\PageCard::page('pong'));
    $chip = 'flex cursor-pointer items-center gap-3 rounded-md bg-well p-2 pr-3 text-left text-ink-2 shadow-ring transition-colors has-[:checked]:bg-btc-chip has-[:checked]:text-ink has-[:checked]:shadow-[inset_0_0_0_2px_var(--color-btc)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-btc';
@endphp
<x-layouts::app :title="'Proof of Pong'" :scripts="['resources/css/pong-picker.css', 'resources/js/pong/picker.js']">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="pong-index">
        <section class="relative isolate flex flex-col overflow-hidden rounded-lg bg-card shadow-ring" aria-labelledby="pong-h">
            <img src="/pong/art/key-title.webp" alt="" width="1600" height="900" class="aspect-[16/7] max-h-[360px] w-full object-cover object-top sm:aspect-[16/5]" data-test="pong-key-art">
            <div class="flex flex-col gap-6 p-5 sm:p-8">
                <div class="flex flex-col gap-3">
                    <h1 id="pong-h" class="sr-only">Proof of Pong</h1>
                    <p class="m-0 max-w-[60ch] text-[15px] text-ink-2">{{ __('Classic Pong with a Bitcoin twist: first to 21 points, and every 21st rally a meme event for both sides.') }}</p>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs text-ink-2" aria-label="{{ __('The game in short') }}">
                            <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('First to :points', ['points' => $rules->pointsToWin]) }}</li>
                            <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('Two points ahead') }}</li>
                            <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('Halving, Brrr, Pizza Day') }}</li>
                        </ul>
                        <a href="{{ route('pong.ladder') }}" class="inline-flex min-h-11 items-center gap-2 rounded-md bg-ground/80 px-3 text-[13px] font-bold text-ink shadow-ring hover:text-ink" data-test="pong-ladder-link"><x-icon name="ladder" :size="16" />{{ __('Elo ladder') }}</a>
                    </div>
                </div>

                <form method="get" action="{{ route('pong.bot') }}" target="_blank" class="grid gap-8 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] lg:gap-10" data-test="pong-play-form">
                    @include('pong.partials.figures', ['figures' => $figures, 'selected' => null])

                    <div class="flex flex-col gap-4">
                        <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                            <legend class="mb-2 text-[13px] font-bold text-ink-2">{{ __('Your opponent') }}</legend>
                            @foreach ($bots as $bot)
                                <label class="{{ $chip }}" data-test="pong-bot-{{ $bot['id'] }}">
                                    <input type="radio" name="bot" value="{{ $bot['id'] }}" class="sr-only" @checked($loop->first)>
                                    <img src="/pong/art/por-{{ $bot['id'] }}.webp" alt="" width="56" height="56" loading="lazy" class="size-14 shrink-0 rounded-md bg-ground/60 object-contain object-bottom">
                                    <span class="flex min-w-0 flex-col gap-0.5">
                                        <span class="flex flex-wrap items-baseline gap-x-2"><span class="text-[14px] font-bold text-ink">{{ $bot['name'] }}</span><span class="text-[12px] font-bold text-btc-hi">{{ match ($bot['id']) { 'lagarde' => __('End boss'), 'schnabel' => __('Pre-boss'), default => __('Level :level', ['level' => $bot['level']]) } }}</span></span>
                                        <span class="text-[12px] text-ink-2">{{ $bot['tagline'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </fieldset>
                        <div class="flex flex-col gap-2">
                            <button type="submit" class="btn-p inline-flex min-h-12 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc" data-test="pong-play-bot">
                                <x-icon name="play" :size="18" />{{ __('Play vs bot') }}
                            </button>
                            <span class="text-xs text-ink-2">{{ auth()->check() ? __('Opens in a new tab, full-screen.') : __('You log in first, then the game opens in a new tab.') }}</span>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <livewire:pong-lobby />

        @if ($lastWin !== null)
            {{-- The viewer's latest won live match (P4): a moment to post on Nostr (SharePosts `pong`). --}}
            <section aria-labelledby="pong-win-h" class="flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg bg-card px-4 py-4 shadow-ring lg:px-6" data-test="pong-last-win">
                <x-game-cover :game="\App\Games\ProofOfPong::SLUG" size="thumb" class="w-16 shrink-0 rounded-tag" />
                <span class="flex min-w-0 grow flex-col gap-0.5">
                    <h2 id="pong-win-h" class="m-0 text-[15px] font-bold">{{ __('Your latest win') }}</h2>
                    <span class="text-[13px] text-ink-2">{{ __(':score against :name', ['score' => max($lastWin->score()).':'.min($lastWin->score()), 'name' => $lastWin->player(1 - (int) $lastWin->sideOf(auth()->user()))?->displayName() ?? __('Deleted account')]) }}</span>
                </span>
                <livewire:share-button type="pong" :moment="$lastWin->ulid" :label="__('Post the win')" :wire:key="'pong-share-'.$lastWin->ulid" />
            </section>
        @endif
    </div>
</x-layouts::app>
