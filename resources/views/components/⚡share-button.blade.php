<?php

use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Cards\SharePost;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The share button of one moment (P11, P46): "Post on Nostr" shows the exact
 * kind 1 note first, with its card, whom it mentions and what it quotes; only
 * "Sign and post" signs it in the browser (sharePost,
 * resources/js/badgeShare.js) and submits it through the league
 * ({@see SharePosts}). "Image" and "Story" download the card (1200 × 630,
 * 1080 × 1920 when the moment has one) for Signal or Telegram.
 * Renders nothing for guests or for a moment that is not the viewer's.
 */
new class extends Component {
    #[Locked]
    public string $type;

    #[Locked]
    public string $moment;

    /** The button's words where the moment has its own ("Post the win"); "Post on Nostr" by default. */
    #[Locked]
    public ?string $label = null;

    /** Off where the page offers the card's download already (the sign-up's invite row). */
    #[Locked]
    public bool $downloads = true;

    public function mount(string $type, string $moment, ?string $label = null, bool $downloads = true): void
    {
        $this->type = $type;
        $this->moment = $moment;
        $this->label = $label;
        $this->downloads = $downloads;
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

    #[Computed]
    public function sharePost(): ?SharePost
    {
        $user = Auth::user();

        try {
            return $user instanceof User ? app(SharePosts::class)->post($user, $this->type, $this->moment) : null;
        } catch (ShareRefused) {
            return null;
        }
    }

    /**
     * What the shared preview sheet shows for this moment (the sheet itself is one per page, see the view). A computed,
     * so it is no action the browser can call.
     *
     * @return array{card: string, alt: string, width: int, height: int, mentions: string|null, quote: string|null}|null
     */
    #[Computed]
    public function preview(): ?array
    {
        $post = $this->sharePost;

        return $post === null ? null : [
            'card' => $post->cardPath(),
            'alt' => $post->sentence,
            'width' => $post->dimensions[0],
            'height' => $post->dimensions[1],
            'mentions' => $post->mentions === [] ? null : __('Mentions :names; their apps may notify them.', ['names' => implode(', ', array_column($post->mentions, 'name'))]),
            'quote' => $post->quote === null ? null : __('Quotes :what, so apps show it under the note.', ['what' => $post->quote['what']]),
        ];
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

@php($post = $this->sharePost)
<div @class(['flex min-w-0 flex-col gap-1.5', 'hidden' => $post === null])>
    @if ($post)
        <div class="flex min-w-0 flex-col gap-2" data-test="share-button" data-type="{{ $type }}"
             x-data="sharePost({ pubkey: @js(auth()->user()->pubkey), relays: @js(ProfileBadges::browserRelays()), messages: @js([...SignerMessages::labels(), 'notPosted' => __('None of your relays took the post. Try again later.'), 'changed' => __('The post changed since you opened it. Check it again, then sign.')]), preview: @js($this->preview) })">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <button type="button" x-on:click="open()" x-show="step !== 'done'" x-bind:disabled="step !== 'idle'" data-test="share-post"
                        class="btn-p inline-flex h-11 min-w-0 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold whitespace-nowrap text-on-btc disabled:cursor-default disabled:opacity-80">
                    <x-icon name="send" :size="16" class="shrink-0" />
                    <span class="truncate" x-text="step === 'opening' ? @js(__('Preparing…')) : @js($label ?? __('Post on Nostr'))">{{ $label ?? __('Post on Nostr') }}</span>
                </button>
                <span x-show="step === 'done'" x-cloak class="inline-flex h-11 items-center gap-2 rounded-md bg-win-tint px-4 text-[13px] font-bold text-win" data-test="share-posted"><x-icon name="check" :size="16" />{{ __('Posted on Nostr') }}</span>
                @if ($downloads)
                    <a href="{{ $post->cardPath() }}" download="twentyone-{{ $type }}.png" data-test="share-download-wide"
                       class="btn-w inline-flex h-11 shrink-0 items-center gap-1.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:text-ink"><x-icon name="download" :size="16" />{{ __('Image') }}</a>
                @endif
                @if ($downloads && $post->storyPath !== null)
                    <a href="{{ $post->storyPath }}" download="twentyone-{{ $type }}-story.png" data-test="share-download-story"
                       class="btn-w inline-flex h-11 shrink-0 items-center gap-1.5 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:text-ink"><x-icon name="download" :size="16" />{{ __('Story') }}</a>
                @endif
            </div>

            {{--
                The preview (the card and the exact note, before anything is signed) is ONE sheet per page, not one per
                button (performance plan P4): the first button of the page carries it as a template, and "Post on
                Nostr" moves it into its own slot (resources/js/badgeShare.js mountSheet()), bound to that button.
                wire:ignore keeps it there when this component renders again.
            --}}
            <div wire:ignore data-share-slot>
                @once
                    <template data-share-sheet>
                        <div x-show="step === 'preview' || step === 'posting'" x-cloak role="group" x-bind:aria-labelledby="'share-pv-' + $wire.$id" data-test="share-preview"
                             class="flex min-w-0 flex-col gap-2.5 rounded-md bg-well px-3.5 py-3">
                            <b x-bind:id="'share-pv-' + $wire.$id" class="text-[13px]">{{ __('Preview: this is the note your profile shows') }}</b>
                            <img x-bind:src="preview.card" x-bind:alt="preview.alt" x-bind:width="preview.width" x-bind:height="preview.height" loading="lazy"
                                 class="aspect-[1200/630] h-auto w-full max-w-[420px] rounded-md shadow-ring" data-test="share-preview-card">
                            <p class="m-0 min-w-0 text-[13px] leading-normal whitespace-pre-wrap text-ink [overflow-wrap:anywhere]" x-text="template?.content ?? ''" data-test="share-preview-text"></p>
                            <ul class="m-0 flex list-none flex-col gap-1 p-0 text-xs leading-normal text-ink-2">
                                <li>{{ __('One note (kind 1), signed with your key. Apps show the card from the link.') }}</li>
                                <template x-if="preview.mentions"><li x-text="preview.mentions" data-test="share-preview-mentions"></li></template>
                                <template x-if="preview.quote"><li x-text="preview.quote" data-test="share-preview-quote"></li></template>
                            </ul>
                            {{-- Stacked on a phone: "Signieren und posten" does not fit half of a 311 px card (measured, German at 375) --}}
                            <span class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                <x-button variant="quiet" x-on:click="cancel()" x-bind:disabled="step === 'posting'" data-test="share-cancel">{{ __('Cancel') }}</x-button>
                                <x-button icon="send" x-on:click="post()" x-bind:disabled="step === 'posting'" class="whitespace-nowrap" data-test="share-sign">
                                    <span x-text="step === 'posting' ? @js(__('Posting…')) : @js(__('Sign and post'))">{{ __('Sign and post') }}</span>
                                </x-button>
                            </span>
                        </div>
                    </template>
                @endonce
            </div>

            <span x-show="step === 'done'" x-cloak role="status" class="text-xs text-win" data-test="share-done">{{ __('Signed by you, sent to your relays.') }}</span>
            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>
            <p x-show="warning" x-text="warning" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="share-warning"></p>
        </div>
        @error('share')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
    @endif
</div>
