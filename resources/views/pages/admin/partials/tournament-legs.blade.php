{{--
    The games per pairing of a two-leg game (Blockli) in a round-robin table and how they count, part of the chooser's
    options (pages.admin.partials.tournament-chooser, its scope: $options, $optionRow, $help). A round robin shows it right
    under "Everyone meets once, twice or three times", whose help counts with it; a two-stage tournament under its groups.
--}}
{{-- A two-leg game (Blockli) in a round-robin table (DerCaddy, 2026-10-18: "das sollte man vorher einstellen können",
     "sollte man dann auch zwei Punkte bekommen können"): one game or two per pairing, points per pairing or per game.
     A knockout keeps its two legs and decider. --}}
<div class="{{ $optionRow }}" data-test="legs-option">
    <span class="flex flex-col items-start gap-2">
        <span id="legs-l" class="text-[13px] font-bold">{{ __('Games per pairing') }}</span>
        {{-- A combined tournament always plays its whole sequence (FormatOptions::$games): nothing to choose. --}}
        @if (! $options->sequence())
            <x-tournaments.segmented labelledby="legs-l" method="pickLegs" :current="$options->legs" :grow="false" data-test="legs"
                :options="[[2, __('Two games')], [1, __('One game')]]" />
        @endif
    </span>
    <span class="{{ $help }}">{{ $options->sequence()
        ? trans_choice(':count game: one of each game of the combined tournament, in its order.|:count games: one of each game of the combined tournament, in its order.', $options->legCount())
        : __('Two games with the colours swapped are fairer: everyone starts once. One game per pairing halves the games; the colours even out over the rounds.') }}</span>
</div>
@if ($options->legs === 2)
    <div class="{{ $optionRow }}" data-test="leg-points-option">
        <span class="flex flex-col items-start gap-2">
            <span id="legp-l" class="text-[13px] font-bold">{{ __('Points in the table') }}</span>
            <x-tournaments.segmented labelledby="legp-l" method="pickLegPoints" :current="$options->legPoints" :grow="false" data-test="leg-points"
                :options="[[false, __('Per pairing')], [true, __('Per game')]]" />
        </span>
        <span class="{{ $help }}">{{ $options->sequence()
            ? __('Per game: every game counts, a win 1 and a draw ½, so up to :count points per pairing, and each game is in the table once it ends. Per pairing: the pairing counts once, 1 for winning it, ½ for a level score.', ['count' => $options->legCount()])
            : __('Per game: every game counts, a win 1 and a draw ½, so up to 2 points per pairing, and each game is in the table once it ends. Per pairing: the pairing counts once, 1 for winning it, ½ when each wins a game.') }}</span>
    </div>
@endif
