@props(['rows' => [], 'valueLabel' => null, 'recordLabel' => null, 'rowTest' => 'ladder-row'])

{{--
    The standings table of the ladder template T5 (LadderPage.dc.html; rule R2: numbers right-aligned, the column
    order fixed): rank (the top three with a medal edge), player, the ladder's value, the record. Used by every
    ladder page (x-ladder.page) and by the "Ladder" block of every lobby (template T1), so a ladder reads the same
    everywhere. Each row: ['rank' => int, 'user' => ?User, 'value' => string|int, 'record' => ?string,
    'me' => bool, 'mark' => ?string (a short tag after the name, e.g. provisional), 'key' => string|int]; a lineup's row
    carries 'clan' => ?Clan and 'lineup' => true instead of a user.
    `recordLabel` false drops the record column.
--}}
@php
    $viewer = auth()->id();
    // Elo and points grouped in the page's language ("1.116" in German, plan "Canvas and live alignment", P1, G5).
    $format = fn (mixed $value): string => is_numeric($value) ? \App\Support\Formats::number((float) $value, is_float($value) && floor($value) != $value ? 1 : 0) : (string) $value;
@endphp

<table {{ $attributes->class('rv-tb') }}>
    <thead>
        <tr>
            <th scope="col" class="w-14">#</th>
            <th scope="col">{{ __('Player') }}</th>
            <th scope="col" class="is-r whitespace-nowrap">{{ $valueLabel ?? __('Elo') }}</th>
            @if ($recordLabel !== false)
                <th scope="col" class="is-r whitespace-nowrap max-sm:hidden">{{ $recordLabel ?? __('Win record') }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            @php
                $me = ($row['me'] ?? false) || ($viewer !== null && ($row['user']?->id ?? null) === $viewer);
            @endphp
            <tr wire:key="ladder-{{ $row['key'] ?? $row['rank'] }}" @class(['lp-row-'.$row['rank'] => $row['rank'] <= 3, 'is-me' => $me]) data-test="{{ $rowTest }}" @if ($me) data-me @endif>
                <td><b @class(['lp-rank', 'text-btc' => in_array($row['rank'], [1, 3], true), 'text-ink-2' => $row['rank'] > 3])>{{ $row['rank'] }}</b></td>
                {{-- w-full + max-w-0: the name cell takes the rest and truncates instead of widening the table (320 px). --}}
                <td class="w-full max-w-0">
                    @if ($row['user'] !== null)
                        <x-player-link :user="$row['user']" class="-my-1.5 flex min-h-11 min-w-0 items-center gap-2 text-ink hover:text-ink">
                            <x-avatar :user="$row['user']" :size="24" class="shrink-0 rounded-full" />
                            <b class="truncate">{{ $row['user']->displayName() }}</b>
                            @if ($me)<span class="rv-m">({{ __('you') }})</span>@endif
                            @if ($row['mark'] ?? null)<span class="rv-tag">{{ $row['mark'] }}</span>@endif
                        </x-player-link>
                    @elseif (($row['clan'] ?? null) !== null)
                        {{-- A lineup's row (the series ladders of team modes): the clan stands where a player would. --}}
                        <a href="{{ route('clans.show', $row['clan']) }}" class="-my-1.5 flex min-h-11 min-w-0 items-center gap-2 text-ink hover:text-ink">
                            <x-clan-tag :clan="$row['clan']" compact />
                            <b class="truncate">{{ $row['clan']->name }}</b>
                        </a>
                    @else
                        <span class="text-ink-3">{{ ($row['lineup'] ?? false) ? __('Deleted lineup') : __('Deleted account') }}</span>
                    @endif
                </td>
                <td class="is-r whitespace-nowrap"><b class="font-display text-lg tabular-nums">{{ $format($row['value']) }}</b></td>
                @if ($recordLabel !== false)
                    <td class="is-r whitespace-nowrap text-ink-2 max-sm:hidden">{{ $row['record'] ?? '' }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
