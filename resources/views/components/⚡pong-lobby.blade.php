<?php

use App\Events\LookingToPlayChanged;
use App\Models\PongInvite;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Pong\PongInvites;
use App\Support\Pong\PongMatches;
use App\Support\Pong\PongRatings;
use App\Support\Pong\PongRuleViolation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * The live part of Proof of Pong's lobby (plan "Proof of Pong", P2), on pages/pong/index next to the game against a
 * bot: who is online with "Looking to play" for Proof of Pong (components/lobby/online-now, the board lobbies'
 * block and Alpine state, resources/js/boardLobby.js), an invite to each player who looks for it, the invites
 * received (Accept opens the match in a new tab, PongController::accept()), the player's running match and their
 * Proof of Pong Elo.
 *
 * An accepted invite reaches the inviter by push (`pong.match-started` on their own channel): their lobby opens the
 * match in a new tab where the browser allows it, else in this one. An answer to an invite (`pong.invite`) reloads
 * the component. While an invite waits for an answer the lobby asks the server itself when it expires and at the
 * latest every SAFETY_NET_SECONDS (data-check-at, the board lobby's pattern); without a websocket every
 * FALLBACK_SECONDS.
 *
 * Invites are throttled per player (P8, review): at most INVITES_PER_MINUTE a minute, then the card says how long to
 * wait; withdrawing, declining and accepting are not counted.
 */
new class extends Component {
    public string $error = '';

    /** Whom this player's open invite goes to, and until when (ms): the online list shows "Invited · Withdraw". */
    #[Locked]
    public ?int $invitedUserId = null;

    #[Locked]
    public int $invitedUntilMs = 0;

    public const SAFETY_NET_SECONDS = 120;

    public const FALLBACK_SECONDS = 4;

    public const INVITES_PER_MINUTE = 6;

    public function mount(): void
    {
        $this->error = match (session('pong-error')) {
            null => '',
            default => $this->message((string) session('pong-error')),
        };
    }

    public function rendering(): void
    {
        $outgoing = $this->outgoing;
        $this->invitedUserId = $outgoing?->invitee_id;
        $this->invitedUntilMs = $outgoing?->expires_at->getTimestampMs() ?? 0;
    }

    /**
     * Asked while an invite waits (data-check-at) or every FALLBACK_SECONDS without a websocket: returns when to ask
     * next (ms, server clock), null when nothing waits.
     */
    public function poll(): ?int
    {
        unset($this->outgoing, $this->incoming, $this->activeMatch, $this->checkAt);

        return $this->checkAt;
    }

    /** When this lobby asks the server on its own (ms, server clock), or null: its invite's expiry, at the latest SAFETY_NET_SECONDS. */
    #[Computed]
    public function checkAt(): ?int
    {
        $outgoing = $this->outgoing;

        if ($outgoing === null) {
            return null;
        }

        return min((int) now()->addSeconds(self::SAFETY_NET_SECONDS)->getTimestampMs(), $outgoing->expires_at->getTimestampMs() + 500);
    }

    public function invite(int $userId): void
    {
        $this->attempt(function (User $user) use ($userId): void {
            $key = 'pong-invite:'.$user->id;

            if (RateLimiter::tooManyAttempts($key, self::INVITES_PER_MINUTE)) {
                throw new PongRuleViolation('too_many_invites', __('Too many invites in a short time: try again in :seconds s.', ['seconds' => RateLimiter::availableIn($key)]));
            }

            RateLimiter::hit($key, 60);
            app(PongInvites::class)->invite($user, User::query()->findOrFail($userId));
        });
    }

    public function withdrawInvite(): void
    {
        $this->attempt(fn (User $user) => app(PongInvites::class)->withdrawOutgoing($user));
    }

    public function declineInvite(int $inviteId): void
    {
        $this->attempt(fn (User $user) => app(PongInvites::class)->close(PongInvite::query()->findOrFail($inviteId), $user));
    }

    /**
     * "Looking to play" Proof of Pong: on, others may invite this player (and any other game's switch goes off: one at
     * a time); off, the invites still open are declined. The page sends the wanted state and gets the stored one back,
     * as in the board lobbies.
     */
    #[Renderless]
    public function setLookingToPlay(bool $looking): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return false;
        }

        $previous = $user->looking_to_play;
        $wanted = $looking ? PongInvites::LOOKING : ($previous === PongInvites::LOOKING ? null : $previous);

        if ($previous !== $wanted) {
            $user->forceFill(['looking_to_play' => $wanted])->save();
            Broadcasts::send(new LookingToPlayChanged($user->id, $user->looking_to_play));

            if (! $looking) {
                app(PongInvites::class)->declineAll($user);
            }
        }

        return $user->looking_to_play === PongInvites::LOOKING;
    }

    #[Computed]
    public function outgoing(): ?PongInvite
    {
        $user = auth()->user();

        return $user instanceof User ? app(PongInvites::class)->outgoing($user) : null;
    }

    /**
     * @return Collection<int, PongInvite>
     */
    #[Computed]
    public function incoming(): Collection
    {
        $user = auth()->user();

        return $user instanceof User ? app(PongInvites::class)->incoming($user) : collect();
    }

    #[Computed]
    public function activeMatch(): ?PongMatch
    {
        $user = auth()->user();

        return $user instanceof User ? PongMatches::activeMatchOf($user) : null;
    }

    /**
     * @return array{rating: int, results: int, wins: int, losses: int, provisional: bool}|null
     */
    #[Computed]
    public function rating(): ?array
    {
        $user = auth()->user();

        return $user instanceof User ? PongRatings::of($user->id) : null;
    }

    private function attempt(Closure $action): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirectRoute('login');

            return;
        }

        $this->error = '';

        try {
            $action($user);
        } catch (PongRuleViolation $violation) {
            $this->error = $this->message($violation->reason, $violation->getMessage());
        }

        unset($this->outgoing, $this->incoming, $this->activeMatch, $this->checkAt);
    }

    private function message(string $reason, string $text = ''): string
    {
        return match ($reason) {
            'already_playing' => __('Finish your Proof of Pong match first.'),
            'playing_elsewhere' => __('You are in another live game. One live game at a time: finish it first.'),
            'invite_closed' => __('That invite is no longer open.'),
            'opponent_playing' => __('That player is already in another live game, so the invite is closed.'),
            'invite_self' => __('You cannot invite yourself.'),
            'not_looking', 'too_many_invites' => $text,
            default => __('That did not work, please try again.'),
        };
    }
}; ?>

