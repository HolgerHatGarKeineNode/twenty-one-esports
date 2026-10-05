<?php

use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Comments\CommentRefused;
use App\Support\Comments\CommentTarget;
use App\Support\Comments\NostrAuthors;
use App\Support\Comments\NostrComments;
use App\Support\GameChat\GameChannels;
use App\Support\LeagueTime;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * Comments and likes on Nostr (P48, NIP "Comments, likes and RSVPs"): a
 * tournament's calendar event, a rated game's record or a rated series'
 * challenge ({@see CommentTarget}). The browser reads them from the league
 * relays (resources/js/nostrComments.js); a comment or like is shown as the
 * exact event first and signed only on "Sign and post" / "Sign and like",
 * then relayed by the league ({@see NostrComments}). Renders an empty box for
 * a page without a league event (a casual game or series).
 */
new class extends Component {
    #[Locked]
    public string $type;

    #[Locked]
    public string $target;

    public function mount(string $type, string $target): void
    {
        $this->type = $type;
        $this->target = $target;
    }

    #[Computed]
    public function commentTarget(): ?CommentTarget
    {
        return CommentTarget::resolve($this->type, $this->target);
    }

    /** @return array{template?: array<string, mixed>, error?: string} */
    #[Renderless]
    public function prepareComment(string $text, NostrComments $comments): array
    {
        return $this->answer(fn () => ['template' => $comments->commentTemplate($this->me(), $this->type, $this->target, $text)]);
    }

    /** @return true|array{error: string} */
    #[Renderless]
    public function submitComment(string $text, string $signed, NostrComments $comments): bool|array
    {
        return $this->answer(fn () => $comments->submitComment($this->me(), $this->type, $this->target, $text, json_decode($signed, true)) !== null);
    }

    /** @return array{template?: array<string, mixed>, error?: string} */
    #[Renderless]
    public function prepareReaction(NostrComments $comments): array
    {
        return $this->answer(fn () => ['template' => $comments->reactionTemplate($this->me(), $this->type, $this->target)]);
    }

    /** @return true|array{error: string} */
    #[Renderless]
    public function submitReaction(string $signed, NostrComments $comments): bool|array
    {
        return $this->answer(fn () => $comments->submitReaction($this->me(), $this->type, $this->target, json_decode($signed, true)) !== null);
    }

    /**
     * @param  array<mixed>  $pubkeys
     * @return array<string, array{name: string, avatar: string, npub: string, href: string|null, player: bool}>
     */
    #[Renderless]
    public function authors(array $pubkeys): array
    {
        return NostrAuthors::of($pubkeys);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|array{error: string}
     */
    private function answer(Closure $action): mixed
    {
        try {
            return $action();
        } catch (CommentRefused $refused) {
            return ['error' => $refused->getMessage()];
        } catch (RejectedEvent) {
            return ['error' => __('The confirmation did not match. Please try again.')];
        }
    }

    private function me(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $target = $this->commentTarget;
    $me = auth()->user();
    $what = match ($type) {
        'tournament' => __('the tournament’s calendar event'),
        'game' => __('the league’s record of the game'),
        default => __('the challenge of the series'),
    };
@endphp
<div @class(['min-w-0', 'hidden' => $target === null])>
    @if ($target)
        <section aria-labelledby="comments-h-{{ $this->getId() }}" class="flex min-w-0 flex-col gap-4 rounded-card bg-card p-4 shadow-ring sm:p-5" data-test="nostr-comments" data-type="{{ $type }}"
                 x-data="nostrComments(@js([
                     'target' => $target->forBrowser(),
                     'relays' => config('esports.relays', []),
                     'readRelays' => (int) config('esports.comments.read_relays', 5),
                     'writeRelays' => ProfileBadges::browserRelays(),
                     'moderation' => ['creator' => GameChannels::creator(), 'relays' => GameChannels::relays()],
                     'muted' => \App\Support\Moderation\SiteModeration::leftOutFor($me instanceof User ? $me : null),
                     'me' => $me?->pubkey,
                     'page' => (int) config('esports.comments.page', 20),
                     'maxShown' => (int) config('esports.comments.max_shown', 200),
                     'readLimit' => (int) config('esports.comments.read_limit', 500),
                     'maxLength' => (int) config('esports.comments.max_length', 1000),
                     'locale' => app()->getLocale(),
                     'timeZone' => LeagueTime::zone(),
                     'labels' => [
                         ...SignerMessages::labels(),
                         'notPosted' => __('None of your relays took it. Try again later.'),
                         'changed' => __('It changed since you opened it. Check it again, then sign.'),
                         'someone' => __('Someone on Nostr'),
                     ],
                 ]))">
            <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-0.5">
                    <h2 id="comments-h-{{ $this->getId() }}" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Comments') }}</h2>
                    <span class="text-xs text-ink-2">{{ __('On Nostr, on :what. Nostr apps that show comments show these too.', ['what' => $what]) }}</span>
                </div>
                @if ($me)
                    <button type="button" x-on:click="previewLike()" x-bind:disabled="likes.mine || likeStep !== 'idle'" x-bind:aria-pressed="likes.mine ? 'true' : 'false'" data-test="like"
                            class="btn-w inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] text-ink disabled:cursor-default"
                            x-bind:class="likes.mine && 'text-btc-hi'">
                        <x-icon name="heart" :size="18" />
                        <span x-text="likes.mine ? @js(__('You like this')) : @js(__('Like'))">{{ __('Like') }}</span>
                        <span class="tabular-nums text-ink-2" x-show="likes.known" x-cloak x-text="likes.count" data-test="like-count"></span>
                    </button>
                @else
                    <span class="inline-flex h-11 items-center gap-2 text-[13px] text-ink-2" x-show="likes.known" x-cloak data-test="like-count-guest">
                        <x-icon name="heart" :size="18" /><span class="tabular-nums" x-text="likes.count"></span>
                    </span>
                @endif
            </div>

            @if ($me)
                {{-- The like, as the event it is, before anything is signed --}}
                <div x-show="likeStep === 'preview' || likeStep === 'posting'" x-cloak role="group" aria-label="{{ __('Preview of your like') }}" data-test="like-preview"
                     class="flex min-w-0 flex-col gap-2.5 rounded-md bg-well px-3.5 py-3">
                    <p class="m-0 text-[13px] leading-normal">{{ __('One like (kind 7, “+”), signed with your key, on :what. It shows in Nostr apps next to it.', ['what' => $what]) }}</p>
                    <span class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <x-button variant="quiet" x-on:click="cancelLike()" x-bind:disabled="likeStep === 'posting'" data-test="like-cancel">{{ __('Cancel') }}</x-button>
                        <x-button icon="heart" x-on:click="like()" x-bind:disabled="likeStep === 'posting'" class="whitespace-nowrap" data-test="like-sign">
                            <span x-text="likeStep === 'posting' ? @js(__('Posting…')) : @js(__('Sign and like'))">{{ __('Sign and like') }}</span>
                        </x-button>
                    </span>
                </div>

                {{-- The composer: write, see the exact comment, sign --}}
                <div class="flex min-w-0 flex-col gap-2" data-test="comment-composer">
                    <label for="comment-{{ $this->getId() }}" class="text-xs text-ink-2">{{ __('Your comment') }}</label>
                    <textarea id="comment-{{ $this->getId() }}" x-model="text" x-bind:readonly="step !== 'idle'" rows="3" maxlength="{{ (int) config('esports.comments.max_length', 1000) }}" data-test="comment-text"
                              class="min-h-24 w-full min-w-0 resize-y rounded-md border border-line bg-well px-3 py-2.5 text-[15px] leading-normal text-ink"></textarea>
                    <div class="flex min-w-0 flex-wrap items-center justify-between gap-2" x-show="step === 'idle' || step === 'opening'">
                        <span class="text-xs text-ink-3 tabular-nums" x-text="@js(__(':count characters left')).replace(':count', left)"></span>
                        <x-button icon="eye" x-on:click="preview()" x-bind:disabled="step !== 'idle' || text.trim() === ''" class="whitespace-nowrap disabled:cursor-default disabled:opacity-60" data-test="comment-preview">
                            <span x-text="step === 'opening' ? @js(__('Preparing…')) : @js(__('Preview'))">{{ __('Preview') }}</span>
                        </x-button>
                    </div>
                    <div x-show="step === 'preview' || step === 'posting'" x-cloak role="group" aria-label="{{ __('Preview of your comment') }}" data-test="comment-preview-box"
                         class="flex min-w-0 flex-col gap-2.5 rounded-md bg-well px-3.5 py-3">
                        <b class="text-[13px]">{{ __('Preview: this is the comment as it goes out') }}</b>
                        <p class="m-0 min-w-0 text-[15px] leading-normal whitespace-pre-wrap text-ink [overflow-wrap:anywhere]" x-text="template?.content ?? ''" data-test="comment-preview-text"></p>
                        <p class="m-0 text-xs leading-normal text-ink-2">{{ __('One comment (kind 1111), signed with your key, on :what. Relays keep it; a deletion request later is not sure to remove it everywhere.', ['what' => $what]) }}</p>
                        <span class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <x-button variant="quiet" x-on:click="edit()" x-bind:disabled="step === 'posting'" data-test="comment-edit">{{ __('Edit') }}</x-button>
                            <x-button icon="send" x-on:click="post()" x-bind:disabled="step === 'posting'" class="whitespace-nowrap" data-test="comment-sign">
                                <span x-text="step === 'posting' ? @js(__('Posting…')) : @js(__('Sign and post'))">{{ __('Sign and post') }}</span>
                            </x-button>
                        </span>
                    </div>
                    <span x-show="posted" x-cloak role="status" class="text-xs text-win" data-test="comment-posted">{{ __('Signed by you, sent to your relays and the league’s.') }}</span>
                </div>
            @else
                <p class="m-0 text-[13px] text-ink-2" data-test="comment-login">
                    <a href="{{ route('login') }}" class="inline-flex min-h-11 items-center font-bold">{{ __('Log in with Nostr to comment') }}</a>
                </p>
            @endif

            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="comment-error"></p>
            <p x-show="warning" x-text="warning" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="comment-warning"></p>

            {{-- The list: newest first, read from the relays --}}
            <div class="flex min-w-0 flex-col gap-3" aria-live="polite">
                <p x-show="status === 'loading'" class="m-0 text-[13px] text-ink-2" data-test="comments-loading">{{ __('Reading the comments from the relays…') }}</p>
                <div x-show="status === 'unreached'" x-cloak class="flex flex-wrap items-center gap-3" data-test="comments-unreached">
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No relay answered, so the comments are unknown right now.') }}</p>
                    <x-button variant="quiet" icon="retry" x-on:click="retry()">{{ __('Try again') }}</x-button>
                </div>
                <p x-show="status === 'ready' && rows.length === 0" x-cloak class="m-0 text-[13px] text-ink-2" data-test="comments-empty">{{ __('No comments yet.') }}</p>

                <ol class="m-0 flex min-w-0 list-none flex-col gap-2 p-0" data-test="comment-list">
                    <template x-for="row in rows" :key="row.id">
                        <li class="flex min-w-0 flex-col gap-1 rounded-md bg-ground px-3 pt-1 pb-3 shadow-ring-hairline" data-test="comment" x-bind:data-id="row.id">
                            <div class="flex min-w-0 flex-wrap items-center gap-x-2.5 text-xs text-ink-2">
                                {{-- A league player's name opens the player card (resources/js/profiles.js); the whole row is the 44 px target --}}
                                <template x-if="row.href">
                                    <a x-bind:href="row.href" x-bind:data-player-card="row.npub" aria-haspopup="dialog" class="inline-flex min-h-11 min-w-0 items-center gap-2.5 text-ink hover:text-btc-hi">
                                        <img x-bind:src="row.avatar" alt="" width="32" height="32" loading="lazy" class="size-8 shrink-0 rounded-full bg-raised">
                                        <span class="min-w-0 truncate text-[13px] font-bold" x-text="row.name" data-test="comment-author"></span>
                                    </a>
                                </template>
                                <template x-if="! row.href">
                                    <span class="inline-flex min-h-11 min-w-0 items-center gap-2.5 text-ink">
                                        <img x-bind:src="row.avatar" x-show="row.avatar" alt="" width="32" height="32" loading="lazy" class="size-8 shrink-0 rounded-full bg-raised">
                                        <span x-show="! row.avatar" class="size-8 shrink-0 rounded-full bg-raised"></span>
                                        <span class="min-w-0 truncate text-[13px] font-bold" x-text="row.name" data-test="comment-author"></span>
                                    </span>
                                </template>
                                <span x-show="! row.player && row.npub" class="text-ink-3">{{ __('not in the league') }}</span>
                                <span x-show="row.reply" class="text-ink-3">{{ __('reply') }}</span>
                                <time x-bind:datetime="row.iso" x-text="row.when" class="tabular-nums"></time>
                            </div>
                            <p class="m-0 min-w-0 text-[15px] leading-normal whitespace-pre-wrap text-ink [overflow-wrap:anywhere]" x-text="row.text" data-test="comment-text-shown"></p>
                        </li>
                    </template>
                </ol>

                <div x-show="more" x-cloak>
                    <x-button variant="quiet" x-on:click="loadMore()" x-bind:disabled="loadingMore" data-test="comments-more">
                        <span x-text="loadingMore ? @js(__('Loading…')) : @js(__('Load more'))">{{ __('Load more') }}</span>
                    </x-button>
                </div>
            </div>
        </section>
    @endif
</div>
