@props(['progress', 'headingId' => 'quests-h'])

{{--
    This week's quests of the logged-in player (P10,
    App\Support\Engagement\Quests): progress counted on the server from
    finished results, a bar per quest, a check once done.
--}}
@php
    use App\Support\Engagement\Quests;

    $texts = [
        Quests::THREE_GAMES => [__('Play 3 games'), __('Any game, casual or rated, this week')],
        Quests::GIANT_SLAYER => [__('Beat a higher-rated player'), __('Win against someone rated above you')],
        Quests::FOR_THE_CLAN => [__('Play for your clan'), __('A series with your clan lineup, or a rated game as a clan player')],
    ];
    $done = count(array_filter($progress, fn (array $quest): bool => $quest['done']));
@endphp

<section aria-labelledby="{{ $headingId }}" {{ $attributes->class('flex flex-col gap-3') }} data-test="quests">
    <span class="flex items-baseline justify-between gap-3">
        <h2 id="{{ $headingId }}" class="m-0 text-[15px] font-bold">{{ __('Weekly quests') }}</h2>
        <span class="text-xs text-ink-2" data-test="quests-done">{{ __(':done of :count done', ['done' => $done, 'count' => count($progress)]) }}</span>
    </span>
    <ul class="m-0 flex list-none flex-col gap-2 p-0">
        @foreach ($progress as $key => $quest)
            @php([$title, $hint] = $texts[$key] ?? [$key, ''])
            <li class="flex flex-col gap-1.5 border-t border-hairline pt-2" data-test="quest" data-quest="{{ $key }}">
                <span class="flex items-baseline justify-between gap-3 text-[13px]">
                    <b @class(['min-w-0 truncate', 'text-win' => $quest['done']])>@if ($quest['done'])<x-icon name="check" :size="14" class="mr-1 inline-block align-[-2px]" />@endif{{ $title }}</b>
                    <span class="shrink-0 text-xs text-ink-2" data-test="quest-progress">{{ $quest['progress'] }}/{{ $quest['target'] }}</span>
                </span>
                <span class="h-1.5 overflow-hidden rounded-full bg-raised" aria-hidden="true">
                    <span @class(['block h-full animate-fill rounded-full', 'bg-win' => $quest['done'], 'bg-btc' => ! $quest['done']]) style="width: {{ intdiv(100 * $quest['progress'], max(1, $quest['target'])) }}%"></span>
                </span>
                <span class="text-[11px] text-ink-3">{{ $hint }}</span>
            </li>
        @endforeach
    </ul>
    <p class="m-0 mt-auto text-[11px] text-ink-3">{{ __('Quests reset every Monday (UTC). They are for fun: no rating, no blocks, no sats.') }}</p>
</section>
