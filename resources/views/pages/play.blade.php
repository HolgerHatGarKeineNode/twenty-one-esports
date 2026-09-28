{{--
    All games and modes (/play, header concept B): every registered game
    (GameRegistry) with its cover, its modes and what a player does in it,
    the viewer's games first. The game hub links here. Public: a guest sees
    the pages anyone can open, and "Log in to play" instead of the actions
    that need an account. The links are the navigation's own
    (App\Support\Navigation\ShellNavigation), so this page never promises
    something the menus do not reach.
--}}
@php
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $games = $nav->games();
    $user = $nav->user;
    $registry = app(\App\Games\GameRegistry::class);
    $modeFacts = function (\App\Games\GameMode $mode): string {
        $facts = [$mode->rates === 'player' ? __('Player ladder') : __('Clan lineup')];
        if ($mode->bestOf !== []) {
            $facts[] = __('best of :list', ['list' => implode(' / ', $mode->bestOf)]);
        }
        if ($mode->boards !== []) {
            $facts[] = __('clan matches on :list boards', ['list' => implode(' / ', $mode->boards)]);
        }

        return implode(', ', $facts);
    };
    app(\App\Support\PageMeta::class)
        ->describe(__('All games and modes'), __('Every game of the TWENTY ONE esports league with its modes: :games. What each one is, how it is rated and where to play it.', ['games' => implode(', ', array_map(fn (string $game): string => \App\Support\GameNames::game($game), array_keys($registry->all())))]))
        ->card(fn () => \App\Support\Cards\PageCard::page('play'));
@endphp
<x-layouts::app :title="__('All games and modes')">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="play-page">
        <div class="flex max-w-[60ch] flex-col gap-2">
            <h1 class="m-0 font-display text-[28px] leading-[1.1] font-bold lg:text-4xl">{{ __('All games and modes') }}</h1>
            <p class="m-0 text-[13px] leading-normal text-ink-2">
                {{ $user ? __('Your games come first, the one you played last on top.') : __('Every game of the league. Log in with Nostr or Google to play, challenge and climb a ladder.') }}
            </p>
        </div>

        {{-- Casual 1v1 (P23 S3): the quickest way into a Rocket League or EA FC match, the game picked here. --}}
        @if (\App\Support\Series\CasualLobby::games() !== [])
            <livewire:casual-play />
        @endif

        <ul class="m-0 flex list-none flex-col gap-4 p-0">
            @foreach ($games as $game)
                @php
                    $modes = $registry->get($game['slug'])->modes();
                    $primary = $game['actions'][0];
                    $more = array_slice($game['actions'], 1);
                @endphp
                <li id="{{ $game['slug'] }}" class="grid grid-cols-1 gap-4 rounded-lg bg-card p-4 sm:grid-cols-[240px_minmax(0,1fr)] lg:grid-cols-[320px_minmax(0,1fr)] lg:gap-6 lg:p-5" style="--game: {{ $game['colour'] }}" data-test="play-game-{{ $game['slug'] }}">
                    <a href="{{ $game['page'] }}" class="block self-start">
                        <x-game-cover :game="$game['slug']" size="card" class="w-full rounded-md border-b-[3px] border-(--game)" :loading="$loop->first ? 'eager' : 'lazy'" />
                    </a>
                    <div class="flex min-w-0 flex-col gap-3">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2 class="m-0 font-display text-xl font-bold"><a href="{{ $game['page'] }}" class="text-ink hover:text-ink">{{ $game['name'] }}</a></h2>
                            @if ($game['played'])
                                <span class="text-xs text-ink-3">{{ __('You play this') }}</span>
                            @endif
                        </div>

                        <dl class="m-0 grid grid-cols-[max-content_minmax(0,1fr)] gap-x-4 gap-y-1 text-[13px]">
                            @foreach ($modes as $mode)
                                <dt class="font-bold text-ink">{{ __($mode->name) }}</dt>
                                <dd class="m-0 text-ink-2">{{ $modeFacts($mode) }}</dd>
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
            @endforeach
        </ul>
    </div>
</x-layouts::app>
