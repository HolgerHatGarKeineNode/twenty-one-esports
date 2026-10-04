@props(['clan', 'moment' => null, 'challenge' => null, 'apply' => null, 'mine' => false, 'numbers' => null, 'loading' => 'lazy'])

{{--
    One clan on /clans as a card: its mark on the house cube (the logo, or
    the tag when there is none), tag and name, its proudest moment
    (ClanPride; nothing when it has none), the players' faces, meetup city
    and founding day, "Challenge" when the viewer may challenge one of
    its Rocket League lineups, and "Apply" (or "Applied") when the viewer may
    apply to it ($apply: ['url' => string, 'applied' => bool] or null). A card with a tournament place, a won series
    or a streak is lit: an orange edge on top. The name link covers the
    whole card (one tab stop); "Challenge" sits above it.

    $numbers: ['rating' => int|null, 'week' => int] once the season runs, else null.
--}}
@php
    use App\Enums\ClanRole;

    $members = $clan->members->sortBy(fn ($member) => [
        $member->user_id === $clan->owner_id ? 0 : ($member->role === ClanRole::Captain ? 1 : 2),
        $member->joined_at->getTimestamp(),
    ])->values();
    $faces = $members->take(5);
    $more = $members->count() - $faces->count();
    $lit = $moment !== null && $moment['weight'] >= \App\Support\Clans\ClanPride::LIT;
    $founded = $clan->created_at;
@endphp

<article {{ $attributes->class([
    'relative flex min-w-0 flex-col gap-4 rounded-card bg-card px-4 pt-6 pb-4 lg:px-5 lg:pb-5',
    'shadow-ring-btc' => $lit,
    'shadow-ring-hairline' => ! $lit,
    'has-[[data-card-link]:focus-visible]:outline-2 has-[[data-card-link]:focus-visible]:outline-offset-2 has-[[data-card-link]:focus-visible]:outline-btc-hi',
]) }} data-test="clan-card" data-clan="{{ $clan->slug }}" @if ($lit) data-lit @endif>
    @if ($lit)
        <span class="absolute inset-x-0 top-0 h-0.5 rounded-t-card bg-btc" aria-hidden="true"></span>
    @endif

    <div class="flex min-w-0 items-end gap-5">
        {{-- The cube's faces hang outside the box, so the logo clips in an inner tile, not on the cube. --}}
        <span class="cube cube-sm mt-3 mr-3 block size-14 shrink-0 bg-[linear-gradient(180deg,#F9B25F,#F7931A)]" data-test="clan-card-mark">
            <x-clan-tag :clan="$clan" :tile="56" :loading="$loading" :class="'flex size-full items-center justify-center font-display font-extrabold text-on-btc '.(mb_strlen($clan->clantag) > 3 ? 'text-xs' : 'text-sm')" />
        </span>
        <div class="flex min-w-0 flex-col gap-1">
            <span class="flex flex-wrap items-center gap-2">
                {{-- Without a logo the cube already shows the tag. --}}
                @if ($clan->localLogoUrl())
                    <span class="font-display text-[13px] font-bold text-btc-hi" data-test="clan-card-tag">{{ $clan->clantag }}</span>
                @endif
                @if ($mine)
                    <span class="inline-flex h-5 items-center rounded-tag bg-btc-chip px-1.5 text-[11px] font-bold text-btc-hi" data-test="clan-card-mine">{{ __('Your clan') }}</span>
                @endif
                @if ($clan->isMemberClan())
                    <x-member-badge />
                @endif
            </span>
            <h3 class="m-0 font-display text-lg leading-[1.2] font-bold break-words">
                <a href="{{ route('clans.show', $clan) }}" class="text-ink after:absolute after:inset-0 after:rounded-card hover:text-btc-hi focus-visible:outline-none" data-card-link>{{ $clan->name }}</a>
            </h3>
        </div>
    </div>

    @if ($moment)
        <x-clans.moment :moment="$moment" :class="\Illuminate\Support\Arr::toCssClasses(['text-[13px] leading-normal', 'font-bold text-btc-hi' => $lit, 'text-ink-2' => ! $lit])" />
    @endif

    <div class="flex min-h-11 flex-wrap items-center gap-3" data-test="clan-card-faces">
        <span class="flex shrink-0 -space-x-2">
            @foreach ($faces as $member)
                <x-avatar :user="$member->user" :size="32" class="rounded-full ring-2 ring-card" />
            @endforeach
            @if ($more > 0)
                <span class="relative inline-flex size-8 items-center justify-center rounded-full bg-raised text-[11px] font-bold text-ink-2 ring-2 ring-card" aria-hidden="true">+{{ $more }}</span>
            @endif
        </span>
        <span class="text-[13px] text-ink-2">{{ trans_choice(':count player|:count players', $members->count()) }}</span>
        @if ($apply)
            <a href="{{ $apply['url'] }}" class="btn-s relative z-10 ml-auto inline-flex h-11 shrink-0 items-center gap-2 rounded-md border border-edge px-4 text-[13px] font-bold text-ink hover:text-ink" data-test="clan-card-apply" data-applied="{{ $apply['applied'] ? 'true' : 'false' }}">
                {{ $apply['applied'] ? __('Applied') : __('Apply') }}<span class="sr-only"> {{ $clan->name }}</span>
            </a>
        @endif
        @if ($challenge)
            <a href="{{ $challenge }}" @class(['btn-s relative z-10 inline-flex h-11 shrink-0 items-center gap-2 rounded-md border border-edge px-4 text-[13px] font-bold text-ink hover:text-ink', 'ml-auto' => ! $apply]) data-test="clan-card-challenge">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" class="shrink-0"><path d="M8 5v14l11-7z"></path></svg>
                {{ __('Challenge') }}<span class="sr-only"> {{ $clan->name }}</span>
            </a>
        @endif
    </div>

    <p class="m-0 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-3">
        @if ($clan->meetup_city)
            <span class="inline-flex items-center gap-1.5" data-test="clan-card-city">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="shrink-0"><path d="M12 21s-7-6.1-7-11a7 7 0 0 1 14 0c0 4.9-7 11-7 11z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>
                {{ __('Meetup :city', ['city' => $clan->meetup_city]) }}
            </span>
        @endif
        @if ($founded)
            <span>{{ __('founded :date', ['date' => $founded->translatedFormat(app()->getLocale() === 'de' ? ($founded->year === now()->year ? 'j. M' : 'j. M Y') : ($founded->year === now()->year ? 'M j' : 'M j, Y'))]) }}</span>
        @endif
    </p>

    @if ($numbers !== null)
        <p class="m-0 mt-auto flex gap-4 border-t border-hairline pt-3 text-xs text-ink-2" data-test="clan-card-numbers">
            <span>{{ __('Clan Rating') }} <b class="font-display text-sm text-ink">{{ $numbers['rating'] ?? '–' }}</b></span>
            <span>{{ __('Hashrate, 7 days') }} <b class="font-display text-sm text-ink">{{ $numbers['week'] }}</b></span>
        </p>
    @endif
</article>
