@props(['clan', 'moments', 'challenge' => null, 'mine' => false])

{{--
    The clan with the strongest moment right now (ClanPride::spotlight()),
    wide above the clan cards: the moment itself is the headline, the clan
    mark large on the house cube, the players' faces, and up to two more of
    its moments. The name link covers the band; "Challenge" sits above it.
    Its logo is in the first screen, so it loads eagerly.
--}}
@php
    use App\Enums\ClanRole;

    $top = $moments[0];
    $members = $clan->members->sortBy(fn ($member) => [
        $member->user_id === $clan->owner_id ? 0 : ($member->role === ClanRole::Captain ? 1 : 2),
        $member->joined_at->getTimestamp(),
    ])->values();
    $faces = $members->take(8);
    $more = $members->count() - $faces->count();
    $when = \Illuminate\Support\Carbon::createFromTimestamp($top['at']);
@endphp

<section aria-labelledby="spot-h" {{ $attributes->class('relative flex flex-col gap-5 rounded-card bg-btc-tint px-4 pt-8 pb-5 shadow-ring-btc has-[[data-card-link]:focus-visible]:outline-2 has-[[data-card-link]:focus-visible]:outline-offset-2 has-[[data-card-link]:focus-visible]:outline-btc-hi sm:flex-row sm:items-center sm:gap-8 lg:px-8 lg:pt-10 lg:pb-8') }}
         data-test="clan-spotlight" data-clan="{{ $clan->slug }}">
    <span class="cube mr-4 block size-20 shrink-0 bg-[linear-gradient(180deg,#F9B25F,#F7931A)] lg:size-24" data-test="clan-spotlight-mark">
        <x-clan-tag :clan="$clan" :tile="96" loading="eager" class="flex size-full items-center justify-center font-display text-xl font-extrabold text-on-btc lg:text-2xl" />
    </span>

    <div class="flex min-w-0 grow flex-col gap-3">
        <h2 id="spot-h" class="m-0 max-w-[32ch] font-display text-2xl leading-[1.15] font-bold text-ink lg:text-3xl">
            <x-clans.moment :moment="$top" :chip="false" class="[&_svg]:mt-1.5 [&_svg]:size-5 [&_svg]:text-btc lg:[&_svg]:mt-2" />
        </h2>
        <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-2">
            <a href="{{ route('clans.show', $clan) }}" class="inline-flex items-center gap-2 font-bold text-ink after:absolute after:inset-0 after:rounded-card hover:text-btc-hi focus-visible:outline-none" data-card-link>
                <span class="font-display text-btc-hi">{{ $clan->clantag }}</span><span class="break-words">{{ $clan->name }}</span>
            </a>
            @if ($mine)
                <span class="inline-flex h-5 items-center rounded-tag bg-btc-chip px-1.5 text-[11px] font-bold text-btc-hi">{{ __('Your clan') }}</span>
            @endif
            <time datetime="{{ $when->copy()->utc()->format('Y-m-d\TH:i:s\Z') }}" class="text-ink-3">{{ $when->diffForHumans() }}</time>
        </p>
        @if (count($moments) > 1)
            <ul class="m-0 flex list-none flex-col gap-1.5 p-0 text-[13px] text-ink-2">
                @foreach (array_slice($moments, 1, 2) as $moment)
                    <li><x-clans.moment :moment="$moment" /></li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="flex shrink-0 flex-col gap-4 sm:items-end">
        <span class="flex items-center gap-3" data-test="clan-spotlight-faces">
            <span class="flex -space-x-2">
                @foreach ($faces as $member)
                    <x-avatar :user="$member->user" :size="36" class="rounded-full ring-2 ring-btc-tint" />
                @endforeach
                @if ($more > 0)
                    <span class="relative inline-flex size-9 items-center justify-center rounded-full bg-raised text-xs font-bold text-ink-2 ring-2 ring-btc-tint" aria-hidden="true">+{{ $more }}</span>
                @endif
            </span>
            <span class="text-[13px] text-ink-2">{{ trans_choice(':count player|:count players', $members->count()) }}</span>
        </span>
        @if ($challenge)
            <a href="{{ $challenge }}" class="btn-p relative z-10 inline-flex h-11 items-center gap-2 self-start rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc sm:self-end" data-test="clan-spotlight-challenge">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" class="shrink-0"><path d="M8 5v14l11-7z"></path></svg>
                {{ __('Challenge :clan', ['clan' => $clan->name]) }}
            </a>
        @endif
    </div>
</section>
