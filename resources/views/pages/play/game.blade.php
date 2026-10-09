{{--
    One game on /play: its cover, name, modes and actions. $game: a ShellNavigation NavGame; $level: the heading
    level of its name (2 in the list, 3 inside the board games group); $eager: load its cover at once.
--}}
@php
    $registry = app(\App\Games\GameRegistry::class);
    $modes = $registry->get($game['slug'])->modes();
    $primary = $game['actions'][0];
    $more = array_slice($game['actions'], 1);
    $heading = 'h'.($level ?? 2);
@endphp
<li id="{{ $game['slug'] }}" class="grid grid-cols-1 gap-4 rounded-lg bg-card p-4 sm:grid-cols-[240px_minmax(0,1fr)] lg:grid-cols-[320px_minmax(0,1fr)] lg:gap-6 lg:p-5" style="--game: {{ $game['colour'] }}" data-test="play-game-{{ $game['slug'] }}">
    <a href="{{ $game['page'] }}" @navigate($game['page']) class="block self-start">
        <x-game-cover :game="$game['slug']" size="card" class="w-full rounded-md border-b-[3px] border-(--game)" :loading="($eager ?? false) ? 'eager' : 'lazy'" />
    </a>
    <div class="flex min-w-0 flex-col gap-3">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <{{ $heading }} class="m-0 font-display text-xl font-bold"><a href="{{ $game['page'] }}" @navigate($game['page']) class="text-ink hover:text-ink">{{ $game['name'] }}</a></{{ $heading }}>
            <x-game-credit :game="$game['slug']" />
            @if ($game['played'])
                <span class="text-xs text-ink-3">{{ __('You play this') }}</span>
            @endif
        </div>

        <dl class="m-0 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-1 text-[13px]">
            @foreach ($modes as $mode)
                @php
                    $scoreGame = $registry->get($game['slug']);
                    // A score game (plan "AoE2 und Trackmania", P4): no Elo ladder, a value per player and points per place.
                    $facts = match (true) {
                        $scoreGame instanceof \App\Games\ScoreGame => [$scoreGame->metric($mode)->lowerIsBetter() ? __('Time attack, the fastest time wins') : __('Highscore, the highest score wins'), __('points per place')],
                        // Hyperbitcoinization (P6): the turn time, 2 to 6 seats, its own season ladder.
                        $scoreGame->kind() === \App\Games\GameKind::Strategy => [
                            $mode->slug === \App\Models\HyperMatch::CORRESPONDENCE ? __(':hours h per turn', ['hours' => (int) config('esports.hyper.correspondence_hours', 24)]) : __(':seconds s per turn', ['seconds' => (int) config('esports.hyper.turn_seconds', 90)]),
                            __('2 to 6 players'),
                            __('season points and Elo'),
                        ],
                        default => [$mode->rates === 'player' ? __('Player ladder') : __('Clan lineup')],
                    };
                    if ($mode->bestOf !== []) {
                        $facts[] = __('best of :list', ['list' => implode(' / ', $mode->bestOf)]);
                    }
                    if ($mode->boards !== []) {
                        $facts[] = __('clan matches on :list boards', ['list' => implode(' / ', $mode->boards)]);
                    }
                @endphp
                <dt class="font-bold text-ink">{{ __($mode->name) }}</dt>
                <dd class="m-0 text-ink-2">{{ implode(', ', $facts) }}</dd>
            @endforeach
        </dl>

        <div class="flex flex-wrap items-center gap-2 pt-1">
            <x-button :href="$primary['href']">{{ $primary['label'] }}</x-button>
            @foreach ($more as $link)
                <x-button variant="secondary" :href="$link['href']">{{ $link['label'] }}</x-button>
            @endforeach
            @guest
                <x-button variant="quiet" :href="route('login', ['then' => 'play'])" data-test="play-login">{{ __('Log in to play') }}</x-button>
            @endguest
        </div>
    </div>
</li>
