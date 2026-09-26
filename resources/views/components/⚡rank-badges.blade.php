<?php

use App\Models\RankBadge;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Badges\ProfileBadges;
use App\Support\Badges\ProfileBadgesRefused;
use App\Support\Badges\RankBadges;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\Rating\RankTiers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * A player's rank badges (P11, NIP "Rank badges"): one per game and mode, the
 * current tier from the NIP-58 definition the league signs, with its artwork.
 * On the player's own badges "Show on my Nostr profile" adds the badge to
 * their kind 10008 after a confirmation that names how many badges stay
 * (profileBadge, resources/js/badgeShare.js; {@see ProfileBadges}).
 */
new class extends Component {
    #[Locked]
    public int $playerId;

    /** The link to the share cards; off on the page that lists them already. */
    #[Locked]
    public bool $shareLink = true;

    public function mount(User $player, bool $shareLink = true): void
    {
        $this->playerId = $player->id;
        $this->shareLink = $shareLink;
    }

    /**
     * @return array{template: array<string, mixed>, kept: int}|null
     */
    public function prepareProfile(int $badge, string $found, bool $read, ProfileBadges $profiles): ?array
    {
        return $this->refusable(fn () => $this->once() ?? $profiles->prepare($this->me(), $this->badge($badge), $this->events($found), $read));
    }

    public function submitProfile(int $badge, string $found, bool $read, string $signed, ProfileBadges $profiles): bool
    {
        return $this->refusable(fn () => $this->once() ?? $profiles->submit($this->me(), $this->badge($badge), $this->events($found), $read, json_decode($signed, true)) !== null) ?? false;
    }

    /**
     * One badge call per Livewire request (gate F2): a request that batches
     * several would multiply the signature checks past the rate limit.
     *
     * @throws ProfileBadgesRefused
     */
    private function once(): null
    {
        if (request()->attributes->getBoolean('profile-badges.called')) {
            throw new ProfileBadgesRefused(__('One badge change at a time. Try again.'));
        }

        request()->attributes->set('profile-badges.called', true);

        return null;
    }

    /**
     * @return Collection<int, RankBadge>
     */
    public function badges(): Collection
    {
        $player = User::query()->find($this->playerId);

        return $player === null ? collect() : RankBadge::query()->where('pubkey', $player->pubkey)->whereNotNull('award_event_id')
            ->with('awardEvent')->orderBy('game')->orderBy('mode')->get();
    }

    /**
     * @return list<mixed>
     */
    private function events(string $found): array
    {
        // Never cut a list: a truncated read would look empty and the new list would drop every other badge.
        $events = strlen($found) > 524_288 ? null : json_decode($found, true);

        if (! is_array($events)) {
            throw new ProfileBadgesRefused(__('Your badge list could not be read. Nothing was changed.'));
        }

        return array_values(array_slice($events, 0, ProfileBadges::MAX_FOUND));
    }

    private function badge(int $id): RankBadge
    {
        return RankBadge::query()->with('awardEvent')->where('pubkey', $this->me()->pubkey)->findOrFail($id);
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
        } catch (ProfileBadgesRefused $refused) {
            $this->addError('badges', $refused->getMessage());
        } catch (RejectedEvent) {
            $this->addError('badges', __('The confirmation did not match. Please try again.'));
        }

        return null;
    }

    private function me(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $badges = $this->badges();
    $viewer = auth()->user();
    $mine = $viewer instanceof User && $viewer->id === $playerId;
    $profiles = app(ProfileBadges::class);
@endphp

<section aria-labelledby="rb-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="rank-badges"
         @if ($mine) x-data="profileBadge({ pubkey: @js($viewer->pubkey), relays: @js(ProfileBadges::browserRelays()), messages: @js([...SignerMessages::labels(), 'notPublished' => __('None of your relays took the new list. Nothing was changed.')]) })" @endif>
    <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 id="rb-h" class="m-0 text-[15px] font-bold">{{ __('Rank badges') }}</h2>
        <span class="text-xs text-ink-2">{{ __('NIP-58 badges, signed by the league on every rank change') }}</span>
    </span>

    @if ($badges->isEmpty())
        <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="rank-badges-empty">
            {{ $mine ? __('Your first rank badge arrives with your first rank, after five rated games in a game and mode.') : __('No rank badge yet. Badges come from rated games only.') }}
        </p>
    @else
        <ul class="m-0 flex list-none flex-col p-0">
            @foreach ($badges as $badge)
                @php($listed = $mine && $profiles->isListed($viewer, $badge))
                <li wire:key="badge-{{ $badge->id }}" class="flex min-h-16 flex-wrap items-center gap-x-3 gap-y-2 border-b border-hairline py-2 last:border-0" data-test="rank-badge" data-tier="{{ $badge->tier }}">
                    <img src="{{ RankBadges::imagePath($badge->game, $badge->tier, true) }}" alt="" width="48" height="48" class="size-12 shrink-0 rounded-md" loading="lazy">
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <b class="truncate text-[13px]" style="color: {{ RankTiers::colour($badge->tier) }}">{{ RankTiers::label($badge->tier) }}</b>
                        <span class="truncate text-xs text-ink-2">{{ BadgeCopy::ladder($badge->game, $badge->mode) }} · {{ BadgeCopy::season($badge->season) }}</span>
                    </span>
                    @if ($mine)
                        @if ($listed)
                            <span class="inline-flex h-11 shrink-0 items-center gap-1.5 text-xs text-win" data-test="badge-listed"><x-icon name="check" :size="14" />{{ __('On your Nostr profile') }}</span>
                        @else
                            <button type="button" x-on:click="start({{ $badge->id }})" x-bind:disabled="step === 'reading' || step === 'signing'" x-show="!(step === 'done' && badge === {{ $badge->id }})" data-test="badge-show"
                                    class="btn-w inline-flex h-11 min-w-0 shrink-0 cursor-pointer items-center gap-2 rounded-md border border-line bg-well px-3.5 text-[13px] font-bold text-ink disabled:cursor-wait disabled:opacity-70">
                                <x-icon name="award" :size="16" class="shrink-0 text-btc" /><span class="truncate">{{ __('Show on my Nostr profile') }}</span>
                            </button>
                            <span x-show="step === 'done' && badge === {{ $badge->id }}" x-cloak class="inline-flex h-11 shrink-0 items-center gap-1.5 text-xs text-win" data-test="badge-added"><x-icon name="check" :size="14" />{{ __('On your Nostr profile') }}</span>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
        <a href="https://njump.me/{{ NostrKeys::hexToNpub($badges->first()->pubkey) }}" rel="nofollow noopener noreferrer" target="_blank" class="self-start text-xs">{{ __('See the profile on Nostr') }}</a>
    @endif

    @if ($mine && $shareLink)
        <a href="{{ route('settings.badges') }}#share" class="inline-flex min-h-11 items-center gap-1.5 self-start text-[13px]" data-test="to-share"><x-icon name="send" :size="14" />{{ __('Share cards and posts') }}</a>
    @endif

    @if ($mine)
        {{-- The confirmation: what the new list keeps, before anything is signed. --}}
        <div x-show="step === 'confirm' || step === 'signing'" x-cloak role="dialog" aria-labelledby="rb-confirm-h" data-test="badge-confirm"
             class="flex flex-col gap-3 rounded-md bg-ground p-4 shadow-[inset_0_0_0_1px_#F7931A]">
            <b id="rb-confirm-h" class="text-[13px]">{{ __('Add this badge to your Nostr profile?') }}</b>
            <p class="m-0 text-[13px] leading-normal text-ink-2">
                <span x-text="kept === 0 ? @js(__('Your profile has no other badges yet.')) : (kept === 1 ? @js(__('Your 1 other badge stays on your profile.')) : @js(__('Your :count other badges stay on your profile.')).replace(':count', kept))" data-test="badge-kept"></span>
                {{ __('Your signer asks you to sign the new list; it goes to your own relays.') }}
            </p>
            <p class="m-0 text-xs text-ink-2" data-test="badge-relays" x-text="@js(__(':answered of :asked of your relays answered.')).replace(':answered', answered).replace(':asked', asked)"></p>
            <div class="flex flex-wrap gap-2">
                <button type="button" x-on:click="confirm()" x-bind:disabled="step === 'signing'" data-test="badge-confirm-sign"
                        class="btn-p inline-flex h-11 cursor-pointer items-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc disabled:cursor-wait disabled:opacity-70"><x-icon name="shield-check" :size="16" />{{ __('Sign and add') }}</button>
                <button type="button" x-on:click="cancel()" x-bind:disabled="step === 'signing'" class="inline-flex h-11 cursor-pointer items-center rounded-md px-3 text-[13px] text-ink-2 hover:text-ink">{{ __('Cancel') }}</button>
            </div>
        </div>
        <p x-show="step === 'reading'" x-cloak role="status" class="m-0 text-xs text-ink-2">{{ __('Reading your current badges from your relays…') }}</p>
        <p x-show="error" x-text="error" x-cloak class="m-0 text-[13px] text-loss" role="alert"></p>
        <p x-show="warning" x-text="warning" x-cloak class="m-0 text-[13px] text-loss" role="alert" data-test="badge-warning"></p>
        @error('badges')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
    @endif
</section>
