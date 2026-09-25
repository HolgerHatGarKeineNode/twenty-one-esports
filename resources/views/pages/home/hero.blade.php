<section aria-labelledby="hero-h" class="grid gap-4 px-4 pt-1 pb-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-stretch lg:gap-12 lg:px-12 lg:pt-5 lg:pb-10">
    <div class="flex flex-col justify-center gap-3.5 pb-1 lg:gap-5 lg:pb-0">
        <h1 id="hero-h" class="m-0 font-display text-[30px] leading-[1.15] font-extrabold tracking-[-0.01em] lg:text-5xl lg:leading-[1.1]">
            <span class="lg:block">{{ __('Play one game.') }}</span>
            <span class="lg:block">{{ __('Mine your first block.') }}</span>
        </h1>
        <p class="m-0 text-[15px] leading-[1.6] text-ink-2 lg:hidden">{{ __('A small, open esports league for chess and Rocket League. Every rated game adds a block to your own chain. Anyone can join, nothing to install.') }}</p>
        <p class="m-0 hidden max-w-[58ch] text-base leading-[1.6] text-ink-2 lg:block">{{ __('TWENTY ONE is a small, open esports league for chess and Rocket League. Every rated game you finish adds a block to your own chain and moves your rating. Anyone can join, no install needed.') }}</p>

        <div role="list" aria-label="{{ __('Right now') }}" class="grid grid-cols-3 lg:mt-2 lg:flex">
            @foreach ($counters as $counter)
                <div role="listitem" class="flex flex-col gap-0.5 border-l border-line pl-3 lg:gap-1 lg:px-7">
                    <span class="flex items-center gap-1.5 font-display text-xl font-bold lg:gap-2 lg:text-2xl">
                        @if ($counter['dot'])
                            <span class="inline-block size-2 animate-live rounded-full bg-win lg:size-[9px]" aria-hidden="true"></span>
                        @endif
                        {{ $counter['value'] }}
                    </span>
                    <span class="text-xs text-ink-2 lg:hidden">{{ $counter['short'] }}</span>
                    <span class="hidden text-[13px] text-ink-2 lg:inline">{{ $counter['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 shadow-ring-btc lg:gap-[18px] lg:px-8 lg:py-7">
        <h2 class="m-0 font-display text-xl leading-[1.25] font-bold lg:text-2xl lg:leading-[1.2]">{{ __('Your first block in 2 minutes') }}</h2>

        <div role="status" class="flex items-center gap-2.5 rounded-md bg-ground p-3 text-[13px] shadow-ring lg:gap-3 lg:px-3.5">
            <span class="inline-block size-2 shrink-0 animate-live rounded-full bg-win" aria-hidden="true"></span>
            <span class="lg:hidden"><b>{{ __('12 players searching') }}</b> {{ __('for Blitz 5+3') }}</span>
            <span class="hidden lg:inline"><b>{{ __('12 players searching') }}</b> {{ __('for Blitz 5+3 right now') }}</span>
            <span class="grow"></span>
            <span class="whitespace-nowrap text-ink-2 lg:hidden">~40 s</span>
            <span class="hidden whitespace-nowrap text-ink-2 lg:inline">{{ __('wait ~40 s') }}</span>
        </div>

        {{-- Mobile order: the action first, then the three steps, then the sign-in options. --}}
        <a href="{{ route('login') }}" class="btn-p flex h-14 items-center justify-center gap-2.5 rounded-lg bg-btc text-[15px] font-bold text-on-btc hover:text-on-btc lg:hidden">
            <x-icon name="pawn" />{{ __('Find opponent, Blitz 5+3') }}
        </a>

        <ol class="m-0 flex list-none flex-col gap-2.5 p-0 text-sm leading-normal">
            @foreach ([
                [__('Tap Find opponent. We pair you with someone who is searching right now.'), __('Continue with Google. No password, no download.')],
                [__('Five minutes each, plus 3 seconds a move.'), __('You land straight in the queue: Find opponent, Blitz 5+3. Five minutes each, plus 3 seconds a move.')],
                [__('Play it out. Your finished game joins the block strip above.'), __('Play it out. Your finished game joins the block strip above.')],
            ] as $index => [$mobileStep, $desktopStep])
                <li class="grid grid-cols-[28px_minmax(0,1fr)] items-baseline gap-2 lg:gap-2.5">
                    <span class="inline-flex size-6 items-center justify-center rounded-full bg-btc-tint text-xs font-bold text-btc">{{ $index + 1 }}</span>
                    <span class="lg:hidden">{{ $mobileStep }}</span>
                    <span class="hidden lg:inline">{{ $desktopStep }}</span>
                </li>
            @endforeach
        </ol>

        <a href="{{ route('login') }}" class="btn-p hidden h-13 items-center justify-center gap-2.5 rounded-lg bg-btc text-[15px] font-bold text-on-btc hover:text-on-btc lg:flex">
            <x-icon name="google" />{{ __('Continue with Google and find an opponent') }}
        </a>
        <a href="{{ route('login') }}" class="btn-s hidden h-11 items-center justify-center gap-2 rounded-lg border border-edge text-[13px] text-ink hover:text-ink lg:flex">
            <x-icon name="lock" :size="16" />{{ __('Nostr extension') }}
        </a>

        <div class="flex flex-col gap-2 border-t border-hairline pt-3 lg:hidden">
            <span class="text-xs text-ink-2">{{ __('First time here? Sign in, then you go straight into the queue.') }}</span>
            <div class="grid grid-cols-2 gap-2">
                <a href="{{ route('login') }}" class="btn-s flex h-11 items-center justify-center gap-1.5 rounded-lg border border-edge text-xs text-ink hover:text-ink"><x-icon name="google" :size="16" />Google</a>
                <a href="{{ route('login') }}" class="btn-s flex h-11 items-center justify-center gap-1.5 rounded-lg border border-edge text-xs text-ink hover:text-ink"><x-icon name="lock" :size="16" />Nostr</a>
            </div>
        </div>

        <p class="m-0 text-xs leading-[1.6] text-ink-3">{{ __('Free for everyone. Your first 5 rated games are provisional, so your rating finds its level fast.') }}</p>
    </div>
</section>
