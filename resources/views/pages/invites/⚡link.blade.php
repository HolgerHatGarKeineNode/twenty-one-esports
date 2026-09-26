<?php

use App\Enums\InviteLinkType;
use App\Enums\JoinRequestStatus;
use App\Models\ChessGame;
use App\Models\ClanJoinRequest;
use App\Models\InviteLink;
use App\Models\InviteLinkUse;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Clans\ClanJoinRequests;
use App\Support\Clans\ClanRuleViolation;
use App\Support\Invites\InviteCopy;
use App\Support\Invites\InviteLinkRefused;
use App\Support\Invites\InviteLinks;
use App\Support\PageMeta;
use App\Support\PreSeason;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The invite landing `/i/{code}` (P6b), from InviteLanding.dc.html and
 * MobileInviteLanding.dc.html: who invites, to what, and one clear next step
 * for every state (open, own link, expired, taken, cancelled, a clan request
 * on its way).
 *
 * A guest logs in right here; the login button carries the code
 * (<x-nostr-login :invite>), so after login the player comes back and the
 * invite is taken at once (blitz, daily, clan request). A Rocket League
 * challenge waits for the taker to pick their lineup and a start.
 *
 * The page is never indexed; its title, description and preview image are
 * plain tags in the first response for Signal, Telegram and Nostr clients.
 */
