{{--
    "Deine Woche" for a player (Main.dc.html aside; HomePhone.dc.html "Wochen-Quests" as one line): this week's
    quests with their progress and the player's latest ladder with its rating (provisional while it is).

    $quests: Quests::progress(); $rating: HomeBoard::rating().
--}}
@php
    use App\Support\Engagement\Quests;

    $titles = [
        Quests::THREE_GAMES => __('Play 3 games'),
        Quests::GIANT_SLAYER => __('Beat a higher-rated player'),
        Quests::FOR_THE_CLAN => __('Play for your clan'),
    ];
    $done = count(array_filter($quests, fn (array $quest): bool => $quest['done']));
@endphp

<section aria-labelledby="you-h" class="rv-card hidden lg:block" data-test="home-your-week">
    <div class="flex items-center gap-2 p-4">
        <h2 id="you-h" class="rv-h3">{{ __('Your week') }}</h2>
        <span class="rv-m">{{ __('Quests :done of :count', ['done' => $done, 'count' => count($quests)]) }}</span>
        <span class="grow"></span>
        <a href="{{ route('dashboard') }}" class="rv-lk text-[13px]">{{ __('You') }}</a>
    </div>
    <table class="rv-tb">
        <tbody>
            @foreach ($quests as $key => $quest)
                <tr data-test="quest" data-quest="{{ $key }}">
                    <td @class(['text-win' => $quest['done']])>{{ $titles[$key] ?? $key }}</td>
                    <td class="is-r tabular-nums">{{ $quest['progress'] }}/{{ $quest['target'] }}</td>
                </tr>
            @endforeach
            @if ($rating !== null)
                <tr data-test="your-rating">
                    <td>{{ $rating['label'] }}</td>
                    <td class="is-r tabular-nums whitespace-nowrap">{{ $rating['rating'] }}@if ($rating['provisional']) <span class="rv-m">{{ __('prov.') }}</span>@endif</td>
                </tr>
            @endif
        </tbody>
    </table>
</section>

<section aria-labelledby="you-h-phone" class="flex flex-col gap-2 border-t border-hairline px-4 pt-4 lg:hidden" data-test="home-quests-phone">
    <div class="flex items-center gap-2">
        <h2 id="you-h-phone" class="rv-h3">{{ __('Weekly quests') }}</h2>
        <span class="rv-m">{{ __(':done of :count', ['done' => $done, 'count' => count($quests)]) }}</span>
        <span class="grow"></span>
        <a href="{{ route('dashboard') }}" class="rv-lk text-[13px]">{{ __('You') }}</a>
    </div>
    <span class="text-[13px] text-ink-2">{{ implode(', ', array_map(fn (string $key, array $quest): string => ($titles[$key] ?? $key).' '.$quest['progress'].'/'.$quest['target'], array_keys($quests), $quests)) }}</span>
</section>
