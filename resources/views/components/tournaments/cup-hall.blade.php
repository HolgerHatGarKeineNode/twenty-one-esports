@props(['winner' => null, 'next' => null, 'empty' => false])

{{--
    The head of the casual cups on the tournaments page (P3 of plan
    mempool-streifen; user, 2026-09-29: the cups "gehen ... total unter" and
    "er versteht sie gar nicht als Turniere, weil es nirgends steht"). It sits
    under the organizers' tournaments and stays smaller than them: their hero
    has the 16:9 cover and a 40 px name, this head a 200 px cover and a 20 px one.

    - The heading with the word tournament next to it, and one line on what a
      cup is (the league opens it on its own, it is casual: RulesPage "Casual
      cups", TournamentMatchMaker: a cup has no ladder, its games move only
      the casual Elo).
    - The last cup's winner as the proud moment (CupBoard::lastWinner()), only
      when there is one.
    - The next cup to sign up for (CupBoard::next()): the game's cover, its
      name, mode, format and places, the seats with the faces of who is in
      (the first open seat is the way in, as on a game page's poster), a live
      countdown to the start (countdown() in resources/js/tournamentLanding.js)
      and the button. Without one, a line says when the next cup comes (the
      league's gap after a final or a call-off). No format before sign-up
      closes: CasualCups::formatFor() picks it by the field (review of P3).
      At zero the countdown says sign-up closed instead of a dead button.

    $winner: CupBoard::lastWinner(). $next: a CupBoard row or null. $empty: no cup is open or running.
--}}
@php
    use App\Support\Pages\RulesPage;
    use App\Support\Tournaments\Lobbies;

    if ($next !== null) {
        $nextCup = $next['tournament'];
        $nextShow = route('tournaments.show', $nextCup);
        $nextIn = $next['entered'];
        $nextHref = $nextIn ? $nextShow : (auth()->check() ? route('tournaments.signup', $nextCup) : route('login'));
        // Up to 16 seats: the faces, then the open ones; a bigger field ends in one "+N" tile (the poster's rule).
        $tiles = min($next['places'], 16);
        $overflow = $next['places'] > $tiles;
        $faces = array_slice($next['faces'], 0, $overflow ? min(count($next['faces']), $tiles - 1) : $tiles);
        $open = max(0, ($overflow ? $tiles - 1 : $tiles) - count($faces));
        $rest = $next['places'] - count($faces) - $open;
        // The first frame of the countdown comes from the server, so nothing jumps.
        $seconds = max(0, (int) now()->diffInSeconds($nextCup->starts_at, false));
        $days = intdiv($seconds, 86400);
        $countdown = ($days > 0 ? trans_choice(':count day|:count days', $days).' ' : '').sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
@endphp

<div class="flex flex-col gap-4 rounded-card bg-card p-4 shadow-ring-hairline lg:gap-5 lg:p-6" data-test="cup-hall">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
        <div class="flex min-w-0 flex-col gap-2">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <h2 id="cup-board-h" class="m-0 font-display text-xl leading-tight font-bold lg:text-2xl">{{ __('Casual cups') }}</h2>
                <span class="inline-flex h-6 items-center gap-1.5 rounded-tag bg-btc-chip px-2 text-xs font-bold text-btc-hi" data-test="cup-kind"><x-icon name="trophy" :size="14" />{{ __('Tournaments') }}</span>
            </div>
            <p class="m-0 max-w-[65ch] text-[13px] leading-normal text-ink-2" data-test="cup-explainer">{{ __('The league opens a cup for every game and region on its own. Its games are casual and move only your casual Elo.') }}</p>
        </div>

        @if ($winner !== null)
            <a href="{{ route('tournaments.show', $winner['cup']) }}" class="flex min-h-11 w-full min-w-0 items-center gap-3 rounded-md bg-btc-chip py-2 pr-4 pl-2 hover:bg-raised lg:w-auto lg:max-w-[22rem] lg:shrink-0 lg:self-start" data-test="cup-winner">
                @if ($winner['user'] !== null)
                    <x-avatar :user="$winner['user']" :size="44" class="rounded-tag" />
                @else
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-tag bg-raised text-btc-hi" aria-hidden="true"><x-icon name="trophy" :size="20" /></span>
                @endif
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="inline-flex items-center gap-1.5 text-xs text-btc-hi"><x-icon name="trophy" :size="14" />{{ __('Won the last cup') }}</span>
                    <span class="font-display text-[15px] leading-tight font-bold [overflow-wrap:anywhere] text-ink" data-test="cup-winner-name">{{ $winner['name'] }}</span>
                    <span class="text-xs [overflow-wrap:anywhere] text-ink-2" data-test="cup-winner-cup">{{ $winner['cup']->name }}</span>
                </span>
            </a>
        @endif
    </div>

    @if ($next !== null)
        <article class="grid grid-cols-[96px_minmax(0,1fr)] items-start gap-x-3 gap-y-4 border-t border-hairline pt-4 sm:grid-cols-[160px_minmax(0,1fr)] sm:gap-x-4 lg:grid-cols-[200px_minmax(0,1fr)_minmax(0,16rem)] lg:items-center lg:gap-x-6 lg:pt-5"
                 aria-labelledby="cup-next-h" data-test="cup-next" data-tournament="{{ $nextCup->id }}"
                 x-data="{ started: false }" x-on:countdown-zero="started = true">
            <a href="{{ $nextShow }}" class="block" tabindex="-1" aria-hidden="true">
                <x-game-cover :game="$nextCup->game" size="card" class="w-full rounded-xs" data-test="cup-next-cover" />
            </a>

            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="inline-flex items-center gap-1.5 text-xs text-btc-hi"><x-icon name="clock" :size="14" />{{ $nextCup->status->label() }}</span>
                    <h3 id="cup-next-h" class="m-0 font-display text-lg leading-tight font-bold break-words lg:text-xl">
                        <a href="{{ $nextShow }}" class="text-ink hover:text-btc-hi" data-test="cup-next-name">{{ $nextCup->name }}</a>
                    </h3>
                    <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="cup-next-facts">{{ __(':mode tournament, :places places', ['mode' => Lobbies::gameLine($nextCup), 'places' => $next['places']]) }}</p>
                    {{-- The format is not known before the close: CasualCups::formatFor() picks it by how many signed up. --}}
                    <p class="m-0 text-xs leading-normal text-ink-3" data-test="cup-next-format">{{ Lobbies::isLobby($nextCup) ? __('One lobby match: everyone plays at once, in lobbies of at most 8.') : __('The format is set at the start, by how many play.') }}</p>
                </div>

                {{-- As many columns as seats up to eight, each at most 48 px: four seats are four real faces, not half a row. --}}
                <ul class="hh-seats m-0 list-none p-0" style="grid-template-columns: repeat({{ max(1, min($tiles, 8)) }}, minmax(0, 3rem))" aria-label="{{ __(':taken of :places spots taken', ['taken' => $next['taken'], 'places' => $next['places']]) }}" data-test="cup-next-seats">
                    @foreach ($faces as $index => $face)
                        <li @class(['hh-seat is-taken', 'is-you' => auth()->id() === $face->id]) style="--i: {{ $index }}" title="{{ $face->displayName() }}" data-test="cup-next-face">
                            <x-avatar :user="$face" :size="48" class="rounded-none" />
                        </li>
                    @endforeach
                    @for ($seat = 0; $seat < $open; $seat++)
                        <li class="hh-seat is-open">
                            @if ($seat === 0 && ! $nextIn)
                                <a href="{{ $nextHref }}" x-show="! started" class="flex size-full items-center justify-center text-ink-2 hover:text-btc-hi" aria-label="{{ __('Take your seat') }}"><x-icon name="user" :size="16" /></a>
                            @endif
                        </li>
                    @endfor
                    @if ($overflow)
                        <li class="hh-seat is-open text-xs font-bold text-ink-2">+{{ $rest }}</li>
                    @endif
                </ul>
            </div>

            <div class="col-span-2 flex min-w-0 flex-col gap-3 lg:col-span-1">
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="text-xs text-ink-2">{{ __('Starts in') }}</span>
                    <span class="font-display text-xl leading-none font-bold tabular-nums" role="timer" x-data="countdown({ at: {{ (int) $nextCup->starts_at->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text" data-test="cup-next-countdown">{{ $countdown }}</span>
                    <time datetime="{{ $nextCup->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="text-xs text-ink-2" data-test="cup-next-start"
                          @unless ($next['fixedZone']) x-data="cupStart({ at: {{ (int) $nextCup->starts_at->getTimestampMs() }}, zone: @js($next['zone']) })" @endunless><span @unless ($next['fixedZone']) x-text="day || @js($next['day'])" @endunless>{{ $next['day'] }}</span>, <span @unless ($next['fixedZone']) x-text="clock || @js($next['clock'])" @endunless>{{ $next['clock'] }}</span>, <span class="text-ink-3" @unless ($next['fixedZone']) x-text="city || @js($next['city'])" @endunless>{{ $next['city'] }}</span></time>
                </div>
                {{-- At zero the countdown says so (countdown-zero): sign-up has closed, the button goes, the cup's page tells how it goes on. --}}
                <p x-show="started" x-cloak class="m-0 text-[13px] leading-normal text-ink-2" data-test="cup-next-closed">{{ __('Sign-up closed') }}. <a href="{{ $nextShow }}" class="inline-flex min-h-11 items-center text-btc-hi hover:text-btc">{{ __('See the tournament') }}</a></p>
                <div x-show="! started" class="flex flex-wrap items-center gap-x-4 gap-y-2" data-test="cup-next-action">
                    @if ($nextIn)
                        <a href="{{ $nextShow }}" class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-win-tint px-[18px] text-[13px] font-bold text-win shadow-ring-win hover:text-win" data-test="cup-next-cta"><x-icon name="check" :size="18" />{{ __('You’re in') }}</a>
                    @else
                        <x-button :href="$nextHref" icon="tournaments" class="shrink-0" data-test="cup-next-cta">{{ __('Sign up') }}</x-button>
                    @endif
                    <span class="text-[13px] text-ink-2" data-test="cup-next-taken"><b class="font-display text-base text-ink tabular-nums">{{ $next['taken'] }}</b> {{ __('of :places in', ['places' => $next['places']]) }}</span>
                </div>
            </div>
        </article>
    @elseif ($empty)
        <p class="m-0 border-t border-hairline pt-4 text-[13px] leading-normal text-ink-2" data-test="cup-none">{{ __('No cup takes players right now. The next one opens :gap after the last cup’s final or call-off.', ['gap' => RulesPage::minutes((int) config('esports.casual_cups.gap_hours', 24) * 60)]) }}</p>
    @endif
</div>
