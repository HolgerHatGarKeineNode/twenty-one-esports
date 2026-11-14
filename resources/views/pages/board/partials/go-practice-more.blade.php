{{-- The quiet ways on after a game against a bot (partials/go-practice-next): the same bot again where the next bot is the orange button, and the bots. --}}
<x-button variant="quiet" class="grow whitespace-nowrap" x-show="nextBot" x-on:click="play()" data-test="play-same">{{ __('Again') }}</x-button>
<x-button variant="secondary" class="grow whitespace-nowrap" x-on:click="pickBot(botId)" data-test="choose-bot">{{ __('Choose a bot') }}</x-button>
