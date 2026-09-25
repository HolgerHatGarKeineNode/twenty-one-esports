<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Challenge a player to daily chess (ChessChallenge.dc.html). Until Elo
 * exists (P7) every game is casual: "Rated" is shown disabled, the trust
 * column and the Elo stakes of the design are left out. Blitz with a friend
 * stays in the lobby ("invite a friend who is online"); Rapid and Bullet are
 * "soon" as in the design.
 */
new #[Title('Challenge')] #[Layout('layouts::app', ['section' => 'chess'])] class extends Component {
    #[Url(as: 'q')]
    public string $search = '';

    /** The chosen opponent: a user id, or an npub from a profile link (`?to=npub1…`). */
    #[Url(as: 'to')]
    public string $to = '';

    public string $color = 'random';

    public string $message = '';

    public string $error = '';

    public function pick(int $id): void
    {
        $this->to = (string) $id;
        $this->error = '';
        unset($this->opponent);
    }

    public function send(): void
    {
        $this->validate([
            'color' => ['required', 'in:'.implode(',', DailyChallenges::COLORS)],
            'message' => ['nullable', 'string', 'max:140'],
        ]);

        $opponent = $this->opponent;

        if ($opponent === null) {
            $this->addError('to', __('Pick an opponent first.'));

            return;
        }

        try {
            $challenge = app(DailyChallenges::class)->challenge($this->user(), $opponent, $this->color, $this->message);
        } catch (ChessRuleViolation $violation) {
            $this->error = match ($violation->reason) {
                'challenge_self' => __('You cannot challenge yourself.'),
                'challenge_open' => __('There is already an open challenge between the two of you.'),
                default => __('That did not work, please try again.'),
            };

            return;
        }

        session()->flash('status', __('Challenge sent to :name. They have :hours h to accept.', ['name' => $challenge->challenged->displayName(), 'hours' => (int) config('esports.chess.challenge_hours')]));
        $this->redirectRoute('me.correspondence');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function players(): Collection
    {
        $term = trim($this->search);

        return User::query()
            ->whereKeyNot($this->user()->id)
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$term.'%')->orWhere('npub', 'like', $term.'%')))
            ->with('clanMember.clan')
            ->withCount(['whiteGames', 'blackGames'])
            ->latest('updated_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function opponent(): ?User
    {
        $to = trim($this->to);
        $query = User::query()->with('clanMember.clan')->whereKeyNot($this->user()->id);

        if (ctype_digit($to)) {
            return $query->find((int) $to);
        }

        $hex = $to === '' ? null : NostrKeys::toHex($to);

        return $hex === null ? null : $query->where('pubkey', $hex)->first();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $me = auth()->user();
    $opponent = $this->opponent;
    $rating = (int) config('esports.chess.queue.start_rating');
    $hours = (int) config('esports.chess.challenge_hours');
    $colorLabel = ['random' => __('Random'), 'white' => __('White'), 'black' => __('Black')];
@endphp

<div class="flex grow flex-col gap-5 px-4 pt-5 pb-8 lg:mx-auto lg:w-full lg:max-w-[1200px] lg:px-0 lg:pt-7 lg:pb-10" data-test="chess-challenge">
    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[32px]">{{ __('Challenge') }}</h1>
        <span class="text-[13px] text-ink-2">{{ __('Daily chess, or a friend who is online') }}</span>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_400px]">
        <div class="flex min-w-0 flex-col gap-5">
            {{-- Opponent --}}
            <section aria-labelledby="op-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" x-data="{ online: [] }" x-init="window.esportsPresence?.subscribe((members) => online = members.map((m) => m.id))">
                <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="op-h" class="m-0 text-[15px] font-bold">{{ __('Opponent') }}</h2><span class="text-xs text-ink-2">{{ __('anyone, with or without a clan') }}</span></span>
                <label for="player-search" class="sr-only">{{ __('Search players') }}</label>
                <input id="player-search" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search players') }}" autocomplete="off" data-test="player-search"
                       class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-sm text-ink placeholder:text-ink-3 lg:max-w-[360px]">
                <div role="radiogroup" aria-labelledby="op-h" class="flex flex-col">
                    <div class="hidden h-9 grid-cols-[minmax(0,1fr)_70px_120px_60px_90px] items-center gap-3 px-3 text-xs text-ink-2 md:grid">
                        <span>{{ __('Player') }}</span><span>{{ __('Daily') }}</span><span>{{ __('Tier') }}</span><span>{{ __('Games') }}</span><span>{{ __('Status') }}</span>
                    </div>
                    @forelse ($this->players as $player)
                        <button type="button" role="radio" wire:key="p-{{ $player->id }}" wire:click="pick({{ $player->id }})" aria-checked="{{ $opponent?->is($player) ? 'true' : 'false' }}" data-test="pick-player"
                                @class(['grid min-h-12 cursor-pointer grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-md border bg-transparent px-3 py-1.5 text-left text-[13px] text-ink md:grid-cols-[minmax(0,1fr)_70px_120px_60px_90px]',
                                    'border-btc bg-btc-press' => $opponent?->is($player), 'border-transparent hover:bg-row-hover' => ! $opponent?->is($player)])>
                            <span class="flex min-w-0 items-center gap-2">
                                <x-avatar :name="$player->displayName()" :src="$player->avatarUrl()" :size="20" class="rounded-sm" />
                                <span class="truncate">{{ $player->displayName() }}</span>
                                @if ($player->clanMember?->clan?->clantag)<x-clan-tag :tag="$player->clanMember->clan->clantag" size="sm" />@endif
                                @if ($player->is_member)<x-member-badge />@endif
                            </span>
                            <span class="max-md:hidden">{{ $rating }}</span>
                            <span class="max-md:hidden"><x-rank-badge tier="provisional" size="sm" /></span>
                            <span class="max-md:hidden tabular-nums">{{ $player->white_games_count + $player->black_games_count }}</span>
                            <span class="text-xs" :class="online.includes({{ $player->id }}) ? 'text-win' : 'text-ink-3'" x-text="online.includes({{ $player->id }}) ? @js(__('online')) : @js(__('offline'))"></span>
                        </button>
                    @empty
                        <p class="m-0 px-3 py-3 text-[13px] text-ink-2">{{ __('No player found.') }}</p>
                    @endforelse
                </div>
                <p class="m-0 text-xs leading-normal text-ink-3">{{ __('Until Season 1 every game is casual: you can challenge anyone, no connection needed.') }}</p>
                @error('to')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
            </section>

            {{-- Time control --}}
            <section aria-labelledby="tc-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
                <h2 id="tc-h" class="m-0 text-[15px] font-bold">{{ __('Time control') }}</h2>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <span role="radio" aria-checked="true" class="flex flex-col gap-1.5 rounded-lg bg-ground px-4 py-3.5 shadow-[inset_0_0_0_2px_#F7931A]">
                        <b class="font-display text-xl">1/day</b><span class="text-[13px]">{{ __('Daily chess') }}</span><span class="text-xs leading-normal text-ink-2">{{ __('1 move per day, at your pace') }}</span>
                    </span>
                    <a href="{{ route('chess.lobby') }}#online-h" class="flex flex-col gap-1.5 rounded-lg bg-ground px-4 py-3.5 text-ink shadow-ring hover:text-ink">
                        <span class="flex items-baseline justify-between"><b class="font-display text-xl">5+3</b><span class="text-[11px] text-ink-2">{{ __('friend') }}</span></span><span class="text-[13px]">{{ __('Blitz') }}</span><span class="text-xs leading-normal text-ink-2">{{ __('invite a friend who is online now') }}</span>
                    </a>
                    @foreach ([['15+10', __('Rapid'), __('15 minutes, +10 s per move')], ['1+0', __('Bullet'), __('1 minute, no increment')]] as [$tc, $name, $text])
                        <span role="radio" aria-checked="false" aria-disabled="true" class="flex flex-col gap-1.5 rounded-lg bg-ground px-4 py-3.5 text-ink-3 shadow-ring">
                            <span class="flex items-baseline justify-between"><b class="font-display text-xl">{{ $tc }}</b><span class="text-[11px]">{{ __('soon') }}</span></span><span class="text-[13px]">{{ $name }}</span><span class="text-xs leading-normal">{{ $text }}</span>
                        </span>
                    @endforeach
                </div>
            </section>

            {{-- Colour and game type --}}
            <section class="grid grid-cols-1 gap-5 rounded-lg bg-card px-4 py-5 lg:grid-cols-2 lg:px-6">
                <div class="flex flex-col gap-3">
                    <h2 id="col-h" class="m-0 text-[15px] font-bold">{{ __('Your color') }}</h2>
                    <div role="radiogroup" aria-labelledby="col-h" class="grid grid-cols-3 gap-2">
                        @foreach ($colorLabel as $value => $label)
                            <button type="button" role="radio" wire:click="$set('color', '{{ $value }}')" aria-checked="{{ $color === $value ? 'true' : 'false' }}" data-test="color-{{ $value }}"
                                    @class(['h-12 cursor-pointer rounded-md border bg-ground text-[13px] text-ink', 'border-btc' => $color === $value, 'border-line' => $color !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex flex-col gap-3">
                    <h2 id="gt-h" class="m-0 text-[15px] font-bold">{{ __('Game type') }}</h2>
                    <div role="radiogroup" aria-labelledby="gt-h" class="grid grid-cols-2 gap-1 rounded-lg bg-ground p-1 shadow-ring">
                        <span role="radio" aria-checked="true" class="flex flex-col gap-0.5 rounded-md bg-raised px-3.5 py-2 shadow-[inset_0_-2px_0_#F7931A]"><b class="text-[13px] text-btc-hi">{{ __('Casual') }}</b><span class="text-[11px] text-ink-2">{{ __('no rating') }}</span></span>
                        <span role="radio" aria-checked="false" aria-disabled="true" class="flex flex-col gap-0.5 px-3.5 py-2 opacity-60"><b class="text-[13px]">{{ __('Rated') }}</b><span class="text-[11px] text-ink-2">{{ __('from Season 1') }}</span></span>
                    </div>
                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('A casual game counts for no rating, Block Height or Clan Hashrate.') }}</p>
                </div>
            </section>

            {{-- Message --}}
            <section aria-labelledby="msg-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
                <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="msg-h" class="m-0 text-[15px] font-bold"><label for="challenge-message">{{ __('Message') }}</label></h2><span class="text-xs text-ink-2">{{ __('optional, up to 140 characters') }}</span></span>
                <textarea id="challenge-message" wire:model="message" maxlength="140" rows="2" data-test="challenge-message"
                          class="w-full resize-none rounded-lg border border-edge bg-ground px-3.5 py-3 text-sm text-ink placeholder:text-ink-3"></textarea>
                @error('message')<p class="m-0 text-[13px] text-loss" role="alert">{{ $message }}</p>@enderror
            </section>
        </div>

        {{-- Summary --}}
        <aside class="flex flex-col gap-4 self-start rounded-lg bg-card px-4 py-5 lg:sticky lg:top-6 lg:px-6" data-test="challenge-summary">
            @if ($opponent)
                <span class="flex items-start gap-3">
                    <x-avatar :name="$opponent->displayName()" :src="$opponent->avatarUrl()" :size="40" class="rounded-md" />
                    <span class="flex min-w-0 flex-col gap-1">
                        <span class="flex flex-wrap items-center gap-2"><b class="truncate text-[15px]">{{ $opponent->displayName() }}</b>@if ($opponent->is_member)<x-member-badge />@endif</span>
                        <span class="text-xs text-btc-hi">{{ __('daily Elo :elo, provisional', ['elo' => $rating]) }}@if ($opponent->clanMember?->clan) · {{ $opponent->clanMember->clan->name }}@endif</span>
                    </span>
                </span>
            @else
                <span class="text-[13px] text-ink-2">{{ __('Pick an opponent on the left.') }}</span>
            @endif

            <div class="flex flex-col">
                @foreach ([[__('Time control'), __('Daily chess (1 move/day)')], [__('Color'), $colorLabel[$color] ?? $color], [__('Game type'), __('Casual')], [__('Open until'), __(':hours h after sending', ['hours' => $hours])], [__('At stake'), __('nothing, casual')]] as [$key, $value])
                    <div class="flex h-10 items-center justify-between gap-3 border-b border-hairline text-[13px]"><span class="text-ink-2">{{ $key }}</span><span class="text-right">{{ $value }}</span></div>
                @endforeach
            </div>

            <button type="button" wire:click="send" @disabled(! $opponent) data-test="send-challenge"
                    class="btn-p inline-flex h-[52px] cursor-pointer items-center justify-center gap-2.5 rounded-md bg-btc px-5 text-[15px] font-bold text-on-btc disabled:cursor-not-allowed disabled:opacity-50">
                <x-icon name="shield-check" :size="18" />{{ __('Send challenge') }}
            </button>
            @if ($error)<p class="m-0 text-[13px] text-loss" role="alert">{{ $error }}</p>@endif
            <span class="text-xs leading-normal text-ink-2">
                {{ $opponent ? __(':name has :hours h to accept. You can withdraw the challenge while it is open.', ['name' => $opponent->displayName(), 'hours' => $hours]) : __('The other player has :hours h to accept.', ['hours' => $hours]) }}
            </span>
            <x-proof toggle="show" class="border-0 bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" :rows="[
                [__('Record'), __('a casual challenge stays with the league (no kind 2150)')],
                [__('Moves'), __('each move a NIP-64 note, signed by the player who makes it')],
                [__('From'), $me->shortNpub()],
                [__('To'), $opponent ? $opponent->shortNpub().' ('.$opponent->displayName().')' : '–'],
            ]" />
        </aside>
    </div>
</div>
