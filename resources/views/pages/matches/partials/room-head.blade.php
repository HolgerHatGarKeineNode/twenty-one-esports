{{--
    The head of the match room (MatchRoom-*.dc.html, TournamentMatchRoom.dc.html): cover, the chips of the match
    (kind, best of, region, live state), the versus plate in display type, one line of what counts, and on the
    right the clock of the step that runs (Min / Sek). Before any game the plate reads "VS"; once games are entered
    it carries the series score (`series-score`). Needs $m, $wins, $games, $chip, $status, $casual, $roomClock,
    $cardClock, $seriesMeta and $mySide from pages/matches/⚡room.
--}}
@php
    $initial = fn (string $name): string => mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
    $kind = $m->rated ? __('Rated') : ($m->tournament_match_id !== null ? __('Tournament') : __('Casual'));
    $scored = $games !== [] || $m->status->hasResult();
    $live = $m->status->isRunning() && $m->status === \App\Enums\SeriesStatus::Accepted && ! $m->start_at?->isFuture();
@endphp
<section aria-labelledby="room-h" class="rv-room-head max-sm:flex-col max-sm:items-stretch" data-test="room-versus">
    <a href="{{ \App\Support\GameNames::page($m->game) }}" class="shrink-0 max-sm:hidden" title="{{ \App\Support\GameNames::game($m->game) }}" aria-label="{{ \App\Support\GameNames::game($m->game) }}">
        <x-game-cover :game="$m->game" size="thumb" class="w-32 rounded-[4px]" data-test="room-game-cover" />
    </a>
    <div class="flex min-w-0 grow flex-col gap-1">
        <span class="flex flex-wrap items-center gap-2" data-test="room-state">
            <span class="rv-tag">{{ $kind }} {{ $m->mode }}</span>
            @unless ($m->isTeamMatch())
                <span class="rv-tag">{{ __('Best of :n', ['n' => $m->best_of]) }}</span>
            @endunless
            @if ($m->lobby_region)
                <span class="rv-tag">{{ __('Region :region', ['region' => $m->lobby_region]) }}</span>
            @endif
            <span class="rv-tag max-sm:hidden">{{ $m->label() }}</span>
            @if ($live)
                <span class="rv-live" style="height: 20px" data-test="room-status"><i></i>{{ __('Match running') }}</span>
            @else
                <span @class(['rv-tag', 'is-ok' => $m->status->hasResult(), 'is-bad' => $m->status === \App\Enums\SeriesStatus::Disputed, 'is-btc' => ! $m->status->hasResult() && $m->status !== \App\Enums\SeriesStatus::Disputed]) data-test="room-status">{{ $status[0] }}</span>
            @endif
        </span>
        <h1 id="room-h" class="rv-vs m-0">
            <span class="rv-av is-o size-10 text-base max-sm:hidden" aria-hidden="true">{{ $initial($m->sideName('challenger')) }}</span>
            <span class="rv-vs-name" title="{{ $m->sideName('challenger') }}" data-test="side-name-challenger">{{ $m->sideName('challenger') }}</span>
            {{-- Before any game "VS", then the series score in its place (the hook keeps its bare text: "1 : 0"). --}}
            <span class="rv-vs-mark" aria-label="{{ $scored ? __('Series :a : :b', ['a' => $wins['challenger'], 'b' => $wins['challenged']]) : __('against') }}" data-test="series-score">{{ $scored ? $wins['challenger'].' : '.$wins['challenged'] : 'VS' }}</span>
            <span class="rv-vs-name" title="{{ $m->sideName('challenged') }}" data-test="side-name-challenged">{{ $m->sideName('challenged') }}</span>
            <span class="rv-av size-10 text-base max-sm:hidden" aria-hidden="true">{{ $initial($m->sideName('challenged')) }}</span>
        </h1>
        {{-- Phone (MatchRoom-*-Phone): the chips already say best of and mode, so only the casual line stays (one line). --}}
        <span class="rv-m text-[13px]" data-test="series-meta"><span @class(['max-sm:hidden' => ! $m->rated])>{{ $seriesMeta }}{{ $m->rated ? '' : '; ' }}</span>{{ $m->rated ? '' : __('casual: no block, no sats until Block 0') }}</span>
    </div>
    @if ($roomClock !== null)
        {{-- Readable at any distance (R2): minutes and seconds under an hour, hours up to a day, then days ("1397 : 23 Min" read as nonsense). On a phone the step's card carries it when $cardClock. --}}
        @php($clockLeft = (int) now()->diffInSeconds($roomClock['at'], true))
        <span @class(['flex shrink-0 items-center gap-3 sm:flex-col sm:items-end sm:gap-1', 'max-sm:hidden' => $cardClock ?? false]) data-test="room-clock">
            <span class="rv-m">{{ $roomClock['label'] }}</span>
            <x-ui.countdown :at="$roomClock['at']" :units="$clockLeft >= 86400 ? 'dhm' : ($clockLeft >= 3600 ? 'hms' : 'ms')" size="sm" :label="$roomClock['label']" />
        </span>
    @endif
</section>
