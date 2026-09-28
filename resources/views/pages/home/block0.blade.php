{{--
    Block 0 as a strip under the hero (before the first season): the
    genesis cube in miniature, when the Pre-Season starts (the date and a
    running clock, or "date coming soon"), the supply when the board saved
    one (the most paid out after the season, not money held now), and
    "Notify me". At most 96 px high on phones and 120 px from lg; the long
    story is in the rules at the end of the page.

    $user: the viewer or null.
--}}
@php
    use App\Support\PreSeason;

    $state = PreSeason::state();
    $timed = $state !== 'undated';
    $secondsLeft = PreSeason::secondsLeft();
    $block0At = PreSeason::block0At()?->setTimezone(PreSeason::timezoneFor($user))->locale(app()->getLocale());
    $pot = PreSeason::potSats();
    $parts = ['d' => intdiv($secondsLeft, 86400), 'h' => intdiv($secondsLeft % 86400, 3600), 'm' => intdiv($secondsLeft % 3600, 60), 's' => $secondsLeft % 60];
    $labels = [
        'd' => __('d'),
        'days' => __('days'),
        'hours' => __('hours'),
        'minutes' => __('minutes'),
        'seconds' => __('seconds'),
        'in' => __('Block 0 in :time'),
        'due' => __('Block 0 is due. The board releases it.'),
    ];
    $barClock = $parts['d'].' '.__('d').' '.sprintf('%02d:%02d:%02d', $parts['h'], $parts['m'], $parts['s']);
    $spoken = $state === 'due'
        ? $labels['due']
        : __('Block 0 in :time', ['time' => "{$parts['d']} {$labels['days']} {$parts['h']} {$labels['hours']} {$parts['m']} {$labels['minutes']} {$parts['s']} {$labels['seconds']}"]);
    $hideWhenDue = $state === 'due' ? 'display: none' : null;
    $showWhenDue = $state === 'due' ? null : 'display: none';
    $notifyClass = 'inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-md px-3.5 text-[13px] font-bold sm:px-5';
@endphp

<section id="block0" aria-labelledby="block0-h" class="mx-4 flex items-center gap-3 rounded-card bg-pl-bar px-3 py-3 shadow-ring-btc sm:gap-4 lg:mx-12 lg:px-5 lg:py-4"
         @if ($timed) x-data="blockZeroCountdown(@js(['secondsLeft' => $secondsLeft, 'labels' => $labels]))" @endif
         data-state="{{ $state }}" data-test="block0-strip">
    <span class="b0-mini" aria-hidden="true">
        <span @class(['cd-cube g0', 'is-mined' => $state === 'due', 'is-wait' => $state !== 'due']) @if ($timed) :class="due ? 'is-mined' : 'is-wait'" @endif><span class="g0-big">0</span></span>
    </span>

    <div class="flex min-w-0 grow flex-col gap-0.5" role="timer" data-test="countdown"
         @if ($timed) :aria-label="spoken" aria-label="{{ $spoken }}" @else aria-label="{{ __('Block 0, date coming soon') }}" @endif>
        <h2 id="block0-h" class="m-0 text-[13px] leading-[1.35] font-bold text-ink">
            @if ($timed)
                <span x-show="!due" @if ($hideWhenDue) style="{{ $hideWhenDue }}" @endif><span class="sm:hidden">{{ __('Pre-Season') }}</span><span class="max-sm:hidden">{{ __('Pre-Season starts at Block 0') }}</span></span>
                <span x-show="due" @if ($showWhenDue) style="{{ $showWhenDue }}" @endif>{{ __('Block 0 is due') }}</span>
            @else
                <span class="sm:hidden">{{ __('Pre-Season') }}</span><span class="max-sm:hidden">{{ __('Pre-Season starts at Block 0') }}</span>
            @endif
        </h2>
        <p class="m-0 flex flex-wrap items-baseline gap-x-3 text-xs leading-[1.4] text-btc-hi" aria-hidden="true">
            @if ($timed)
                <b class="font-display text-sm text-ink tabular-nums" x-show="!due" x-text="barClock" @if ($hideWhenDue) style="{{ $hideWhenDue }}" @endif data-test="bar-clock">{{ $barClock }}</b>
                <span class="max-sm:hidden">{{ $block0At->isoFormat('ddd YYYY-MM-DD, HH:mm z') }}</span>
            @else
                <span>{{ __('date coming soon') }}</span>
            @endif
        </p>
    </div>

    @if ($pot)
        <p class="m-0 hidden shrink-0 flex-col items-end lg:flex" data-test="pot">
            <b class="font-display text-lg leading-tight tabular-nums">{{ PreSeason::formatSats($pot) }}</b>
            <span class="text-xs text-ink-2">{{ __('sats at most, paid after the season') }}</span>
        </p>
    @endif

    @if (! $user)
        <a href="{{ route('login') }}" class="btn-p {{ $notifyClass }} bg-btc text-on-btc hover:text-on-btc" data-test="notify-block0">
            <x-icon name="bell" :size="18" /><span class="sm:hidden">{{ __('Notify me') }}</span><span class="max-sm:hidden">{{ __('Notify me at Block 0') }}</span>
        </a>
    @elseif ($user->notify_block0_at === null)
        <form method="POST" action="{{ route('notify.block0') }}" class="flex shrink-0">
            @csrf
            <button type="submit" class="btn-p {{ $notifyClass }} cursor-pointer bg-btc text-on-btc" data-test="notify-block0">
                <x-icon name="bell" :size="18" /><span class="sm:hidden">{{ __('Notify me') }}</span><span class="max-sm:hidden">{{ __('Notify me at Block 0') }}</span>
            </button>
        </form>
    @else
        <p role="status" class="{{ $notifyClass }} m-0 border border-btc-ring bg-btc-chip font-normal text-btc-hi" data-test="notify-set">
            <x-icon name="check" :size="18" /><span class="max-sm:sr-only">{{ __("We'll tell you at Block 0") }}</span>
        </p>
    @endif
</section>
