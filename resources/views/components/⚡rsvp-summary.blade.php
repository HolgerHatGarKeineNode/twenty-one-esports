<?php

use App\Models\Tournament;
use App\Models\User;
use App\Support\Comments\CommentTarget;
use App\Support\Comments\NostrAuthors;
use App\Support\GameChat\GameChannels;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * Who told Nostr calendars they go to a tournament (P48, NIP-52 RSVPs,
 * kind 31925), read in the browser from the league relays and shown under
 * the sign-up count. RSVPs are what anyone published; the sign-up count is
 * the league's own record, and the two are never added up.
 */
new class extends Component {
    #[Locked]
    public int $tournamentId;

    public function mount(int $tournament): void
    {
        $this->tournamentId = $tournament;
    }

    #[Computed]
    public function address(): ?string
    {
        return CommentTarget::tournament(Tournament::query()->with('event')->find($this->tournamentId))?->address;
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
}; ?>

@php($address = $this->address)
<div @class(['min-w-0', 'hidden' => $address === null])>
    @if ($address)
        @php($viewer = auth()->user())
        <div class="flex min-h-7 min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-2" data-test="rsvp-summary"
             x-data="rsvpSummary(@js([
                 'address' => $address,
                 'relays' => config('esports.relays', []),
                 'readRelays' => (int) config('esports.comments.read_relays', 5),
                 'readLimit' => (int) config('esports.comments.read_limit', 500),
                 'moderation' => ['creator' => GameChannels::creator(), 'relays' => GameChannels::relays()],
                 'muted' => $viewer instanceof User ? $viewer->mutedPubkeys() : [],
                 'faces' => 6,
             ]))">
            <x-icon name="calendar" :size="14" class="shrink-0" />
            <span x-show="status === 'loading'">{{ __('Reading RSVPs on Nostr…') }}</span>
            <span x-show="status === 'unreached'" x-cloak data-test="rsvp-unreached">{{ __('RSVPs on Nostr: no relay answered.') }}</span>
            <template x-if="status === 'ready'">
                <span class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="flex shrink-0 -space-x-1.5" x-show="faces.length > 0">
                        <template x-for="face in faces" :key="face.pubkey">
                            <img x-bind:src="face.avatar" x-show="face.avatar" x-bind:alt="face.name" width="22" height="22" loading="lazy" class="size-[22px] rounded-full bg-raised ring-2 ring-card">
                        </template>
                    </span>
                    <span data-test="rsvp-count" x-text="going.length === 1 ? @js(__('1 said on Nostr they’re going')) : @js(__(':count said on Nostr they’re going')).replace(':count', going.length)"></span>
                    <span x-show="notGoing > 0" x-text="notGoing === 1 ? @js(__('1 not going')) : @js(__(':count not going')).replace(':count', notGoing)"></span>
                </span>
            </template>
            <span class="text-ink-3">{{ __('RSVPs are not sign-ups.') }}</span>
        </div>
    @endif
</div>
