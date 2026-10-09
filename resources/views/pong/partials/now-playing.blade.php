{{--
    The credit of the MIDI track that plays in a Proof of Pong match (plan "Proof of Pong", P8;
    resources/js/midi/player.js announces each one as a `midi-track` event, resources/js/pong/show.js fills it): title,
    linked to its source, author and license. CC-BY and OGA-BY tracks require it; hidden while no music plays. It sits in
    the strip page.js keeps free under the field.
--}}
<p class="now-playing" id="now-playing" data-test="pong-now-playing" hidden><span>{{ __('Playing now') }}:</span> <a id="now-playing-title" rel="noopener" target="_blank"></a> <span id="now-playing-by"></span></p>
