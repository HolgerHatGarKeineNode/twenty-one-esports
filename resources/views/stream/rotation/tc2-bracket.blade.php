{{--
    TC2 · tournament bracket (look C). PLACEHOLDER until the designed slide lands (plan
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
<text x="40" y="70" font-family="Unbounded" font-weight="800" font-size="32" fill="#FFFFFF">{{ \App\Support\TwentyOne\Stream\PublicName::limit($tournament['name'], 40) }} · {{ $tournament['countdown'] }}</text>
<text x="40" y="110" font-family="JetBrains Mono" font-size="20" fill="#B8B2A7">Who plays · +{{ $tournament['openSpots'] }} open spots</text>
@foreach ($tournament['roster'] as $row)
<text x="40" y="{{ 145 + 30 * $loop->index }}" font-family="JetBrains Mono" font-size="20" fill="#FFFFFF">#{{ $row['seed'] }} {{ \App\Support\TwentyOne\Stream\PublicName::limit($row['name'], 24) }} {{ $row['rating'] ?? '' }}</text>
@endforeach
@if ($tournament['preview'] !== null)
<text x="560" y="110" font-family="JetBrains Mono" font-size="20" fill="#B8B2A7">{{ $tournament['preview']['stageNote'] }} · A preview, not the draw</text>
@if ($tournament['preview']['kind'] === 'groups')
@foreach ($tournament['preview']['groups'] as $letter => $members)
<text x="560" y="{{ 145 + 30 * $loop->index }}" font-family="JetBrains Mono" font-size="18" fill="#FFFFFF">Group {{ $letter }}:@foreach ($members as $side) #{{ $side['seed'] }} {{ $side['name'] === null ? 'Open spot' : \App\Support\TwentyOne\Stream\PublicName::limit($side['name'], 12) }}@endforeach</text>
@endforeach
@else
@foreach (array_slice($tournament['preview']['matches'], 0, 12) as $match)
<text x="560" y="{{ 145 + 30 * $loop->index }}" font-family="JetBrains Mono" font-size="18" fill="#FFFFFF">@foreach ($match['sides'] as $side){{ $loop->first ? '' : ' vs ' }}#{{ $side['seed'] }} {{ $side['name'] === null ? 'Open spot' : \App\Support\TwentyOne\Stream\PublicName::limit($side['name'], 16) }}@endforeach</text>
@endforeach
@endif
@if ($tournament['preview']['byes'] !== [])
<text x="560" y="600" font-family="JetBrains Mono" font-size="18" fill="#B8B2A7">Byes: {{ implode(', ', $tournament['preview']['byes']) }}</text>
@endif
@endif
<text x="40" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $tournament['url'] }}</text>
</svg>
