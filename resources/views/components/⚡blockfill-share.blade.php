<?php

use App\Models\StackerRun;
use App\Models\User;
use App\Support\Cards\SharePost;
use App\Support\Cards\SharePosts;
use App\Support\Cards\ShareRefused;
use App\Support\Stacker\BlockfillMoments;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The share sheet of a Blockfill moment: a personal best, a new first place
 * of the week, a week place (App\Support\Stacker\BlockfillMoments). One per
 * page; every share button of the page opens it with a window event
 * `blockfill-share` ({ moment: <run id> }): the result screen of the game,
 * the player's own row of a week's leaderboard, the moment's own page.
 *
 * It shows the moment's card and offers the Nostr post (the share button,
 * {@see SharePosts}, signed in the browser), the system share sheet (Web
 * Share API) and the link to copy, all pointing at the moment's page. Only
 * the logged-in owner of a verified run that is a moment sees anything:
 * an id that is not theirs opens nothing.
 */
new class extends Component {
    /** The run the sheet shows; checked again on every render ({@see run()}). */
    public ?string $moment = null;

    public function openSheet(mixed $moment): void
    {
        $id = is_scalar($moment) ? (string) $moment : '';
        $this->moment = ctype_digit($id) && strlen($id) <= 18 ? $id : null;
        unset($this->run, $this->post);
    }

    public function closeSheet(): void
    {
        $this->moment = null;
    }

    /** The viewer's own run that is a moment, or null. */
    #[Computed]
    public function run(): ?StackerRun
    {
        $user = Auth::user();

        return $user instanceof User && $this->moment !== null ? app(BlockfillMoments::class)->ownedBy($user, $this->moment) : null;
    }

    #[Computed]
    public function post(): ?SharePost
    {
        $user = Auth::user();

        try {
            return $user instanceof User && $this->run !== null ? app(SharePosts::class)->post($user, 'blockfill', (string) $this->run->id) : null;
        } catch (ShareRefused) {
            return null;
        }
    }
}; ?>

@php
    $run = $this->run;
    $post = $this->post;
    $facts = $run === null ? null : app(BlockfillMoments::class)->of($run);
@endphp
<div x-data x-on:blockfill-share.window="$wire.openSheet($event.detail?.moment ?? null)" data-test="blockfill-share">
    @if ($run && $post && $facts)
        @php($url = route('stacker.moment', $run->id))
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-ground/75 sm:items-center sm:p-6" x-on:click.self="$wire.closeSheet()" x-on:keydown.escape.window="$wire.closeSheet()" wire:key="bf-sheet-{{ $run->id }}">
            <section role="dialog" aria-modal="true" aria-labelledby="bf-share-h" x-trap.noscroll="true" data-test="blockfill-share-sheet"
                     class="flex max-h-[calc(100svh-1rem)] w-full min-w-0 flex-col gap-4 overflow-y-auto overscroll-contain rounded-t-xl border-t border-line bg-bar px-4 pt-3 pb-6 sm:max-w-[560px] sm:rounded-lg sm:border sm:bg-card sm:px-6 sm:pt-5 sm:shadow-[0_16px_48px_rgba(0,0,0,.5)]"
                     x-data="{ copied: false, hint: '', canShare: typeof navigator.share === 'function', url: @js($url), text: @js($post->sentence),
                               async copy() { try { await navigator.clipboard.writeText(this.url); this.copied = true; this.hint = ''; setTimeout(() => { this.copied = false; }, 2500); } catch (e) { this.hint = @js(__('Copy did not work here. Select the link and copy it.')); } },
                               async share() { if (this.canShare) { try { await navigator.share({ title: document.title, text: this.text, url: this.url }); return; } catch (e) { if (e?.name === 'AbortError') return; } } await this.copy(); } }">
                <span aria-hidden="true" class="mx-auto h-1 w-10 shrink-0 rounded-xs bg-edge sm:hidden"></span>
                <div class="flex min-w-0 items-start justify-between gap-3">
                    <div class="flex min-w-0 flex-col gap-1">
                        <h2 id="bf-share-h" class="m-0 font-display text-xl font-bold [overflow-wrap:anywhere]">{{ __('Share your moment') }}</h2>
                        <p class="m-0 text-[13px] text-ink-2 [overflow-wrap:anywhere]" data-test="blockfill-share-headline">{{ BlockfillMoments::headline($facts['kind'], $facts['place']) }} · <span class="font-mono tabular-nums">{{ BlockfillMoments::time((int) $run->ticks) }}</span></p>
                    </div>
                    <button type="button" x-on:click="$wire.closeSheet()" class="inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-2 hover:text-ink" aria-label="{{ __('Close') }}" data-test="blockfill-share-close"><x-icon name="close" :size="20" /></button>
                </div>

                <img src="{{ $post->cardPath() }}" alt="{{ $post->sentence }}" width="{{ $post->dimensions[0] }}" height="{{ $post->dimensions[1] }}"
                     class="aspect-[1200/630] h-auto w-full rounded-md shadow-ring" data-test="blockfill-share-card">

                <livewire:share-button type="blockfill" :moment="(string) $run->id" :key="'bf-share-button-'.$run->id" />

                <div class="flex min-w-0 flex-wrap gap-2" data-test="blockfill-share-elsewhere">
                    <button type="button" x-on:click="share()" data-test="blockfill-share-system"
                            class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink"><x-icon name="send" :size="16" />{{ __('Share elsewhere') }}</button>
                    <button type="button" x-on:click="copy()" data-test="blockfill-share-copy"
                            class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink"><x-icon name="copy" :size="16" /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))">{{ __('Copy link') }}</span></button>
                </div>
                <p class="m-0 min-w-0 font-mono text-xs text-ink-3 [overflow-wrap:anywhere]" data-test="blockfill-share-url">{{ $url }}</p>
                <span class="text-xs text-win" role="status" x-show="copied" x-cloak>{{ __('Link copied') }}</span>
                <span class="text-xs text-loss" role="alert" x-show="hint" x-text="hint" x-cloak></span>
            </section>
        </div>
    @endif
</div>
