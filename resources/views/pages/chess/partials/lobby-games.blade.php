{{--
    "Your games" in the chess lobby: the live game this player is in, daily
    challenges received, then the daily games, those waiting for this
    player's move first. A guest is asked to log in.
--}}
@php
    use App\Support\Rating\Ratings;

    $dailyPool = Ratings::headline(null, 'chess', 'correspondence')['pool'];
    $dailyGames = $this->dailyGames;
    $challenges = $this->dailyChallenges;
    $nowMs = (int) now()->getTimestampMs();
    $tag = 'shrink-0 rounded-xs px-1.5 py-0.5 text-[11px] leading-4 font-bold';
@endphp

<section id="your-games" aria-labelledby="games-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:col-span-4 lg:px-5 xl:max-2xl:col-span-5" data-test="lobby-daily">
    <span class="flex items-baseline justify-between gap-3">
        <h2 id="games-h" class="m-0 text-[15px] font-bold">{{ __('Your games') }}</h2>
        @auth<a href="{{ route('me.correspondence') }}" class="inline-flex min-h-11 items-center text-xs text-ink lg:min-h-6" data-test="lobby-daily-all">{{ __('All :count', ['count' => $dailyGames->count()]) }}</a>@endauth
    </span>

    @guest
        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Log in to see your games.') }}</p>
        <x-button variant="quiet" :href="route('login')" class="self-start">{{ __('Log in') }}</x-button>
    @else
        <ul role="list" class="m-0 flex list-none flex-col p-0 empty:hidden">
            @if ($active)
                @php
                    $opponent = $active->opponentOf($user);
                @endphp
                <li>
                    <a href="{{ route('games.show', $active) }}" class="flex min-h-14 items-center gap-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="resume-game">
                        <x-avatar :user="$opponent" :size="28" class="rounded-sm" />
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <b class="truncate text-[13px]">{{ $opponent?->displayName() }}</b>
                            <span class="text-xs text-ink-2">{{ __('Blitz 5+3') }}, {{ $active->number() }}</span>
                        </span>
                        <span class="{{ $tag }} flex items-center gap-1.5 bg-btc text-on-btc"><span class="size-1.5 animate-live rounded-full bg-on-btc" aria-hidden="true"></span>{{ __('Live') }}</span>
                    </a>
                </li>
            @endif

            @foreach ($challenges->take(2) as $challenge)
                <li wire:key="dc-{{ $challenge->id }}" class="flex flex-col gap-2 border-b border-hairline py-2.5" data-test="lobby-daily-challenge">
                    <span class="flex items-center gap-3">
                        <x-avatar :user="$challenge->challenger" :size="28" class="rounded-sm" />
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <b class="truncate text-[13px]">{{ $challenge->challenger->displayName() }}</b>
                            <span class="text-xs text-ink-2">{{ __('Daily chess · Casual · you play :color', ['color' => match ($challenge->color) { 'white' => __('Black'), 'black' => __('White'), default => __('a random colour') }]) }}</span>
                        </span>
                        <span class="{{ $tag }} bg-btc-press text-btc-hi">{{ __('challenges you') }}</span>
                    </span>
                    <span class="grid grid-cols-2 gap-2">
                        <x-button variant="quiet" wire:click="declineDailyChallenge({{ $challenge->id }})">{{ __('Decline') }}</x-button>
                        <x-button variant="secondary" icon="shield-check" wire:click="acceptDailyChallenge({{ $challenge->id }})">{{ __('Accept') }}</x-button>
                    </span>
                </li>
            @endforeach

            @foreach ($dailyGames->take(5) as $daily)
                @php
                    $opp = $daily->opponentOf($user);
                    $mine = $daily->turn() === $daily->colorOf($user);
                    $left = intdiv(max(0, (int) $daily->deadline_ms - $nowMs), 60_000);
                @endphp
                <li wire:key="dg-{{ $daily->id }}">
                    <a href="{{ route('games.show', $daily) }}" class="flex min-h-14 items-center gap-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="lobby-daily-game" data-mine="{{ $mine ? 'true' : 'false' }}">
                        <x-avatar :user="$opp" :size="28" class="rounded-sm" />
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <span class="flex min-w-0 items-baseline gap-2 text-[13px]"><b class="truncate">{{ $opp?->displayName() }}</b><span class="shrink-0 text-xs text-ink-2" data-test="daily-opponent-elo">{{ Ratings::forUser($opp?->id, 'chess', 'correspondence', $dailyPool)['rating'] }}</span></span>
                            <span class="text-xs text-ink-2">{{ $mine ? __(':h h :m min left', ['h' => intdiv($left, 60), 'm' => str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT)]) : __('move :n', ['n' => intdiv($daily->ply, 2) + 1]) }}</span>
                        </span>
                        @if ($mine)
                            <span class="{{ $tag }} bg-btc text-on-btc">{{ __('Your move') }}</span>
                        @else
                            <span class="{{ $tag }} font-normal text-ink-2 shadow-ring">{{ __('Their move') }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        @if (! $active && $challenges->isEmpty() && $dailyGames->isEmpty())
            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="lobby-daily-empty">{{ __('No game running. Start one with a tile above.') }}</p>
        @endif
    @endguest
</section>
