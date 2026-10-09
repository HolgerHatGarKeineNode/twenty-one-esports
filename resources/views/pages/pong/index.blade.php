{{--
    Proof of Pong's start page in the league's shell (plan "Proof of Pong", P1): the game in short and a game against
    a bot, the level picked from the four (Nocoiner Uncle to Madame Brrr Lagarde). The form opens the game in a new
    tab, full-screen (PongController::bot()); a guest logs in first and lands in the game. Not indexed until the
    league's surfaces take the game up (P4). Under it the live 1v1 (P2, components/⚡pong-lobby): who is online and
    looking to play, invites, the running match.
--}}
@php
    $chip = 'flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-well font-bold text-ink-2 shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:text-ink has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-btc';
@endphp
<x-layouts::app :title="'Proof of Pong'">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="pong-index">
        <section class="relative isolate flex flex-col gap-5 overflow-hidden rounded-lg bg-card p-5 shadow-ring sm:p-8" aria-labelledby="pong-h">
            <div class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_85%_20%,rgb(247_147_26/0.22),transparent_45%),radial-gradient(circle_at_10%_90%,rgb(124_58_237/0.18),transparent_40%)]"></div>
            <div class="flex flex-col gap-2">
                <span class="text-[11px] font-bold tracking-[0.3em] text-btc-hi uppercase">TWENTY ONE esports</span>
                <h1 id="pong-h" class="m-0 font-display text-[clamp(26px,7vw,48px)] leading-none font-extrabold">PROOF OF <span class="text-btc">PONG</span></h1>
                <p class="m-0 max-w-[60ch] text-[15px] text-ink-2">{{ __('Classic Pong with a Bitcoin twist: first to 21 points, and every 21st rally a meme event for both sides.') }}</p>
                <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs text-ink-2" aria-label="{{ __('The game in short') }}">
                    <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('First to :points', ['points' => $rules->pointsToWin]) }}</li>
                    <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('Two points ahead') }}</li>
                    <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('Halving, Brrr, Pizza Day') }}</li>
                </ul>
            </div>

            <form method="get" action="{{ route('pong.bot') }}" target="_blank" class="flex flex-col gap-4" data-test="pong-play-form">
                <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Your opponent') }}</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach ($levels as $level => $bot)
                            <label class="{{ $chip }} min-h-16 flex-col gap-0.5 px-2 text-center text-[13px]" data-test="pong-level-{{ $level }}">
                                <input type="radio" name="level" value="{{ $level }}" class="sr-only" @checked($level === 1)>
                                <span>{{ __($bot['name']) }}</span>
                                <span class="text-[11px] font-medium text-ink-3">{{ $level === 4 ? __('End boss') : __('Level :level', ['level' => $level]) }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn-p inline-flex min-h-12 cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc" data-test="pong-play-bot">
                        <x-icon name="play" :size="18" />{{ __('Play vs bot') }}
                    </button>
                    <span class="text-xs text-ink-2">{{ auth()->check() ? __('Opens in a new tab, full-screen.') : __('You log in first, then the game opens in a new tab.') }}</span>
                </div>
            </form>
        </section>

        <livewire:pong-lobby />
    </div>
</x-layouts::app>
