<?php

use App\Models\Season;
use App\Models\SeasonBlockVoid;
use App\Models\SeasonPayout;
use App\Models\TrustRank;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Cards\ShareCard;
use App\Support\Cards\ShareMoments;
use App\Support\Chess\RatedChess;
use App\Support\Engagement\HomeBoard;
use App\Support\FairPlay\FairPlay;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\PageMeta;
use App\Support\PreSeason;
use App\Support\Prizes\PoolInvoices;
use App\Support\QrCode;
use App\Support\Rating\LadderBoard;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\OpponentLists;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * /mining, the Season page (Mining.dc.html, MobileMining.dc.html), from the
 * real chain (P7c, P33): the supply mined over time with the forecast, the
 * era schedule with what a win pays, the latest blocks, the top miners, why
 * wins did not mine, the league reserve with its zaps, the payout rules, the
 * season review and the rules in force with their change log. Between
 * seasons: the ended season as it closed. Before Block 0: the rest state
 * with the countdown and the chain draft of the board (ChainDraft), which it
 * releases.
 *
 * Amounts are configured, mined, or received per zap; no pot ever shows a
 * wallet balance. The reserve is zapped through a QR code of the league's
 * LNURL, never its Lightning address as text (P39).
 *
 * P37: between seasons the review's corrections (block and public reason)
 * and the season payouts that were paid, each with its Payout (2157); paid
 * amounts only, never what is still owed or what a wallet holds.
 */
