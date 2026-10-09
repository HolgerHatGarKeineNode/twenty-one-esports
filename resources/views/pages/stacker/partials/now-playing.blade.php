{{--
    The credit of the MIDI track that plays (resources/js/midi/player.js announces each one as a
    `midi-track` event): title, linked to its source, author and license. CC-BY and OGA-BY tracks
    require it; shown for every track while the music plays, hidden otherwise. Inside stackerGame's
    x-data, next to the sound control (rendered twice like it; `$class` says which is displayed).
--}}
<p @class(['m-0 min-w-0 truncate text-[12px] leading-tight text-ink-3', $class]) x-show="nowPlaying" x-cloak data-test="now-playing">
    <span>{{ __('Playing now') }}:</span>
    <template x-if="nowPlaying">
        <span>
            <a x-bind:href="nowPlaying.source" x-text="nowPlaying.title" rel="noopener" target="_blank" class="text-ink-2 underline decoration-ink-3 underline-offset-4 hover:text-ink hover:decoration-btc" data-test="now-playing-title"></a>
            <span x-text="'– ' + nowPlaying.author + ' (' + nowPlaying.license + ')'"></span>
        </span>
    </template>
</p>
