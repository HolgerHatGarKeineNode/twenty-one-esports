<?php

use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\SeasonChain\OpponentListRefused;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\Opponents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The player's opponent list (P7e, NIP "Opponent list"): who is on it,
 * who lists them back (rated play needs both), and who lists them without
 * being on their list yet. Removing and adding back are signed new versions
 * of the list, as on the player page ({@see Opponents}). No artboard draws
 * this tab; it uses the settings cards and the key/value rows of the
 * player page.
 */
new #[Title('Opponents')] class extends Component {
    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareRemove(string $pubkey, Opponents $opponents): ?array
    {
        return $this->refusable(fn () => $opponents->prepareRemove($this->user(), $pubkey));
    }

    public function remove(string $pubkey, string $signed, Opponents $opponents): void
    {
        $this->refusable(fn () => $opponents->remove($this->user(), $pubkey, array_values((array) json_decode($signed, true))));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareAdd(string $pubkey, Opponents $opponents): ?array
    {
        return $this->refusable(fn () => $opponents->prepareAdd($this->user(), $this->player($pubkey)));
    }

    public function add(string $pubkey, string $signed, Opponents $opponents): void
    {
        $this->refusable(fn () => $opponents->add($this->user(), $this->player($pubkey), array_values((array) json_decode($signed, true))));
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
            $this->addError('opponents', $refused->getMessage());
        } catch (RejectedEvent) {
            $this->addError('opponents', __('The confirmation did not match. Please try again.'));
        }

        return null;
    }

    private function player(string $pubkey): User
    {
        return User::query()->where('pubkey', $pubkey)->firstOrFail();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $user = auth()->user();
    $opponents = app(Opponents::class);
    $open = OpponentLists::forLeague() !== null;
    $entries = $opponents->entries($user);
    $mutual = array_flip($opponents->mutual($user));
    $waiting = array_values(array_diff($opponents->listedBy($user), $entries));
    /** @var Collection<string, User> $players */
    $players = User::query()->whereIn('pubkey', [...$entries, ...$waiting])->get()->keyBy('pubkey');
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="opponent-settings"
     x-data="nostrAction({ pubkey: @js($user->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[32px]">{{ __('Settings') }}</h1>
        <span class="grow"></span>
        @include('pages.settings.partials.nav', ['current' => 'opponents'])
    </div>

    @error('opponents')<p class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
    <p x-show="error" x-text="error" x-cloak class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert"></p>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,400px)]">
        <section aria-labelledby="ol-h" class="flex flex-col gap-3 self-start rounded-lg bg-card px-4 py-5 lg:px-6" data-test="opponent-list">
            <span class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="ol-h" class="m-0 text-[15px] font-bold">{{ __('Your opponent list') }}</h2>
                <span class="text-xs text-ink-2" data-test="opponent-counts">{{ __(':entries listed, :mutual list you back', ['entries' => count($entries), 'mutual' => count($mutual)]) }}</span>
            </span>
            @if (! $open)
                <p class="m-0 text-[13px] text-ink-2">{{ __('Opponent lists open once the league is set up.') }}</p>
            @elseif ($entries === [])
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Nobody yet. Open a player\'s page and choose "Add as opponent". Rated games need both of you to list each other.') }}</p>
            @else
                <ul class="m-0 flex list-none flex-col p-0">
                    @foreach ($entries as $pubkey)
                        @php($player = $players->get($pubkey))
                        <li wire:key="entry-{{ $pubkey }}" class="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 border-b border-hairline py-1 text-[13px] last:border-0" data-test="opponent-entry">
                            @if ($player)
                                <x-player-link :user="$player" class="flex min-h-11 min-w-0 grow items-center gap-3 text-ink hover:text-ink">
                                    <x-avatar :user="$player" :size="24" class="rounded-sm" /><b class="min-w-0 truncate">{{ $player->displayName() }}</b>
                                </x-player-link>
                            @else
                                <span class="flex min-h-11 min-w-0 grow items-center text-ink-2">{{ 'npub1…'.\Illuminate\Support\Str::substr(\App\Support\Nostr\NostrKeys::hexToNpub($pubkey), -4) }} · {{ __('no account here') }}</span>
                            @endif
                            @if (isset($mutual[$pubkey]))
                                <span class="flex shrink-0 items-center gap-1.5 text-xs text-win" data-test="opponent-entry-mutual"><x-icon name="check" :size="14" />{{ __('lists you back') }}</span>
                            @else
                                <span class="shrink-0 text-xs text-ink-3">{{ __('not listing you yet') }}</span>
                            @endif
                            <button type="button" x-on:click="run('prepareRemove', 'remove', @js($pubkey))" x-bind:disabled="busy" data-test="opponent-entry-remove"
                                    class="inline-flex h-11 shrink-0 cursor-pointer items-center rounded-md px-2 text-[13px] text-ink-2 hover:text-ink disabled:cursor-wait disabled:opacity-70">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="flex flex-col gap-5">
            <section aria-labelledby="lw-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="opponent-waiting">
                <h2 id="lw-h" class="m-0 text-[15px] font-bold">{{ __('They list you') }}</h2>
                @if ($waiting === [])
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Nobody is waiting for you to add them back.') }}</p>
                @else
                    <ul class="m-0 flex list-none flex-col p-0">
                        @foreach ($waiting as $pubkey)
                            @php($player = $players->get($pubkey))
                            @continue($player === null)
                            <li wire:key="waiting-{{ $pubkey }}" class="flex min-h-12 items-center gap-3 border-b border-hairline text-[13px] last:border-0" data-test="opponent-waiting-entry">
                                <x-player-link :user="$player" class="flex min-h-11 min-w-0 grow items-center gap-3 text-ink hover:text-ink">
                                    <x-avatar :user="$player" :size="24" class="rounded-sm" /><b class="min-w-0 truncate">{{ $player->displayName() }}</b>
                                </x-player-link>
                                <button type="button" x-on:click="run('prepareAdd', 'add', @js($pubkey))" x-bind:disabled="busy" data-test="opponent-add-back"
                                        class="btn-w inline-flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink disabled:cursor-wait disabled:opacity-70">
                                    <x-icon name="shield-check" :size="16" class="text-proof" />{{ __('Add back') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="lp-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 text-[13px] leading-normal text-ink-2 lg:px-6">
                <h2 id="lp-h" class="m-0 text-[15px] font-bold text-ink">{{ __('How it works') }}</h2>
                <p class="m-0">{{ __('A rated game needs both players to list each other, and both to be Trusted. Casual games need neither.') }}</p>
                <p class="m-0">{{ __('Your list is public on Nostr: anyone can see whom you listed. Every change is signed by you and published through the league.') }}</p>
            </section>
        </div>
    </div>
</div>
