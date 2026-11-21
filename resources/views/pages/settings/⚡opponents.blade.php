<?php

use App\Models\User;
use App\Support\FairPlay\AcceptWarnings;
use App\Support\FairPlay\Impersonation;
use App\Support\FairPlay\ProfileStandard;
use App\Support\Nostr\RejectedEvent;
use App\Support\SeasonChain\OpponentListRefused;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\OpponentRequests;
use App\Support\SeasonChain\Opponents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The player's opponent list (P7e, NIP "Opponent list"): who is on it,
 * who lists them back (rated play needs both), and the requests of players
 * who list them without being on their list yet (P57, {@see OpponentRequests}).
 * A request card shows what the league knows about the requester (joined,
 * games here, trust, clan, who of your opponents lists them) so a fake or a
 * bot can be told apart. Accept is the signed add of the player page
 * ({@see Opponents}); decline hides the request and stops notifications,
 * and "Declined" undoes it. A requester below the profile standard or under
 * an impersonation hold carries a warning, and accepting them then takes an
 * explicit confirm step (P4, {@see AcceptWarnings}). A short explanation
 * leads the page. No artboard draws this tab; it uses the settings cards and
 * the key/value rows of the player page.
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
        $player = $this->player($pubkey);

        // P4: a requester with a warning is accepted only through the confirm step, never in one click.
        if (AcceptWarnings::for([$player]) !== []) {
            $this->addError('opponents', __('Read the note on this request first, then confirm the accept.'));

            return null;
        }

        return $this->refusable(fn () => $opponents->prepareAdd($this->user(), $player));
    }

    /**
     * The accept after the member confirmed the warning on the request card
     * ({@see AcceptWarnings}); signed and submitted like any other accept.
     *
     * @return list<array<string, mixed>>|null
     */
    public function prepareConfirmedAdd(string $pubkey, Opponents $opponents): ?array
    {
        return $this->refusable(fn () => $opponents->prepareAdd($this->user(), $this->player($pubkey)));
    }

    public function add(string $pubkey, string $signed, Opponents $opponents): void
    {
        $this->refusable(fn () => $opponents->add($this->user(), $this->player($pubkey), array_values((array) json_decode($signed, true))));
    }

    public function decline(string $pubkey, OpponentRequests $requests): void
    {
        $this->refusable(fn () => $requests->decline($this->user(), $pubkey));
    }

    public function restore(string $pubkey, OpponentRequests $requests): void
    {
        $requests->restore($this->user(), $pubkey);
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
    $requests = app(OpponentRequests::class);
    $open = OpponentLists::forLeague() !== null;
    $entries = $opponents->entries($user);
    $mutual = array_flip($opponents->mutual($user));
    ['open' => $asking, 'declined' => $declined] = $requests->of($user);
    /** @var Collection<string, User> $players */
    $players = User::query()->with('clanMember.clan')->whereIn('pubkey', [...$entries, ...$asking, ...$declined])->get()->keyBy('pubkey');
    $asking = array_values(array_filter($asking, fn (string $pubkey): bool => $players->has($pubkey)));
    $declined = array_values(array_filter($declined, fn (string $pubkey): bool => $players->has($pubkey)));
    // One batch for every card (P57 review: no queries per request).
    $requesters = $players->filter(fn (User $player): bool => in_array($player->pubkey, $asking, true))->values();
    $signals = $requests->signals($user, $requesters);
    // P4: why rated play with a requester stays closed for now (missing hard marks, an open hold), batched like the facts.
    $warnings = AcceptWarnings::for($requesters);
    $row = 'grid min-h-7 grid-cols-[112px_minmax(0,1fr)] items-baseline gap-x-2 py-0.5';
    // The profile standard of rated play: only the steps still missing, empty while it is off, met or the player exempt.
    $standardSteps = ProfileStandard::stepsFor($user);
    // An impersonation hold (P2): the player reads only that the account is being checked, never why; it outranks the steps.
    $held = Impersonation::held([$user->pubkey]) !== [];
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="opponent-settings"
     x-data="nostrAction({ pubkey: @js($user->pubkey), messages: @js(\App\Support\Nostr\SignerMessages::labels()) })">
    <x-settings.header current="opponents" />

    @error('opponents')<p class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
    <p x-show="error" x-text="error" x-cloak class="m-0 rounded-lg bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert"></p>

    {{-- P57: what the lists are for and what Accept and Decline do, in lines under 68 characters. --}}
    <section aria-labelledby="oe-h" class="flex flex-col gap-3 text-[13px] leading-normal text-ink-2" data-test="opponent-explainer">
        <h2 id="oe-h" class="m-0 text-[15px] font-bold text-ink">{{ __('What opponent lists are for') }}</h2>
        <p class="m-0 max-w-[68ch]">{{ __('Rated games only happen between two players who added each other as opponents. That keeps fake and bot accounts from farming rating or mining blocks off you. Casual games need none of this.') }}</p>
        <dl class="m-0 flex max-w-[68ch] flex-col gap-2">
            <div class="flex flex-col gap-x-3 sm:flex-row"><dt class="shrink-0 font-bold text-ink sm:w-20">{{ __('Accept') }}</dt><dd class="m-0">{{ __('adds them to your list. Rated games between you become possible.') }}</dd></div>
            <div class="flex flex-col gap-x-3 sm:flex-row"><dt class="shrink-0 font-bold text-ink sm:w-20">{{ __('Decline') }}</dt><dd class="m-0">{{ __('hides the request and stops notifications from them. You can undo it.') }}</dd></div>
        </dl>
        <p class="m-0 max-w-[68ch] text-ink-3">{{ __('Your list is public on Nostr: anyone can see whom you listed. Every change is signed by you and published through the league.') }}</p>
    </section>

    @if ($held || $standardSteps !== [])
        <section id="rated-standard" aria-labelledby="rs-h" class="flex scroll-mt-20 flex-col gap-3 rounded-lg bg-card px-4 py-5 text-[13px] leading-normal lg:px-6" data-test="rated-standard">
            <h2 id="rs-h" class="m-0 text-[15px] font-bold">{{ __('Your profile for rated play') }}</h2>
            @if ($held)
                <p class="m-0 max-w-[68ch] text-ink" data-test="rated-held">{{ __('Your account is being checked. Rated play opens again once the check is done.') }}</p>
            @endif
            @if ($standardSteps !== [])
                <p class="m-0 max-w-[68ch] text-ink-2">{{ __('Before you can play rated:') }}</p>
                <ol class="m-0 flex max-w-[68ch] list-decimal flex-col gap-2 pl-5">
                    @foreach ($standardSteps as $step)
                        <li data-test="rated-standard-{{ $step['key'] }}">
                            <span>{{ $step['text'] }}</span>
                            @if ($step['url'] !== null)
                                <a href="{{ $step['url'] }}" @navigate($step['url']) class="flex min-h-11 w-fit items-center font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="rated-standard-link-{{ $step['key'] }}">{{ __('Set up your Nostr address') }}</a>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
            <p class="m-0 max-w-[68ch] text-ink-3">{{ __('Casual play stays open.') }}</p>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <section id="requests" aria-labelledby="or-h" class="flex scroll-mt-20 flex-col gap-3 self-start rounded-lg bg-card px-4 py-5 lg:px-6" data-test="opponent-requests">
            <span class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="or-h" class="m-0 text-[15px] font-bold">{{ __('Opponent requests') }}</h2>
                <span class="text-xs text-ink-2" data-test="opponent-requests-count">{{ trans_choice(':count open|:count open', count($asking)) }}</span>
            </span>
            @if ($asking === [])
                <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('No open requests. When someone adds you as an opponent, it shows up here and you get a notification.') }}</p>
            @else
                <ul class="m-0 flex list-none flex-col gap-3 p-0">
                    @foreach ($asking as $pubkey)
                        @php
                            $player = $players->get($pubkey);
                            $name = $player->displayName();
                            $facts = $signals[$pubkey];
                            $warning = $warnings[$pubkey] ?? null;
                        @endphp
                        <li wire:key="request-{{ $pubkey }}" class="flex flex-col gap-3 rounded-md bg-well p-4 shadow-ring-hairline" data-test="opponent-request" data-pubkey="{{ $pubkey }}"
                            @if ($warning) x-data="{ confirming: false }" @endif>
                            <x-player-link :user="$player" class="flex min-h-11 min-w-0 items-center gap-3 self-start text-ink hover:text-ink">
                                <x-avatar :user="$player" :size="40" class="shrink-0 rounded-md" /><b class="min-w-0 text-[15px] [overflow-wrap:anywhere]">{{ $name }}</b>
                            </x-player-link>

                            <dl class="m-0 flex flex-col text-[13px]" aria-label="{{ __('What the league knows about :name', ['name' => $name]) }}" data-test="opponent-request-facts">
                                @if ($facts['joined'])
                                    <div class="{{ $row }}" data-test="fact-joined"><dt class="text-ink-3">{{ __('Joined') }}</dt>
                                        <dd class="m-0">{{ \App\Support\Formats::day($facts['joined']) }} <span class="text-ink-3">({{ $facts['joined']->locale(app()->getLocale())->diffForHumans() }})</span></dd></div>
                                @endif
                                <div class="{{ $row }}" data-test="fact-games"><dt class="text-ink-3">{{ __('Games here') }}</dt>
                                    <dd @class(['m-0', 'text-ink-3' => $facts['games'] === 0])>{{ $facts['games'] === 0 ? __('none finished yet') : trans_choice(':count finished|:count finished', $facts['games']) }}</dd></div>
                                @if ($facts['trusted'] !== null)
                                    <div class="{{ $row }}" data-test="fact-trust"><dt class="text-ink-3">{{ __('Trust') }}</dt>
                                        <dd class="m-0">@if ($facts['trusted'])<span class="inline-flex items-center gap-1.5 text-win"><x-icon name="check" :size="14" class="shrink-0" />{{ __('Trusted') }}</span>@else<span class="text-ink-3">{{ __('not Trusted yet') }}</span>@endif</dd></div>
                                @endif
                                @if ($facts['member'])
                                    <div class="{{ $row }}" data-test="fact-member"><dt class="text-ink-3">{{ __('Membership') }}</dt><dd class="m-0"><x-member-badge /></dd></div>
                                @endif
                                <div class="{{ $row }}" data-test="fact-clan"><dt class="text-ink-3">{{ __('Clan') }}</dt>
                                    <dd @class(['m-0 [overflow-wrap:anywhere]', 'text-ink-3' => $facts['clan'] === null])>{{ $facts['clan'] === null ? __('no clan') : ($facts['same_clan'] ? __(':clan, your clan', ['clan' => $facts['clan']]) : $facts['clan']) }}</dd></div>
                                @if ($facts['meetup'])
                                    <div class="{{ $row }}" data-test="fact-meetup"><dt class="text-ink-3">{{ __('Meetup') }}</dt><dd class="m-0 [overflow-wrap:anywhere]">{{ __(':meetup, same as your clan', ['meetup' => $facts['meetup']]) }}</dd></div>
                                @endif
                                @if ($entries !== [])
                                    <div class="{{ $row }}" data-test="fact-vouched"><dt class="text-ink-3">{{ __('Your opponents') }}</dt>
                                        <dd @class(['m-0', 'text-ink-3' => $facts['vouched'] === 0])>{{ $facts['vouched'] === 0 ? __('nobody on your list lists them') : trans_choice(':count on your list lists them too|:count on your list list them too', $facts['vouched']) }}</dd></div>
                                @endif
                            </dl>

                            @if ($facts['new'])
                                <p class="m-0 flex max-w-[68ch] items-start gap-2 rounded-md bg-btc-tint px-3 py-2 text-[13px] leading-normal text-btc-hi" data-test="opponent-request-new">
                                    <x-icon name="alert" :size="16" class="mt-0.5 shrink-0" /><span>{{ __('New account with no games here yet. If you do not know this player, decline.') }}</span>
                                </p>
                            @endif

                            @if ($warning)
                                {{-- P4: why rated play with them stays closed for now; a hold says only that the account is checked, never why. --}}
                                <div class="flex max-w-[68ch] items-start gap-2 rounded-md bg-btc-tint px-3 py-2 text-[13px] leading-normal text-btc-hi" role="note" data-test="opponent-request-warning">
                                    <x-icon name="alert" :size="16" class="mt-0.5 shrink-0" />
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <p class="m-0 font-bold">{{ __(':name cannot play rated yet:', ['name' => $name]) }}</p>
                                        <ul class="m-0 flex list-disc flex-col gap-1 pl-4">
                                            @if ($warning['held'])
                                                <li data-test="opponent-request-warning-held">{{ __('This account is being checked.') }}</li>
                                            @endif
                                            @foreach ($warning['missing'] as $mark)
                                                <li data-test="opponent-request-warning-{{ $mark['key'] }}">{{ $mark['text'] }}</li>
                                            @endforeach
                                        </ul>
                                        <p class="m-0">{{ __('You can still accept. Rated games between you open only after that.') }}</p>
                                    </div>
                                </div>
                            @endif

                            @if ($warning)
                                {{-- Deferred: Accept only opens the confirm; nothing is signed before "Accept anyway" (R18, focus on the safe choice). --}}
                                <div x-show="confirming" x-cloak class="flex flex-col gap-2 rounded-md border border-line p-3" role="group" aria-label="{{ __('Accept :name as opponent', ['name' => $name]) }}" data-test="opponent-request-confirm">
                                    <p class="m-0 text-[13px] leading-normal text-ink">{{ __('Accept :name anyway?', ['name' => $name]) }}</p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" x-on:click="run('prepareConfirmedAdd', 'add', @js($pubkey))" x-bind:disabled="busy" data-test="opponent-request-confirm-accept"
                                                class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 whitespace-nowrap rounded-md border border-line bg-card px-4 text-[13px] font-bold text-ink disabled:cursor-wait disabled:opacity-70">
                                            <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" />{{ __('Accept anyway') }}
                                        </button>
                                        <button type="button" x-ref="cancelAccept" x-on:click="confirming = false; $nextTick(() => $refs.acceptButton.focus())" x-bind:disabled="busy" data-test="opponent-request-confirm-cancel"
                                                class="inline-flex h-11 cursor-pointer items-center whitespace-nowrap rounded-md px-3 text-[13px] text-ink-2 hover:text-ink disabled:cursor-wait disabled:opacity-70">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-2" @if ($warning) x-show="! confirming" @endif>
                                @if ($warning)
                                    <button type="button" x-ref="acceptButton" x-on:click="confirming = true; $nextTick(() => $refs.cancelAccept.focus())" x-bind:disabled="busy" x-bind:aria-expanded="confirming" data-test="opponent-request-accept" aria-label="{{ __('Accept :name as opponent', ['name' => $name]) }}"
                                            class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-card px-4 text-[13px] font-bold text-ink disabled:cursor-wait disabled:opacity-70">
                                        <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" />{{ __('Accept') }}
                                    </button>
                                @else
                                    <button type="button" x-on:click="run('prepareAdd', 'add', @js($pubkey))" x-bind:disabled="busy" data-test="opponent-request-accept" aria-label="{{ __('Accept :name as opponent', ['name' => $name]) }}"
                                            class="btn-w inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-card px-4 text-[13px] font-bold text-ink disabled:cursor-wait disabled:opacity-70">
                                        <x-icon name="shield-check" :size="16" class="shrink-0 text-proof" />{{ __('Accept') }}
                                    </button>
                                @endif
                                <button type="button" wire:click="decline(@js($pubkey))" x-bind:disabled="busy" data-test="opponent-request-decline" aria-label="{{ __('Decline :name', ['name' => $name]) }}"
                                        class="inline-flex h-11 cursor-pointer items-center rounded-md px-3 text-[13px] text-ink-2 hover:text-ink disabled:cursor-wait disabled:opacity-70">{{ __('Decline') }}</button>
                                <a href="{{ route('players.show', $player->npub) }}" class="ml-auto inline-flex h-11 items-center px-1 text-[13px] text-ink-2 hover:text-ink" data-test="opponent-request-profile">{{ __('Profile') }}</a>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($declined !== [])
                <details class="group border-t border-hairline pt-2" data-test="opponent-declined">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 text-[13px] text-ink-2 hover:text-ink [&::-webkit-details-marker]:hidden" data-test="opponent-declined-toggle">
                        <x-icon name="chevron-down" :size="16" class="shrink-0 transition-transform duration-150 ease-out group-open:rotate-180 motion-reduce:transition-none" />{{ __('Declined (:count)', ['count' => count($declined)]) }}
                    </summary>
                    <p class="m-0 max-w-[68ch] py-2 text-[13px] leading-normal text-ink-3">{{ __('Their public list still names you, because it is their list. No rated game happens between you unless you add them back.') }}</p>
                    <ul class="m-0 flex list-none flex-col p-0">
                        @foreach ($declined as $pubkey)
                            @php($player = $players->get($pubkey))
                            <li wire:key="declined-{{ $pubkey }}" class="flex min-h-12 items-center gap-3 border-b border-hairline text-[13px] last:border-0" data-test="opponent-declined-entry">
                                <x-player-link :user="$player" class="flex min-h-11 min-w-0 grow items-center gap-3 text-ink-2 hover:text-ink">
                                    <x-avatar :user="$player" :size="24" class="rounded-sm" /><span class="min-w-0 truncate">{{ $player->displayName() }}</span>
                                </x-player-link>
                                <button type="button" wire:click="restore(@js($pubkey))" data-test="opponent-declined-restore" aria-label="{{ __('Show the request of :name again', ['name' => $player->displayName()]) }}"
                                        class="inline-flex h-11 shrink-0 cursor-pointer items-center rounded-md px-2 text-[13px] text-ink-2 hover:text-ink">{{ __('Show again') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

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
    </div>
</div>
