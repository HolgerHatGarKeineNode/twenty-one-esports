@props(['at'])

{{--
    One tournament moment on a card or in a list: "Sa, 3. Okt 2026, 20:00 MESZ"
    in the league's zone (App\Support\LeagueTime), always with the zone named,
    the UTC instant machine-readable in `datetime` and readable in the title.
--}}
<time datetime="{{ $at->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" title="{{ \App\Support\LeagueTime::utc($at) }}" {{ $attributes }}>{{ \App\Support\LeagueTime::stamp($at) }}</time>
