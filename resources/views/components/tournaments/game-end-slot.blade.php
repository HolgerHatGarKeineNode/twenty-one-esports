@props(['url', 'panel' => null])

{{--
    The place of <x-tournaments.game-end> on a live board (chess, board games),
    whose end the browser sees before the server renders anything: the page's
    component renders the panel (`tournamentPanel()`) once the game is over, as
    the league synced the bracket after the result. `panel` = the panel's data
    for a game that was already over when the page loaded: rendered right here.
    If the request fails, "Back to the tournament" stays.
--}}
@if ($panel)
    <x-tournaments.game-end :panel="$panel" :framed="false" />
@else
    <div x-data="{ panel: null }" x-init="$wire.tournamentPanel().then((html) => panel = html ?? '').catch(() => panel = '')" {{ $attributes }} data-test="tournament-panel-slot">
        <div x-html="panel ?? ''"></div>
        <template x-if="panel === ''">
            <x-button :href="$url" variant="quiet" class="w-full" data-test="back-to-tournament">{{ __('Back to the tournament') }}</x-button>
        </template>
    </div>
@endif
