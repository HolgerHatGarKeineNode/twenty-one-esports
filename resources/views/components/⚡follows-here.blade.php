<?php

use App\Enums\InviteLinkType;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\InviteLink;
use App\Models\Tournament;
use App\Models\User;
use App\Support\GameNames;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\Nostr\NostrBar;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignerMessages;
use App\Support\Series\CasualLobby;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * "Your follows here" (P47): which of the viewer's Nostr follows (their
 * NIP-02 kind 3, read in the browser from their relays when the section
 * comes into view, resources/js/followsHereUi.js) already play in the league,
 * with the page's challenge action, and an invite for the ones who do not:
 * a DM with the viewer's personal invite link, signed and encrypted by the
 * viewer's own signer and sent from the browser (inviteDm.js, NIP-17, NIP-04
 * only after a yes per recipient). The league stores neither the follow list
 * nor the text.
 *
 * Contexts: `me` (the own page: daily chess and a 1v1), `chess` (the lobby:
 * daily chess), `board` (a board game's lobby, `subject` its slug: a
 * correspondence challenge, the board game's correspondence page with the
 * follow picked, never sent on its own), `series` (a game page, `subject`
 * its slug: a 1v1 where the game has casual 1v1s) and `tournament`
 * (`subject` its id: no challenge; the invite carries the personal
 * tournament link while sign-up is open). The personal link is made on the
 * click that asks for the invite's preview, never on page load; a board
 * game has no invite link, so its lobby points to the own page for that.
 *
 * On the two lobbies (`chess`, `board`; plan brettspiel-chat-und-follows,
 * P2) each follow's row says whether they are online and whether they look
 * for this lobby's blitz, from the page-wide presence the lobby's "Online
 * now" reads (window.esportsPresence, never a second source), and a follow
 * who looks for it gets the lobby's own blitz invite ($wire.$parent.invite,
 * the method "Online now" calls; ChessInvites/BoardInvites refuse the rest).
 * A `board` subject that is no board game switched on is a 404, as its
 * lobby.
 *
 * Matching is one query however long the list ({@see match()}: one whereIn
 * over at most MAX_FOLLOWS keys), the list shows the first SHOWN.
 * Renders nothing for guests.
 */
