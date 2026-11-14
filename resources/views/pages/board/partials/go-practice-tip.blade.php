{{--
    A bot game's tip on a phone, in Go's practice (design pass 2026-11-14): one place under the board, as wide as the
    board's column and as tall as the longest tip the page can show at this width, so a tip that comes or goes moves
    nothing (before it stood above the board beside its button and pushed the board down 46 px at 390 x 844). The room
    is kept by the texts themselves: every tip lies unseen in the one grid cell, with the longest bot's name, a
    two-digit count and the longest area in it, and the cell is as tall as the tallest of them, in the page's language.
    Without a tip it says what the tip button in the bar gives, or that the bot is thinking. From lg the tips card of
    the panel holds all of it (components/board/practice).

    `$config` the practice script's config (App\Livewire\Concerns\JudgesPractice::practiceConfig()).
--}}
@php
    $labels = $config['labels'];
    $longest = fn (array $texts): string => collect($texts)->sortByDesc(fn (string $text): int => mb_strlen($text))->first();
    $values = [
        ':name' => $longest(array_column($config['bots'], 'name')),
        ':stones' => str_replace(':count', '10', $labels['stones'][1]),
        ':point' => 'E5',
        ':where' => $longest($labels['advice']['areas']),
    ];
    $idle = __('Stuck? Ask for a tip: it tells you what kind of move fits, never the move itself.');
    $room = collect([$idle, ...array_values($labels['tips']), ...array_values(array_filter($labels['advice'], 'is_string'))])
        ->map(fn (string $text): string => strtr($text, $values));
@endphp

<div class="grid text-[13px] leading-[18px] max-[359px]:text-xs max-[359px]:leading-4 lg:hidden" x-cloak x-show="mode === 'bots' && stage === 'play'" data-test="tip-slot">
    @foreach ($room as $text)
        <p class="invisible col-start-1 row-start-1 m-0" aria-hidden="true">{{ $text }}</p>
    @endforeach
    <p role="status" aria-live="polite" class="col-start-1 row-start-1 m-0" :class="tip ? 'text-ink' : (myTurnHere ? 'text-ink-3' : 'text-ink-2')"
       x-text="tip ? tip.text : (myTurnHere ? @js($idle) : turnLine)" data-test="tip-phone"></p>
</div>
