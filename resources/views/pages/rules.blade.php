{{--
    The rules (P28, /rules). Every sentence and number comes from
    App\Support\Pages\RulesPage, which reads the config and the code that
    applies each rule at render time. This view only lays them out: a lead,
    the numbers at a glance, the rules as short lines, a table where rows
    compare, and the pages where the rule applies.
--}}
@php
    $sections = \App\Support\Pages\RulesPage::sections();
    $season = \App\Support\Series\Ladders::season();
    app(\App\Support\PageMeta::class)->describe(__('Rules'), __('The rules of the TWENTY ONE esports league: casual and rated, games and modes, casual 1v1, chess, clan series, tournaments, casual cups, prize pots, fair play and chat.'));
@endphp
<x-layouts::app :title="__('Rules')">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="rules-page">
        <header class="flex max-w-[64ch] flex-col gap-3">
            <h1 class="m-0 font-display text-[28px] leading-[1.1] font-bold lg:text-4xl">{{ __('Rules') }}</h1>
            <p class="m-0 text-[15px] leading-relaxed text-ink-2">{{ __('How the league plays. Every number on this page is the one the league applies right now.') }}</p>
            <span @class(['inline-flex h-8 items-center gap-2 self-start rounded-md px-3 text-xs font-bold', 'bg-win-tint text-win' => $season !== null, 'bg-raised text-ink-2' => $season === null]) data-test="rules-season">
                <span @class(['size-2 rounded-full', 'bg-win' => $season !== null, 'bg-edge' => $season === null]) aria-hidden="true"></span>
                {{ $season === null ? __('Before Block 0: every match is casual') : __('Season :season is live', ['season' => $season]) }}
            </span>
        </header>

        <x-doc-page :sections="array_map(fn ($s) => [$s['id'], $s['title']], $sections)" :nav-label="__('Rules sections')">
            @foreach ($sections as $section)
                <x-doc-section :id="$section['id']" :title="$section['title']">
                    <p class="m-0 max-w-[68ch] text-[15px] leading-relaxed">{{ $section['lead'] }}</p>

                    @if (! empty($section['facts']))
                        <dl class="m-0 grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6" data-test="rules-facts">
                            @foreach ($section['facts'] as [$label, $value])
                                <div class="flex min-w-0 flex-col-reverse justify-end gap-1 rounded-md bg-well px-3 py-2.5">
                                    <dt class="text-xs leading-snug text-ink-2">{{ $label }}</dt>
                                    <dd class="m-0 font-display text-lg leading-tight font-bold break-words text-btc-hi">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if (! empty($section['table']))
                        <div class="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:px-0" data-test="rules-table">
                            <table class="w-full min-w-[480px] border-collapse text-left text-[13px]">
                                <thead>
                                    <tr class="text-xs text-ink-2">
                                        @foreach ($section['table']['head'] as $head)<th scope="col" class="border-b border-line py-2 pr-4 font-normal">{{ $head }}</th>@endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['table']['rows'] as $rowIndex => $row)
                                        <tr>
                                            @foreach ($row as $index => $cell)
                                                @if ($index === 0)<th scope="row" class="border-b border-hairline py-2.5 pr-4 font-bold">@if (isset($section['table']['covers'][$rowIndex]))<span class="flex items-center gap-2.5"><x-game-cover :game="$section['table']['covers'][$rowIndex]" size="thumb" class="w-12 rounded-xs" data-test="rules-table-cover" />{{ $cell }}</span>@else{{ $cell }}@endif</th>@else<td class="border-b border-hairline py-2.5 pr-4 text-ink-2">{{ $cell }}</td>@endif
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (! empty($section['items']))
                        <ul class="m-0 flex max-w-[68ch] list-none flex-col gap-2.5 p-0 text-[14px] leading-relaxed" data-test="rules-items">
                            @foreach ($section['items'] as $item)
                                <li class="flex gap-3"><span class="mt-[0.6em] size-1.5 shrink-0 rounded-[1px] bg-btc" aria-hidden="true"></span><span>{{ $item }}</span></li>
                            @endforeach
                        </ul>
                    @endif

                    @if (! empty($section['links']))
                        <p class="m-0 flex flex-wrap gap-x-5 border-t border-hairline pt-2 text-[13px]">
                            @foreach ($section['links'] as [$label, $url])
                                <a href="{{ $url }}" class="inline-flex min-h-11 items-center">{{ $label }}</a>
                            @endforeach
                        </p>
                    @endif
                </x-doc-section>
            @endforeach
        </x-doc-page>
    </div>
</x-layouts::app>
