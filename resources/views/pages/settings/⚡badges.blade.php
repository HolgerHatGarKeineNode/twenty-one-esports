<?php

use App\Models\Season;
use App\Support\Badges\BadgeCopy;
use App\Support\Cards\ShareCard;
use App\Support\Cards\ShareMoments;
use App\Support\Rating\RankTiers;
use App\Support\SeasonChain\Seasons;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Badges and sharing (P11): the player's rank badges with "Show on my Nostr
 * profile", and every moment they can share as a signed Nostr post or a
 * downloaded card: Season Wrapped, rank ups, mined blocks, tournament wins.
 * No artboard draws this tab; it uses the settings cards and the share cards
 * of ShareCards.dc.html as previews.
 */
new #[Title('Badges and sharing')] class extends Component {}; ?>

@php
    $user = auth()->user();
    $season = Seasons::live() ?? Seasons::latest();
    $wrapped = $season !== null && ShareMoments::hasWrapped($season, $user) ? ShareCard::wrapped($season, $user) : null;
    $rankUps = ShareMoments::rankUpsOf($user);
    $blocks = ShareMoments::minedBy($user)->take(6);
    $tournaments = array_slice(ShareMoments::tournamentWins($user), 0, 6);
    $moments = [];

    if ($wrapped !== null) {
        $moments[] = ['type' => 'wrapped', 'id' => $season->slug, 'card' => $wrapped, 'title' => __(':season on one card', ['season' => BadgeCopy::season($season->slug)]), 'detail' => __('Blocks, sats and your best rank')];
    }

    foreach ($rankUps as $version) {
        $moments[] = ['type' => 'rank-up', 'id' => (string) $version->id, 'card' => ShareCard::rankUp($version), 'title' => __('Rank up: :rank', ['rank' => RankTiers::label($version->tier)]), 'detail' => BadgeCopy::ladder($version->badge->game, $version->badge->mode).' · '.\Illuminate\Support\Carbon::createFromTimestamp($version->signed_at)->translatedFormat('M j')];
    }

    foreach ($blocks as $block) {
        $moments[] = ['type' => 'block', 'id' => (string) $block->id, 'card' => ShareCard::block($block, $user), 'title' => __('Block :height mined', ['height' => $block->height]), 'detail' => BadgeCopy::ladder($block->game, $block->mode).' · '.$block->label];
    }

    foreach ($tournaments as $win) {
        $moments[] = ['type' => 'tournament', 'id' => (string) $win['tournament']->id, 'card' => ShareCard::tournament($win['tournament'], $win['winner']), 'title' => __('Won :tournament', ['tournament' => $win['tournament']->name]), 'detail' => BadgeCopy::ladder($win['tournament']->game, $win['tournament']->mode)];
    }
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="badge-settings">
    <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[32px]">{{ __('Settings') }}</h1>
        <span class="grow"></span>
        @include('pages.settings.partials.nav', ['current' => 'badges'])
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,400px)]">
        <section id="share" aria-labelledby="sh-h" class="flex min-w-0 flex-col gap-4 self-start rounded-lg bg-card px-4 py-5 lg:order-first lg:px-6" data-test="share-moments">
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h2 id="sh-h" class="m-0 text-[15px] font-bold">{{ __('Share your moments') }}</h2>
                <span class="text-xs text-ink-2">{{ __('A post signed by you, with the card. Or download the card for Signal and Telegram.') }}</span>
            </span>
            @if ($moments === [])
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="share-empty">{{ __('Nothing to share yet. Rank ups, mined blocks and tournament wins show up here, and your season card once Block 0 is out.') }}</p>
            @else
                <ul class="m-0 flex list-none flex-col gap-5 p-0">
                    @foreach ($moments as $moment)
                        <li wire:key="moment-{{ $moment['type'] }}-{{ $moment['id'] }}" class="flex min-w-0 flex-col gap-3 border-b border-hairline pb-5 last:border-0 last:pb-0 sm:flex-row sm:items-start" data-test="share-moment" data-type="{{ $moment['type'] }}">
                            <img src="{{ $moment['card']->path('wide') }}" alt="{{ $moment['title'] }}" width="1200" height="630" loading="lazy"
                                 class="aspect-[1200/630] h-auto w-full shrink-0 rounded-md shadow-ring sm:w-[240px]">
                            <div class="flex min-w-0 flex-col gap-2">
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <b class="text-[13px] [overflow-wrap:anywhere]">{{ $moment['title'] }}</b>
                                    <span class="text-xs text-ink-2 [overflow-wrap:anywhere]">{{ $moment['detail'] }}</span>
                                </span>
                                <livewire:share-button :type="$moment['type']" :moment="$moment['id']" :wire:key="'share-'.$moment['type'].'-'.$moment['id']" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <livewire:rank-badges :player="$user" :share-link="false" />
    </div>
</div>
