{{--
    Login, 1:1 from the design canvas of plan "Refactor und Design-Revamp" (boards Login, LoginPhone; rules R13,
    R14, R15): on the left the league behind its covers, its name in one line, its counts, the featured
    tournament and the last champion; on the right "Willkommen in der Liga" with the two ways in (Google first,
    Nostr for people with a key), the member line and what comes after. Phones keep the covers as a strip, the
    counts, both buttons and the member line. Google and Nostr only (user decision). The right column is the
    <x-nostr-login> scope: the buttons run the P3 flow, `busy` shows the pending line, `error` the alert.
--}}
@php
    use App\Support\Engagement\HomeBoard;
    use App\Support\Engagement\HomeHub;

    $stats = HomeBoard::stats();
    $live = HomeBoard::counts()['live'];
    $games = (new HomeBoard(null))->games();
    $covers = array_column([...$games['browser'], ...$games['own']], 'slug');
    $gameCount = count($covers);
    $featured = (new HomeHub(null))->cups()[0] ?? null;
    $champion = HomeBoard::lastChampion();
    // The board's backdrop: the browser games, then three own-copy games, twice (Login.dc.html).
    $wall = array_values(array_intersect(['hyperbitcoinization', 'proof-of-pong', 'chess', 'blockfill', 'blockli', 'rocket-league', 'age-of-empires-2', 'tmnf'], $covers)) ?: $covers;
    $backdrop = array_slice([...$wall, ...$wall, ...$wall], 0, 16);

    // Shared links get a preview; search engines leave the page out.
    $meta = app(App\Support\PageMeta::class)->describe(__('Log in'), __('Log in to TWENTY ONE esports with Google or Nostr and play chess and Rocket League in the league.'));
    $meta->noindex = true;
    $meta->card(fn () => App\Support\Cards\PageCard::page('login'));
@endphp

