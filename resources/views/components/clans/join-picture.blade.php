@props(['cta' => null])

{{--
    The picture of an empty "clan" state (P53: "zu textlich. BILDER!!!!"): the
    marks of the league's five newest clans and how many there are. Real data
    only: without a clan it renders nothing, and the caller's sentence stands
    alone. `cta`: the one button's label, to the clan list; none without it.
--}}
@php
    use App\Models\Clan;

    $joinClans = Clan::query()->latest('created_at')->latest('id')->limit(5)->get();
    $joinCount = $joinClans->isEmpty() ? 0 : Clan::query()->count();
@endphp

@if ($joinClans->isNotEmpty())
    <a href="{{ route('clans.index') }}" {{ $attributes->class('flex min-w-0 items-center gap-3 rounded-md bg-well p-2 text-xs text-ink-2 hover:bg-row-hover hover:text-ink-2') }} data-test="join-picture">
        <span class="flex shrink-0 gap-1" aria-hidden="true">
            @foreach ($joinClans as $joinClan)
                <x-clan-tag :clan="$joinClan" :tile="32" class="flex size-8 shrink-0 items-center justify-center rounded-xs bg-btc-tint text-[9px] font-bold text-btc" />
            @endforeach
        </span>
        <span>{{ trans_choice(':count clan plays in the league|:count clans play in the league', $joinCount) }}</span>
    </a>
@endif
@if ($cta !== null)
    <x-button variant="quiet" :href="route('clans.index')" class="self-start" data-test="join-picture-cta">{{ $cta }}</x-button>
@endif
