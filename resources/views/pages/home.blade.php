{{--
    Home as the league's hub (2026-09-27): what can I play, what is on right
    now, what comes next. Images, faces and numbers first, one short line of
    copy per block, the rules behind a disclosure at the end.

    Order: the viewer's own open matches (logged in, when there are any);
    the stage, flush under the header: the next tournament with its cover,
    when it starts, its pot and its seats as the faces of who is in (up to
    two more open tournaments beside it), or, with none open, the games
    themselves; under it the Block 0 strip (before the first season) or the
    live season's strip. Then play now (every game with its main action and
    the invite), happening now (live boards, running tournaments, results,
    who joined), the top of every ladder, weekly events and quests, and how
    the season works.

    Everything shown is real (App\Support\Engagement\HomeHub, config/esports.php
    `preseason`); a block with nothing to show says so and offers the action.
--}}
@php
    use App\Support\Badges\BadgeCopy;
    use App\Support\Engagement\HomeHub;
    use App\Support\Engagement\Quests;
    use App\Support\Engagement\WeeklySlots;
    use App\Support\Navigation\ShellNavigation;
    use App\Support\PreSeason;
    use App\Support\SeasonChain\SeasonChains;
    use App\Support\SeasonChain\SeasonRelease;
    use App\Support\SeasonChain\Seasons;

    $user = auth()->user();
    $hub = new HomeHub($user);
    $cups = $hub->cups();
    $live = $hub->live();
    $games = ShellNavigation::current()->games();

    // A live season replaces the Block 0 strip with the season's strip.
    $liveSeason = Seasons::live();
    // The rules name the season they explain: the Pre-Season before and during it, a later season by its name.
    $laterSeason = $liveSeason !== null && $liveSeason->slug !== SeasonRelease::SLUG;
    $season = null;
    if ($liveSeason !== null) {
        $chains = app(SeasonChains::class);
        $season = [
            'name' => BadgeCopy::season($liveSeason->slug),
            'height' => $chains->tip($liveSeason)['height'] ?? 0,
            'mined' => PreSeason::formatSats($chains->chain($liveSeason)->mined()),
            'supply' => PreSeason::formatSats($liveSeason->supply),
        ];
    }

    // P10: the next weekly events, and this week's quests of a logged-in player.
    $weekly = app(WeeklySlots::class)->upcoming(4);
    $quests = $user ? app(Quests::class)->progress($user) : null;

    app(App\Support\PageMeta::class)->describe(
        __('Chess and Rocket League ladder for Bitcoiners'),
        $liveSeason !== null
            ? __('The esports league of the Bitcoin community EINUNDZWANZIG: blitz and daily chess, Rocket League series between clans, login with Nostr. The season is live: every fair rated win mines a block.')
            : __('The esports league of the Bitcoin community EINUNDZWANZIG: blitz and daily chess, Rocket League series between clans, login with Nostr. The Pre-Season starts at Block 0.'),
    );
@endphp

<x-layouts::app section="home" flush :scripts="$live['boards']->isNotEmpty() ? ['resources/js/chess.js'] : []">
    <h1 class="sr-only">{{ __('TWENTY ONE Esports: chess and Rocket League for Bitcoiners') }}</h1>

    <div class="hh-stage flex flex-col gap-5 pb-5 lg:gap-6 lg:pb-8" data-test="home-stage">
        @include('pages.home.your-next', ['items' => $hub->yourNext()])

        @if ($cups !== [])
            @include('pages.home.hero', ['cup' => $cups[0], 'more' => array_slice($cups, 1)])
        @else
            @include('pages.home.games', ['games' => $games, 'live' => $live, 'stage' => true])
        @endif

        {{-- The casual cups: a side mention, never the hero (user, 2026-09-28). --}}
        <x-tournaments.cup-mentions class="px-4 lg:px-12" />

        @if ($season !== null)
            @include('pages.home.season', ['season' => $season])
        @else
            @include('pages.home.block0', ['user' => $user])
        @endif
    </div>

    <div class="flex grow flex-col gap-10 pt-8 pb-10 lg:gap-14 lg:pt-12 lg:pb-14">
        @if ($cups !== [])
            @include('pages.home.games', ['games' => $games, 'live' => $live, 'stage' => false])
        @endif

        @include('pages.home.happening', ['live' => $live, 'running' => $hub->running(), 'results' => $hub->results(), 'newcomers' => $hub->newcomers()])

        @include('pages.home.ladders', ['ladders' => $hub->ladders()])

        @if ($weekly->isNotEmpty() || $quests !== null)
            <div class="grid grid-cols-1 gap-4 px-4 lg:grid-cols-2 lg:gap-5 lg:px-12">
                <x-weekly-events :events="$weekly" class="pl-card" />
                @if ($quests !== null)
                    <x-quests :progress="$quests" class="pl-card" />
                @endif
            </div>
        @endif

        @include('pages.home.rules', ['laterSeason' => $laterSeason, 'liveSeason' => $liveSeason])
    </div>
</x-layouts::app>
