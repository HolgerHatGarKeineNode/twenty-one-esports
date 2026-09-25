@php
    $boards = [
        'strongest' => [
            'id' => 'gr-h',
            'title' => __('Strongest · Global Rating'),
            'note' => __('How strong you are across all games: your rank inside each game, weighted by games played, on a scale around 1000. Shows from 5 rated games.'),
            'mobileNote' => __('Strength across all games: your rank inside each game, weighted by games played, on a scale around 1000. Shows from 5 rated games.'),
            'sub' => __('Plays'),
            'value' => __('Rating'),
            'tab' => __('Strongest'),
            'metric' => __('Global Rating'),
        ],
        'active' => [
            'id' => 'bh-h',
            'title' => __('Most active · Block Height'),
            'note' => __('How much you play: every rated game in any game adds one block to your chain. New players show up here first.'),
            'mobileNote' => __('Every rated game in any game adds one block to your chain. New players show up here first.'),
            'sub' => __('This week'),
            'value' => __('Height'),
            'tab' => __('Most active'),
            'metric' => __('Block Height'),
        ],
    ];
    $ladder = route('ladder.show', ['chess', 'blitz']);
@endphp

{{-- Desktop: two ranking cards side by side (Main.dc.html) --}}
@foreach ($boards as $key => $board)
    <section aria-labelledby="{{ $board['id'] }}" class="hidden flex-col gap-1.5 rounded-lg bg-card px-6 py-5 lg:flex">
        <span class="flex items-baseline justify-between">
            <h2 id="{{ $board['id'] }}" class="m-0 text-[15px] font-bold">{{ $board['title'] }}</h2>
            <a href="{{ $ladder }}" class="inline-flex min-h-6 items-center text-xs">{{ __('Full ranking') }}</a>
        </span>
        <p class="mt-0 mb-2 text-xs leading-[1.6] text-ink-2">{{ $board['note'] }}</p>
        <div class="grid h-7 grid-cols-[24px_minmax(0,1fr)_88px_72px] items-center gap-3 border-b border-hairline px-2 text-xs text-ink-3">
            <span>#</span><span>{{ __('Player') }}</span><span>{{ $board['sub'] }}</span><span class="text-right">{{ $board['value'] }}</span>
        </div>
        @foreach ($rankings[$key] as $row)
            <a href="{{ route('players.show', $row['name']) }}" class="tr grid h-10 grid-cols-[24px_minmax(0,1fr)_88px_72px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink">
                <span class="text-ink-3">{{ $row['rank'] }}</span>
                <span class="flex min-w-0 items-center gap-2">
                    <x-avatar :name="$row['name']" :background="$row['avatar']" />
                    <span class="truncate">{{ $row['name'] }}</span>
                    @if ($row['member'])
                        <x-member-badge />
                    @endif
                </span>
                <span class="flex items-center gap-1.5 text-ink-2">{{ $row['sub'] }}</span>
                <b class="text-right font-display text-sm">{{ $row['value'] }}</b>
            </a>
        @endforeach
    </section>
@endforeach

<p class="col-span-full -mt-2 mb-0 hidden items-center gap-2 text-xs text-ink-3 lg:flex">
    <x-member-badge />
    <span>{{ __('marks an EINUNDZWANZIG member. Membership adds perks, never rating or gameplay.') }}</span>
</p>

{{-- Mobile: one card, two tabs (MobileHome.dc.html) --}}
<section aria-labelledby="rank-h" class="flex flex-col gap-2 rounded-lg bg-card p-4 lg:hidden" x-data="{ tab: 'strongest' }">
    <span class="flex items-baseline justify-between">
        <h2 id="rank-h" class="m-0 text-[15px] font-bold">{{ __('Global rankings') }}</h2>
        <a href="{{ $ladder }}" class="inline-flex min-h-11 items-center text-xs">{{ __('Full ranking') }}</a>
    </span>
    <div role="tablist" aria-label="{{ __('Ranking') }}" class="grid grid-cols-2 gap-1 rounded-lg border border-line bg-ground p-1">
        @foreach ($boards as $key => $board)
            <button type="button" role="tab" id="rank-tab-{{ $key }}" aria-controls="rank-panel-{{ $key }}"
                    x-bind:aria-selected="(tab === '{{ $key }}').toString()"
                    x-bind:tabindex="tab === '{{ $key }}' ? 0 : -1"
                    x-on:click="tab = '{{ $key }}'"
                    x-on:keydown.right.prevent="tab = tab === 'strongest' ? 'active' : 'strongest'; $nextTick(() => document.getElementById('rank-tab-' + tab).focus())"
                    x-on:keydown.left.prevent="tab = tab === 'strongest' ? 'active' : 'strongest'; $nextTick(() => document.getElementById('rank-tab-' + tab).focus())"
                    class="flex min-h-12 cursor-pointer flex-col items-center justify-center gap-0.5 rounded-md px-2 py-1"
                    x-bind:class="tab === '{{ $key }}' ? 'bg-raised text-btc' : 'text-ink-2'">
                <b class="text-[13px]">{{ $board['tab'] }}</b>
                <span class="text-[11px]" x-bind:class="tab === '{{ $key }}' ? 'text-ink-2' : 'text-ink-3'">{{ $board['metric'] }}</span>
            </button>
        @endforeach
    </div>
    @foreach ($boards as $key => $board)
        <div id="rank-panel-{{ $key }}" role="tabpanel" aria-labelledby="rank-tab-{{ $key }}" class="flex flex-col"
             x-show="tab === '{{ $key }}'" @if ($key !== 'strongest') x-cloak @endif>
            <p class="mt-1 mb-2 text-xs leading-[1.6] text-ink-2">{{ $board['mobileNote'] }}</p>
            <div class="grid h-7 grid-cols-[20px_minmax(0,1fr)_60px] items-center gap-2 border-b border-hairline text-[11px] text-ink-3">
                <span>#</span><span>{{ __('Player') }}</span><span class="text-right">{{ $board['value'] }}</span>
            </div>
            @foreach (array_slice($rankings[$key], 0, 6) as $row)
                <a href="{{ route('players.show', $row['name']) }}" class="tr grid min-h-13 grid-cols-[20px_minmax(0,1fr)_60px] items-center gap-2 rounded-sm text-[13px] text-ink hover:text-ink">
                    <span class="text-ink-3">{{ $row['rank'] }}</span>
                    <span class="flex min-w-0 items-center gap-2">
                        <x-avatar :name="$row['name']" :background="$row['avatar']" :size="28" />
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="flex min-w-0 items-center gap-1.5">
                                <span class="truncate">{{ $row['name'] }}</span>
                                @if ($row['member'])
                                    <x-member-badge />
                                @endif
                            </span>
                            <span class="text-[11px] text-ink-2">{{ $key === 'strongest' ? __('plays :games', ['games' => $row['sub']]) : __(':n blocks this week', ['n' => $row['sub']]) }}</span>
                        </span>
                    </span>
                    <b class="text-right font-display text-[13px]">{{ $row['value'] }}</b>
                </a>
            @endforeach
        </div>
    @endforeach
</section>
