{{--
    The season, right column (Main.dc.html season card): before Block 0 the Pre-Season with the countdown to
    Block 0 ("--" while no date is set) and "Bei Block 0 benachrichtigen"; while a season runs its height. Under it
    the first three eras with what a win pays (HomeBoard::season(), the same schedule as /mining).

    Below lg the same season as one compact section for guests and players (HomePhone.dc.html drew two lines for
    players only; guests saw no season on a phone): before Block 0 its date, the countdown, the supply and the
    notify action; live the current block and what a win mines now, paid out after the season review.

    $season: HomeBoard::season(); $user: the viewer or null; $quests: the player's quests (phone layout) or null.
--}}
@php
    use App\Support\Engagement\HomeBoard;
    use App\Support\Formats;
    use App\Support\PreSeason;
    use App\Support\SeasonChain\ChainOverview;

    $block0At = $season['live'] ? null : PreSeason::block0At();
    $undated = ! $season['live'] && $block0At === null;
    $tag = $season['live'] ? __('Live now') : __('Pre-Season');
    $first = $season['eras'][0] ?? null;
    $zone = PreSeason::timezoneFor($user);
    $supply = $season['live'] ? null : PreSeason::potSats();
@endphp

<section id="block0" aria-labelledby="season-h" class="rv-card hidden scroll-mt-24 flex-col gap-3 p-4 lg:flex" data-test="home-season" data-state="{{ $season['live'] ? 'live' : PreSeason::state() }}">
    <div class="flex items-center gap-2">
        <h2 id="season-h" class="rv-h3">{{ __('Season') }}</h2>
        <span class="rv-tag">{{ $tag }}</span>
        <span class="grow"></span>
        <a href="{{ route('mining') }}" @navigate(route('mining')) class="rv-lk text-[13px]">{{ __('Open it') }}</a>
    </div>

    @if ($season['live'])
        <b class="text-[13px]" data-test="season-live">{{ __('Block :height', ['height' => HomeBoard::sats((int) $season['height'])]) }}</b>
    @else
        <div class="flex flex-col gap-2">
            <b class="text-[13px]">{{ __('The chain starts at Block 0') }}</b>
            <x-ui.countdown :at="$block0At" size="sm" :label="__('Block 0 in')" />
            @if ($undated)
                <span class="rv-m">{{ __('Date to follow; the countdown starts once it is set.') }}</span>
            @endif
        </div>
    @endif

    @if ($season['eras'] !== [])
        <table class="rv-tb" data-test="season-eras">
            <thead>
                <tr><th>{{ __('Era') }}</th><th>{{ __('from') }}</th><th class="is-r">{{ __('Sats per win') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($season['eras'] as $era)
                    <tr>
                        <td>{{ $era['era'] }}</td>
                        <td class="tabular-nums">{{ $era['from']->setTimezone($zone)->locale(app()->getLocale())->isoFormat(app()->getLocale() === 'de' ? 'dd D.M.' : 'ddd D MMM') }}</td>
                        <td class="is-r tabular-nums">{{ HomeBoard::sats($era['sats']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @unless ($season['live'])
        @guest
            <a href="{{ route('login') }}" class="rv-b2 is-sm self-start" data-test="notify-block0">{{ __('Notify me at Block 0') }}</a>
        @else
            @if ($user->notify_block0_at === null)
                <form method="POST" action="{{ route('notify.block0') }}" class="self-start">
                    @csrf
                    <button type="submit" class="rv-b2 is-sm" data-test="notify-block0">{{ __('Notify me at Block 0') }}</button>
                </form>
            @else
                <p role="status" class="m-0 flex items-center gap-2 text-[13px] text-btc-hi" data-test="notify-set"><x-icon name="check" :size="14" />{{ __("We'll tell you at Block 0") }}</p>
            @endif
        @endguest
    @endunless
</section>

{{-- The phone, guests and players alike (UX audit 2026-11-17, F3): the state first, then what a win pays, then the one action. --}}
<section aria-labelledby="season-h-phone" class="flex flex-col gap-3 px-4 pt-2 pb-4 lg:hidden" data-test="home-season-phone" data-state="{{ $season['live'] ? 'live' : PreSeason::state() }}">
    <div class="mt-2 flex items-center gap-2">
        <h2 id="season-h-phone" class="rv-h3">{{ __('Season') }}</h2>
        <span class="rv-tag">{{ $season['live'] ? __('Live now') : __('Block 0 soon') }}</span>
        <span class="grow"></span>
        <a href="{{ route('mining') }}" @navigate(route('mining')) class="rv-lk inline-flex min-h-6 items-center text-[13px]">{{ __('Open it') }}</a>
    </div>

    @if ($season['live'])
        <b class="font-display text-xl leading-tight" data-test="season-phone-block">{{ __('Block :height', ['height' => HomeBoard::sats((int) $season['height'])]) }}</b>
        @if ($season['payKey'] !== null && $season['pays'] > 0)
            <span class="flex flex-wrap items-center gap-x-2 gap-y-1" data-test="season-phone-pays">
                <span class="rv-tag is-btc"><x-icon name="mining" :size="12" />{{ __('A win mines :sats sats', ['sats' => HomeBoard::sats($season['pays'])]) }}</span>
                <span class="rv-m">{{ ChainOverview::keyLabel($season['payKey']) }}, {{ __('paid out after the season review') }}</span>
            </span>
        @endif
    @else
        @if ($block0At !== null)
            <b class="text-[13px]" data-test="season-phone-date">{{ __('Block 0 on :when', ['when' => Formats::date($block0At, true, $zone)]) }}</b>
        @endif
        <x-ui.countdown :at="$block0At" size="sm" :label="__('Block 0 in')" />
        @if ($undated)
            <span class="rv-m">{{ __('Date to follow; the countdown starts once it is set.') }}</span>
        @endif
        @if ($supply !== null || ($first !== null && $season['payKey'] !== null))
            <span class="flex flex-wrap items-center gap-x-2 gap-y-1" data-test="season-phone-facts">
                @if ($supply !== null)
                    <span class="rv-tag" data-test="season-phone-supply">{{ __('Supply') }} {{ HomeBoard::sats($supply) }} Sats</span>
                @endif
                @if ($first !== null && $season['payKey'] !== null)
                    <span class="rv-m">{{ __('Era :era pays :sats sats per win (:key)', ['era' => $first['era'], 'sats' => HomeBoard::sats($first['sats']), 'key' => ChainOverview::keyLabel($season['payKey'])]) }}, {{ __('paid out after the season review') }}</span>
                @endif
            </span>
        @endif
        @guest
            <a href="{{ route('login') }}" class="rv-b2 self-start" data-test="notify-block0-phone">{{ __('Notify me at Block 0') }}</a>
        @else
            @if ($user->notify_block0_at === null)
                <form method="POST" action="{{ route('notify.block0') }}" class="self-start">
                    @csrf
                    <button type="submit" class="rv-b2" data-test="notify-block0-phone">{{ __('Notify me at Block 0') }}</button>
                </form>
            @else
                <p role="status" class="m-0 flex items-center gap-2 text-[13px] text-btc-hi" data-test="notify-set-phone"><x-icon name="check" :size="14" />{{ __("We'll tell you at Block 0") }}</p>
            @endif
        @endguest
    @endif
</section>
