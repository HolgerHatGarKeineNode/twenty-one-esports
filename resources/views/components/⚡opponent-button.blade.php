<?php

use App\Models\User;
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
 */
new class extends Component {
    #[Locked]
    public int $playerId;

    public function mount(User $player): void
    {
        $this->playerId = $player->id;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareAdd(Opponents $opponents): ?array
    {
        return $this->refusable(fn () => $opponents->prepareAdd($this->me(), $this->player()));
    }

    public function add(string $signed, Opponents $opponents): void
    {
        $this->refusable(fn () => $opponents->add($this->me(), $this->player(), array_values((array) json_decode($signed, true))));
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
@endphp

<div @class(['flex min-w-0 flex-col gap-1.5 border-b border-hairline py-3 lg:pt-0' => $show, 'hidden' => ! $show])>
    @if ($show)
        <div class="flex min-w-0 flex-col gap-1.5" data-test="opponent" data-state="{{ $mine && $theirs ? 'mutual' : ($mine ? 'listed' : ($theirs ? 'lists-you' : 'none')) }}"
             x-data="nostrAction({ pubkey: @js($viewer->pubkey), messages: @js(SignerMessages::labels()) })">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                @if ($mine && $theirs)
                    <span role="status" class="inline-flex h-11 min-w-0 items-center gap-2 rounded-md bg-win-tint px-3.5 text-[13px] text-win shadow-ring-win" data-test="opponent-mutual">
                        <x-icon name="check" :size="16" class="shrink-0" /><span class="truncate">{{ __('You list each other') }}</span>
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
                    <button type="button" x-on:click="run('prepareAdd', 'add')" x-bind:disabled="busy" data-test="opponent-add"
                            class="btn-w inline-flex h-11 min-w-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] font-bold whitespace-nowrap text-ink disabled:cursor-wait disabled:opacity-70">
                        <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" /><span class="truncate">{{ $theirs ? __('Add :name back', ['name' => $name]) : __('Add as opponent') }}</span>
                    </button>
                @endif
            </div>
            <span class="text-xs leading-normal text-ink-3" data-test="opponent-note">
                @if ($mine && $theirs)
                    {{ __('Rated games between you are possible. Opponent lists are public on Nostr.') }}
                @elseif ($mine)
                    {{ __('Rated games need :name to add you back. Opponent lists are public on Nostr.', ['name' => $name]) }}
                @elseif ($theirs)
                    {{ __(':name lists you as an opponent. Add them back for rated games. Opponent lists are public on Nostr.', ['name' => $name]) }}
                @else
                    {{ __('Rated games need you to list each other. Opponent lists are public on Nostr.') }}
                @endif
            </span>
            @error('opponent')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
            <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>
        </div>
    @endif
</div>
