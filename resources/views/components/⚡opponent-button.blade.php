<?php

use App\Models\User;
use App\Support\FairPlay\AcceptWarnings;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\SeasonChain\OpponentListRefused;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\Opponents;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * "Add as opponent" on a player page (P7e, NIP "Opponent list"): the
 * signed-in player adds this player to their own opponent list or takes
 * them off it, each a new version of their list signed in the browser
 * (nostrAction, resources/js/nostrSign.js) and submitted through the league
 * ({@see Opponents}). When both list each other the chip says so, the
 * design's "You're connected" (Player.dc.html): that is what rated play
 * needs. Nothing shows for guests, on the own page, or before the league
 * key is set.
 *
 * P57: a player who lists you is a request ("Accept :name's request", the
 * same signed add). `inline` drops the sidebar rule and names the player on
 * the button, for the notices where rated play was refused because two
 * players do not list each other (challenge, answer, rated queue). Every
 * change dispatches `opponent-list-changed`, so such a notice re-renders.
 *
 * Plan "Season vs. Casual", P5: `afterGame` (x-opponents.after-game, the end
 * of a game) says what a mutual listing means there: rated next time, only
 * while `ratedNext` (the viewer meets every other step of the path; design-lead
 * review, finding 2), and leaves the note under the button to the card's own
 * line. `note` false drops that note too (a list of buttons, the opponents
 * page's #trust section).
 *
 * D17 (plan "Profile standard and impersonation"): a player below the
 * profile standard or under an impersonation hold carries the same warning
 * as a request card on /settings/opponents ({@see AcceptWarnings}); the add
 * or accept then opens an explicit confirm, and nothing is signed before it.
 */
new class extends Component {
    #[Locked]
    public int $playerId;

    #[Locked]
    public bool $inline = false;

    #[Locked]
    public bool $afterGame = false;

    #[Locked]
    public bool $ratedNext = true;

    #[Locked]
    public bool $note = true;

    public function mount(User $player, bool $inline = false, bool $afterGame = false, bool $ratedNext = true, bool $note = true): void
    {
        $this->playerId = $player->id;
        $this->inline = $inline;
        $this->afterGame = $afterGame;
        $this->ratedNext = $ratedNext;
        $this->note = $note;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareAdd(Opponents $opponents): ?array
    {
        $player = $this->player();

        // D17: a player with a warning is added only through the confirm step, never in one click.
        if (AcceptWarnings::for([$player]) !== []) {
            $this->addError('opponent', __('Read the note above first, then confirm.'));

            return null;
        }

        return $this->refusable(fn () => $opponents->prepareAdd($this->me(), $player));
    }

    /**
     * The add after the member confirmed the warning ({@see AcceptWarnings});
     * signed and submitted like any other add.
     *
     * @return list<array<string, mixed>>|null
     */
    public function prepareConfirmedAdd(Opponents $opponents): ?array
    {
        return $this->refusable(fn () => $opponents->prepareAdd($this->me(), $this->player()));
    }

    public function add(string $signed, Opponents $opponents): void
    {
        $this->refusable(fn () => $opponents->add($this->me(), $this->player(), array_values((array) json_decode($signed, true))));
        $this->dispatch('opponent-list-changed');
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareRemove(Opponents $opponents): ?array
    {
        return $this->refusable(fn () => $opponents->prepareRemove($this->me(), $this->player()->pubkey));
    }

    public function remove(string $signed, Opponents $opponents): void
    {
        $this->refusable(fn () => $opponents->remove($this->me(), $this->player()->pubkey, array_values((array) json_decode($signed, true))));
        $this->dispatch('opponent-list-changed');
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
        } catch (OpponentListRefused $refused) {
            $this->addError('opponent', $refused->getMessage());
        } catch (RejectedEvent) {
            $this->addError('opponent', __('The confirmation did not match. Please try again.'));
        }

        return null;
    }

    private function player(): User
    {
        return User::query()->findOrFail($this->playerId);
    }

    private function me(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $viewer = auth()->user();
    $player = $this->player();
    $show = $viewer instanceof User && $viewer->id !== $player->id && OpponentLists::forLeague() !== null;
    $opponents = app(Opponents::class);
    $mine = $show && $opponents->lists($viewer, $player);
    $theirs = $show && $opponents->lists($player, $viewer);
    $name = $player->displayName();
    // D17: why rated play with them stays closed for now, before the add; no query while both switches are off.
    $warning = $show && ! $mine ? (AcceptWarnings::for([$player])[$player->pubkey] ?? null) : null;
@endphp

<div @class(['flex min-w-0 flex-col gap-1.5', 'border-b border-hairline py-3 lg:pt-0' => $show && ! $inline, 'hidden' => ! $show])>
    @if ($show)
        <div class="flex min-w-0 flex-col gap-1.5" data-test="opponent" data-state="{{ $mine && $theirs ? 'mutual' : ($mine ? 'listed' : ($theirs ? 'lists-you' : 'none')) }}"
             x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(SignerMessages::labels()) })">
            <div class="flex min-w-0 flex-col gap-1.5" x-data="{ confirming: false }">
                @if ($warning)
                    <x-opponents.accept-warning :warning="$warning" :name="$name" :accept="$theirs" />

                    {{-- Deferred: the add only opens the confirm; nothing is signed before "Accept anyway" / "Add anyway" (R18, focus on the safe choice). --}}
                    <div x-show="confirming" x-cloak class="flex flex-col gap-2 rounded-md border border-line p-3" role="group" aria-label="{{ $theirs ? __('Accept :name\'s request', ['name' => $name]) : __('Add :name as opponent', ['name' => $name]) }}" data-test="opponent-confirm">
                        <p class="m-0 text-[13px] leading-normal text-ink [overflow-wrap:anywhere]">{{ $theirs ? __('Accept :name anyway?', ['name' => $name]) : __('Add :name anyway?', ['name' => $name]) }}</p>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" x-on:click="run('prepareConfirmedAdd', 'add')" x-bind:disabled="busy" data-test="opponent-confirm-add"
                                    class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 whitespace-nowrap rounded-md border border-line bg-well px-4 text-[13px] font-bold text-ink disabled:cursor-wait disabled:opacity-70">
                                <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" />{{ $theirs ? __('Accept anyway') : __('Add anyway') }}
                            </button>
                            <button type="button" x-ref="cancelAdd" x-on:click="confirming = false; $nextTick(() => $refs.addButton.focus())" x-bind:disabled="busy" data-test="opponent-confirm-cancel"
                                    class="inline-flex h-11 cursor-pointer items-center whitespace-nowrap rounded-md px-3 text-[13px] text-ink-2 hover:text-ink disabled:cursor-wait disabled:opacity-70">{{ __('Cancel') }}</button>
                        </div>
                    </div>
                @endif
                <div class="flex min-w-0 flex-wrap items-center gap-2" @if ($warning) x-show="! confirming" @endif>
                    @if ($mine && $theirs)
                        <span role="status" @class(['inline-flex min-w-0 items-center gap-2 rounded-md bg-win-tint px-3.5 text-[13px] text-win shadow-ring-win', 'h-11' => ! $afterGame, 'min-h-11 py-2' => $afterGame]) data-test="opponent-mutual">
                            <x-icon name="check" :size="16" class="shrink-0" /><span @class(['truncate' => ! $afterGame, 'min-w-0 [overflow-wrap:anywhere]' => $afterGame])>{{ $afterGame && $ratedNext ? __('Mutual opponents, rated next time') : __('You list each other') }}</span>
                        </span>
                    @elseif ($mine)
                        <span role="status" class="inline-flex h-11 min-w-0 items-center gap-2 rounded-md bg-well px-3.5 text-[13px] text-ink-2 shadow-ring-hairline" data-test="opponent-listed">
                            <x-icon name="check" :size="16" class="shrink-0" /><span class="truncate">{{ __('On your opponent list') }}</span>
                        </span>
                    @endif

                    @if ($mine)
                        <button type="button" x-on:click="run('prepareRemove', 'remove')" x-bind:disabled="busy" data-test="opponent-remove"
                                class="inline-flex h-11 shrink-0 cursor-pointer items-center justify-center rounded-md px-3 text-[13px] text-ink-2 hover:text-ink disabled:cursor-wait disabled:opacity-70">{{ __('Remove') }}</button>
                    @else
                        <button type="button" @if ($warning) x-ref="addButton" x-on:click="confirming = true; $nextTick(() => $refs.cancelAdd.focus())" x-bind:aria-expanded="confirming" @else x-on:click="run('prepareAdd', 'add')" @endif x-bind:disabled="busy" data-test="opponent-add"
                                @class(['btn-w inline-flex min-w-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] font-bold text-ink disabled:cursor-wait disabled:opacity-70', 'h-11 whitespace-nowrap' => ! $afterGame, 'min-h-11 py-2 text-left' => $afterGame])>
                            <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" /><span @class(['truncate' => ! $afterGame, 'min-w-0 [overflow-wrap:anywhere]' => $afterGame])>{{ $theirs ? __('Accept :name\'s request', ['name' => $name]) : ($inline ? __('Add :name as opponent', ['name' => $name]) : __('Add as opponent')) }}</span>
                        </button>
                    @endif
                </div>
                @if ($note && ! $afterGame)
                <span @class(["text-xs leading-normal text-ink-3", "max-w-[68ch]" => $inline]) data-test="opponent-note">
                    @if ($mine && $theirs)
                        {{ __('Rated games between you are possible. Opponent lists are public on Nostr.') }}
                    @elseif ($mine)
                        {{ __('Rated games need :name to add you back. Opponent lists are public on Nostr.', ['name' => $name]) }}
                    @elseif ($theirs)
                        {{ __(':name added you as an opponent. Accept for rated games between you. Opponent lists are public on Nostr.', ['name' => $name]) }}
                    @else
                        {{ __('Rated games need you to list each other. Opponent lists are public on Nostr.') }}
                    @endif
                </span>
                @endif
            </div>
            @error('opponent')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>
        </div>
    @endif
</div>
