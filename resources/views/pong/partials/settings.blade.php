{{--
    The settings of a Proof of Pong page (plan "Proof of Pong", P3), opened by the gear in the HUD: volume, effects,
    voices (the soundboard clips), music and its own volume (resources/js/pong/sound.js, localStorage `pong-sound`), the arena's quality
    and motion (resources/js/pong/page.js, `pong-settings`). The game runs on while it is open.
--}}
<div class="settings" id="settings" hidden role="dialog" aria-labelledby="settings-h" data-test="pong-settings">
    <div class="settings-head">
        <h2 id="settings-h">{{ __('Sound and display') }}</h2>
        <button type="button" class="leave" data-close aria-label="{{ __('Close') }}" title="{{ __('Close') }}">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>
    <label class="row range"><span>{{ __('Volume') }}</span><input type="range" name="vol" min="0" max="100" step="5"></label>
    <label class="row"><input type="checkbox" name="fx"><span>{{ __('Effects') }}</span></label>
    <label class="row"><input type="checkbox" name="board"><span>{{ __('Voices') }}</span></label>
    <label class="row"><input type="checkbox" name="music" data-test="pong-setting-music"><span>{{ __('Music') }}</span></label>
    <label class="row range"><span>{{ __('Volume: :channel', ['channel' => __('Music')]) }}</span><input type="range" name="musicVol" min="0" max="100" step="5" data-test="pong-setting-music-volume"></label>
    <label class="row"><input type="checkbox" name="motion" data-test="pong-setting-motion"><span>{{ __('Motion effects') }}</span></label>
    <label class="row select"><span>{{ __('Quality') }}</span>
        <select name="quality" data-test="pong-setting-quality">
            <option value="auto">{{ __('Automatic') }}</option>
            <option value="high">{{ __('High') }}</option>
            <option value="medium">{{ __('Medium') }}</option>
            <option value="low">{{ __('Low') }}</option>
        </select>
    </label>
</div>
