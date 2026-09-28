@props(['game' => null, 'except' => null])

{{--
    The league's casual cups (P25) as a side mention: one compact row each,
    open for sign-up or running, of one game or of all, every game's EU and
    US cup next to each other (user, 2026-09-28: separate EU and US cups),
    each with its region and its start in the viewer's zone: their own zone
    when signed in, else the browser's (the league's zone until the browser
    says otherwise). Never a poster or a hero: those belong to the special
    tournaments (user, 2026-09-28: "Casual Cups sollen nicht so einen
    präsenten Platz bekommen und eher Seitenerwähnungen bleiben").
    `except`: a tournament id to leave out (the cup whose page this is).
    Renders nothing when no cup is on.
--}}
@php
    use App\Enums\TournamentStatus;
    use App\Models\Tournament;
    use App\Support\LeagueTime;
    use App\Support\Tournaments\CasualCups;

    $cupRows = Tournament::query()->casualCup()
        ->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Running])
        ->when($game !== null, fn ($query) => $query->where('game', $game))
        ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
        ->orderBy('game')->orderBy('cup_series')->orderBy('id')
        ->limit(max(3, count(CasualCups::enabledGames()) * max(1, count(CasualCups::regions()))))->get();
    $cupSignups = app(\App\Support\Tournaments\TournamentSignups::class);
    $viewerZone = auth()->user()?->timezone;
    $cupZone = $viewerZone ?? LeagueTime::zone();
@endphp

@if ($cupRows->isNotEmpty())
    <ul {{ $attributes->class('m-0 grid list-none grid-cols-1 gap-1 p-0 lg:grid-cols-2') }} aria-label="{{ __('Casual cups') }}" data-test="cup-mentions">
        @foreach ($cupRows as $cupRow)
            @php
                $cupPlaces = $cupSignups->places($cupRow);
                $cupRegion = CasualCups::regionLabel($cupRow);
                $cupStart = LeagueTime::stamp($cupRow->starts_at, $cupZone);
            @endphp
            <li wire:key="cup-mention-{{ $cupRow->id }}">
                <a href="{{ route('tournaments.show', $cupRow) }}" class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-hairline px-3 py-2 text-xs text-ink-2 hover:border-line hover:text-ink" data-test="cup-mention" data-region="{{ $cupRegion }}">
                    <span class="inline-flex h-6 items-center rounded-tag bg-raised px-2 font-bold">{{ __('Casual') }}</span>
                    {{-- The name carries the region: "Chess Casual Cup EU #1". --}}
                    <span class="min-w-0 font-bold text-ink">{{ $cupRow->name }}</span>
                    <span>{{ $cupRow->status->label() }}</span>
                    @if ($cupRow->status === TournamentStatus::Signup)
                        <span class="tabular-nums" aria-label="{{ __(':taken of :places spots taken', ['taken' => $cupPlaces['taken'], 'places' => $cupPlaces['places']]) }}">{{ $cupPlaces['taken'] }}/{{ $cupPlaces['places'] }}</span>
                        <time datetime="{{ $cupRow->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="tabular-nums" data-test="cup-mention-start"
                              @if ($viewerZone === null) x-data="localTime({ at: {{ (int) $cupRow->starts_at->getTimestampMs() }}, zone: @js($cupZone), label: ':time' })" x-text="text || @js($cupStart)" @endif>{{ $cupStart }}</time>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
@endif
