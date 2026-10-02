{{--
    The viewer's own TMNF moment on a week's board (App\Support\Tmnf\TmnfMoments::shareableOn()): their counted finish
    that holds their place, with the share button (a Nostr post with its card, the card as an image).
    $moment: the score run id, or null for nothing to share (the partial renders nothing then).
--}}
@if ($moment !== null)
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line bg-well px-3 py-2" data-test="tmnf-share">
        <span class="flex items-center gap-2 text-[13px] text-ink"><x-icon name="flag" :size="16" class="shrink-0 text-tmnf" />{{ __('Your time is on the board. Share it.') }}</span>
        <livewire:share-button type="tmnf" :moment="$moment" :key="'tmnf-share-'.$moment" />
    </div>
@endif