new class extends Component {
    public const MAX_FOLLOWS = 5000;

    public const SHOWN = 12;

    #[Locked]
    public string $context = 'me';

    #[Locked]
    public string $subject = '';

    /** @var list<int> accounts of the viewer's follows that play here, in follow order */
    #[Locked]
    public array $hereIds = [];

    public function mount(string $context = 'me', string $subject = ''): void
    {
        abort_unless(in_array($context, ['me', 'chess', 'board', 'series', 'tournament'], true), 404);
        abort_if($context === 'board' && ! app(GameRegistry::class)->isBoard($subject), 404);

        $this->context = $context;
        $this->subject = $subject;
    }

    /**
     * The viewer's follows (hex pubkeys from their kind 3) that have an
     * account here: one query, however many. Returns their pubkeys, so the
     * browser knows who is not here yet.
     *
     * @param  array<mixed>  $pubkeys
     * @return array{here: list<string>}
     */
    public function match(array $pubkeys): array
    {
        $me = $this->me();
        $keys = array_values(array_unique(array_filter(
            array_slice($pubkeys, 0, self::MAX_FOLLOWS),
            fn (mixed $pubkey): bool => is_string($pubkey) && NostrKeys::isHexPubkey($pubkey) && $pubkey !== $me->pubkey,
        )));

        if ($keys === []) {
            $this->hereIds = [];

            return ['here' => []];
        }

        $found = User::query()->whereIn('pubkey', $keys)->pluck('id', 'pubkey')->all();
        $order = array_flip($keys);
        uksort($found, fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        $this->hereIds = array_values(array_map(intval(...), $found));
        unset($this->players);

        return ['here' => array_keys($found)];
    }

    /**
     * The first SHOWN of them, in follow order: one query plus the eager clan.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function players(): Collection
    {
        $ids = array_slice($this->hereIds, 0, self::SHOWN);

        if ($ids === []) {
            return collect();
        }

        $users = User::query()->whereKey($ids)->with('clanMember.clan')->get()->keyBy('id');

        return collect($ids)->map(fn (int $id): ?User => $users->get($id))->filter()->values();
    }

    #[Computed]
    public function tournament(): ?Tournament
    {
        return $this->context === 'tournament' && ctype_digit($this->subject) ? Tournament::query()->find((int) $this->subject) : null;
    }

    /**
     * The `users.looking_to_play` value of the lobby this section sits in,
     * or null off a lobby: the rows then say nothing about presence.
     */
    public function lookingKey(): ?string
    {
        return match ($this->context) {
            'chess' => 'chess/blitz',
            'board' => $this->subject.'/blitz',
            default => null,
        };
    }

    /** Whether this page offers the invite DM: a personal link exists for it. */
    public function invites(): bool
    {
        return match ($this->context) {
            'me', 'chess' => true,
            'tournament' => $this->tournament?->isSignupOpen() === true,
            default => false,
        };
    }

    /**
     * The invite's link and text, for the preview: the personal tournament
     * link on a tournament, else the viewer's open daily chess link for
     * several friends (made now if there is none with a day left).
     *
     * @return array{link?: string, text?: string, error?: string}
     */
    public function inviteText(InviteLinks $links): array
    {
        $me = $this->me();

        try {
            if ($this->context === 'tournament') {
                $tournament = $this->tournament;
                abort_unless($tournament !== null && $this->invites(), 404);
                $link = $links->forTournament($me, $tournament)->url();

                return ['link' => $link, 'text' => __('I’m in :tournament on TWENTY ONE Esports. Join me: :link', ['tournament' => $tournament->name, 'link' => $link])];
            }

            abort_unless($this->invites(), 404);
            $open = InviteLink::query()->where(['inviter_id' => $me->id, 'type' => InviteLinkType::Daily])->whereNull('max_uses')->whereNull('revoked_at')
                ->where('expires_at', '>', now()->addDay())->latest('id')->first();
            $link = ($open ?? $links->create($me, InviteLinkType::Daily, ['uses' => 'several', 'hours' => 168, 'color' => 'random']))->url();

            return ['link' => $link, 'text' => __('I play chess and more on TWENTY ONE Esports, the Bitcoin league on Nostr. Play a daily chess game with me: :link', ['link' => $link])];
        } catch (InviteLinkRefused $refused) {
            return ['error' => $refused->getMessage()];
        }
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
    $casual = $context === 'series' && CasualLobby::offers($subject);
    $invites = $viewer !== null && $this->invites();
    $lookingKey = $this->lookingKey();
    // The tag "Online now" puts on a player who looks for this lobby's blitz (components/lobby/online-now), word for word.
    $lookingTag = $context === 'board' ? __('looking: :game', ['game' => GameNames::game($subject)]) : __('looking: Blitz 5+3');
    $correspondence = $context === 'board' && app(GameRegistry::class)->mode($subject, BoardGame::CORRESPONDENCE) !== null;
    $button = 'inline-flex h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md border border-line bg-well px-3 text-[13px] text-ink hover:text-ink';
    $primary = 'btn-p inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-md bg-btc px-4 text-[13px] font-bold text-on-btc hover:text-on-btc disabled:cursor-default disabled:opacity-60';
@endphp
<div @class(['contents' => $viewer !== null, 'hidden' => $viewer === null])>
    @if ($viewer)
        <section id="follows-here" aria-labelledby="fh-h-{{ $this->getId() }}" data-test="follows-here" data-context="{{ $context }}"
                 class="flex min-w-0 flex-col gap-3 rounded-card bg-card px-4 py-4 shadow-ring lg:px-5"
                 x-data="followsHere(@js([
                     'me' => $viewer->pubkey,
                     'relays' => NostrBar::browserRelays(),
                     'lookingKey' => $lookingKey,
                     'labels' => [
                         'signer' => SignerMessages::labels(),
                         'failed' => __('That did not work. Please try again.'),
                         'notRead' => __('Your follow list could not be read (:answered of :asked relays answered).'),
                         'partial' => __(':answered of :asked relays answered; the list may be incomplete.'),
                         'tooMany' => __('You can invite up to :max at once.'),
                         'noLink' => __('Keep the invite link in the message.'),
                         'unsent' => __('Signed, but no relay took it.'),
                         'dm_no_encryption' => __('Your signer cannot encrypt messages (neither NIP-44 nor NIP-04).'),
                         'dm_no_dm_relays' => __('No relays for direct messages, and your signer has no NIP-04 to reach them otherwise.'),
                         'dm_not_read' => __('No relay answered, so it is unknown where they receive messages.'),
                         'dm_self' => __('That is your own key.'),
                     ],
                 ]))"
                 :data-state="state">
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h2 id="fh-h-{{ $this->getId() }}" class="m-0 font-display text-lg font-bold lg:text-xl">{{ __('Your follows here') }}</h2>
                <span class="text-xs text-ink-2" x-show="state === 'done'" x-cloak x-text="@js(__('Accounts you follow on Nostr: :total. Playing here: :here.')).replace(':total', total).replace(':here', here.length)" data-test="follows-here-count"></span>
            </span>

            <p class="m-0 text-[13px] text-ink-2" x-show="state === 'idle' || state === 'reading'" data-test="follows-here-reading">{{ __('Reading your follow list from your relays…') }}</p>
            <p class="m-0 text-[13px] leading-normal text-ink-2" x-show="state === 'none'" x-cloak data-test="follows-here-none">{{ __('No follow list of yours was found on your relays. Follow players here or in your Nostr app, and they show up here.') }}</p>
            <p role="alert" class="m-0 text-[13px] leading-normal text-loss" x-show="state === 'failed'" x-cloak x-text="error" data-test="follows-here-failed"></p>
            <p class="m-0 text-xs leading-normal text-ink-3" x-show="state === 'done' && ! complete" x-cloak x-text="label('partial', { answered, asked })" data-test="follows-here-partial"></p>

            @if ($this->players->isNotEmpty())
                <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2 xl:grid-cols-3" data-test="follows-here-list">
                    @foreach ($this->players as $player)
                        <li wire:key="fh-{{ $player->id }}" class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 rounded-md bg-well px-3 py-2" data-test="follows-here-player">
                            <x-avatar :user="$player" :size="36" class="shrink-0" />
                            <span class="flex min-w-0 grow basis-0 flex-col">
                                {{-- The name in a box of its own: text-overflow does not act on a flex container's text, so a long name was cut without the ellipsis. --}}
                                <x-player-link :user="$player" class="inline-flex min-h-11 min-w-11 items-center overflow-hidden text-[13px] font-bold"><span class="min-w-0 truncate" data-test="follows-here-name">{{ $player->displayName() }}</span></x-player-link>
                                @if ($player->clanMember?->clan)
                                    <span class="truncate text-xs text-ink-2">{{ $player->clanMember->clan->name }}</span>
                                @endif
                                {{-- On a lobby: online, or looking for its blitz, as the row in "Online now" says it; nothing while offline. --}}
                                @if ($lookingKey)
                                    <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 pb-1" x-show="online[{{ $player->id }}]" x-cloak data-test="follows-here-presence">
                                        <span class="inline-flex items-center gap-1.5 text-xs text-ink-2" x-show="! looks({{ $player->id }})"><span class="size-2 shrink-0 rounded-full bg-win" aria-hidden="true"></span>{{ __('online') }}</span>
                                        <span class="max-w-full rounded-xs bg-win-tint px-1.5 py-0.5 text-[11px] font-bold wrap-break-word text-win shadow-ring-win" x-show="looks({{ $player->id }})" data-test="follows-here-looking">{{ $lookingTag }}</span>
                                    </span>
                                @endif
                            </span>
                            @if ($lookingKey)
                                {{--
                                    The lobby's blitz invite, for a follow who looks for it; "Invited" until the invite is answered or
                                    runs out. One labelled action per row, the timely one: next to it the challenge keeps its icon and
                                    its aria-label, so the name keeps its room in a three-column row (German "Einladen" + "Herausfordern").
                                --}}
                                <template x-if="looks({{ $player->id }}) && ! invitedBlitz({{ $player->id }})">
                                    <button type="button" class="{{ $button }}" x-on:click="inviteBlitz({{ $player->id }})" x-bind:disabled="inviting !== null" data-test="follows-here-invite" aria-label="{{ __('Invite :name to blitz 5+3', ['name' => $player->displayName()]) }}" title="{{ __('Invite :name to blitz 5+3', ['name' => $player->displayName()]) }}">
                                        <x-icon name="bolt" :size="16" class="shrink-0" /><span class="max-sm:sr-only">{{ __('Invite') }}</span>
                                    </button>
                                </template>
                                <template x-if="invitedBlitz({{ $player->id }})">
                                    <span class="inline-flex h-11 min-w-11 shrink-0 items-center justify-center gap-2 px-1 text-[13px] text-ink-2" data-test="follows-here-invited">
                                        <x-icon name="check" :size="16" class="shrink-0 text-win" /><span class="max-sm:sr-only">{{ __('Invited') }}</span>
                                    </span>
                                </template>
                            @endif
                            @if ($correspondence)
                                <a href="{{ route('board.correspondence', ['board' => $subject, 'to' => $player->npub]) }}" class="{{ $button }}" data-test="follows-here-challenge" aria-label="{{ __('Challenge :name to :game by correspondence', ['name' => $player->displayName(), 'game' => GameNames::game($subject)]) }}" title="{{ __('Challenge :name to :game by correspondence', ['name' => $player->displayName(), 'game' => GameNames::game($subject)]) }}">
                                    <x-icon name="calendar" :size="16" class="shrink-0" /><span class="max-sm:sr-only" @if ($lookingKey) x-bind:class="{ 'sr-only': looks({{ $player->id }}) || invitedBlitz({{ $player->id }}) }" @endif>{{ __('Challenge') }}</span>
                                </a>
                            @endif
                            @if (in_array($context, ['me', 'chess'], true))
                                <a href="{{ route('chess.challenge', ['to' => $player->npub]) }}" class="{{ $button }}" data-test="follows-here-challenge" aria-label="{{ __('Challenge :name to daily chess', ['name' => $player->displayName()]) }}" title="{{ __('Challenge :name to daily chess', ['name' => $player->displayName()]) }}">
                                    <x-icon name="pawn" :size="16" class="shrink-0" /><span class="max-sm:sr-only" @if ($lookingKey) x-bind:class="{ 'sr-only': looks({{ $player->id }}) || invitedBlitz({{ $player->id }}) }" @endif>{{ __('Challenge') }}</span>
                                </a>
                            @endif
                            @if ($context === 'me' || $casual)
                                <a href="{{ route('challenges.casual', array_filter(['to' => $player->id, 'game' => $casual ? $subject : null])) }}" class="{{ $button }}" data-test="follows-here-1v1" aria-label="{{ __('Schedule a 1v1 with :name', ['name' => $player->displayName()]) }}">
                                    <x-icon name="calendar" :size="16" class="shrink-0" /><span class="max-sm:sr-only">1v1</span>
                                </a>
                            @endif
                            @if ($lookingKey)
                                <p role="alert" class="m-0 basis-full text-xs leading-normal text-loss empty:hidden" x-text="blitzError({{ $player->id }})" data-test="follows-here-invite-error"></p>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if (count($hereIds) > $this->players->count())
                    <p class="m-0 text-xs text-ink-2" data-test="follows-here-more">{{ trans_choice('and :count more|and :count more', count($hereIds) - $this->players->count()) }}</p>
                @endif
            @endif
            <p class="m-0 text-[13px] leading-normal text-ink-2" x-show="state === 'done' && here.length === 0" x-cloak data-test="follows-here-empty">{{ __('None of the accounts you follow plays here yet.') }}</p>

            {{-- Invite: follows who are not here yet, a DM each with your personal link; preview first, send on the button. --}}
            @if ($invites)
                <div class="flex min-w-0 flex-col gap-3 border-t border-hairline pt-3" x-show="state === 'done' && notHere.length > 0" x-cloak data-test="follows-invite">
                    <button type="button" class="{{ $button }} self-start" x-on:click="openPicker()" x-show="! pickerOpen" data-test="follows-invite-open">
                        <x-icon name="send" :size="16" class="shrink-0" /><span x-text="@js(__('Invite follows who are not here yet (:count)')).replace(':count', notHere.length)"></span>
                    </button>

                    <div class="flex min-w-0 flex-col gap-3" x-show="pickerOpen" x-cloak data-test="follows-invite-picker">
                        <template x-if="step === 'pick'">
                            <div class="flex min-w-0 flex-col gap-2">
                                <label class="flex min-w-0 flex-col gap-1.5">
                                    <span class="text-xs text-ink-2">{{ __('Pick up to :max people you follow. Each gets a direct message from you with your invite link.', ['max' => 10]) }}</span>
                                    <input type="search" x-model="search" placeholder="{{ __('Search a name or npub') }}" class="h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink" data-test="follows-invite-search">
                                </label>
                                <p class="m-0 text-xs text-ink-2" x-show="namesLoading">{{ __('Loading names…') }}</p>
                                <ul class="m-0 flex max-h-80 list-none flex-col overflow-y-auto p-0" data-test="follows-invite-list">
                                    <template x-for="pubkey in candidates" :key="pubkey">
                                        <li>
                                            <label class="flex min-h-11 min-w-0 cursor-pointer items-center gap-3 border-b border-hairline px-1 py-1.5 text-[13px]" data-test="follows-invite-candidate">
                                                <input type="checkbox" class="size-5 shrink-0 cursor-pointer accent-btc" :value="pubkey" :checked="picked.includes(pubkey)" x-on:change="pick(pubkey)" :disabled="! picked.includes(pubkey) && picked.length >= 10">
                                                <span class="min-w-0 grow truncate" x-text="nameOf(pubkey)"></span>
                                                <span class="shrink-0 font-mono text-xs text-ink-3 max-sm:hidden" x-text="shortNpub(pubkey)"></span>
                                            </label>
                                        </li>
                                    </template>
                                </ul>
                                <p class="m-0 text-xs text-ink-3" x-show="moreCandidates > 0" x-text="@js(__('Search to see :count more.')).replace(':count', moreCandidates)"></p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="{{ $primary }}" x-on:click="preview()" x-bind:disabled="picked.length === 0 || busy" data-test="follows-invite-preview">
                                        <span x-text="busy ? @js(__('Preparing…')) : @js(__('Preview the invite'))">{{ __('Preview the invite') }}</span>
                                    </button>
                                    <button type="button" class="{{ $button }}" x-on:click="closePicker()">{{ __('Cancel') }}</button>
                                </div>
                            </div>
                        </template>

                        {{-- The preview: exactly who gets what, before anything is signed --}}
                        <template x-if="step !== 'pick'">
                            <div class="flex min-w-0 flex-col gap-2" data-test="follows-invite-preview-panel">
                                <b class="text-[13px]" x-text="@js(__('A direct message to each of these :count:')).replace(':count', picked.length)"></b>
                                <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[13px]">
                                    <template x-for="pubkey in picked" :key="pubkey">
                                        <li class="flex min-h-11 min-w-0 flex-wrap items-center gap-2 border-b border-hairline py-1" data-test="follows-invite-recipient" :data-status="results[pubkey]?.status ?? 'waiting'">
                                            <span class="min-w-0 grow truncate" x-text="nameOf(pubkey)"></span>
                                            <span class="shrink-0 text-xs text-win" x-show="results[pubkey]?.status === 'sent'" x-text="results[pubkey]?.format === 'nip04' ? @js(__('Sent (NIP-04)')) : @js(__('Sent (NIP-17)'))"></span>
                                            <span class="shrink-0 text-xs text-loss" x-show="['unsent', 'refused', 'error'].includes(results[pubkey]?.status)" x-text="resultText(pubkey)"></span>
                                            <span class="shrink-0 text-xs text-ink-2" x-show="results[pubkey]?.status === 'pending'">{{ __('Not sent') }}</span>
                                            <span class="shrink-0 text-xs text-ink-2" x-show="results[pubkey]?.status === 'skipped'">{{ __('Skipped') }}</span>
                                            {{-- P45 audit F3: the older format only after a yes for exactly this person. --}}
                                            <span class="flex min-w-0 basis-full flex-wrap items-center gap-2" x-show="results[pubkey]?.status === 'confirm'" role="alertdialog" data-test="follows-invite-confirm">
                                                <span class="min-w-0 grow text-xs leading-normal text-ink" x-text="results[pubkey]?.reason === 'no_nip44' ? @js(__('Your signer cannot do NIP-44, so this can only go as an older NIP-04 DM. Send it that way? Relays then see who wrote to whom and when, not what.')) : @js(__('This person has no DM relay list, so NIP-17 cannot reach them. Send it as an older NIP-04 DM? Relays then see who wrote to whom and when, not what.'))"></span>
                                                <button type="button" class="{{ $primary }}" x-on:click="send(pubkey)" x-bind:disabled="busy" data-test="follows-invite-nip04">{{ __('Send as NIP-04') }}</button>
                                                <button type="button" class="{{ $button }}" x-on:click="skip(pubkey)">{{ __('Skip') }}</button>
                                            </span>
                                        </li>
                                    </template>
                                </ul>
                                <label class="flex min-w-0 flex-col gap-1.5">
                                    <span class="text-xs text-ink-2">{{ __('Message') }}</span>
                                    <textarea x-model="text" rows="4" maxlength="2000" :readonly="step !== 'preview'" data-test="follows-invite-text"
                                              class="min-h-24 w-full min-w-0 rounded-md border border-edge bg-ground p-3 text-sm text-ink [overflow-wrap:anywhere]"></textarea>
                                </label>
                                <p class="m-0 text-xs leading-normal text-ink-3">{{ __('Encrypted and signed by your own signer, one message per person, sent from this browser to their relays (NIP-17, or NIP-04 only if you say yes for that person). The league does not store it.') }}</p>
                                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end" x-show="step === 'preview' || step === 'sending'">
                                    <button type="button" class="{{ $button }}" x-on:click="step = 'pick'" x-bind:disabled="busy" data-test="follows-invite-back">{{ __('Back') }}</button>
                                    <button type="button" class="{{ $primary }}" x-on:click="send()" x-bind:disabled="busy || unsent.length === 0" data-test="follows-invite-send">
                                        <x-icon name="send" :size="16" class="shrink-0" /><span x-text="busy ? @js(__('Sending…')) : @js(__('Sign and send :count')).replace(':count', unsent.length)"></span>
                                    </button>
                                </div>
                                <button type="button" class="{{ $button }} self-start" x-show="step === 'done'" x-on:click="closePicker()" data-test="follows-invite-close">{{ __('Done') }}</button>
                                <p role="alert" class="m-0 text-xs text-loss" x-show="error" x-text="error" data-test="follows-invite-error"></p>
                            </div>
                        </template>
                    </div>
                </div>
            @elseif (in_array($context, ['series', 'board'], true))
                <a href="{{ route('dashboard') }}#follows-here" class="inline-flex min-h-11 items-center gap-1.5 self-start text-[13px]" x-show="state === 'done' && notHere.length > 0" x-cloak data-test="follows-invite-elsewhere">
                    <x-icon name="send" :size="14" />{{ __('Invite the others from your page') }}
                </a>
            @endif
        </section>
    @endif
</div>
