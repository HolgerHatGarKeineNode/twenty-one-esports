{{--
    The season, right column (Main.dc.html season card; HomePhone.dc.html as two lines, players only): before
    Block 0 the Pre-Season with the countdown to Block 0 ("--" while no date is set) and "Bei Block 0
    benachrichtigen"; while a season runs its height. Under it the first three eras with what a win pays
    (HomeBoard::season(), the same schedule as /mining).

    $season: HomeBoard::season(); $user: the viewer or null; $quests: the player's quests (phone layout) or null.
--}}
@php
    use App\Support\Engagement\HomeBoard;
    use App\Support\PreSeason;
    use App\Support\SeasonChain\ChainOverview;

    $block0At = $season['live'] ? null : PreSeason::block0At();
    $undated = ! $season['live'] && $block0At === null;
    $tag = $season['live'] ? __('Live now') : __('Pre-Season');
    $first = $season['eras'][0] ?? null;
    $zone = PreSeason::timezoneFor($user);
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

@if ($user)
    <section aria-labelledby="season-h-phone" class="flex flex-col gap-2 px-4 pt-2 pb-4 lg:hidden" data-test="home-season-phone">
        <div class="mt-2 flex items-center gap-2">
            <h2 id="season-h-phone" class="rv-h3">{{ __('Season') }}</h2>
            <span class="rv-tag">{{ $season['live'] ? __('Live now') : __('Block 0 soon') }}</span>
            <span class="grow"></span>
            <a href="{{ route('mining') }}" @navigate(route('mining')) class="rv-lk text-[13px]">{{ __('Open it') }}</a>
        </div>
        @if ($first !== null && $season['payKey'] !== null)
            <span class="text-[13px] text-ink-2">{{ __('Era :era pays :sats sats per win (:key)', ['era' => $first['era'], 'sats' => HomeBoard::sats($first['sats']), 'key' => ChainOverview::keyLabel($season['payKey'])]) }}</span>
        @endif
    </section>
@endif
