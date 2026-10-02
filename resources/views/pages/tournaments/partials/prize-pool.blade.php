{{--
    The prize pot of a tournament (P9) as part of the page's hero (user,
    2026-09-28: "das ist das wichtigste an einem Turnier", it sat far down
    the page): the pot as the tournament sets it, what is still to be won of
    it (less only what was paid out, never the wallet balance), what each
    place wins as a podium, the paid sponsors with their logos, and the zap
    sponsors with their Nostr pictures and zapped sums, the biggest first;
    their sats are on top of the pot (user, 2026-10-02). Rendered
    only when the tournament has a pot (App\Support\Tournaments\
    TournamentPrizePool), so every number here is the pot's own.

    $pool: the shape of TournamentPrizePool::for() — sats, base, zaps,
    zappers, left, mode, split, sponsors. A Lightning address is never shown
    as text here (user, 2026-09-27). A zapper's name and picture come from
    their Nostr profile: the league's cached one for a player, else read by
    the browser from the profile relays (the generated picture until then).
    $topUp: true when anyone can add sats right now (the "Add to the pot"
    panel further down the page, #pot-topup).
    $manage: the viewer manages the tournament (gate `manage-tournament`):
    "Add a sponsor" leads to the sponsor form of the prize pool page.
--}}
@php
    $sats = fn (int $amount): string => \App\Support\Cards\ShareCard::sats($amount);
    $placeLabel = fn (int $place): string => match ($place) { 1 => __('1st place'), 2 => __('2nd place'), 3 => __('3rd place'), default => __(':place. place', ['place' => $place]) };
    $poolLeft = $pool['left'] ?? $pool['sats'];
    $podium = array_slice($pool['split'], 0, 3);
    $rest = array_slice($pool['split'], 3);
    $topUp ??= false;
    $manage ??= false;
    $zappers = $pool['zappers'] ?? [];
    $zapped = (int) ($pool['zaps'] ?? 0);
    $zapOpen = \App\Support\Prizes\PotZaps::open($tournament);
