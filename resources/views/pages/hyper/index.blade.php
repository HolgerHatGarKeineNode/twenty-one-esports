{{--
    Hyperbitcoinization's start page in the league's shell (plan "Hyperbitcoinization", P2) until the lobby comes
    (P3): the title art, one primary action (a live match against 1 to 5 bots, faction and round limit to pick) that
    opens the full-screen match in a new tab, and the viewer's running matches. A plain form: it needs no script,
    and posting into a new tab is no popup a browser could block (HyperMatchController::quick() sends it on).
--}}
@php
    $labels = ['bitcoiner' => 'Bitcoiner', 'fed' => 'Fed', 'ezb' => __('ECB'), 'goldbug' => 'Goldbug', 'shitcoiner' => 'Shitcoiner', 'nocoiner' => 'Nocoiner'];
    $portrait = \App\Support\Hyper\HyperGame::FACTIONS;
    $chip = 'flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-well font-bold text-ink-2 shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:text-ink has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-btc';
@endphp
<x-layouts::app :title="'Hyperbitcoinization'">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="hyper-index">
        <section class="relative isolate overflow-hidden rounded-lg bg-card shadow-ring" aria-labelledby="hyper-h">
            <img src="/hyper/art/key-title.jpg?v=1" alt="" width="1600" height="900" class="absolute inset-0 -z-10 size-full object-cover object-[50%_35%]" fetchpriority="high">
            <div class="absolute inset-0 -z-10 bg-[linear-gradient(180deg,rgb(3_9_20/0.35),rgb(3_9_20/0.55)_45%,rgb(3_9_20/0.92))] lg:bg-[linear-gradient(90deg,rgb(3_9_20/0.94),rgb(3_9_20/0.72)_48%,rgb(3_9_20/0.15))]"></div>
            <div class="flex max-w-[720px] flex-col gap-5 p-5 pt-36 sm:p-8 sm:pt-44 lg:pt-10">
                <div class="flex flex-col gap-2">
                    <span class="text-[11px] font-bold tracking-[0.3em] text-btc-hi uppercase">TWENTY ONE esports</span>
                    <h1 id="hyper-h" class="m-0 font-display text-[clamp(14px,4.9vw,40px)] leading-none font-extrabold whitespace-nowrap">HYPER<span class="text-btc">₿</span>ITCOINIZATION</h1>
                    <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs text-ink-2" aria-label="{{ __('The game in short') }}">
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('2 to 6 players') }}</li>
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('10 currency spaces') }}</li>
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __('Topple central banks') }}</li>
                        <li class="rounded-tag bg-ground/70 px-2 py-1 shadow-ring">{{ __(':seconds s per turn', ['seconds' => (int) config('esports.hyper.turn_seconds', 90)]) }}</li>
                    </ul>
                </div>

                @if ($viewer === null)
                    <div class="flex flex-wrap items-center gap-3">
                        <x-button variant="primary" :href="route('login')" class="h-12 px-6 text-[15px]" data-test="hyper-login">{{ __('Log in to play') }}</x-button>
                    </div>
                @else
                    <form method="post" action="{{ route('hyper.quick') }}" target="_blank" class="flex flex-col gap-4" data-test="hyper-quick">
                        @csrf
                        <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                            <legend class="mb-2 text-xs font-bold tracking-[0.12em] text-ink-2 uppercase">{{ __('Your faction') }}</legend>
                            <div class="grid grid-cols-4 gap-2 sm:grid-cols-7">
                                @foreach ($factions as $faction)
                                    <label class="{{ $chip }} min-h-[76px] flex-col gap-1 px-1 text-[10px]">
                                        <input type="radio" name="faction" value="{{ $faction }}" class="sr-only" @checked(old('faction', 'bitcoiner') === $faction)>
                                        <img src="/hyper/art/por-{{ $portrait[$faction] }}.jpg?v=1" alt="" width="40" height="40" class="size-10 rounded-full object-cover shadow-ring" loading="lazy">
                                        <span class="max-w-full truncate">{{ $labels[$faction] }}</span>
                                    </label>
                                @endforeach
                                <label class="{{ $chip }} min-h-[76px] flex-col gap-1 px-1 text-[10px]">
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
                @endif
            </div>
        </section>

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
                                        <b class="truncate text-sm">{{ __('Round :round · :seats seats', ['round' => $round, 'seats' => $match->seats->count()]) }}</b>
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