<x-layouts::app :title="__('Log in')" flush>
    <div class="grid grow grid-cols-1 lg:min-h-[844px] lg:grid-cols-[minmax(0,1fr)_560px]" data-test="login-page">
        {{-- The league --}}
        <section aria-label="{{ __('The league') }}" class="relative flex flex-col gap-4 overflow-hidden px-4 pt-5 lg:gap-6 lg:border-r lg:border-hairline lg:px-12 lg:py-8">
            <div class="absolute inset-0 hidden grid-cols-4 gap-2 p-2 opacity-35 lg:grid" aria-hidden="true">
                @foreach ($backdrop as $slug)
                    <x-game-cover :game="$slug" class="w-full rounded-[4px] [&_img]:object-cover" />
                @endforeach
            </div>
            <div class="absolute inset-0 hidden bg-[linear-gradient(180deg,rgba(10,10,11,.4),#0A0A0B_85%)] lg:block" aria-hidden="true"></div>

            <div class="flex h-24 gap-2 overflow-hidden lg:hidden" aria-hidden="true">
                @foreach (array_slice($covers, 0, 3) as $slug)
                    <x-game-cover :game="$slug" class="w-40 shrink-0 rounded-[4px] opacity-70 [&_img]:object-cover" />
                @endforeach
            </div>

            <div class="relative flex flex-col gap-4 lg:mt-[300px] lg:gap-6">
                <h1 class="m-0 font-display text-[26px] leading-[1.1] font-bold lg:text-[44px] lg:leading-[1.05]">{{ trans_choice('The Bitcoin league. :count game, one account.|The Bitcoin league. :count games, one account.', $gameCount) }}</h1>
                <div class="flex gap-4 lg:gap-8" data-test="login-stats">
                    <span class="flex flex-col"><span class="font-display text-[22px] font-bold tabular-nums lg:text-[30px]">{{ $stats['players'] }}</span><span class="rv-m">{{ __('Players') }}</span></span>
                    <span class="flex flex-col max-lg:hidden"><span class="font-display text-[30px] font-bold tabular-nums">{{ $stats['clans'] }}</span><span class="rv-m">{{ __('Clans') }}</span></span>
                    <span class="flex flex-col"><span class="font-display text-[22px] font-bold tabular-nums lg:text-[30px]">{{ $stats['games'] }}</span><span class="rv-m">{{ __('Games') }}</span></span>
                    <span class="flex flex-col">
                        <span class="flex items-center gap-1 lg:gap-2">
                            <span class="rv-live max-lg:h-[18px] max-lg:px-1"><i aria-hidden="true"></i><span class="max-lg:sr-only">LIVE</span></span>
                            <span class="font-display text-[22px] font-bold tabular-nums lg:text-[30px]">{{ $live }}</span>
                        </span>
                        <span class="rv-m"><span class="max-lg:hidden">{{ __('are playing now') }}</span><span class="lg:hidden">{{ __('live') }}</span></span>
                    </span>
                </div>
                @if ($featured !== null || $champion !== null)
                    <div class="hidden flex-wrap gap-6 lg:flex">
                        @if ($featured !== null)
                            @php($tournament = $featured['tournament'])
                            <a href="{{ route('tournaments.show', $tournament) }}" class="rv-card flex items-center gap-3 border-btc-ring bg-feature p-4" data-test="login-featured">
                                <x-icon name="trophy" :size="18" class="shrink-0 text-btc" />
                                <span class="flex flex-col">
                                    <b>{{ HomeBoard::shortName($tournament) }}</b>
                                    <span class="rv-m">{{ HomeBoard::dayClock($tournament->starts_at) }}@if (isset($featured['pot']['sats'])), <b class="text-btc">{{ HomeBoard::sats((int) $featured['pot']['sats']) }} Sats</b>@endif</span>
                                </span>
                            </a>
                        @endif
                        @if ($champion !== null)
                            <span class="rv-card flex items-center gap-3 p-4" data-test="login-champion">
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-btc text-xs font-bold text-on-btc" aria-hidden="true">{{ mb_strtoupper(mb_substr($champion['name'], 0, 1)) }}</span>
                                <span class="flex flex-col">
                                    <b>{{ $champion['name'] }}</b>
                                    <span class="rv-m">{{ $champion['prize'] !== null
                                        ? __('Champion :tournament, :sats Sats prize money', ['tournament' => $champion['tournament'], 'sats' => HomeBoard::sats($champion['prize'])])
                                        : __('Champion :tournament', ['tournament' => $champion['tournament']]) }}</span>
                                </span>
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        {{-- The way in --}}
        <x-nostr-login class="flex flex-col gap-4 px-4 pt-4 pb-20 lg:px-12 lg:py-16" aria-labelledby="li-h">
            <h2 id="li-h" class="m-0 hidden font-display text-[30px] leading-[1.15] font-bold lg:block">{{ __('Welcome to the league') }}</h2>
            <span class="text-ink-2 max-lg:order-3 max-lg:text-xs max-lg:text-ink-3">{{ __('New here? The same buttons create your player. No password.') }}</span>

            <button type="button" class="rv-b h-[52px] w-full max-lg:order-1" x-on:click="loginWithGoogle()" x-bind:disabled="busy" data-test="login-google">{{ __('Continue with Google') }}</button>
            <button type="button" class="rv-b2 h-[52px] w-full max-lg:order-2" x-on:click="loginWithNostr()" x-bind:disabled="busy" data-test="login-nostr">{{ __('Log in with Nostr') }}</button>

            <span class="flex items-center gap-1.5 text-xs text-btc-hi max-lg:order-2" role="status" x-show="busy" x-cloak data-test="login-pending">
                <span class="inline-block size-[7px] animate-live rounded-full bg-btc-hi" aria-hidden="true"></span>
                {{ __('Waiting for your confirmation') }}
            </span>
            <div class="flex items-start gap-2.5 rounded-[4px] border border-live-ring bg-loss-tint px-3.5 py-2.5 text-xs leading-normal text-ink max-lg:order-2" role="alert" x-show="error" x-cloak data-test="login-error">
                <x-icon name="alert" :size="16" class="mt-px shrink-0 text-loss" />
                <span x-text="error"></span>
            </div>

            <span class="rv-m max-lg:hidden">{{ __('Nostr: for everyone with a key, by browser extension or app. By continuing you accept the') }} <a href="{{ route('rules') }}" class="rv-lk">{{ __('rules') }}</a>.</span>

            <span class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[4px] border border-btc-ring bg-feature px-3 py-2.5 text-[13px] max-lg:order-4" data-test="login-member">
                <x-icon name="shield-check" :size="14" class="shrink-0 text-btc" />
                <b>{{ __('Member of EINUNDZWANZIG?') }}</b>
                <span class="rv-m max-lg:hidden">{{ __('Your badge comes by itself') }}</span>
                <span class="grow"></span>
                <a href="https://verein.einundzwanzig.space" class="rv-lk" rel="noopener">{{ __('Become a member') }}</a>
            </span>

            <h3 class="rv-h3 mt-4 hidden lg:block">{{ __('After that') }}</h3>
            <ol class="m-0 hidden list-none flex-col gap-2 p-0 lg:flex" aria-label="{{ __('First steps') }}">
                <li class="rv-card flex items-center gap-3 p-4">
                    <x-icon name="user" :size="20" class="shrink-0 text-btc" />
                    <span class="flex flex-col"><b>{{ __('Create your account') }}</b><span class="rv-m">{{ __('with Google or Nostr') }}</span></span>
                </li>
                <li>
                    <a href="{{ route('chess.lobby') }}" class="rv-card flex items-center gap-3 p-4">
                        <x-icon name="play" :size="20" class="shrink-0 text-btc" />
                        <span class="flex flex-col"><b>{{ __('Casual game') }}</b><span class="rv-m">{{ __('Chess or a browser game') }}</span></span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('clans.index') }}" class="rv-card flex items-center gap-3 p-4">
                        <x-icon name="clans" :size="20" class="shrink-0 text-btc" />
                        <span class="flex flex-col"><b>{{ __('Join a clan') }}</b><span class="rv-m">{{ __('for clan series') }}</span></span>
                    </a>
                </li>
            </ol>
        </x-nostr-login>
    </div>
</x-layouts::app>
