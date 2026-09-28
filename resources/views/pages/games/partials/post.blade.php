{{--
    "Post this game to my profile" (NIP rev. 9.4), on a finished game's page
    for its players (resources/js/gamePost.js). Optional and never on its own:
    moves are no events, the league signs the game's record, and this posts
    the player's own copy (kind 64, the same PGN, quoting the league's record)
    only after the preview and a click on "Sign and post". Once per player.
--}}
@php
    use App\Support\Badges\ProfileBadges;
    use App\Support\Nostr\SignerMessages;

    $posted = ($color === 'w' ? $game->white_post_event_id : $game->black_post_event_id) !== null;
    $postConfig = [
        'pubkey' => auth()->user()->pubkey,
        'relays' => ProfileBadges::browserRelays(),
        'posted' => $posted,
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

<section id="post" aria-labelledby="post-h" x-data="gamePost(@js($postConfig))" class="flex scroll-mt-20 flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6" data-test="game-post">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-6">
        <span class="flex min-w-0 grow flex-col gap-1">
            <h2 id="post-h" class="m-0 font-display text-base leading-[1.25] font-bold">{{ __('Your Nostr profile') }}</h2>
            <span class="text-[13px] leading-normal text-ink-2" x-show="step !== 'done'">{{ __('Moves are not posted. If you like, post the whole game once, as a chess board on your profile. Nothing is posted unless you click.') }}</span>
            <span class="text-[13px] leading-normal text-win" role="status" x-show="step === 'done'" x-cloak data-test="game-post-done">{{ __('Posted to your profile, signed by you.') }}</span>
        </span>
        <template x-if="step === 'idle'">
            <x-button variant="quiet" icon="send" class="shrink-0 whitespace-nowrap" x-on:click="open()" data-test="game-post-open">{{ __('Post this game to my profile') }}</x-button>
        </template>
    </div>

    <template x-if="step === 'preview' || step === 'posting'">
        <div class="flex min-w-0 flex-col gap-2.5 rounded-md bg-well px-3.5 py-3" role="group" aria-labelledby="post-pv" data-test="game-post-preview">
            <b id="post-pv" class="text-[13px]">{{ __('Preview: this is what your profile shows') }}</b>
            <span class="text-xs leading-normal text-ink-2">{{ __('One chess note (NIP-64, kind 64), signed with your key and quoting the league\'s record. Apps that know chess show the board; others show this line:') }}</span>
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

    <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="game-post-error"></p>
    <p x-show="warning" x-text="warning" x-cloak class="m-0 text-[13px] text-ink-2" role="status" data-test="game-post-warning"></p>
</section>