@php
    $user = auth()->user();
    $active = $this->activeMatch;
    $rating = $this->rating;
    $checkAt = $this->checkAt;
    $looking = $user?->looking_to_play === PongInvites::LOOKING;
@endphp

<section class="@container flex flex-col gap-4 rounded-lg bg-card p-5 shadow-ring" aria-labelledby="pong-live-h" data-test="pong-lobby"
         data-server-now="{{ now()->getTimestampMs() }}" @if ($checkAt !== null) data-check-at="{{ $checkAt }}" @endif
         x-data="boardLobby(@js(['userId' => $user?->id, 'lookingKey' => PongInvites::LOOKING, 'looking' => $user?->looking_to_play === PongInvites::LOOKING, 'fallback' => $this::FALLBACK_SECONDS, 'events' => ['started' => '.pong.match-started', 'invite' => '.pong.invite'], 'newTab' => true]))">
    {{-- The card's head carries its state: Looking to play is the switch others see (P9: in the first screen on a phone, too). --}}
    <div class="flex flex-col gap-2">
        <span class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            {{-- Below md the segmented control above names the card. --}}
            <h2 id="pong-live-h" class="m-0 scroll-mt-24 font-display text-xl font-bold max-md:sr-only">{{ __('Live 1v1') }}</h2>
            @if ($user)
                <x-lobby.looking-toggle :on="$user->looking_to_play === PongInvites::LOOKING" />
            @endif
        </span>
        <p class="m-0 text-[13px] text-ink-2">{{ __('Live to 21 against real players, for Elo.') }}@if ($rating !== null) <span class="whitespace-nowrap" data-test="pong-rating">{{ __('Your Elo') }} <b class="text-ink">{{ $rating['rating'] }}</b>@if ($rating['provisional']) ({{ __('provisional') }})@endif</span>@endif</p>
    </div>

    @if ($error)
        <p role="alert" class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="pong-lobby-error">{{ $error }}</p>
    @endif

    @if ($active !== null)
        <div class="flex flex-col gap-3 rounded-lg bg-well p-3 shadow-ring-btc @xl:flex-row @xl:items-center @xl:px-4" data-test="pong-active-match">
            <b class="grow text-[15px]">{{ __('Your match against :name is on.', ['name' => ($active->left_id === $user?->id ? $active->right : $active->left)?->displayName() ?? '?']) }}</b>
            <x-button :href="route('pong.match', $active)" target="_blank" icon="play" data-test="pong-open-match">{{ __('Open match') }}</x-button>
        </div>
    @endif

    @foreach ($this->incoming as $invite)
        <div wire:key="pong-invite-{{ $invite->id }}" class="flex flex-col gap-3 rounded-lg bg-well p-3 shadow-ring-btc @xl:flex-row @xl:items-center @xl:px-4" data-test="pong-incoming-invite">
            <span class="flex min-w-0 grow items-center gap-3">
                <x-player-link :user="$invite->inviter" class="flex min-h-11 min-w-11 shrink-0 items-center"><x-avatar :user="$invite->inviter" :size="40" class="rounded-md" /></x-player-link>
                <span class="flex min-w-0 flex-col gap-0.5">
                    <b class="truncate text-[15px]">{{ __(':name invites you', ['name' => $invite->inviter->displayName()]) }}</b>
                    <span class="text-xs text-ink-2">{{ __('Live to :points, sides drawn at random', ['points' => (int) config('esports.pong.points_to_win', 21)]) }}</span>
                </span>
            </span>
            <span class="grid grid-cols-2 gap-2 @xl:flex">
                <x-button variant="quiet" wire:click="declineInvite({{ $invite->id }})" data-test="pong-decline-invite">{{ __('Decline') }}</x-button>
                {{-- A form, so the match opens in a new tab as the game against a bot does. --}}
                <form method="post" action="{{ route('pong.accept', $invite) }}" target="_blank" class="contents" x-on:submit="setTimeout(() => $wire.$refresh(), 800)">
                    @csrf
                    <x-button type="submit" icon="play" data-test="pong-accept-invite">{{ __('Accept') }}</x-button>
                </form>
            </span>
        </div>
    @endforeach

    <x-lobby.online-now :user="$user" :looking-key="PongInvites::LOOKING" :looking-tag="__('looking: :game', ['game' => 'Proof of Pong'])" :can-invite="$active === null" :show-elo="false" list-class="max-h-40" :toggle="false">
        {{-- Nobody else here: what to do next, following the switch in the card's head. --}}
        <x-slot:empty>
            <span x-show="looking" @if (! $looking) style="display: none" @endif>{{ __('Nobody else is online. Whoever comes can invite you; until then, play the bot.') }}</span>
            <span x-show="! looking" @if ($looking) style="display: none" @endif>{{ __('Nobody else is online. Turn on "Looking to play" so others can invite you, or play the bot.') }}</span>
        </x-slot:empty>
    </x-lobby.online-now>
</section>