new #[Layout('layouts::app', ['section' => 'mining'])] class extends Component
{
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Season'));
        app(PageMeta::class)->describe(__('Season'), __('Every fair rated win is a block, counted in the order the league saves results. The reward of a game halves as that game mines its share; the sats are paid once, after the season review.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('mining'));
    }

    /**
     * The live season, else the latest ended one as it closed, else the draft.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function chain(): array
    {
        $season = Seasons::live();

        if ($season !== null) {
            return app(ChainOverview::class)->live($season);
        }

        $ended = Seasons::latest();

        return $ended === null ? app(ChainOverview::class)->draft() : app(ChainOverview::class)->ended($ended);
    }

    /**
     * The ended season's corrections, paid payouts and what is owed (P37),
     * null while a season is live or before Block 0. Before the approval
     * `owed` is the review's replay with the voids so far (it may still
     * change); after it, the approved payouts. Amounts owed or paid only,
     * never a wallet balance.
     *
     * @return array{paid: Collection<int, SeasonPayout>, voids: Collection<int, SeasonBlockVoid>, approved: bool, owed: int, owed_players: int, paid_sats: int}|null
     */
    #[Computed]
    public function settlement(): ?array
    {
        $season = $this->chain['season'];

        if (! $season instanceof Season || Seasons::state() !== 'between') {
            return null;
        }

        $paid = SeasonSettlement::paid($season);
        $approved = $season->settlement_approved_at !== null;

        if ($approved) {
            $owed = (int) $season->payouts()->sum('amount_sats');
            $players = $season->payouts()->count();
        } else {
            $rows = array_filter(app(SeasonSettlement::class)->review($season)['rows'], fn (array $row): bool => $row['payout'] > 0);
            $owed = array_sum(array_column($rows, 'payout'));
            $players = count($rows);
        }

        return ['paid' => $paid, 'voids' => $season->blockVoids()->orderBy('height')->get(), 'approved' => $approved,
            'owed' => $owed, 'owed_players' => $players, 'paid_sats' => (int) $paid->sum('amount_sats')];
    }

    /**
     * The viewer's own standing for rated chess in a live season (plan "mining-page-clarity" P2, canvas SeasonLive,
     * NEU (Scope) approved 2026-11-20): the trust rank from the member network against the minimum, the players they
     * list each other with, and their chess blocks today against the daily limit. Only the viewer sees it, never a
     * guest or another player; a chip that blocks rated play links to its fix. Scalars only.
     *
     * @return list<array{key: string, state: 'ok'|'bad'|'wait', label: string, href: string|null, cta: string|null, title: string|null}>|null
     */
    #[Computed]
    public function standing(): ?array
    {
        $user = auth()->user();
        $season = Seasons::live();

        if (! $user instanceof User || $season === null) {
            return null;
        }

        // The same facts the gate reads (AnchoredTrustFacts: the published rank, the archived lists), read once each.
        $facts = app(TrustFacts::class);
        $minimum = RatedTrustGate::minimum();
        $chips = [];

        if (! $facts->available()) {
            $chips[] = ['key' => 'trust', 'state' => 'wait', 'label' => __('Trust ranks not computed yet'), 'href' => null, 'cta' => null, 'title' => RatedTrustGate::message(RatedTrustGate::NOT_COMPUTED)];
        } elseif (($barred = FairPlay::message($user->pubkey, $user)) !== null) {
            $chips[] = ['key' => 'trust', 'state' => 'bad', 'label' => __('Barred from rated play'), 'href' => null, 'cta' => null, 'title' => $barred];
        } else {
            $rank = (int) (TrustRank::query()->where('pubkey', $user->pubkey)->value('rank') ?? 0);
            $trusted = $rank >= $minimum;
            $chips[] = ['key' => 'trust', 'state' => $trusted ? 'ok' : 'bad', 'label' => __('Trust rank :rank, needs :minimum', ['rank' => $rank, 'minimum' => $minimum]),
                'href' => $trusted ? null : route('settings.opponents'), 'cta' => $trusted ? null : __('How it rises'),
                'title' => $trusted ? null : __('Rated from trust rank :minimum. It rises when members add you as an opponent.', ['minimum' => $minimum])];
        }

        // Mutual: on my newest list, and their newest list names me (Opponents::mutual(), in two reads instead of one per entry).
        $lists = OpponentLists::forLeague();
        $mutual = $lists === null ? 0 : count(array_intersect(app(Opponents::class)->entries($user), $lists->listing($user->pubkey)));
        $chips[] = $mutual > 0
            ? ['key' => 'opponents', 'state' => 'ok', 'label' => trans_choice(':count mutual opponent|:count mutual opponents', $mutual), 'href' => null, 'cta' => null, 'title' => null]
            : ['key' => 'opponents', 'state' => 'bad', 'label' => __('No mutual opponent'), 'href' => route('settings.opponents'), 'cta' => __('Add one'), 'title' => __('Rated needs an opponent who adds you back.')];

        $inForce = $this->chain['in_force'];
        $limit = $inForce->dailyLimitFor('chess');

        if ($limit !== null) {
            // Consensus rule 5 counts a player's blocks of the game's share group per UTC day.
            $shareKey = $inForce->shareKey('chess');
            $today = $season->attestations()->whereNotNull('height')->where('attested_at', '>=', CarbonImmutable::now()->utc()->startOfDay())->get(['game', 'candidate'])
                ->filter(fn ($block): bool => $inForce->shareKey((string) $block->game) === $shareKey && in_array($user->pubkey, $block->winners(), true))->count();
            $chips[] = ['key' => 'today', 'state' => $today >= $limit ? 'bad' : 'ok', 'label' => __(':game today :count of :limit blocks', ['game' => ChainOverview::gameLabel($shareKey), 'count' => $today, 'limit' => $limit]),
                'href' => null, 'cta' => null, 'title' => $today >= $limit ? __('The daily limit is reached; the next block counts from midnight UTC.') : null];
        }

        return $chips;
    }

    /**
     * The cheapest and the dearest win that mines now, over every mode of every game with a share: the figures the
     * stream's season slides read from the same ChainOverview::keys() rows (SeasonSlides::sharePays()).
     *
     * @param  array<string, array{modes: array<string, array{pays: int}>}>  $keys
     * @return array{0: int, 1: int}|null
     */
    protected function winRange(array $keys): ?array
    {
        $pays = [];

        foreach ($keys as $key) {
            foreach ($key['modes'] as $weightKey => $mode) {
                if (ChainOverview::mines((string) $weightKey) && $mode['pays'] > 0) {
                    $pays[] = (int) $mode['pays'];
                }
            }
        }

        return $pays === [] ? null : [min($pays), max($pays)];
    }

    /**
     * The ladders of the league per game (Season.dc.html "Ladders"): the registry's display order, each game's modes
     * and the page its first ladder opens; the score games keep leaderboards, not ladders.
     *
     * @return list<array{slug: string, name: string, modes: string, href: string}>
     */
    #[Computed]
    public function ladders(): array
    {
        $registry = app(\App\Games\GameRegistry::class);
        $rows = [];

        foreach ($registry->all() as $slug => $game) {
            $row = match ($game->kind()) {
                \App\Games\GameKind::Score => null,
                \App\Games\GameKind::Strategy => \Illuminate\Support\Facades\Route::has('hyper.ladder') ? ['modes' => __('Season ladder'), 'href' => route('hyper.ladder')] : null,
                \App\Games\GameKind::Arcade => \Illuminate\Support\Facades\Route::has('pong.ladder') ? ['modes' => 'Elo', 'href' => route('pong.ladder')] : null,
                default => (function () use ($slug, $game): ?array {
                    $modes = $slug === 'chess' ? \App\Support\Chess\ChessModes::all() : array_keys($game->modes());

                    return $modes === [] ? null : [
                        'modes' => implode(', ', array_map(fn (string $mode): string => $slug === 'chess' ? \App\Support\Chess\ChessModes::short($mode) : GameNames::mode($slug, $mode), $modes)),
                        'href' => route('ladder.show', [$slug, $modes[0]]),
                    ];
                })(),
            };

            if ($row !== null) {
                $rows[] = ['slug' => $slug, 'name' => GameNames::game($slug), ...$row];
            }
        }

        return $rows;
    }

    /**
     * @return array{count: int, latest: list<array{name: string, sats: int, at: \Carbon\CarbonImmutable}>}
     */
    #[Computed]
    public function zaps(): array
    {
        return app(ChainOverview::class)->reserveZaps();
    }

    /**
     * The QR code (SVG) of the league's LNURL, the pool key's Lightning
     * address `pool@<host>` that takes zaps into the reserve; null while
     * that endpoint takes no payments.
     */
    #[Computed]
    public function reserveZapQr(): ?string
    {
        return PoolInvoices::receives() ? QrCode::svg('lightning:'.PoolInvoices::lnurl(), label: __('QR code to zap the league reserve')) : null;
    }
}; ?>

@php
    $viewer = auth()->user();
    $zone = PreSeason::timezoneFor($viewer instanceof User ? $viewer : null);
    $chain = $this->chain;
    $state = Seasons::state();
    $live = $state === 'live';
    $ended = $state === 'between';
    $hasChain = $chain['season'] !== null;
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $date = fn ($at, string $format = 'D j M, H:i'): string => $at->copy()->setTimezone($zone)->locale(app()->getLocale())->translatedFormat($format);
    $inForce = $chain['in_force'];
    $games = array_keys($inForce->shares + $inForce->daily);
    $zaps = $this->zaps;
    $settlement = $this->settlement;
    $unmined = $hasChain ? max(0, $chain['supply'] - ($live ? $chain['estimate']['end_mined'] : $chain['mined'])) : null;
    $block0At = $live || $ended ? null : PreSeason::block0At();
    // Live: the state, not the season's name ("Pre-Season" read as "not started yet" after Block 0, plan P2).
    $stateTag = match (true) {
        $live => __('Live since :date', ['date' => $date($chain['season']->genesis_at, 'j M')]),
        $ended => __('Between seasons'),
        default => __('Pre-Season'),
    };
    // The era table's short heads (Season.dc.html: "RL 1v1", "Schach Blitz"): the registry's short name and the mode.
    $registry = app(\App\Games\GameRegistry::class);
    $shortKey = function (string $key) use ($registry): string {
        [$game, $mode] = array_pad(explode('/', $key, 2), 2, null);
        $found = $registry->find($game);
        if ($found === null || $mode === null || $game === 'chess') {
            return ChainOverview::keyLabel($key);
        }

        return __($found->assets()->shortLabel).' '.($registry->isBoard($game) ? GameNames::mode($game, $mode) : $mode);
    };
    $payKey = collect(array_keys($chain['rewards_now']))->first(fn (string $key): bool => ChainOverview::mines($key));
    $firstPay = $payKey === null ? 0 : (int) ($chain['schedule'][0]['rewards'][$payKey] ?? 0);
    // Block 0 signs the shares; before it they are the draft's and can still change.
    $shareTerm = $live || $ended ? __('Share of the supply, fixed at Block 0') : __('Share of the supply');
    // The share key of every game and mode: a game stands in the era of its own key (`season-chain-v2`).
    $shareOf = [];
    foreach (array_keys($chain['rewards_now']) as $weightKey) {
        $shareOf[$weightKey] = $inForce->shareKey(explode('/', (string) $weightKey, 2)[0]);
    }
@endphp

{{--
    The Season (Season.dc.html, SeasonPhone.dc.html; template T14; rules R1, R2, R7, R14): the name with its state, "gerade
    eben", before Block 0 the countdown hero with the genesis block, the chain stats, what a win pays per era, then the
    ladders, the latest blocks and the advanced statistics on the left, the strongest players, the league reserve and
    the payouts on the right, the Nostr line last. Between seasons the review and the payouts follow.
--}}
<div class="flex grow flex-col gap-4 px-4 pt-4 pb-10 lg:gap-6 lg:px-12 lg:pt-8" data-test="mining" data-state="{{ $state }}">
    <div class="flex flex-wrap items-center gap-4">
        <h1 class="rv-h1 max-lg:text-[24px]">{{ __('Season') }}</h1>
        <span @class(['rv-tag', 'is-ok' => $live]) data-test="season-state">{{ $stateTag }}</span>
    </div>

    <x-ui.live-bar :ticks="(new HomeBoard($viewer instanceof User ? $viewer : null))->ticker()" />

    @if ($live)
        @php
            $standing = $this->standing;
            $tip = (int) $chain['blocks'];
        @endphp
        {{-- The live hero (canvas SeasonLive, plan "mining-page-clarity" P2, rules R3, R14, R15): what to do to mine, the one
             primary action, the way to how it works, and the viewer's own standing (NEU (Scope), approved 2026-11-20). --}}
        <section aria-labelledby="mine-h" class="sn-hero" data-test="mining-hero">
            <div class="flex min-w-0 flex-col gap-4">
                <h2 id="mine-h" class="m-0 max-w-[26ch] font-display text-[22px] leading-[1.15] font-bold lg:text-[36px]">{{ __('Win a rated game, mine the next block') }}</h2>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    @if ($viewer instanceof User)
                        <a href="{{ route('chess.lobby') }}" class="rv-b h-[52px] px-6" data-test="mining-primary" data-variant="rated">{{ RatedChess::offered() ? __('Play rated chess') : __('Play a casual game') }}</a>
                    @else
                        <a href="{{ route('login') }}" class="rv-b h-[52px] px-6" data-test="mining-primary" data-variant="login">{{ __('Log in to mine') }}</a>
                    @endif
                    <a href="#season-reference" class="rv-lk inline-flex min-h-11 items-center text-[13px]" data-test="mining-how"
                       x-data x-on:click="const d = document.getElementById('season-reference'); (d?.tagName === 'DETAILS' ? d : d?.querySelector('details'))?.setAttribute('open', '')">{{ __('How mining works') }}</a>
                </div>
                @if ($standing !== null)
                    <ul class="m-0 flex list-none flex-wrap gap-2 p-0" aria-label="{{ __('Your standing for rated play') }}" data-test="mining-standing">
                        @foreach ($standing as $chip)
                            @php
                                $chipClass = ['inline-flex min-h-11 items-center gap-2 rounded-[4px] border px-3 text-[13px] whitespace-nowrap',
                                    'border-win-ring bg-win-tint' => $chip['state'] === 'ok', 'border-live-ring bg-live-tint' => $chip['state'] === 'bad', 'border-line bg-ground text-ink-2' => $chip['state'] === 'wait'];
                            @endphp
                            <li class="flex min-w-0" data-test="standing-{{ $chip['key'] }}" data-state="{{ $chip['state'] }}">
                                @if ($chip['href'] !== null)
                                    <a href="{{ $chip['href'] }}" @class($chipClass) @if ($chip['title']) title="{{ $chip['title'] }}" @endif>
                                        <x-icon name="alert" :size="14" class="shrink-0 text-loss" /><span>{{ $chip['label'] }}</span> <span class="text-loss underline underline-offset-2">{{ $chip['cta'] }}</span>
                                    </a>
                                @else
                                    <span @class($chipClass) @if ($chip['title']) title="{{ $chip['title'] }}" @endif>
                                        <x-icon :name="match ($chip['state']) { 'ok' => 'check', 'bad' => 'alert', default => 'clock' }" :size="14" :class="match ($chip['state']) { 'ok' => 'shrink-0 text-win', 'bad' => 'shrink-0 text-loss', default => 'shrink-0' }" /><span>{{ $chip['label'] }}</span>
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="m-0 max-w-[60ch] text-xs text-ink-2" data-test="mining-guest-note">{{ __('Rated play needs trust rank :minimum and an opponent who adds you back.', ['minimum' => RatedTrustGate::minimum()]) }}</p>
                @endif
            </div>
            {{-- The tip of the chain and the blocks still to come: the next one waits for a fair rated win. --}}
            <div class="flex items-center max-lg:hidden" role="img" aria-label="{{ __('Chain: block :tip is the newest, block :next waits for the next fair rated win', ['tip' => $tip, 'next' => $tip + 1]) }}">
                <span class="sn-genesis"><span class="rv-cube-tp"></span><span class="rv-cube-sd"></span>
                    <span class="sn-genesis-fr" data-test="tip-cube"><b class="text-xs">{{ $tip === 0 ? __('Genesis') : __('Newest') }}</b><span class="font-display text-[18px] leading-[1.2] font-bold whitespace-nowrap">{{ __('Block :height', ['height' => $tip]) }}</span><span class="text-xs leading-[1.2]">{{ $date($chain['last_block_at'] ?? $chain['season']->genesis_at, 'j M, H:i') }}</span></span>
                </span>
                @foreach ([66, 60, 54] as $index => $size)
                    <span class="sn-link" aria-hidden="true"></span>
                    <span class="sn-ghost" style="width: {{ $size }}px; height: {{ $size }}px; opacity: {{ 0.85 - $index * 0.15 }}" aria-hidden="true">{{ $tip + $index + 1 }}</span>
                @endforeach
            </div>
        </section>
    @else
        <section aria-labelledby="b0-h" class="sn-hero" data-test="mining-rest">
            <div class="flex min-w-0 flex-col gap-3">
                <span class="rv-tag is-btc self-start">{{ $ended ? __('Between seasons') : __('Pre-Season · countdown to Block 0') }}</span>
                <h2 id="b0-h" class="m-0 font-display text-[24px] leading-[1.1] font-bold lg:text-[36px]">{{ $ended ? __('The chain rests between seasons') : __('The chain starts at Block 0') }}</h2>
                <p class="m-0 max-w-[60ch] text-[14px] text-ink-2">{{ Seasons::restMessage($viewer instanceof User ? $viewer : null) }}</p>
                @unless ($ended)
                    <x-ui.countdown :at="$block0At" units="dhms" :label="__('Block 0 in')" class="sn-cd" />
                @endunless
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('chess.lobby') }}" class="rv-b h-[52px] px-6">{{ __('Play a casual game') }}</a>
                    @unless ($ended)
                        @guest
                            <a href="{{ route('login') }}" class="rv-b2 h-[52px]" data-test="notify-block0"><x-icon name="bell" :size="14" />{{ __('Notify me at Block 0') }}</a>
                        @else
                            @if ($viewer->notify_block0_at === null)
                                <form method="POST" action="{{ route('notify.block0') }}">
                                    @csrf
                                    <button type="submit" class="rv-b2 h-[52px]" data-test="notify-block0"><x-icon name="bell" :size="14" />{{ __('Notify me at Block 0') }}</button>
                                </form>
                            @else
                                <p role="status" class="m-0 flex h-[52px] items-center gap-2 text-[13px] text-btc-hi" data-test="notify-set"><x-icon name="check" :size="14" />{{ __("We'll tell you at Block 0") }}</p>
                            @endif
                        @endguest
                    @endunless
                </div>
                <p class="m-0 text-xs text-ink-3">{{ $ended ? __('The numbers below are the season that ended :when, as it closed.', ['when' => $date($chain['season']->ends_at, 'D j M')]) : __('The numbers below are the Pre-Season draft. The board can still change them before it releases Block 0.') }}</p>
            </div>
            {{-- Block 0 waits for the board; blocks 1 to 4 follow it (dashed, not mined yet). --}}
            <div class="flex items-center max-lg:hidden" aria-label="{{ __('Chain: Block 0 waits, then block 1 to 4') }}" role="img">
                <span class="sn-genesis"><span class="rv-cube-tp"></span><span class="rv-cube-sd"></span>
                    <span class="sn-genesis-fr" data-test="genesis-cube"><b class="text-xs">{{ __('Genesis') }}</b><span class="font-display text-[18px] leading-[1.2] font-bold whitespace-nowrap">{{ __('Block 0') }}</span><span class="text-xs leading-[1.2]">{{ $ended ? __('season ended') : __('waits for the board') }}</span></span>
                </span>
                @foreach ([66, 60, 54, 48] as $index => $size)
                    <span class="sn-link" aria-hidden="true"></span>
                    <span class="sn-ghost" style="width: {{ $size }}px; height: {{ $size }}px; opacity: {{ 0.85 - $index * 0.15 }}" aria-hidden="true">{{ $index + 1 }}</span>
                @endforeach
            </div>
        </section>
    @endif

    {{-- P46: at season end, the player's own Season Wrapped card with its post (preview first, then the signer) --}}
    @if ($ended && $hasChain && $viewer instanceof User && ShareMoments::hasWrapped($chain['season'], $viewer))
        @php
            $wrapped = ShareCard::wrapped($chain['season'], $viewer);
        @endphp
        <section aria-labelledby="wrapped-h" class="rv-card flex flex-col gap-4 p-4 shadow-[inset_0_0_0_1px_#F7931A] sm:flex-row sm:items-start lg:p-6" data-test="season-wrapped">
            <img src="{{ $wrapped->path('wide') }}" alt="{{ __('Your :season on one card', ['season' => BadgeCopy::season($chain['season']->slug)]) }}" width="1200" height="630" loading="lazy"
                 class="aspect-[1200/630] h-auto w-full shrink-0 rounded-[4px] sm:w-[280px]">
            <div class="flex min-w-0 flex-col gap-2">
                <h2 id="wrapped-h" class="rv-h2">{{ __('Your :season, wrapped', ['season' => BadgeCopy::season($chain['season']->slug)]) }}</h2>
                <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2">{{ __('Your blocks, sats, wins and best rank of the season on one card. Post it, or download it for your chats.') }}</p>
                <livewire:share-button type="wrapped" :moment="$chain['season']->slug" :label="__('Post my season')" :wire:key="'wrapped-share-'.$chain['season']->slug" />
            </div>
        </section>
    @endif

    <section aria-label="{{ __('Chain stats') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-5 lg:gap-4" data-test="mining-stats">
        @php
            $minedOf = $hasChain ? __(':percent % of :supply', ['percent' => number_format($chain['mined'] / max(1, $chain['supply']) * 100, 1), 'supply' => $sats($chain['supply'])]) : '';
            $payLabel = $payKey ? __(':game pays', ['game' => $shortKey($payKey)]) : __('A win pays');
            $stats = match (true) {
                $live => [
                    [__('Eras'), (string) $chain['season']->eras, __('each game halves by what it mined')],
                    [__('Mined'), $sats($chain['mined']), $minedOf],
                    [__('Left to mine'), $sats($chain['remaining']), __('mining stops at 0 or at the season end')],
                    [__('Blocks'), (string) $chain['blocks'], trans_choice(':count today|:count today', $chain['blocks_today'])],
                    // The cheapest to the dearest win now, the stream slides' figure, instead of one mode picked out of all.
                    ($range = $this->winRange($chain['keys'])) === null
                        ? [__('A win pays'), '0', __('no game mines right now')]
                        : [__('A win pays'), $range[0] === $range[1] ? $sats($range[0]) : __(':low to :high', ['low' => $sats($range[0]), 'high' => $sats($range[1])]), __('sats per winner now, by mode'), 'stat-win-range'],
                ],
                $ended => [
                    [__('Blocks'), (string) $chain['blocks'], __('at the season end')],
                    [__('Mined'), $sats($chain['mined']), $minedOf],
                    [__('Not mined'), $sats((int) $unmined), __('goes to the league reserve')],
                    [__('Players who mined'), (string) $chain['miner_count'], __('each paid once, after the review')],
                    [__('Season'), trans_choice(':count week|:count weeks', (int) round($chain['season']->genesis_at->diffInDays($chain['season']->ends_at) / 7)), __(':from to :to', ['from' => $date($chain['season']->genesis_at, 'j M'), 'to' => $date($chain['season']->ends_at, 'j M Y')])],
                ],
                default => [
                    [__('Eras'), (string) $chain['parameters']->eras(), __('each game halves by what it mined')],
                    // The board's saved chain draft only, as home and /rules: no server default is announced.
                    PreSeason::potSats() === null
                        ? [__('Supply'), '–', __('not announced yet')]
                        : [__('Supply'), $sats(PreSeason::potSats()), __('the most it pays out, after the season')],
                    [__('Mined'), '0', __('nothing before Block 0')],
                    [__('Blocks'), '0', __('Block 1 follows Block 0')],
                    [$payLabel, $payKey ? $sats($chain['rewards_now'][$payKey]) : '0', __('sats per winner, era :era', ['era' => 1])],
                ],
            };
        @endphp
        @foreach ($stats as $stat)
            @php
                [$label, $value, $sub] = $stat;
            @endphp
            <div @class(['rv-card flex min-w-0 flex-col gap-1 p-4', 'col-span-2 lg:col-span-1' => $loop->last]) @isset($stat[3]) data-test="{{ $stat[3] }}" @endisset>
                <span class="rv-m">{{ $label }}</span>
                <b class="font-display text-[22px] leading-tight lg:text-[26px]">{{ $value }}</b>
                <span class="rv-m">{{ $sub }}</span>
            </div>
        @endforeach
    </section>

    @if ($live)
        @php
            // What a win pays, one card per share key of ChainOverview::keys() (plan "mining-page-clarity" P3): the
            // modes that mine, one chip per mode and figure (the sizes of a group together while they pay the same), read
            // from the same rows as the eras table below and the stream slides.
            $payCards = [];
            foreach ($chain['keys'] as $shareKey => $key) {
                if ($key['pot'] === 0) {
                    continue;
                }
                $chips = [];
                $covered = [];
                foreach ($key['modes'] as $weightKey => $mode) {
                    if (! ChainOverview::mines((string) $weightKey) || $mode['pays'] <= 0) {
                        continue;
                    }
                    [$modeGame, $modeName] = explode('/', (string) $weightKey, 2);
                    $covered[$modeGame] = GameNames::game($modeGame);
                    $label = $modeGame === 'chess' ? \App\Support\Chess\ChessModes::short($modeName) : \Illuminate\Support\Str::ucfirst((string) preg_replace('/\s+\d+\+\d+$/', '', GameNames::mode($modeGame, $modeName)));
                    $chips[$modeName.'|'.$mode['pays']] ??= ['label' => $label, 'pays' => (int) $mode['pays'], 'keys' => []];
                    $chips[$modeName.'|'.$mode['pays']]['keys'][] = (string) $weightKey;
                }
                usort($chips, fn (array $a, array $b): int => $a['pays'] <=> $b['pays']);
                $payCards[] = ['key' => $key, 'chips' => $chips, 'games' => array_values($covered)];
            }
            // Which games mine a block now and which do not (casual and own-copy games): the registry's list entries.
            $mineGames = [[], []];
            foreach ($registry->listed() as $listedSlug => $listedGame) {
                $members = $registry->covered($listedSlug);
                $minesNow = collect(array_keys($inForce->weights))->contains(fn (string $weightKey): bool => in_array(explode('/', $weightKey, 2)[0], $members, true)
                    && ChainOverview::mines($weightKey) && ($chain['keys'][$inForce->shareKey(explode('/', $weightKey, 2)[0])]['era'] ?? null) !== null);
                $mineGames[$minesNow ? 0 : 1][] = $registry->listedName($listedSlug);
            }
        @endphp
        <section aria-labelledby="eras-h" class="flex min-w-0 flex-col gap-3" data-test="mining-keys">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id="eras-h" class="rv-h2">{{ __('What a win pays') }}</h2>
                <span class="rv-m">{{ __('sats per winner now; each game halves by what it mined') }}</span>
            </div>
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[repeat(auto-fit,minmax(300px,1fr))] lg:gap-4" data-test="key-halvings">
                @foreach ($payCards as $card)
                    @php($key = $card['key'])
                    <article class="rv-card flex min-w-0 flex-col gap-3 p-4" data-test="key-halving" data-key="{{ $key['key'] }}" data-era="{{ $key['era'] ?? 'closed' }}">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h3 class="m-0 text-[16px] font-bold">{{ ChainOverview::gameLabel($key['key']) }}</h3>
                            <span class="rv-tag is-btc" data-test="key-era">{{ $key['closed'] ? __('mined out') : __('Era :era of :eras', ['era' => $key['era'], 'eras' => $key['eras']]) }}</span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="block h-1 w-full overflow-hidden rounded-[2px] bg-raised" aria-hidden="true">
                                <span class="block h-full bg-btc" style="width: {{ $key['pot'] > 0 ? min(100, round(100 * $key['mined'] / $key['pot'], 2)) : 0 }}%"></span>
                            </span>
                            <span class="rv-m tabular-nums" data-test="key-mined">{{ __(':mined of :pot sats mined', ['mined' => $sats($key['mined']), 'pot' => $sats($key['pot'])]) }}</span>
                        </div>
                        @if ($card['chips'] !== [])
                            <ul class="m-0 flex list-none flex-wrap gap-2 p-0" aria-label="{{ __('A win mines now, in sats per winner') }}" data-test="key-pays">
                                @foreach ($card['chips'] as $chip)
                                    <li class="inline-flex h-8 items-center gap-2 rounded-[4px] border border-line px-2.5 text-[13px] whitespace-nowrap" data-test="pay-chip" data-keys="{{ implode(',', $chip['keys']) }}" data-pays="{{ $chip['pays'] }}"
                                        @if (count($chip['keys']) > 1) title="{{ collect($chip['keys'])->map(fn (string $chipKey): string => ChainOverview::keyLabel($chipKey))->implode(', ') }}" @endif>
                                        <span class="text-ink-2">{{ $chip['label'] }}</span> <b class="font-display text-btc-hi tabular-nums">{{ $sats($chip['pays']) }}</b>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if (count($card['games']) > 1)
                            <span class="rv-m">{{ implode(', ', $card['games']) }}</span>
                        @endif
                        <span @class(['text-[13px] tabular-nums', 'text-btc-hi' => ! $key['closed'], 'text-ink-2' => $key['closed']]) data-test="key-next-halving">{{ ChainOverview::halvingLine($key) }}</span>
                    </article>
                @endforeach
            </div>
            <div class="flex flex-col gap-2 text-[13px]" data-test="mining-games">
                @foreach ([[__('Mine blocks now'), $mineGames[0], true], [__('Mine no block'), $mineGames[1], false]] as [$caption, $names, $mines])
                    @if ($names !== [])
                        <div class="flex flex-wrap items-center gap-1.5" data-test="{{ $mines ? 'games-mine' : 'games-mine-not' }}">
                            <span class="mr-1 text-ink-2">{{ $caption }}</span>
                            @foreach ($names as $name)
                                <span @class(['rv-tag', 'is-btc' => $mines])>{{ $name }}</span>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
    @endif

    {{-- Every game in its own halving (`season-chain-v2`): no era of the season, no date. Before the board saved a
         draft the pots would announce a server default, so the section waits for it (as the supply tile). --}}
    @if (! $live && ($ended || PreSeason::potSats() !== null))
    <section aria-labelledby="keys-h" class="rv-card flex flex-col p-4 lg:p-6" data-test="mining-keys">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2 id="keys-h" class="rv-h2">{{ __('Halving per game') }}</h2>
            <span class="rv-m">{{ match (true) { $ended => __('where each game stood at the season end'), $live => __('a game halves once it mined half of what its share still holds'), default => __('a game halves once it mined half of what its share still holds; values from the Pre-Season draft') } }}</span>
        </div>
        <x-key-halvings :keys="$chain['keys']" :live="$live" :ended="$ended" />
    </section>
    @endif

    @if ($hasChain && ! $live)
        @include('partials.mining-supply')
    @endif

    @if ($live)
        {{-- The full era-by-mode table, closed: the cards above answer what a win pays now (plan P3). --}}
        <details class="rv-adv min-w-0" data-test="mining-eras">
            <summary><x-icon name="chevron-down" :size="16" />{{ __('Show all eras') }} <span class="rv-m font-normal max-sm:hidden">{{ __('sats per winner in each era of a game') }}</span></summary>
    @else
    <section aria-labelledby="eras-h" class="rv-card flex min-w-0 flex-col p-0" data-test="mining-eras">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-4">
            <h2 id="eras-h" class="rv-h2">{{ __('What a win pays') }}</h2>
            <span class="rv-m">{{ $live || $ended ? __('sats per winner in each era of a game') : __('sats per winner in each era of a game, values from the Pre-Season draft') }}</span>
        </div>
    @endif
        {{-- The table scrolls inside its card, never the page (it ran 970 px wide at 390, 2430 px in a 1342 px card at
             1440): the edge fades say there is more on that side (the chain viewport's pattern), the region takes the
             keyboard. --}}
        <x-ui.scroll-x :label="__('What a win pays, scrolls sideways')" data-test="mining-eras-scroll">
            <table class="rv-tb sn-tb">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Era') }}</th>
                        <th scope="col"><span class="sr-only">{{ $payKey ? __(':game pays', ['game' => $shortKey($payKey)]) : '' }}</span></th>
                        @foreach (array_keys($chain['rewards_now']) as $key)
                            <th scope="col" class="is-r" @unless (ChainOverview::mines($key)) data-test="reward-not-open" @endunless>{{ $shortKey($key) }}</th>
                        @endforeach
                        @foreach ($games as $game)
                            <th scope="col" class="is-r">{{ __(':game mined', ['game' => ChainOverview::gameLabel($game)]) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($chain['schedule'] as $row)
                        @php($pay = $payKey === null ? 0 : (int) ($row['rewards'][$payKey] ?? 0))
                        <tr>
                            <td class="text-btc">{{ $row['era'] }}</td>
                            <td>
                                @if ($firstPay > 0)
                                    <span class="flex items-center gap-2">
                                        <span class="sn-bar" style="width: {{ max(2, round(60 * $pay / $firstPay)) }}px" aria-hidden="true" data-test="era-pay-bar"></span>
                                        <span class="font-display text-[13px] text-btc-hi tabular-nums">{{ $sats($pay) }}</span>
                                    </span>
                                @endif
                            </td>
                            @foreach ($row['rewards'] as $key => $reward)
                                {{-- A game's own era, marked per cell: chess may stand in era 2 while the board games are in era 1. --}}
                                @php($payNow = $live && ChainOverview::mines((string) $key) && in_array($shareOf[$key] ?? '', $row['keys'], true))
                                <td @class(['is-r tabular-nums', 'text-ink-3' => ! ChainOverview::mines((string) $key), 'font-bold text-btc-hi' => $payNow]) @if ($payNow) data-test="pays-now" data-key="{{ $key }}" @endif>{{ ChainOverview::mines((string) $key) ? $sats($reward) : '–' }}</td>
                            @endforeach
                            @foreach ($games as $game)
                                @php($minedHere = (int) ($hasChain ? ($chain['mined_by_game_and_era'][$game][$row['era']] ?? 0) : 0))
                                @php($capHere = (int) ($row['caps'][$game] ?? 0))
                                <td @class(['is-r tabular-nums', 'text-ink-2' => ! ($live && in_array($game, $row['keys'], true)), 'text-btc-hi' => $live && in_array($game, $row['keys'], true)])>
                                    {{ $sats($minedHere) }} / {{ $sats($capHere) }}
                                    @if ($minedHere > 0)
                                        {{-- How much of the game's era cap is mined, as a bar under the numbers (P53). --}}
                                        <span class="mt-1 ml-auto block h-1 w-full max-w-24 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true" data-test="era-cap-bar">
                                            <span class="block h-full bg-btc" style="width: {{ $capHere > 0 ? min(100, round(100 * $minedHere / $capHere, 2)) : 0 }}%"></span>
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.scroll-x>
        <div class="flex flex-col gap-1 p-4 rv-m">
            @unless (collect(array_keys($chain['rewards_now']))->filter(fn (string $key): bool => str_starts_with($key, 'chess/'))->every(fn (string $key): bool => ChainOverview::mines($key)))
                <span data-test="rated-chess-not-open">{{ __('Chess counts once rated chess opens; until then –.') }}</span>
            @endunless
            @unless (collect(array_keys($chain['rewards_now']))->reject(fn (string $key): bool => str_starts_with($key, 'chess/'))->every(fn (string $key): bool => ChainOverview::mines($key)))
                <span data-test="rated-board-not-open">{{ __('Board games count once their rated games open; until then –.') }}</span>
            @endunless
            <span>{{ __('Mined / era size: a game enters its next era once it has mined this much; the block that crosses the line counts in full.') }}</span>
            @if ($live)
                <span>{{ __('Highlighted: the era each game stands in now.') }}</span>
            @endif
        </div>
    @if ($live)
        </details>
        </section>
    @else
    </section>
    @endif

    @if ($live && $chain['blocks'] > 0)
        @include('partials.mining-supply')
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-6">
        <div class="flex min-w-0 flex-col gap-4 lg:col-span-8 lg:gap-6">
            @if ($live)
                {{-- One chain block (plan "mining-page-clarity" P4): the newest blocks as cubes and rows, one line while
                     no block exists, the wins that did not mine folded inside. --}}
                <section aria-labelledby="latest-h" class="rv-card flex min-w-0 flex-col gap-3 p-4 lg:p-6" data-test="mining-latest">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2 id="latest-h" class="rv-h2">{{ __('The chain') }}</h2>
                        @if ($chain['latest'] !== [])
                            <span class="rv-m">{{ trans_choice(':count block mined, pending the season review|:count blocks mined, pending the season review', $chain['blocks']) }}</span>
                        @endif
                    </div>
                    @if ($chain['latest'] === [])
                        <p class="m-0 text-[13px] text-ink-2" data-test="chain-empty">{{ __('No block yet. The first fair rated win mines block 1.') }}</p>
                    @else
                        <x-ui.scroll-x :label="__('The newest blocks, scrolls sideways')" data-test="chain-cubes">
                            <ol class="m-0 flex list-none gap-4 p-0 pt-1 pb-1">
                                @foreach (array_slice($chain['latest'], 0, 6) as $block)
                                    @php($blockGame = explode('/', (string) $block['key'], 2)[0])
                                    <li class="flex-none">
                                        <a href="#block-{{ $block['height'] }}" class="block" data-test="chain-cube" aria-label="{{ __('Block :height', ['height' => $block['height']]) }}, {{ ChainOverview::keyLabel($block['key']) }}, {{ __(':sats sats', ['sats' => $sats($block['reward'])]) }}">
                                            <span @class(['rv-cube', 'g-'.\App\Support\Matches\MatchBlocks::family($blockGame), 'is-newest' => $loop->first]) aria-hidden="true">
                                                <span class="rv-cube-tp"></span>
                                                <span class="rv-cube-sd"></span>
                                                <span class="rv-cube-fr">
                                                    <span class="rv-cube-k font-bold">{{ GameNames::cube($blockGame) }}</span>
                                                    <span class="rv-cube-v">{{ $block['height'] }}</span>
                                                    <span class="rv-cube-k">{{ $sats($block['reward']) }}</span>
                                                    <span class="rv-cube-k">{{ $date($block['at'], 'D H:i') }}</span>
                                                </span>
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        </x-ui.scroll-x>
                        <div class="flex flex-col">
                            @foreach ($chain['latest'] as $block)
                                {{-- Anchored by height: the Mempool chain links a mined match here. --}}
                                <div id="block-{{ $block['height'] }}" class="grid scroll-mt-24 grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2.5 text-[13px] last:border-0 target:bg-btc-chip">
                                    <b class="font-display text-base">{{ $block['height'] }}</b>
                                    <span class="flex min-w-0 flex-col gap-0.5">
                                        <span class="truncate">{{ $block['label'] }}, {{ ChainOverview::keyLabel($block['key']) }}</span>
                                        <span class="truncate text-xs text-ink-2">{{ implode(', ', $block['winners']) }}</span>
                                    </span>
                                    <span class="flex flex-col items-end gap-0.5"><b class="tabular-nums">{{ $sats($block['reward']) }}</b><span class="text-xs whitespace-nowrap text-ink-3">{{ $date($block['at'], 'D H:i') }}</span></span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if ($chain['rejected'] !== [])
                        <details class="rv-adv min-w-0" data-test="mining-rejected">
                            <summary><x-icon name="chevron-down" :size="16" />{{ __('Wins that did not mine') }} <span class="rv-m font-normal">{{ array_sum($chain['rejected']) }}</span></summary>
                            <div class="flex flex-col pt-2">
                                @foreach ($chain['rejected'] as $reason => $count)
                                    <div class="flex items-center justify-between gap-3 border-b border-hairline py-2 text-[13px] last:border-0"><span>{{ ChainOverview::reasonLabel((string) $reason) }}</span><b class="tabular-nums">{{ $count }}</b></div>
                                @endforeach
                            </div>
                        </details>
                    @endif
                </section>
            @elseif ($hasChain)
                <section aria-labelledby="latest-h" class="rv-card flex flex-col gap-2 p-4 lg:p-6" data-test="mining-latest">
                    <div class="flex flex-wrap items-baseline gap-x-3"><h2 id="latest-h" class="rv-h2">{{ __('Latest blocks') }}</h2><span class="rv-m">{{ __('mined, pending the season review') }}</span></div>
                    @forelse ($chain['latest'] as $block)
                        {{-- Anchored by height: the Mempool chain links a mined match here. --}}
                        <div id="block-{{ $block['height'] }}" class="grid scroll-mt-24 grid-cols-[56px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2.5 text-[13px] last:border-0 target:bg-btc-chip">
                            <b class="font-display text-base">{{ $block['height'] }}</b>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate">{{ $block['label'] }}, {{ ChainOverview::keyLabel($block['key']) }}</span>
                                <span class="truncate text-xs text-ink-2">{{ implode(', ', $block['winners']) }}</span>
                            </span>
                            <span class="flex flex-col items-end gap-0.5"><b class="tabular-nums">{{ $sats($block['reward']) }}</b><span class="text-xs whitespace-nowrap text-ink-3">{{ $date($block['at'], 'D H:i') }}</span></span>
                        </div>
                    @empty
                        <p class="m-0 py-4 text-[13px] text-ink-2">{{ $ended ? __('No block was mined this season.') : __('No block yet. The first fair rated win mines block 1.') }}</p>
                    @endforelse
                </section>

                <section aria-labelledby="nomine-h" class="rv-card flex flex-col gap-2 p-4 lg:p-6" data-test="mining-rejected">
                    <h2 id="nomine-h" class="rv-h2">{{ __('Wins that did not mine') }}</h2>
                    @forelse ($chain['rejected'] as $reason => $count)
                        <div class="flex items-center justify-between gap-3 border-b border-hairline py-2 text-[13px] last:border-0"><span>{{ ChainOverview::reasonLabel((string) $reason) }}</span><b class="tabular-nums">{{ $count }}</b></div>
                    @empty
                        <p class="m-0 text-[13px] text-ink-2">{{ __('Every rated win so far mined a block.') }}</p>
                    @endforelse
                </section>
            @endif

            @if ($live)
                {{-- Live (plan P5): the ladders as one chip row, each to its game's ladder. --}}
                <section aria-labelledby="lad-h" class="rv-card flex min-w-0 flex-col gap-2 p-4" data-test="mining-ladders">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1"><h2 id="lad-h" class="rv-h2">{{ __('Ladders') }}</h2><span class="rv-m">{{ __('rated and casual Elo per game and mode') }}</span></div>
                    <x-ui.chips :label="__('Ladders')" test="mining-ladder" :items="collect($this->ladders)->map(fn (array $ladder): array => ['label' => $ladder['name'], 'href' => $ladder['href'], 'key' => $ladder['slug']])->values()->all()" />
                </section>
            @else
            <section aria-labelledby="lad-h" class="rv-card flex flex-col p-0" data-test="mining-ladders">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-4"><h2 id="lad-h" class="rv-h2">{{ __('Ladders') }}</h2><span class="rv-m">{{ $live ? __('rated and casual Elo per game and mode') : __('casual Elo per game and mode, until Block 0') }}</span></div>
                <table class="rv-tb">
                    <tbody>
                        @foreach ($this->ladders as $ladder)
                            <tr data-test="mining-ladder-{{ $ladder['slug'] }}">
                                {{-- Phones: no cover and the link as a 44 px chevron, so "Hyperbitcoinization" and the link fit 320 px (the row ran 418 px at 390). --}}
                                <td class="w-[60px] max-sm:hidden"><x-game-cover :game="$ladder['slug']" size="thumb" class="h-6 w-[43px] rounded-[2px] object-cover" /></td>
                                <td class="max-sm:w-full">{{ $ladder['name'] }}</td>
                                <td class="text-ink-2 max-sm:hidden">{{ $ladder['modes'] }}</td>
                                <td class="is-r max-sm:py-0 max-sm:pr-1"><a href="{{ $ladder['href'] }}" class="rv-lk inline-flex min-h-11 items-center justify-center whitespace-nowrap max-sm:min-w-11" data-test="mining-ladder-link"><span class="max-sm:sr-only">{{ __('Open the ladder') }}</span><x-icon name="next" :size="16" class="sm:hidden" /></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
            @endif

            @unless ($live)
                @include('partials.mining-rules')
            @endunless
        </div>

        <div class="flex min-w-0 flex-col gap-4 lg:col-span-4 lg:gap-6">
            {{-- Live: the top miners only once a block exists (plan P4); an empty list says nothing the chain does not. --}}
            @unless ($live && $chain['blocks'] < 1)
            <section aria-labelledby="miners-h" class="rv-card flex flex-col gap-3 p-4" data-test="mining-strongest">
                <h2 id="miners-h" class="rv-h2">{{ $hasChain ? __('Top miners') : __('The strongest players') }}</h2>
                @if ($hasChain)
                    @forelse ($chain['miners'] as $index => $miner)
                        <div class="grid grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-3 border-b border-hairline py-2 text-[13px] last:border-0">
                            <span class="text-ink-3">{{ $index + 1 }}</span><span class="truncate">{{ $miner['name'] }}</span>
                            <span class="text-right"><b class="tabular-nums">{{ $sats($miner['sats']) }}</b> <span class="text-xs text-ink-3">{{ trans_choice(':count block|:count blocks', $miner['blocks']) }}</span></span>
                        </div>
                    @empty
                        <p class="m-0 py-2 text-[13px] text-ink-2">{{ __('Nobody has mined yet.') }}</p>
                    @endforelse
                    <p class="m-0 text-xs text-ink-3">{{ __('Sats mined wait for the season review; nobody is paid during the season.') }}</p>
                @else
                    <div class="flex flex-col gap-3 rounded-[4px] border border-dashed border-line p-4">
                        <b class="text-[14px]">{{ __('The list opens at Block 0') }}</b>
                        <span class="text-[13px] text-ink-2">{{ __('From then on everyone with enough rated results stands here, with a Global Rating across all games.') }}</span>
                        <a href="{{ route('ladder.strongest') }}" class="rv-lk self-start text-[13px]">{{ __('How it is counted') }}</a>
                    </div>
                @endif
            </section>
            @endunless

            <section aria-labelledby="reserve-h" class="rv-card flex flex-col gap-3 p-4" data-test="mining-reserve">
                <div class="flex items-baseline gap-3">
                    <h2 id="reserve-h" class="rv-h2">{{ __('League reserve') }}</h2>
                    <span class="grow"></span>
                    <span class="rv-m" data-test="reserve-zap-count">{{ trans_choice(':count zap|:count zaps', $zaps['count']) }}</span>
                </div>
                @if ($unmined !== null)
                    <div class="flex items-baseline justify-between gap-3 border-b border-hairline pb-2.5 text-[13px]" data-test="reserve-unmined">
                        <span class="text-ink-2">{{ $live ? __('Unmined rest at the season end, at the current rate') : __('Unmined rest of the season') }}</span>
                        <b class="whitespace-nowrap tabular-nums">{{ $live ? '~' : '' }}{{ $sats($unmined) }}</b>
                    </div>
                @endif
                @if ($zaps['latest'] !== [])
                    <ul class="m-0 flex list-none flex-col p-0 text-[13px]" data-test="reserve-zaps">
                        @foreach ($zaps['latest'] as $zap)
                            <li class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3 border-b border-hairline py-2 last:border-0">
                                <span class="truncate">{{ $zap['name'] }}</span>
                                <span class="whitespace-nowrap"><b class="tabular-nums">{{ $sats($zap['sats']) }}</b> Sats</span>
                                <span class="text-xs whitespace-nowrap text-ink-3">{{ $date($zap['at'], 'j M') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($this->reserveZapQr !== null)
                    <div class="flex items-center gap-3" data-test="reserve-zap">
                        <div class="size-28 shrink-0 rounded-[4px] bg-white p-2" data-test="reserve-zap-qr">{!! $this->reserveZapQr !!}</div>
                        <p class="m-0 rv-m">{{ __('Scan with a Lightning wallet or a Nostr client.') }}</p>
                    </div>
                @endif
            </section>

            @if ($hasChain)
                <section aria-labelledby="payouts-h" class="rv-card flex flex-col gap-2 p-4" data-test="mining-payouts">
                    <h2 id="payouts-h" class="rv-h2">{{ __('Payouts') }}</h2>
                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Nobody is paid during the season. After the season review each player is paid once, to their Lightning address.') }}</p>
                    <dl class="m-0 flex flex-col text-[13px]">
                        @foreach ([
                            [__('Season end'), $date($chain['season']->ends_at)],
                            ...match (true) {
                                $settlement === null => [[__('Mined so far, paid after the review'), __(':sats sats, :players', ['sats' => $sats($chain['mined']), 'players' => trans_choice(':count player|:count players', $chain['miner_count'])]), 'mined']],
                                ! $settlement['approved'] => [[__('Waiting for the review, corrections may still lower it'), __(':sats sats, :players', ['sats' => $sats($settlement['owed']), 'players' => trans_choice(':count player|:count players', $settlement['owed_players'])]), 'review']],
                                default => [
                                    [__('Approved to pay'), __(':sats sats, :players', ['sats' => $sats($settlement['owed']), 'players' => trans_choice(':count player|:count players', $settlement['owed_players'])]), 'approved'],
                                    [__('Paid so far'), __(':sats sats, :players', ['sats' => $sats($settlement['paid_sats']), 'players' => trans_choice(':count player|:count players', $settlement['paid']->count())]), 'paid'],
                                ],
                            },
                            [__('Without a valid address'), __('the sats wait :days days for a claim, then go to the reserve', ['days' => intdiv($chain['season']->claim_seconds, 86400)])],
                        ] as $item)
                            <div class="flex flex-col gap-0.5 border-b border-hairline py-2.5 last:border-0" @isset($item[2]) data-test="payouts-{{ $item[2] }}" @endisset><dt class="text-xs text-ink-2">{{ $item[0] }}</dt><dd class="m-0">{{ $item[1] }}</dd></div>
                        @endforeach
                    </dl>
                </section>
            @endif

            {{-- P45, rule R7: the season chain on Nostr (its genesis), the reserve's zap QR and a follow, one line at the end. --}}
            <x-nostr-bar :bar="\App\Support\Nostr\NostrBar::season($chain['season'], $this->reserveZapQr)" />
        </div>
    </div>

    @if ($hasChain && ! $live)
        @include('partials.mining-review')
    @endif

    @if ($settlement !== null)
        <section id="season-payouts" aria-labelledby="season-payouts-h" class="rv-card flex flex-col gap-3 p-4 lg:p-6" data-test="mining-season-payouts">
            <h2 id="season-payouts-h" class="rv-h2">{{ __('Season payouts') }}</h2>
            <p class="m-0 max-w-[68ch] text-xs leading-normal text-ink-2">{{ __('What each player was paid for their blocks, once, to the Lightning address in their Nostr profile. Each payment is a signed Payout event with the invoice and its preimage. No Lightning address in your profile? Add one so your sats can be paid.') }}</p>
            @forelse ($settlement['paid'] as $payout)
                <div class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-3 gap-y-0.5 border-b border-hairline py-2 text-[13px] last:border-0" data-test="mining-season-payout">
                    <span class="truncate">@if ($payout->user)<x-player-link :user="$payout->user" />@else{{ $payout->name }}@endif</span>
                    <b class="whitespace-nowrap">{{ $sats($payout->amount_sats) }} {{ __('sats') }}</b>
                    <span class="text-xs text-ink-3">{{ trans_choice(':count block|:count blocks', $payout->blocks) }}, {{ $payout->paid_at ? $date($payout->paid_at, 'j M Y') : '' }}</span>
                    @if ($payout->event)
                        <a href="{{ LadderBoard::NJUMP.NostrKeys::nevent($payout->event->event_id, $payout->event->pubkey, 2157) }}" rel="noopener noreferrer" target="_blank" class="text-xs whitespace-nowrap text-proof underline decoration-proof/50 underline-offset-2" data-test="mining-season-payout-proof">{{ __('Payout event') }}<span class="sr-only"> {{ __('(opens njump.me in a new tab)') }}</span></a>
                    @endif
                </div>
            @empty
                <p class="m-0 text-[13px] text-ink-2">{{ __('No season payout has been paid yet.') }}</p>
            @endforelse
        </section>
    @endif

    {{-- How the season works: when a rated win mines a block, the supply and the genesis message (moved here from home, P3). --}}
    @if ($live)
        {{-- Reference, closed at the end (plan "mining-page-clarity" P5): how mining works, the rules in force, the change
             log and the season review in one disclosure; "How mining works" in the hero opens it. --}}
        <details id="season-reference" class="group min-w-0 rounded-lg bg-card" data-test="mining-reference">
            <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 lg:px-6 [&::-webkit-details-marker]:hidden">
                <h2 class="pl-h2">{{ __('How mining works, rules and change log') }}</h2>
                <x-icon name="chevron-down" :size="18" class="shrink-0 text-ink-2 transition-transform duration-200 group-open:rotate-180" />
            </summary>
            <div class="flex min-w-0 flex-col gap-6 border-t border-hairline px-4 pt-4 pb-5 lg:px-6">
                @include('partials.season-rules', ['laterSeason' => $chain['season']?->slug !== App\Support\SeasonChain\SeasonRelease::SLUG, 'liveSeason' => Seasons::live(), 'bare' => true])
                @include('partials.mining-rules')
                @include('partials.mining-review')
            </div>
        </details>
    @else
        @include('partials.season-rules', ['laterSeason' => $live && $chain['season']?->slug !== App\Support\SeasonChain\SeasonRelease::SLUG, 'liveSeason' => Seasons::live()])
    @endif
</div>
