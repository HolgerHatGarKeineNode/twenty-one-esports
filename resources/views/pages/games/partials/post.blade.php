{{--
    Sharing the game on Nostr (NIP rev. 9.4), right under the result of a
    finished game, for its players (resources/js/gamePost.js). Reported
    2026-09-28: as a small grey card further down nobody saw it. Now it is the
    moment after the result: the final position, a line for the winner, and
    two main actions side by side from sm (stacked on a phone): share the
    game, or ask for a rematch.

    Optional and never on its own: moves are no events, the league signs the
    record of a rated game, and this posts the player's own copy (kind 64, the
    same PGN, quoting the league's record when there is one; a casual game's
    post stands alone) only after the preview and a click on "Sign and post".
    Once per player; after that the card says so and links to the note.
--}}
@php
    use App\Models\NostrEvent;
    use App\Support\Badges\ProfileBadges;
    use App\Support\Nostr\NostrKeys;
    use App\Support\Nostr\SignerMessages;

    $postId = $color === 'w' ? $game->white_post_event_id : $game->black_post_event_id;
    $postEvent = $postId === null ? null : NostrEvent::query()->find($postId);
    $won = $winner !== null && $winner === $color;
    $lastUci = $game->moves->last()?->uci;
    $postConfig = [
        'pubkey' => auth()->user()->pubkey,
        'relays' => ProfileBadges::browserRelays(),
        'posted' => $postId !== null,
        'postUrl' => $postEvent === null ? null : 'https://njump.me/'.NostrKeys::nevent($postEvent->event_id, $postEvent->pubkey, 64),
        // From the game-over card's button: open the preview right away.
        'preview' => request()->boolean('post'),
        'labels' => [
            'signer' => SignerMessages::labels(),
            'notOnYourRelays' => __('None of your relays took it yet; the league relays carry it.'),
            'errors' => [
                'already_posted' => __('You already posted this game.'),
                'not_finished' => __('Only a finished game can be posted.'),
                'not_a_player' => __('Only the two players can do that.'),
                'signature_rejected' => __('Your signature did not match the post. Nothing was posted.'),
                'default' => __('That did not work. Please try again.'),
            ],
        ],
    ];
@endphp

<section id="post" aria-labelledby="post-h" x-data="gamePost(@js($postConfig))"
         class="grid scroll-mt-20 grid-cols-[88px_minmax(0,1fr)] gap-x-4 gap-y-4 rounded-lg bg-card p-4 shadow-[inset_0_0_0_1px_var(--color-btc-ring)] sm:grid-cols-[120px_minmax(0,1fr)] lg:gap-x-6 lg:p-6"
         data-test="game-post">
    <x-match-dock.board :fen="$game->fen" :flip="$color === 'b'" :last="$lastUci ? [substr($lastUci, 0, 2), substr($lastUci, 2, 2)] : []"
                        :label="__('Final position of :number', ['number' => $game->number()])" class="w-full self-start sm:row-span-3" data-test="game-post-board" />

    <h2 id="post-h" @class(['m-0 self-center font-display text-lg leading-tight font-bold text-balance sm:self-end lg:text-[22px]', 'text-btc-hi' => $won])>{{ $won ? __('You won — show it') : __('Keep this game on your profile') }}</h2>

    {{-- On a phone the actions come before this line: under the result and the chat sheet the share button was out of the first viewport (German, 375 x 812) --}}
    <div class="order-2 col-span-2 sm:order-none sm:col-span-1 sm:col-start-2">
        <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2" x-show="step !== 'done'">{{ __('Share the board and every move as one note on Nostr, signed by you. You see the note first; nothing is posted until you sign it.') }}</p>
        <p class="m-0 text-[13px] leading-normal text-win" role="status" x-show="step === 'done'" x-cloak data-test="game-post-done">{{ __('Posted to your profile, signed by you.') }}</p>
    </div>

    {{-- The two main actions: side by side from sm, stacked on a phone, 44 px each --}}
    <div class="order-1 col-span-2 flex flex-col gap-2 sm:order-none sm:col-span-1 sm:col-start-2 sm:flex-row sm:flex-wrap sm:items-center" data-test="game-post-actions">
        <template x-if="step !== 'done'">
            <x-button icon="send" class="whitespace-nowrap" x-on:click="open()" x-bind:disabled="step !== 'idle'" data-test="game-post-open">{{ __('Share this game on Nostr') }}</x-button>
        </template>
        <template x-if="step === 'done'">
            <span class="flex min-h-11 flex-wrap items-center gap-x-4 gap-y-1">
                <span class="inline-flex h-11 items-center gap-2 rounded-md bg-win-tint px-[18px] text-[13px] font-bold text-win" data-test="game-post-posted"><x-icon name="check" :size="16" />{{ __('Posted') }}</span>
                <a x-show="postUrl" :href="postUrl" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center text-[13px] font-bold underline underline-offset-2" data-test="game-post-view">{{ __('View it on Nostr') }}<span class="sr-only"> {{ __('(opens njump.me in a new tab)') }}</span></a>
            </span>
        </template>
        @if ($rival ?? null)
            <x-button :href="route('chess.challenge', ['to' => $rival->npub])" icon="retry" class="whitespace-nowrap" data-test="challenge-again">{{ __('Rematch') }}</x-button>
        @endif
    </div>

    <template x-if="step === 'preview' || step === 'posting'">
        <div class="order-3 col-span-2 flex min-w-0 flex-col gap-2.5 rounded-md bg-well px-3.5 py-3" role="group" aria-labelledby="post-pv" data-test="game-post-preview">
            <b id="post-pv" class="text-[13px]">{{ __('Preview: this is what your profile shows') }}</b>
            <span class="text-xs leading-normal text-ink-2">{{ $game->record_event_id !== null ? __('One chess note (NIP-64, kind 64), signed with your key and quoting the league\'s record. Apps that know chess show the board; others show this line:') : __('One chess note (NIP-64, kind 64), signed with your key. Apps that know chess show the board; others show this line:') }}</span>
            <span class="min-w-0 text-[13px] break-words text-ink" x-text="alt" data-test="game-post-alt"></span>
            <pre tabindex="0" aria-label="{{ __('PGN of the game') }}" class="m-0 max-h-40 overflow-auto rounded-md bg-ground px-3 py-2 font-mono text-xs leading-[1.6] whitespace-pre-wrap text-ink-2" x-text="template?.content ?? ''" data-test="game-post-pgn"></pre>
            <span class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                <x-button variant="quiet" x-on:click="cancel()" x-bind:disabled="step === 'posting'" data-test="game-post-cancel">{{ __('Cancel') }}</x-button>
                <x-button icon="send" x-on:click="post()" x-bind:disabled="step === 'posting'" class="whitespace-nowrap" data-test="game-post-sign">
                    <span x-text="step === 'posting' ? @js(__('Posting…')) : @js(__('Sign and post'))">{{ __('Sign and post') }}</span>
                </x-button>
            </span>
        </div>
    </template>

    <p x-show="error" x-text="error" x-cloak class="order-3 col-span-2 m-0 text-[13px] text-loss" role="alert" data-test="game-post-error"></p>
    <p x-show="warning" x-text="warning" x-cloak class="order-3 col-span-2 m-0 text-[13px] text-ink-2" role="status" data-test="game-post-warning"></p>
</section>