@endphp
<section aria-labelledby="pool-h" class="tl-pot flex flex-col gap-4 rounded-card bg-btc-chip p-4 shadow-ring-btc sm:p-5" data-test="prize-pool">
    <div class="flex flex-col gap-1">
        <h2 id="pool-h" class="m-0 flex items-center gap-1.5 text-[13px] font-bold text-btc-hi"><x-icon name="bolt" :size="16" />{{ __('Prize pool') }}</h2>
        <p class="m-0 flex flex-col gap-1">
            <span class="flex items-baseline gap-2 font-display leading-none font-bold text-btc tabular-nums"><span class="text-[44px] sm:text-[56px]" data-test="pool-sats">{{ $sats($pool['sats']) }}</span><span class="text-lg sm:text-xl">{{ __('sats') }}</span></span>
            <span class="text-[13px] text-ink-2" data-test="pool-left">{{ __(':left of :total sats still to be won', ['left' => $sats($poolLeft), 'total' => $sats($pool['sats'])]) }}</span>
            @if ($zapped > 0)
                <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px]" data-test="pool-zaps-on-top">
                    <b class="inline-flex items-center gap-1 text-btc-hi"><x-icon name="bolt" :size="14" />{{ __('+:sats sats from zaps on top', ['sats' => $sats($zapped)]) }}</b>
                    <span class="text-ink-2">{{ __(':base sats pot + :zaps sats zaps', ['base' => $sats((int) ($pool['base'] ?? $pool['sats'])), 'zaps' => $sats($zapped)]) }}</span>
                </span>
            @endif
        </p>
        @if ($pool['sats'] > 0)
            <span class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-raised" aria-hidden="true"><span class="block h-full bg-btc" style="width: {{ min(100, (int) floor(100 * $poolLeft / $pool['sats'])) }}%"></span></span>
        @endif
    </div>

    @if ($podium !== [])
        {{-- The first three as a podium: 2nd, 1st, 3rd from sm up; the list keeps the order 1, 2, 3 for reading. --}}
        <ol class="m-0 grid list-none grid-cols-3 items-end gap-2 p-0" aria-label="{{ __('What each place wins') }}" data-test="pool-podium">
            @foreach ($podium as $share)
                <li @class([
                        'flex min-w-0 flex-col justify-end gap-1 rounded-md px-3 py-2.5',
                        'order-2 min-h-24 bg-btc text-on-btc' => $share['place'] === 1,
                        'order-1 min-h-20 bg-ground shadow-ring-hairline' => $share['place'] === 2,
                        'order-3 min-h-16 bg-ground shadow-ring-hairline' => $share['place'] === 3,
                    ]) wire:key="pool-{{ $share['place'] }}" data-test="pool-place">
                    <span @class(['text-xs', 'font-bold' => $share['place'] === 1, 'text-ink-2' => $share['place'] !== 1])>{{ $placeLabel($share['place']) }}@if ($share['percent'] !== null), {{ $share['percent'] }} %@endif</span>
                    <span class="font-display text-base leading-tight font-bold break-words tabular-nums sm:text-lg">{{ __(':sats sats', ['sats' => $sats($share['sats'])]) }}</span>
                </li>
            @endforeach
        </ol>
        @if ($rest !== [])
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs text-ink-2">
                @foreach ($rest as $share)
                    <li class="rounded-xs bg-ground px-2 py-1" wire:key="pool-{{ $share['place'] }}">{{ $placeLabel($share['place']) }}: <b class="text-ink tabular-nums">{{ __(':sats sats', ['sats' => $sats($share['sats'])]) }}</b></li>
                @endforeach
            </ul>
        @endif
    @endif

    {{-- The sponsors whose invoice is paid, with their logos; none yet: how to back the pot. --}}
    <div class="flex flex-col gap-2 border-t border-btc-ring pt-4" data-test="pool-sponsors">
        @if ($pool['sponsors'] !== [])
            <span class="text-xs text-ink-2">{{ __('Backed by') }}</span>
            {{-- One row that scrolls on a phone, so the pot does not push the sign-up far down; wraps from sm. --}}
            <ul class="m-0 flex list-none gap-2 overflow-x-auto p-0 pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0">
                @foreach ($pool['sponsors'] as $sponsor)
                    <li class="flex h-14 max-w-[240px] min-w-0 shrink-0 items-center gap-2.5 rounded-md bg-ground px-3 shadow-ring-hairline" data-test="pool-sponsor">
                        @if ($sponsor['logo'])
                            <img src="{{ $sponsor['logo'] }}" alt="" width="40" height="40" loading="lazy" class="size-10 shrink-0 rounded-sm bg-white object-contain">
                        @endif
                        @if ($sponsor['url'])
                            <a href="{{ $sponsor['url'] }}" target="_blank" rel="noopener noreferrer sponsored" class="truncate text-[13px] font-bold text-ink hover:text-btc-hi">{{ $sponsor['name'] }}</a>
                        @else
                            <span class="truncate text-[13px] font-bold">{{ $sponsor['name'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="pool-no-sponsor">{{ __('No sponsor yet. Sponsors show here with their logo once they have paid.') }}</p>
        @endif
        @if ($zappers !== [])
            {{-- The zap sponsors: their Nostr picture and name, the sum they zapped; the biggest first. --}}
            <div class="flex flex-col gap-2" data-test="pool-zappers" x-data="potZappers" data-relays="{{ json_encode(array_values((array) config('esports.profile_relays', []))) }}">
                <span class="text-xs text-ink-2">{{ __('Zapped on top by') }}</span>
                <ol class="m-0 flex list-none gap-2 overflow-x-auto p-0 pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0">
                    @foreach ($zappers as $zapper)
                        @php($zapName = $zapper['user']?->displayName() ?? \Illuminate\Support\Str::limit($zapper['npub'], 12, '…'))
                        <li class="flex h-14 max-w-[240px] min-w-0 shrink-0 items-center gap-2.5 rounded-md bg-ground px-3 shadow-ring-hairline" data-test="pool-zapper" data-pubkey="{{ $zapper['pubkey'] }}" wire:key="zapper-{{ $zapper['pubkey'] }}">
                            @if ($zapper['user'])
                                <x-avatar :user="$zapper['user']" :size="36" class="shrink-0" />
                            @else
                                <img src="{{ \App\Support\Nostr\PlayerProfile::generatedAvatarUrl($zapper['pubkey']) }}" alt="{{ __(':name avatar, generated', ['name' => $zapName]) }}" width="36" height="36" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                     data-zapper-avatar="{{ $zapper['pubkey'] }}" class="block size-9 shrink-0 rounded-full bg-raised object-cover">
                            @endif
                            <span class="flex min-w-0 flex-col">
                                <span class="truncate text-[13px] font-bold" @unless ($zapper['user']) data-zapper-name="{{ $zapper['pubkey'] }}" @endunless>{{ $zapName }}</span>
                                <span class="text-xs text-btc-hi tabular-nums" data-test="pool-zapper-sats">{{ __(':sats sats', ['sats' => $sats($zapper['sats'])]) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
        @if ($topUp || $manage || $zapOpen)
            <div class="flex flex-wrap gap-2" data-test="pool-sponsor-cta">
                @if ($zapOpen)
                    <x-button icon="bolt" href="#pot-zap" data-test="pool-zap">{{ __('Zap the pot') }}</x-button>
                @endif
                @if ($topUp)
                    <x-button variant="secondary" icon="bolt" href="#pot-topup" data-test="pool-add">{{ __('Add to the pot') }}</x-button>
                @endif
                @if ($manage)
                    <x-button variant="quiet" :href="route('tournaments.pool', $tournament).'#sponsors-h'" data-test="pool-add-sponsor">{{ __('Add a sponsor') }}</x-button>
                @endif
            </div>
        @endif
    </div>
</section>
