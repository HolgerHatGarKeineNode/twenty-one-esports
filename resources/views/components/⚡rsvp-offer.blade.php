<?php

use App\Models\Tournament;
use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Comments\CommentRefused;
use App\Support\Comments\Rsvps;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * "Tell Nostr calendars you're going" after a sign-up, "…you're not going"
 * after a withdrawal of a player who said so through the league (P48, NIP-52
 * RSVP, kind 31925; {@see Rsvps}). The RSVP is shown as the event it is and
 * signed only on the click; it is not the sign-up and changes nothing in it.
 */
new class extends Component {
    #[Locked]
    public int $tournamentId;

    public function mount(int $tournament): void
    {
        $this->tournamentId = $tournament;
    }

    #[Computed]
    public function tournament(): ?Tournament
    {
        return Tournament::query()->with('event')->find($this->tournamentId);
    }

    #[Computed]
    public function offer(): ?string
    {
        $user = Auth::user();

        return $user instanceof User && $this->tournament !== null ? app(Rsvps::class)->offer($user, $this->tournament) : null;
    }

    /** @return array{template?: array<string, mixed>, error?: string} */
    #[Renderless]
    public function prepareRsvp(Rsvps $rsvps): array
    {
        return $this->answer(fn () => ['template' => $rsvps->template($this->me(), $this->found(), (string) $this->offer)]);
    }

    /** @return true|array{error: string} */
    #[Renderless]
    public function submitRsvp(string $signed, Rsvps $rsvps): bool|array
    {
        return $this->answer(fn () => $rsvps->submit($this->me(), $this->found(), (string) $this->offer, json_decode($signed, true)) !== null);
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

    private function found(): Tournament
    {
        return $this->tournament ?? abort(404);
    }

    private function me(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php($offer = $this->offer)
<div @class(['min-w-0', 'hidden' => $offer === null])>
    @if ($offer)
        <div class="flex min-w-0 flex-col gap-2" data-test="rsvp-offer" data-status="{{ $offer }}"
             x-data="rsvpOffer(@js(['me' => auth()->user()?->pubkey, 'writeRelays' => ProfileBadges::browserRelays(), 'labels' => [
                 ...SignerMessages::labels(),
                 'notPosted' => __('None of your relays took it. Try again later.'),
                 'changed' => __('It changed since you opened it. Check it again, then sign.'),
             ]]))">
            <span class="text-xs text-ink-2">{{ $offer === Rsvps::ACCEPTED
                ? __('Nostr calendar apps can show that you go. That is not your sign-up: that is done above.')
                : __('You told Nostr calendars you would go. Tell them you won’t, now that you pulled out.') }}</span>
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <button type="button" x-on:click="open()" x-show="step !== 'done'" x-bind:disabled="step !== 'idle'" data-test="rsvp-open"
                        class="btn-w inline-flex h-11 min-w-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] font-bold text-ink disabled:cursor-default disabled:opacity-80">
                    <x-icon name="calendar" :size="16" class="shrink-0" />
                    <span class="truncate" x-text="step === 'opening' ? @js(__('Preparing…')) : @js($offer === Rsvps::ACCEPTED ? __('Tell Nostr calendars you’re going') : __('Tell Nostr calendars you’re not going'))">{{ $offer === Rsvps::ACCEPTED ? __('Tell Nostr calendars you’re going') : __('Tell Nostr calendars you’re not going') }}</span>
                </button>
                <span x-show="step === 'done'" x-cloak role="status" class="inline-flex h-11 items-center gap-2 rounded-md bg-win-tint px-4 text-[13px] font-bold text-win" data-test="rsvp-done">
                    <x-icon name="check" :size="16" />{{ $offer === Rsvps::ACCEPTED ? __('Nostr calendars show you as going') : __('Nostr calendars show you as not going') }}
                </span>
            </div>
            <div x-show="step === 'preview' || step === 'posting'" x-cloak role="group" aria-label="{{ __('Preview of your RSVP') }}" data-test="rsvp-preview"
                 class="flex min-w-0 flex-col gap-2.5 rounded-md bg-well px-3.5 py-3">
                <p class="m-0 text-[13px] leading-normal">{{ $offer === Rsvps::ACCEPTED
                    ? __('One RSVP (kind 31925) “accepted”, signed with your key, to the tournament’s calendar event. Anyone can see it.')
                    : __('One RSVP (kind 31925) “declined”, signed with your key, to the tournament’s calendar event. It replaces your “accepted”.') }}</p>
                <span class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <x-button variant="quiet" x-on:click="cancel()" x-bind:disabled="step === 'posting'" data-test="rsvp-cancel">{{ __('Cancel') }}</x-button>
                    <x-button icon="send" x-on:click="send()" x-bind:disabled="step === 'posting'" class="whitespace-nowrap" data-test="rsvp-sign">
                        <span x-text="step === 'posting' ? @js(__('Posting…')) : @js(__('Sign and send'))">{{ __('Sign and send') }}</span>
                    </x-button>
                </span>
            </div>
            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="rsvp-error"></p>
            <p x-show="warning" x-text="warning" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="rsvp-warning"></p>
        </div>
    @endif
</div>
