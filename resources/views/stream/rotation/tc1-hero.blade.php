{{--
    TC1 · tournament hero (look C). PLACEHOLDER until the designed slide lands (plan
    2026-09-27T1456-stream-tournament-slides, P2): prints the contract fields as text.

    Data contract (App\Support\TwentyOne\Stream\TournamentSlides::data(), via SceneSource::rotation()):
      $tournament  array:
        id int, name string, description string|null, status "Sign-up open",
        game string ("EA Sports FC 26"), mode string ("1v1"), format string ("Two Stage"), teamSize int, rated bool,
        where "Online"|"On site",
        startsAt string ("Sat 3 Oct, 20:00", Europe/Berlin), signupClosesAt string|null (same format),
        countdown string ("6d 06:05:01", "06:05:01" below a day; ticks every second), countdownLabel "Sign-up closes in",
        taken int, places int, spotsLeft int (player places),
        roster list<array{seed: int, name: string, rating: int|null}> (seeded entries, best first, at most 8), openSpots int,
        preview array|null: {kind: 'groups'|'bracket', groups?: array<string, list<array{seed: int, name: string|null}>>,
                             matches?: list<array{sides: list<array{seed: int|null, name: string|null}>}>, byes: list<int>,
                             stageNote: string}  (null name = open spot)
        cover string|null (data:image/jpeg;base64,…), url "esports.einundzwanzig.space/tournaments/1" (text, no scheme)
      $stats  StreamStats::all()
    Names are cleaned (PublicName::clean), not shortened or escaped: the view limits and Blade escapes.
--}}
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
<rect width="1280" height="720" fill="#0A0A0B"/>
@if ($tournament['cover'] !== null)
<image x="880" y="40" width="360" height="203" href="{{ $tournament['cover'] }}"/>
@endif
<text x="40" y="80" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">{{ \App\Support\TwentyOne\Stream\PublicName::limit($tournament['name'], 36) }}</text>
<text x="40" y="130" font-family="JetBrains Mono" font-size="22" fill="#FFFFFF">{{ $tournament['game'] }} · {{ $tournament['mode'] }} · {{ $tournament['format'] }} · {{ $tournament['where'] }}{{ $tournament['rated'] ? ' · Rated' : '' }}</text>
<text x="40" y="170" font-family="JetBrains Mono" font-size="20" fill="#B8B2A7">{{ \App\Support\TwentyOne\Stream\PublicName::limit((string) $tournament['description'], 80) }}</text>
<text x="40" y="240" font-family="JetBrains Mono" font-size="22" fill="#FFFFFF">{{ $tournament['status'] }} · {{ $tournament['countdownLabel'] }} {{ $tournament['countdown'] }}</text>
<text x="40" y="280" font-family="JetBrains Mono" font-size="22" fill="#FFFFFF">Starts {{ $tournament['startsAt'] }} · Sign-up closes {{ $tournament['signupClosesAt'] }}</text>
<text x="40" y="320" font-family="JetBrains Mono" font-size="22" fill="#FFFFFF">{{ $tournament['taken'] }}/{{ $tournament['places'] }} places · {{ $tournament['spotsLeft'] }} left · team size {{ $tournament['teamSize'] }}</text>
<text x="40" y="380" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#F7931A">{{ $tournament['spotsLeft'] > 0 ? 'Sign up' : 'Watch it live' }}</text>
<text x="40" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $tournament['url'] }}</text>
</svg>
