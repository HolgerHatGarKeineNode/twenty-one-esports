<?php

use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Cards\ShareCard;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The share button of one moment (P11): "Post on Nostr" signs a kind 1 note
 * with the share card in the browser (sharePost, resources/js/badgeShare.js)
 * and submits it through the league ({@see SharePosts}); "Image" and "Story"
 * download the card (1200 × 630, 1080 × 1920) for Signal or Telegram.
 * Renders nothing for guests or for a moment that is not the viewer's.
 */
new class extends Component {
    #[Locked]
    public string $type;

    #[Locked]
    public string $moment;

    public function mount(string $type, string $moment): void
    {
        $this->type = $type;
        $this->moment = $moment;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function prepareShare(SharePosts $posts): ?array
    {
        return $this->refusable(fn () => $posts->prepare($this->me(), $this->type, $this->moment));
    }

    public function submitShare(string $signed, SharePosts $posts): bool
    {
        return $this->refusable(fn () => $posts->submit($this->me(), $this->type, $this->moment, json_decode($signed, true)) !== null) ?? false;
    }

    public function card(): ?ShareCard
    {
        $user = Auth::user();

        try {
            return $user instanceof User ? app(SharePosts::class)->card($user, $this->type, $this->moment) : null;
        } catch (ShareRefused) {
            return null;
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|null
     */
    private function refusable(Closure $action): mixed
    {
        try {
            return $action();
        } catch (ShareRefused $refused) {
            $this->addError('share', $refused->getMessage());
        } catch (RejectedEvent) {
            $this->addError('share', __('The confirmation did not match. Please try again.'));
        }

        return null;
    }

    private function me(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php($card = $this->card())
<div @class(['flex min-w-0 flex-col gap-1.5', 'hidden' => $card === null])>
    @if ($card)
        <div class="flex min-w-0 flex-wrap items-center gap-2" data-test="share-button" data-type="{{ $type }}"
             x-data="sharePost({ pubkey: @js(auth()->user()->pubkey), relays: @js(ProfileBadges::browserRelays()), messages: @js(SignerMessages::labels()) })">
            <button type="button" x-on:click="share()" x-bind:disabled="busy || done" data-test="share-post"
                    class="btn-p inline-flex h-11 min-w-0 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold whitespace-nowrap text-on-btc disabled:cursor-default disabled:opacity-80">
                <x-icon name="send" :size="16" class="shrink-0" />
                <span class="truncate" x-text="done ? @js(__('Posted on Nostr')) : (busy ? @js(__('Posting…')) : @js(__('Post on Nostr')))">{{ __('Post on Nostr') }}</span>
            </button>
            <a href="{{ $card->path('wide') }}" download="twentyone-{{ $type }}.png" data-test="share-download-wide"
               class="btn-w inline-flex h-11 shrink-0 items-center gap-1.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:text-ink"><x-icon name="download" :size="16" />{{ __('Image') }}</a>
            <a href="{{ $card->path('story') }}" download="twentyone-{{ $type }}-story.png" data-test="share-download-story"
               class="btn-w inline-flex h-11 shrink-0 items-center gap-1.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:text-ink"><x-icon name="download" :size="16" />{{ __('Story') }}</a>
            <span x-show="done" x-cloak role="status" class="text-xs text-win" data-test="share-done">{{ __('Signed by you, sent to your relays.') }}</span>
            <p x-show="error" x-text="error" x-cloak class="m-0 basis-full text-[13px] text-loss" role="alert"></p>
        </div>
        @error('share')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
    @endif
</div>
