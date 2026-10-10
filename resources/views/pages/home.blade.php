{{--
    The start page, 1:1 from the design canvas of plan "Refactor und Design-Revamp" (boards Main, HomeGuest,
    HomePhone, HomeGuestPhone; template T15, rules R1, R3, R9, R14): the key-art band with the one main action,
    the "gerade eben" bar, then on a 12-column grid the mempool chain with its proud moments and the games
    (browser games first, own-copy games under them) on the left 8, this week's tournaments, "Deine Woche" or
    "Mitspielen", and the season on the right 4. Phones stack it in that order and keep the hero, the bar, the
    chain, the games and quests with the season (or "Mitspielen").

    The band (rule R9): a player sees the featured tournament (organizer tournaments and pots first, never a
    casual cup), a guest the Blockfill week they can play without an account, with the featured tournament as a
    card under it on phones. Each falls back to the other when it has nothing to show.

    Everything shown is real (App\Support\Engagement\HomeBoard, HomeHub, MempoolStrip); a block with nothing to
    show is left out.
--}}
@php
    use App\Support\Engagement\HomeBoard;
    use App\Support\Engagement\HomeHub;
    use App\Support\Engagement\Quests;

    $user = auth()->user();
    $hub = new HomeHub($user);
    $board = new HomeBoard($user);
    $cups = $hub->cups();
    $featured = $cups[0] ?? null;
    $blockfill = $board->blockfillWeek();
    $counts = HomeBoard::counts();
    $strip = $board->strip();
    $games = $board->games();
    $gameCount = count($games['browser']) + count($games['own']);
    $week = $board->week($cups);
    $pride = $board->pride();
    $quests = $user ? app(Quests::class)->progress($user) : null;
    $rating = $board->rating();
    $season = HomeBoard::season();
    // A player's band is the featured tournament, a guest's the Blockfill week; each falls back to the other.
    $hero = match (true) {
        $user !== null && $featured !== null => 'cup',
        $user === null && $blockfill !== null => 'blockfill',
        $featured !== null => 'cup',
        $blockfill !== null => 'blockfill',
        default => null,
    };

    app(App\Support\PageMeta::class)->describe(
        __('Chess and Rocket League ladder for Bitcoiners'),
        $season['live']
            ? __('The esports league of the Bitcoin community EINUNDZWANZIG: rapid, blitz and daily chess, Rocket League series between clans, login with Nostr. The season is live: every fair rated win mines a block.')
            : __('The esports league of the Bitcoin community EINUNDZWANZIG: rapid, blitz and daily chess, Rocket League series between clans, login with Nostr. The Pre-Season starts at Block 0.'),
    )->card(fn () => \App\Support\Cards\PageCard::page('home'));
@endphp

<x-layouts::app section="home" flush>
    {{--
        States the boards do not draw, kept from the user's decisions: an open cup match before anything else
        (CupMatchNow, 2026-10-03), then a player's next match or event (2026-10-02). Both render nothing without one.
    --}}
    @auth
        <livewire:cup-match variant="banner" frame="block px-4 pt-4 lg:px-12 lg:pt-6" />
        <livewire:upcoming-events variant="card" />
    @endauth

    @if ($hero === 'cup')
        @include('pages.home.band-cup', ['cup' => $featured, 'live' => $counts['live']])
    @elseif ($hero === 'blockfill')
        @include('pages.home.band-blockfill', ['week' => $blockfill, 'hub' => $hub, 'user' => $user, 'featured' => $user === null ? $featured : null, 'live' => $counts['live']])
    @else
        <h1 class="sr-only">{{ __('TWENTY ONE Esports: chess and Rocket League for Bitcoiners') }}</h1>
    @endif

    <x-ui.live-bar :ticks="$board->ticker()" />

    <div class="mx-auto grid w-full max-w-[1440px] grid-cols-1 items-start gap-0 lg:grid-cols-[minmax(0,8fr)_minmax(0,4fr)] lg:gap-6 lg:px-12 lg:pt-6 lg:pb-8" data-test="home-grid">
        <div class="flex min-w-0 flex-col lg:gap-8">
            @include('pages.home.mempool', ['strip' => $strip, 'counts' => $counts, 'pride' => $pride, 'user' => $user, 'best' => $board->bestToday()])
            @include('pages.home.games', ['games' => $games, 'count' => $gameCount])
        </div>

        <aside class="flex min-w-0 flex-col lg:gap-6" aria-label="{{ __('This week') }}">
            @guest
                @include('pages.home.join', ['count' => $gameCount])
            @endguest
            @if ($week !== [])
                @include('pages.home.week', ['rows' => $week, 'user' => $user])
            @endif
            @if ($quests !== null)
                @include('pages.home.your-week', ['quests' => $quests, 'rating' => $rating])
            @endif
            @include('pages.home.season-card', ['season' => $season, 'user' => $user, 'quests' => $quests])
        </aside>
    </div>
</x-layouts::app>
