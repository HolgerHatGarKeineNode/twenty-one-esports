{{--
    The way on after a game against a bot, in the result card (partials/result, `$actions`): one orange button, the next
    bot after a win, else the same bot again; from lg the quiet ways beside it (on a phone they follow the card, so the
    button keeps the first screen). The lessons are one tap away in the title row.
--}}
<div class="flex flex-col gap-2" data-test="practice-next">
    <x-button icon="bolt" class="w-full whitespace-nowrap" x-show="nextBot" x-on:click="const next = nextBot; pickBot(next); play()" data-test="next-bot"><span x-text="nextBot ? @js(__('Play :name')).replace(':name', cfg.bots[nextBot].name) : ''"></span></x-button>
    <x-button icon="bolt" class="w-full whitespace-nowrap" x-show="!nextBot" x-on:click="play()" data-test="play-again">{{ __('Again') }}</x-button>
    <div class="flex gap-2 max-lg:hidden">@include('pages.board.partials.go-practice-more')</div>
</div>
