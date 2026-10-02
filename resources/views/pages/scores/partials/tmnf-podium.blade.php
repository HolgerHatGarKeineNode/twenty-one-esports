{{--
    The podium of a TMNF week (its board page): the first three as steps, shown second, first, third from left to right,
    each with avatar, league name (never a TMNF login), best time, and how it stands to the track's author time; a
    time under the author time carries "Beat the author time". A place nobody holds yet stays an open seat.
    $standings: list<App\Support\Scores\ScoreStanding>; $metric: App\Games\ScoreMetric; $authorMs: the track's author
    time in ms (0: unknown).
--}}
@php
    $placed = array_values(array_filter($standings, fn ($row): bool => $row->place !== null && $row->value !== null));
    $authorMs = (int) ($authorMs ?? 0);
    // Read first to third; shown second, first, third (CSS order): the winner stands in the middle, on the highest step.
    $steps = [
        1 => ['row' => $placed[0] ?? null, 'step' => 'h-20 bg-rank-gold lg:h-28', 'avatar' => 64, 'order' => 'order-2'],
        2 => ['row' => $placed[1] ?? null, 'step' => 'h-14 bg-rank-silver lg:h-20', 'avatar' => 48, 'order' => 'order-1'],
        3 => ['row' => $placed[2] ?? null, 'step' => 'h-10 bg-rank-bronze lg:h-14', 'avatar' => 48, 'order' => 'order-3'],
    ];
    $versus = function (int $ms) use ($authorMs, $metric): ?string {
        if ($authorMs <= 0) {
            return null;
        }
        $gap = abs($ms - $authorMs);
        $text = $gap < 60_000 ? sprintf('%d.%03d', intdiv($gap, 1000), $gap % 1000) : $metric->format($gap);

        return ($ms < $authorMs ? '−' : '+').$text;
    };
@endphp
<ol class="m-0 grid list-none grid-cols-3 items-end gap-2 p-0 lg:gap-4" aria-label="{{ __('The week\'s podium') }}" data-test="tmnf-podium">
    @foreach ($steps as $place => ['row' => $row, 'step' => $step, 'avatar' => $size, 'order' => $order])
        @php($user = $row?->participant->user)
        <li class="flex min-w-0 flex-col items-center gap-1.5 text-center {{ $order }}" data-test="podium-{{ $place }}">
            @if ($row !== null)
                @if ($place === 1)
                    <x-icon name="trophy" :size="20" class="text-rank-gold" />
                @endif
                @if ($user)
                    <x-avatar :user="$user" :size="$size" class="shrink-0 rounded-md" />
                    <a href="{{ route('players.show', $user->npub) }}" class="w-full truncate text-[13px] font-bold text-ink hover:text-btc-hi lg:text-[15px]">{{ $row->participant->name }}</a>
                @else
                    <span class="w-full truncate text-[13px] font-bold text-ink-2 lg:text-[15px]">{{ $row->participant->name }}</span>
                @endif
                <b @class(['font-mono leading-none font-bold tabular-nums', 'text-lg lg:text-2xl' => $place === 1, 'text-[15px] lg:text-xl' => $place !== 1])>{{ $metric->format($row->value) }}</b>
                @if (($gap = $versus((int) $row->value)) !== null)
                    @if ((int) $row->value < $authorMs)
                        <span class="inline-flex min-h-6 items-center rounded-xs bg-[color-mix(in_oklab,var(--color-tmnf)_18%,transparent)] px-1.5 text-[11px] font-bold text-tmnf" data-test="beat-author">{{ __('Beat the author time') }}</span>
                    @else
                        <span class="text-xs text-ink-2 tabular-nums" title="{{ __('Author time') }}">{{ __(':gap to the author time', ['gap' => $gap]) }}</span>
                    @endif
                @endif
            @else
                <span class="grid size-12 place-items-center rounded-md border-2 border-dashed border-edge text-ink-3" aria-hidden="true"><x-icon name="user" :size="18" /></span>
                <span class="text-[13px] text-ink-2">{{ __('Place still free') }}</span>
            @endif
            <span class="mt-1 flex w-full items-start justify-center rounded-t-md pt-1.5 font-display text-xl font-bold text-on-btc tabular-nums {{ $step }}" aria-label="{{ __('Place :place', ['place' => $place]) }}">{{ $place }}</span>
        </li>
    @endforeach
</ol>
