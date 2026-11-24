@props(['user' => null, 'compact' => false, 'stateless' => false])

{{--
    "How to play Season 1" (plan "Season vs. Casual", P6): the one explainer of rated play, in three steps, each a link
    to where it is done: become Trusted (the profile standard's marks and the trust rank), add each other as opponents,
    play rated and mine the next block. A signed-in player sees their own state (SeasonPath, the gate itself): a done
    step ticked, the next open one marked; a guest sees the three steps and "Log in". Steps, not a paragraph (R3).

    `compact`: the three steps as one row of chips under a small heading, for a page whose first screen is tight.
    `stateless`: the three steps without the player's state, for a page that shows that state right above (/mining's
    hero chips): the gate is read once per page, not twice. `user`: another viewer than the signed-in one (tests only). The section's id `season-how` is the band's anchor.
--}}
@php
    $viewer = $user ?? auth()->user();
    $player = $viewer instanceof \App\Models\User;
    $path = $player && ! $stateless ? app(\App\Support\SeasonChain\SeasonPath::class)->for($viewer) : null;
    $season = \App\Support\Badges\BadgeCopy::season(\App\Support\Series\Ladders::season() ?? 'pre-season');
    $steps = collect($path['steps'] ?? [])->keyBy('key');
    $trustSteps = $steps->only(['known', 'nip05', 'profile', 'trust']);
    // The first open mark of being Trusted leads there (a NIP-05 address, the profile, the trust rank); all done: none.
    $trustFix = $trustSteps->first(fn (array $step): bool => ! $step['done']);
    $mine = $path !== null ? \App\Support\SeasonChain\SeasonPath::gameToMine($viewer) : null;
    $rows = [
        ['trusted', __('Become Trusted'), $trustFix['href'] ?? route('settings.opponents'), $trustSteps->isNotEmpty() && $trustFix === null],
        ['opponents', __('Add each other as opponents'), route('settings.opponents'), (bool) ($steps->get('opponents')['done'] ?? false)],
        ['play', __('Play rated, mine the next block'), $mine['href'] ?? route('play'), ($path['state'] ?? null) === 'eligible'],
    ];
    $next = $path !== null ? collect($rows)->search(fn (array $row): bool => ! $row[3]) : false;
@endphp

<section id="season-how" aria-labelledby="season-how-h" {{ $attributes->class(['flex min-w-0 scroll-mt-20 flex-col gap-2', 'rounded-lg bg-card px-4 py-4 lg:px-5' => ! $compact]) }}
         data-test="season-how" data-viewer="{{ $player ? 'player' : 'guest' }}" @if ($path !== null) data-state="{{ $path['state'] }}" @endif>
    <span class="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <h2 id="season-how-h" @class(['m-0 font-bold', 'text-[15px]' => ! $compact, 'text-[13px]' => $compact])>{{ __('How to play :season', ['season' => $season]) }}</h2>
        @guest
            <a href="{{ route('login') }}" class="rv-b2 shrink-0 whitespace-nowrap" data-test="season-how-login">{{ __('Log in') }}</a>
        @endguest
    </span>
    @if (($path['state'] ?? null) === 'blocked')
        <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2" data-test="season-how-blocked">{{ $path['message'] }}</p>
    @endif
    <ol @class(['m-0 flex list-none p-0', 'flex-col' => ! $compact, 'flex-wrap gap-2' => $compact])>
        @foreach ($rows as $index => [$key, $label, $href, $done])
            <li @class(['flex min-w-0', 'min-h-12 items-center gap-3 border-b border-hairline last:border-0' => ! $compact]) data-test="season-how-step" data-step="{{ $key }}" data-done="{{ $done ? 'true' : 'false' }}">
                <a href="{{ $href }}" @class([
                    'flex min-h-11 min-w-0 items-center gap-2.5 text-[13px] hover:text-ink',
                    'rounded-[4px] border px-2.5' => $compact,
                    'border-win-ring bg-win-tint' => $compact && $done,
                    'border-btc-ring bg-btc-chip' => $compact && ! $done && $index === $next,
                    'border-line bg-ground' => $compact && ! $done && $index !== $next,
                    'font-bold text-ink' => ! $done && $index === $next,
                    'text-ink-2' => $done || $index !== $next,
                ])>
                    @if ($done)
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-md bg-win-tint text-win"><x-icon name="check" :size="14" /><span class="sr-only">{{ __('Done') }}:</span></span>
                    @else
                        <span @class(['flex size-6 shrink-0 items-center justify-center rounded-md font-display text-xs font-bold', 'bg-btc text-on-btc' => $index === $next, 'bg-raised text-ink-2' => $index !== $next]) aria-hidden="true">{{ $index + 1 }}</span>
                    @endif
                    <span class="min-w-0">{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ol>
</section>