new #[Layout('layouts::app')] class extends Component {
    #[Locked]
    public int $linkId;

    public ?int $lineupId = null;

    public ?int $start = null;

    public string $error = '';

    public function mount(InviteLink $link): void
    {
        $this->linkId = $link->id;
        $user = auth()->user();

        if ($user instanceof User) {
            $this->lineupId = $this->myLineups->first()?->id;
            $this->start = $this->proposals()[0] ?? null;
            $this->takeAfterLogin($user);
        }
    }

    /**
     * Back from the login this invite started: take it without another tap.
     */
    private function takeAfterLogin(User $user): void
    {
        $intent = session('invite.intent');

        if (! is_array($intent) || ($intent['code'] ?? null) !== $this->link->code) {
            return;
        }

        session()->forget('invite.intent');

        if ($this->link->type === InviteLinkType::Series || app(InviteLinks::class)->state($this->link, $user) !== 'open') {
            return;
        }

        $this->take(wasNew: $user->created_at !== null && $user->created_at->getTimestamp() >= (int) ($intent['seen_at'] ?? PHP_INT_MAX));
    }

    #[Computed]
    public function link(): InviteLink
    {
        return InviteLink::query()->with(['inviter.clanMember.clan', 'clan.members.user'])->findOrFail($this->linkId);
    }

    public function accept(): void
    {
        $this->take(wasNew: false);
    }

    private function take(bool $wasNew): void
    {
        $this->error = '';
        $user = $this->user();
        $choice = $this->link->type === InviteLinkType::Series ? ['lineup_id' => (int) $this->lineupId, 'start' => (int) $this->start] : [];

        try {
            $made = app(InviteLinks::class)->accept($this->link, $user, $choice, $wasNew);
        } catch (InviteLinkRefused $refused) {
            $this->error = $refused->getMessage();
            unset($this->link);

            return;
        }

        if ($made instanceof ChessGame) {
            $this->redirectRoute('games.show', $made);
        } elseif ($made instanceof SeriesMatch) {
            $this->redirectRoute('matches.room', $made);
        } else {
            unset($this->link);
        }
    }

    public function revoke(): void
    {
        try {
            app(InviteLinks::class)->revoke($this->link, $this->user());
        } catch (InviteLinkRefused $refused) {
            $this->error = $refused->getMessage();
        }

        unset($this->link);
    }

    public function withdrawRequest(): void
    {
        $request = $this->joinRequest();

        if ($request === null) {
            return;
        }

        try {
            app(ClanJoinRequests::class)->withdraw($request, $this->user());
        } catch (ClanRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }

        unset($this->link);
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $link = $this->link;
        $view->title((new InviteCopy($link))->title());

        // The preview speaks the inviter's language: it stands for their message.
        $locale = App::getLocale();
        $inviterLocale = $link->inviter->locale;

        if (is_string($inviterLocale) && in_array($inviterLocale, config('app.supported_locales', []), true)) {
            App::setLocale($inviterLocale);
        }

        $copy = new InviteCopy($link);
        $meta = app(PageMeta::class);
        $meta->noindex = true;
        $meta->title = $copy->title();
        $meta->description = $copy->description();
        $meta->url = $link->url();
        $meta->images = [
            [route('invites.card', ['code' => $link->code, 'format' => 'wide']), 1200, 630, $copy->cardAlt()],
            [route('invites.card', ['code' => $link->code, 'format' => 'square']), 1080, 1080, $copy->cardAlt()],
        ];

        App::setLocale($locale);
    }

    public function state(): string
    {
        return app(InviteLinks::class)->state($this->link, auth()->user());
    }

    public function linkUse(): ?InviteLinkUse
    {
        $user = auth()->user();

        return $user instanceof User ? app(InviteLinks::class)->useOf($this->link, $user)?->load(['game', 'match']) : null;
    }

    public function joinRequest(): ?ClanJoinRequest
    {
        $user = auth()->user();

        if (! $user instanceof User || $this->link->clan_id === null) {
            return null;
        }

        return ClanJoinRequest::query()->where(['clan_id' => $this->link->clan_id, 'user_id' => $user->id])
            ->with('clanInvite')->latest('id')->first();
    }

    /**
     * Rocket League: the viewer's lineups that can take this challenge.
     *
     * @return Collection<int, Lineup>
     */
    #[Computed]
    public function myLineups(): Collection
    {
        $user = auth()->user();
        $link = $this->link;

        if (! $user instanceof User || $link->type !== InviteLinkType::Series || $user->clanMember === null) {
            return collect();
        }

        return Lineup::query()->with(['clan', 'seats.user.clanMember'])
            ->where('clan_id', $user->clanMember->clan_id)
            ->where('game', (string) $link->option('game'))->where('mode', (string) $link->option('mode'))
            ->get()
            ->filter(fn (Lineup $lineup) => $lineup->isActingCaptain($user) && $lineup->isReady() && $lineup->id !== (int) $link->option('lineup_id'))
            ->values();
    }

    /**
     * @return list<int>
     */
    public function proposals(): array
    {
        return array_values(array_map(intval(...), (array) $this->link->option('proposals', [])));
    }

    public function when(?CarbonInterface $time): string
    {
        if ($time === null) {
            return '–';
        }

        $local = $time->copy()->timezone(PreSeason::timezoneFor(auth()->user() instanceof User ? auth()->user() : null))->locale(App::getLocale());

        return $local->isToday() ? __('Today :time', ['time' => $local->format('H:i')]) : $local->translatedFormat('D Y-m-d, H:i');
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $link = $this->link;
    $copy = new InviteCopy($link);
    $state = $this->state();
    $viewer = auth()->user();
    $inviter = $link->inviter;
    $type = $link->type;
    $clan = $type === InviteLinkType::Clan ? $link->clan : $copy->lineup()?->clan;
    $use = $state === 'taken' ? $this->linkUse() : null;
    $request = in_array($state, ['requested', 'taken'], true) && $type === InviteLinkType::Clan ? $this->joinRequest() : null;
    $closed = in_array($state, ['expired', 'used_up', 'revoked'], true);
    $mine = $viewer !== null && $inviter->is($viewer);
    $name = $inviter->displayName();
    $gamesPlayed = $inviter->whiteGames()->count() + $inviter->blackGames()->count();

    $linkKind = $type === InviteLinkType::Clan
        ? ($link->max_uses === 1 ? __('One player may ask') : __('Anyone with the link may ask'))
        : ($type === InviteLinkType::Series
            ? ($link->max_uses === 1 ? __('One team, one series') : __('Several teams, a series each'))
            : ($link->max_uses === 1 ? __('One friend, one game') : __('Several friends, a game each')));

    $heading = match (true) {
        $state === 'own' => __('Your invite is ready to share'),
        $mine && $state === 'used_up' => __('Your invite was taken'),
        $state === 'expired' => __('This invite has run out'),
        $state === 'used_up' => __('This invite is already taken'),
        $state === 'revoked' => __('This invite was cancelled'),
        $state === 'member' => __('You are in :clan', ['clan' => $clan?->name]),
        $request !== null || $state === 'requested' => __('You asked to join :clan', ['clan' => $clan?->name]),
        $state === 'taken' => __('You took this invite'),
        default => $copy->headline(),
    };

    $subline = match (true) {
        $state === 'own' => $type === InviteLinkType::Clan
            ? __('Send it around. Everyone who uses it asks to join, and a captain confirms each one.')
            : __('Send it to a friend. When they log in, they land right at your game.'),
        $state === 'expired' => __(':name’s invite was open until :time. Ask for a fresh link, or find a game right now.', ['name' => $name, 'time' => $this->when($link->expires_at)]),
        $state === 'used_up' && ! $mine => __('It was a one-time link, and another player took it. Ask :name for a fresh link, or find a game right now.', ['name' => $name]),
        $state === 'revoked' => __(':name cancelled this invite. Ask for a fresh link, or find a game right now.', ['name' => $name]),
        default => $copy->subline(),
    };

    $tag = match (true) {
        $state === 'expired' => [__('Expired'), 'warn'],
        $state === 'used_up' => [__('Taken'), 'warn'],
        $state === 'revoked' => [__('Cancelled'), 'warn'],
        $state === 'requested' => [__('Request sent'), 'plain'],
        $type === InviteLinkType::Clan => [__('Join request'), 'plain'],
        default => [__('Casual'), 'plain'],
    };

    $details = match ($type) {
        InviteLinkType::Blitz, InviteLinkType::Daily => [
            [__('Game'), $copy->gameChip()],
            [__('Game type'), __('Casual, no rating change')],
            [__('Colours'), match ($type === InviteLinkType::Daily ? (string) $link->option('color', 'random') : 'random') {
                'white' => __(':name plays White', ['name' => $name]),
                'black' => __(':name plays Black', ['name' => $name]),
                default => __('Random'),
            }],
        ],
        InviteLinkType::Series => [
            [__('Game'), __('Rocket League :mode, best of :bo', ['mode' => (string) $link->option('mode'), 'bo' => (int) $link->option('best_of')])],
            [__('Game type'), __('Casual scrim, no rating change')],
            [__('Against'), collect($copy->lineup()?->activeSeats() ?? [])->map(fn ($seat) => $seat->user->displayName())->implode(', ') ?: '–'],
        ],
        InviteLinkType::Clan => [
            [__('Clan'), ($clan?->name ?? '–').', '.($clan?->clantag ?? '')],
            [__('Meetup'), $clan?->meetup_name ?? '–'],
            [__('Players'), trans_choice(':count player|:count players', $clan?->members->count() ?? 0)],
        ],
    };
    $details[] = [__('Link'), $linkKind];
    $details[] = [__('Sent'), $this->when($link->created_at)];
    $details[] = $closed
        ? [__('Status'), match ($state) { 'expired' => __('Expired, nobody took it'), 'used_up' => __('Used, the link is closed'), default => __('Cancelled by :name', ['name' => $name]) }]
        : [__('Open until'), $this->when($link->expires_at)];

    $steps = match ($type) {
        InviteLinkType::Blitz => [[__('Continue with Google or Nostr'), __('New here? That creates your player. No password to remember.')], [__('You land at the board'), __('No lobby, no search. :name gets a ping that you are in.', ['name' => $name])], [__('Play your first game'), __('Casual games also build your trust, and trust opens rated play later.')]],
        InviteLinkType::Daily => [[__('Continue with Google or Nostr'), __('New here? That creates your player. No password to remember.')], [__('You land in the game'), __('Make your move whenever you like. You get a notification when it is your turn again.')], [__('Play your first game'), __('Casual games also build your trust, and trust opens rated play later.')]],
        InviteLinkType::Series => [[__('Continue with Google or Nostr'), __('New here? That creates your player. No password to remember.')], [__('Pick your lineup and a start'), __('Take the challenge with a lineup you captain. No team yet? Start a clan first.')], [__('Play the series'), __(':clan gets a ping, the match room opens for both teams.', ['clan' => $clan?->name])]],
        InviteLinkType::Clan => [[__('Continue with Google or Nostr'), __('New here? That creates your player. No password to remember.')], [__('Your request goes to :clan', ['clan' => $clan?->name]), __('A captain of :clan looks at your profile and confirms. You get a notification.', ['clan' => $clan?->name])], [__('Play for :clan', ['clan' => $clan?->name]), __('Casual games build your trust first.')]],
    };

    $closedCta = match ($type) {
        InviteLinkType::Blitz => [__('Find a blitz opponent'), route('chess.lobby'), __('Until then, the blitz queue pairs you with whoever is online.')],
        InviteLinkType::Daily => [__('Challenge someone to daily chess'), route('chess.challenge'), __('Until then, you can challenge any player to daily chess.')],
        InviteLinkType::Series => [__('See open matches'), route('matches.index'), __('Until then, look for clans to play on the match list.')],
        InviteLinkType::Clan => [__('Browse clans'), route('clans.index'), __('Clans take new players through open links too. Or start your own clan and invite friends.')],
    };

    $row = 'grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-[13px] lg:grid-cols-[124px_minmax(0,1fr)] lg:text-sm';
    $primary = 'btn-p inline-flex min-h-14 w-full cursor-pointer items-center justify-center gap-2.5 rounded-lg bg-btc px-5 text-[15px] font-bold text-on-btc hover:text-on-btc disabled:cursor-wait disabled:opacity-70';
    $secondary = 'btn-w inline-flex min-h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-lg border border-line bg-well px-4 text-[13px] text-ink hover:text-ink';
    $shareText = $copy->cardQuestion().' '.$copy->cardSubline();
@endphp

<div class="mx-auto flex w-full max-w-[1248px] grow flex-col gap-6 px-4 pb-10 lg:px-0 lg:pb-16" data-test="invite-landing" data-state="{{ $state }}">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_440px] lg:grid-rows-[auto_1fr] lg:gap-x-12">
        {{-- Main column: who, what, the stage (details below the panel on small screens, as MobileInviteLanding) --}}
        <div class="flex min-w-0 flex-col gap-5 lg:col-start-1 lg:row-start-1 lg:gap-6">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex h-7 items-center gap-2 rounded-md border border-line bg-well px-2.5 text-xs">
                    <x-icon :name="$type->isChess() ? 'pawn' : ($type === InviteLinkType::Series ? 'rocket-league' : 'clans')" :size="14" />{{ $copy->gameChip() }}
                </span>
                <span @class(['inline-flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-xs', 'border-line text-ink-2' => $tag[1] === 'plain', 'border-btc-deep bg-btc-chip font-bold text-btc-hi' => $tag[1] === 'warn'])>
                    @if ($tag[1] === 'warn')<x-icon name="clock" :size="14" />@endif{{ $tag[0] }}
                </span>
                @if ($mine)
                    <span class="inline-flex h-7 items-center gap-1.5 rounded-md bg-btc-chip px-2.5 text-xs text-btc-hi"><x-icon name="link" :size="14" />{{ __('Your invite') }}</span>
                @endif
            </div>

            <div class="flex flex-col gap-3 lg:gap-4">
                <h1 class="m-0 font-display text-[30px] leading-[1.08] font-bold break-words lg:text-[52px]" data-test="invite-heading">{{ $heading }}</h1>
                <p class="m-0 max-w-[560px] text-sm leading-[1.6] text-ink-2 lg:text-base">{{ $subline }}</p>
            </div>

            {{-- Stage: inviter, the game, the seat --}}
            <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,0.95fr)_minmax(0,1fr)] items-center gap-2.5 lg:grid-cols-[220px_40px_minmax(0,1fr)_40px_210px] lg:gap-0" data-test="invite-stage">
                <section aria-label="{{ __('Invited by :name', ['name' => $name]) }}" class="flex min-w-0 flex-col items-center gap-1.5 rounded-lg bg-card px-2 py-3 text-center lg:gap-2.5 lg:px-4 lg:py-6">
                    <x-avatar :user="$inviter" :size="88" class="size-14! rounded-lg lg:size-[88px]!" />
                    <x-player-link :user="$inviter" class="inline-flex min-h-11 max-w-full items-center font-display text-[13px] font-bold lg:text-xl"><span class="truncate">{{ $name }}</span></x-player-link>
                    <x-rank-badge tier="provisional" size="sm" />
                    <span class="hidden text-xs text-ink-2 lg:block">{{ trans_choice(':count game played|:count games played', $gamesPlayed) }}</span>
                    @if ($inviter->is_member)<x-member-badge long class="hidden lg:inline-flex" />@endif
                </section>

                <span class="hidden flex-col items-center gap-1 text-xs text-btc lg:flex" aria-hidden="true">{{ $type === InviteLinkType::Clan ? __('joins') : 'vs' }}<span class="block h-0.5 w-7 bg-[repeating-linear-gradient(90deg,#F7931A_0_6px,transparent_6px_10px)]"></span></span>

                <div class="flex min-w-0 flex-col items-center gap-2 lg:px-4">
                    @if ($type->isChess())
                        <div class="w-full max-w-[250px] pt-3 pr-3 lg:pt-4 lg:pr-4">
                            @include('pages.invites.partials.board', ['dim' => $closed])
                        </div>
                    @else
                        <span @class(['flex flex-col items-center gap-2', 'opacity-45 grayscale' => $closed])>
                            <span class="flex size-16 items-center justify-center overflow-hidden rounded-xl bg-[linear-gradient(135deg,#F9B25F,#F7931A_55%,#B9640A)] font-display text-sm font-extrabold text-on-btc lg:size-28 lg:text-2xl">
                                @if ($clan?->picture)<img src="{{ $clan->picture }}" alt="" class="size-full object-cover" loading="lazy" referrerpolicy="no-referrer">@else{{ $clan?->clantag }}@endif
                            </span>
                            <b class="max-w-full truncate text-center text-xs lg:text-sm">{{ $clan?->name }}</b>
                        </span>
                    @endif
                    <span class="hidden text-center text-xs text-ink-2 lg:block">{{ match ($type) {
                        InviteLinkType::Blitz => __('5+3, colours drawn at random'),
                        InviteLinkType::Daily => __('1 move a day'),
                        InviteLinkType::Series => __(':mode, best of :bo', ['mode' => (string) $link->option('mode'), 'bo' => (int) $link->option('best_of')]),
                        InviteLinkType::Clan => trans_choice(':count player|:count players', $clan?->members->count() ?? 0),
                    } }}</span>
                </div>

                <span class="hidden flex-col items-center gap-1 text-xs text-btc lg:flex" aria-hidden="true">{{ $type === InviteLinkType::Clan ? __('joins') : 'vs' }}<span class="block h-0.5 w-7 bg-[repeating-linear-gradient(90deg,#F7931A_0_6px,transparent_6px_10px)]"></span></span>

                @if ($viewer !== null && ! $mine && ! $closed)
                    <section aria-label="{{ __('You, :name', ['name' => $viewer->displayName()]) }}" class="flex min-w-0 flex-col items-center gap-1.5 rounded-lg bg-card px-2 py-3 text-center shadow-[inset_0_0_0_2px_#F7931A] lg:gap-2.5 lg:px-4 lg:py-6">
                        <x-avatar :user="$viewer" :size="88" class="size-14! rounded-lg lg:size-[88px]!" />
                        <b class="max-w-full truncate font-display text-[13px] lg:text-xl">{{ $viewer->displayName() }}</b>
                        <x-rank-badge tier="provisional" size="sm" />
                        <span class="text-xs text-btc-hi">{{ __('you') }}</span>
                    </section>
                @else
                    <section aria-label="{{ __('Your seat') }}" @class(['flex min-h-[150px] min-w-0 flex-col items-center justify-center gap-1.5 rounded-lg px-2 py-3 text-center lg:min-h-[260px] lg:gap-2.5 lg:px-4',
                        'border border-dashed border-edge' => ! $closed, 'border border-line bg-card' => $closed])>
                        <span @class(['flex size-12 items-center justify-center rounded-lg font-display text-2xl font-bold lg:size-[88px] lg:text-4xl', 'border border-dashed border-edge text-ink-3' => ! $closed, 'bg-raised text-ink-2' => $closed])>
                            @if ($closed)<x-icon name="lock" :size="22" />@else ?@endif
                        </span>
                        <b class="font-display text-[13px] lg:text-lg">{{ match (true) {
                            $state === 'expired', $state === 'revoked' => __('Seat closed'),
                            $state === 'used_up' => __('Seat taken'),
                            $mine => __('Waiting for a friend'),
                            $type === InviteLinkType::Clan => __('Your spot'),
                            $type === InviteLinkType::Series => __('Your team'),
                            default => __('Your seat'),
                        } }}</b>
                        <span class="text-[11px] leading-normal text-ink-2 lg:text-xs">{{ match (true) {
                            $state === 'expired' => __('The link ran out'),
                            $state === 'revoked' => __('The link was cancelled'),
                            $state === 'used_up' => __('Someone got here first'),
                            $mine => trans_choice('{0} Nobody has used it yet|{1} :count player used it|[2,*] :count players used it', $link->uses),
                            $type === InviteLinkType::Clan => __('On the :clan roster', ['clan' => $clan?->name]),
                            default => __('Log in to sit down'),
                        } }}</span>
                    </section>
                @endif
            </div>

            @if ($mine && ! $closed)
                <section aria-labelledby="pv-h" class="flex flex-col gap-3" data-test="invite-preview">
                    <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="pv-h" class="m-0 text-[15px] font-bold">{{ __('What your friend sees in the chat') }}</h2><span class="text-xs text-ink-3">{{ __('link preview') }}</span></span>
                    <img src="{{ route('invites.card', ['code' => $link->code, 'format' => 'wide']) }}" width="1200" height="630" alt="{{ $copy->cardAlt() }}" loading="lazy" class="block h-auto w-full max-w-[400px] rounded-lg shadow-ring">
                </section>
            @endif

        </div>

        {{-- Aside: the one next step --}}
        <aside class="flex flex-col gap-4 self-start rounded-lg bg-card px-4 py-6 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:px-8 lg:py-8" data-test="invite-panel">
            @if ($error !== '')
                <p class="m-0 flex items-start gap-2 rounded-md bg-loss-tint px-3.5 py-2.5 text-[13px] leading-normal text-ink shadow-[inset_0_0_0_1px_#5A2A2E]" role="alert" data-test="invite-error"><x-icon name="alert" :size="16" class="mt-px shrink-0 text-loss" />{{ $error }}</p>
            @endif

            @if ($viewer === null && ! $closed)
                {{-- Guest: log in here, come back into the game --}}
                <x-nostr-login :invite="$link->code" class="flex flex-col gap-4">
                    <h2 class="m-0 font-display text-2xl font-bold">{{ match ($type) { InviteLinkType::Clan => __('Ask to join :clan', ['clan' => $clan?->name]), InviteLinkType::Series => __('Take the challenge'), default => __('Take the seat') } }}</h2>
                    <button type="button" x-on:click="loginWithGoogle()" x-bind:disabled="busy" data-test="login-google" class="{{ $primary }} justify-start">
                        <x-icon name="google" />{{ __('Continue with Google') }}
                    </button>
                    <button type="button" x-on:click="loginWithNostr()" x-bind:disabled="busy" data-test="login-nostr"
                            class="btn-w flex min-h-14 w-full cursor-pointer items-center gap-3 rounded-lg border border-line bg-well px-4 py-2 text-left text-[13px] text-ink disabled:cursor-wait">
                        <x-icon name="key" :size="18" />
                        <span class="flex flex-col items-start gap-0.5"><span>{{ __('Continue with Nostr') }}</span><span class="text-[11px] text-ink-2">{{ __('browser extension or remote signer') }}</span></span>
                    </button>
                    <span class="flex items-center gap-1.5 text-xs text-btc-hi" role="status" x-show="busy" x-cloak>
                        <span class="inline-block size-[7px] animate-live rounded-full bg-btc-hi" aria-hidden="true"></span>{{ __('Waiting for your confirmation') }}
                    </span>
                    <p class="m-0 text-[13px] text-loss" role="alert" x-show="error" x-text="error" x-cloak></p>
                    <span class="flex items-start gap-2 text-xs leading-normal text-ink-2"><x-icon name="link" :size="14" class="mt-0.5 shrink-0" />{{ $linkKind }}. {{ __('Open until :time.', ['time' => $this->when($link->expires_at)]) }}</span>
                </x-nostr-login>
                <div class="flex flex-col gap-3.5 border-t border-hairline pt-4">
                    <h3 class="m-0 text-[13px] font-bold">{{ __('What happens next') }}</h3>
                    <ol class="m-0 flex list-none flex-col gap-3.5 p-0">
                        @foreach ($steps as $index => [$title, $text])
                            <li class="flex gap-3"><span class="flex size-7 shrink-0 items-center justify-center rounded-md border border-btc-deep font-display text-[13px] font-bold text-btc-hi">{{ $index + 1 }}</span>
                                <span class="flex flex-col gap-1"><b class="text-[13px]">{{ $title }}</b><span class="text-xs leading-normal text-ink-2">{{ $text }}</span></span></li>
                        @endforeach
                    </ol>
                </div>
            @elseif ($state === 'own')
                {{-- The inviter: share it --}}
                <div class="flex flex-col gap-4" data-test="invite-share"
                     x-data="{ copied: false, hint: '', canShare: typeof navigator.share === 'function', url: @js($link->url()), text: @js($shareText),
                               async copy(hint = '') { try { await navigator.clipboard.writeText(this.url); this.copied = true; this.hint = hint; setTimeout(() => this.copied = false, 2500); } catch (e) { this.hint = @js(__('Copy did not work here. Select the link and copy it.')); } },
                               async share(hint) { if (this.canShare) { try { await navigator.share({ title: document.title, text: this.text, url: this.url }); return; } catch (e) { if (e?.name === 'AbortError') return; } } await this.copy(hint); } }">
                    <h2 class="m-0 font-display text-2xl font-bold">{{ __('Share your invite') }}</h2>
                    <label for="invite-url" class="text-xs text-ink-2">{{ __('Invite link') }}</label>
                    <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                        <input id="invite-url" type="text" readonly value="{{ $link->url() }}" x-on:focus="$el.select()" data-test="invite-url"
                               class="h-12 min-w-0 rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink">
                        <button type="button" x-on:click="copy()" data-test="copy-link"
                                class="btn-p inline-flex h-12 cursor-pointer items-center gap-2 rounded-lg px-4 text-sm font-bold whitespace-nowrap"
                                x-bind:class="copied ? 'bg-win-tint text-win shadow-[inset_0_0_0_1px_#1F5A34]' : 'bg-btc text-on-btc'">
                            <span x-show="! copied" class="inline-flex items-center gap-2"><x-icon name="copy" :size="16" /><span class="sm:hidden">{{ __('Copy') }}</span><span class="max-sm:hidden">{{ __('Copy link') }}</span></span>
                            <span x-show="copied" x-cloak class="inline-flex items-center gap-2"><x-icon name="check" :size="16" />{{ __('Copied') }}</span>
                        </button>
                    </div>
                    <span class="text-xs text-win" role="status" x-show="copied || hint" x-text="hint || @js(__('Link copied. Paste it in any chat.'))" x-cloak></span>

                    <span class="text-xs text-ink-2">{{ __('Share to') }}</span>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" x-on:click="share(@js(__('Link copied. Paste it into a note in your Nostr app.')))" class="{{ $secondary }}"><x-icon name="chat" :size="16" />Nostr</button>
                        <button type="button" x-on:click="share(@js(__('Link copied. Paste it in Signal.')))" class="{{ $secondary }}"><x-icon name="send" :size="16" />Signal</button>
                        <a href="https://t.me/share/url?url={{ urlencode($link->url()) }}&amp;text={{ urlencode($shareText) }}" target="_blank" rel="noopener noreferrer" class="{{ $secondary }}"><x-icon name="send" :size="16" />Telegram</a>
                        <button type="button" x-show="canShare" x-on:click="share('')" class="{{ $secondary }}" data-test="native-share"><x-icon name="link" :size="16" />{{ __('More apps') }}</button>
                    </div>

                    <div class="flex flex-col gap-2 border-t border-hairline pt-4 text-xs leading-normal">
                        <span class="flex items-start gap-2"><x-icon name="clock" :size="14" class="mt-0.5 shrink-0" />{{ __('Open until :time.', ['time' => $this->when($link->expires_at)]) }} {{ $linkKind }}.</span>
                        <span class="text-ink-2">{{ __('Invites never count toward ratings, Hashrate, blocks or rewards.') }}</span>
                    </div>
                    <button type="button" wire:click="revoke" wire:confirm="{{ __('Cancel this invite? The link stops working at once.') }}" data-test="revoke-link"
                            class="inline-flex min-h-12 w-full cursor-pointer items-center justify-center rounded-lg border border-[#5A2A2E] bg-transparent text-[13px] text-loss">{{ __('Cancel this invite') }}</button>
                </div>
            @elseif ($closed || $state === 'used_up')
                <h2 class="m-0 font-display text-2xl font-bold">{{ $mine ? __('Make a new one') : __('Play anyway') }}</h2>
                <p class="m-0 text-[13px] leading-[1.6] text-ink-2">{{ $mine ? __('A fresh link works the same way.') : __('A new link from :name works the same way.', ['name' => $name]).' '.$closedCta[2] }}</p>
                <a href="{{ $closedCta[1] }}" class="{{ $primary }}" data-test="closed-cta">{{ $closedCta[0] }}</a>
                @unless ($mine)
                    <a href="{{ route('players.show', $inviter->npub) }}" class="{{ $secondary }}">{{ __('See :name’s profile', ['name' => $name]) }}</a>
                @endunless
            @elseif ($state === 'member')
                <h2 class="m-0 font-display text-2xl font-bold">{{ __('Welcome aboard') }}</h2>
                <p class="m-0 text-[13px] leading-[1.6] text-ink-2">{{ __('You are on the :clan roster.', ['clan' => $clan?->name]) }}</p>
                <a href="{{ route('clans.show', $clan) }}" class="{{ $primary }}">{{ __('Go to :clan', ['clan' => $clan?->name]) }}</a>
            @elseif ($request !== null)
                {{-- The clan request on its way, step by step (NIP decision (a)) --}}
                @php
                    [$title, $lead, $text] = match ($request->status) {
                        JoinRequestStatus::Pending => [__('Waiting for a captain'), __('Request sent. A captain of :clan will confirm.', ['clan' => $clan?->name]), __('Sent :time. You get a notification when they answer.', ['time' => $this->when($request->created_at)])],
                        JoinRequestStatus::Approved => [__('Waiting for the founder'), __('A captain said yes.'), __('The founder of :clan adds you to the clan record with their key next. You get a notification.', ['clan' => $clan?->name])],
                        JoinRequestStatus::Listed => [__('Confirm and join'), __(':clan said yes.', ['clan' => $clan?->name]), __('Confirm your membership to join the roster. You can be in one clan at a time.')],
                        JoinRequestStatus::Withdrawn => [__('Request withdrawn'), __('You took your request back.'), __('Ask a captain for a new link if you change your mind.')],
                        default => [__('Not this time'), __(':clan declined your request.', ['clan' => $clan?->name]), __('You can keep playing casual games, or ask another clan.')],
                    };
                    $good = in_array($request->status, [JoinRequestStatus::Pending, JoinRequestStatus::Approved, JoinRequestStatus::Listed], true);
                @endphp
                <h2 class="m-0 font-display text-2xl font-bold" data-test="request-state" data-status="{{ $request->status->value }}">{{ $title }}</h2>
                <div @class(['flex items-start gap-3 rounded-lg px-4 py-3.5', 'bg-win-tint shadow-[inset_0_0_0_1px_#1F5A34]' => $good, 'bg-well shadow-ring' => ! $good])>
                    <x-icon :name="$good ? 'check' : 'close'" :size="16" @class(['mt-0.5 shrink-0', 'text-win' => $good, 'text-ink-2' => ! $good]) />
                    <span class="flex flex-col gap-1"><b class="text-[13px]">{{ $lead }}</b><span class="text-xs leading-normal text-ink-2">{{ $text }}</span></span>
                </div>
                @if ($request->status === JoinRequestStatus::Listed && $request->clanInvite)
                    <a href="{{ route('invites.show', $request->clanInvite) }}" class="{{ $primary }}" data-test="confirm-join">{{ __('Confirm and join') }}</a>
                @else
                    <p class="m-0 text-[13px] leading-[1.6] text-ink-2">{{ __('No need to wait around: casual games work right away and build your trust.') }}</p>
                    <a href="{{ route('chess.lobby') }}" class="{{ $primary }}"><x-icon name="pawn" :size="18" />{{ __('Play a casual game') }}</a>
                @endif
                @if ($request->status->isOpen())
                    <button type="button" wire:click="withdrawRequest" class="{{ $secondary }}" data-test="withdraw-request">{{ __('Withdraw request') }}</button>
                @endif
            @elseif ($state === 'taken' && $use !== null)
                <h2 class="m-0 font-display text-2xl font-bold">{{ __('You are in') }}</h2>
                <p class="m-0 text-[13px] leading-[1.6] text-ink-2">{{ __('You took this invite :time.', ['time' => $this->when($use->created_at)]) }}</p>
                @if ($use->game)
                    <a href="{{ route('games.show', $use->game) }}" class="{{ $primary }}" data-test="go-to-game">{{ __('Go to the game') }}</a>
                @elseif ($use->match)
                    <a href="{{ route('matches.room', $use->match) }}" class="{{ $primary }}" data-test="go-to-game">{{ __('Go to the match room') }}</a>
                @endif
            @elseif ($type === InviteLinkType::Series)
                {{-- Rocket League: pick your lineup and a start --}}
                <h2 class="m-0 font-display text-2xl font-bold">{{ __('Take the challenge') }}</h2>
                @if ($this->myLineups->isEmpty())
                    <p class="m-0 text-[13px] leading-[1.6] text-ink-2" data-test="no-lineup">{{ __('You need a ready :mode lineup you captain, in another clan, to take this challenge.', ['mode' => (string) $link->option('mode')]) }}</p>
                    <a href="{{ $viewer->clanMember ? route('clans.manage', $viewer->clanMember->clan) : route('clans.create') }}" class="{{ $primary }}">{{ $viewer->clanMember ? __('Set up your lineup') : __('Start a clan') }}</a>
                @else
                    <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                        <legend class="pb-2 text-xs text-ink-2">{{ __('Your lineup') }}</legend>
                        @foreach ($this->myLineups as $lineup)
                            <label wire:key="lu-{{ $lineup->id }}" class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-line bg-ground px-4 text-[13px] has-checked:border-btc">
                                <input type="radio" wire:model="lineupId" value="{{ $lineup->id }}" class="accent-btc">{{ $lineup->clan->name }} · {{ $lineup->mode }}
                            </label>
                        @endforeach
                    </fieldset>
                    <fieldset class="m-0 flex flex-col gap-2 border-0 p-0">
                        <legend class="pb-2 text-xs text-ink-2">{{ __('Start') }}</legend>
                        @foreach ($this->proposals() as $proposal)
                            <label wire:key="st-{{ $proposal }}" class="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-line bg-ground px-4 text-[13px] has-checked:border-btc">
                                <input type="radio" wire:model="start" value="{{ $proposal }}" class="accent-btc">{{ $this->when(now()->setTimestamp($proposal)) }}
                            </label>
                        @endforeach
                    </fieldset>
                    <button type="button" wire:click="accept" wire:loading.attr="disabled" class="{{ $primary }}" data-test="accept-invite">{{ __('Accept') }}</button>
                @endif
            @elseif ($type === InviteLinkType::Clan)
                <h2 class="m-0 font-display text-2xl font-bold">{{ __('Ask to join :clan', ['clan' => $clan?->name]) }}</h2>
                <p class="m-0 text-[13px] leading-[1.6] text-ink-2">{{ __('A captain of :clan confirms your request. You can be in one clan at a time.', ['clan' => $clan?->name]) }}</p>
                <button type="button" wire:click="accept" wire:loading.attr="disabled" class="{{ $primary }}" data-test="accept-invite">{{ __('Send join request') }}</button>
            @else
                <h2 class="m-0 font-display text-2xl font-bold">{{ __('Ready to play?') }}</h2>
                <button type="button" wire:click="accept" wire:loading.attr="disabled" class="{{ $primary }}" data-test="accept-invite">{{ __('Accept') }}</button>
                <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center justify-center text-[13px] text-btc">{{ __('Not now') }}</a>
                <p class="m-0 text-xs leading-normal text-ink-2">{{ $type === InviteLinkType::Blitz ? __('Accepting opens the board right away. :name gets a ping.', ['name' => $name]) : __('Accepting starts the daily game. :name gets a notification.', ['name' => $name]) }}</p>
            @endif
        </aside>

            <div class="grid grid-cols-1 gap-x-8 self-start rounded-lg bg-card px-4 py-2 lg:col-start-1 lg:row-start-2 lg:grid-cols-2 lg:px-6" data-test="invite-details">
            @foreach ($details as [$key, $value])
                <div class="{{ $row }}"><span class="text-ink-2">{{ $key }}</span><span @class(['min-w-0 break-words', 'text-btc-hi' => $key === __('Status') || $key === __('Open until') && $closed])>{{ $value }}</span></div>
            @endforeach
        </div>
    </div>
</div>
