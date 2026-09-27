@props(['game' => null])

{{--
    The league's casual cups (P25) as a side mention: one compact row each,
    open for sign-up or running, of one game or of all. Never a poster or a
    hero: those belong to the special tournaments (user, 2026-09-28: "Casual
    Cups sollen nicht so einen präsenten Platz bekommen und eher
    Seitenerwähnungen bleiben"). Renders nothing when no cup is on.
--}}
@php
    use App\Enums\TournamentStatus;
    use App\Models\Tournament;

    $cupRows = Tournament::query()->casualCup()
        ->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Running])
        ->when($game !== null, fn ($query) => $query->where('game', $game))
        ->orderBy('starts_at')->orderBy('id')->limit(3)->get();
    $cupSignups = app(\App\Support\Tournaments\TournamentSignups::class);
@endphp

@if ($cupRows->isNotEmpty())
    <ul {{ $attributes->class('m-0 flex list-none flex-col gap-1 p-0') }} aria-label="{{ __('Casual cups') }}" data-test="cup-mentions">
        @foreach ($cupRows as $cupRow)
            @php($cupPlaces = $cupSignups->places($cupRow))
            <li wire:key="cup-mention-{{ $cupRow->id }}">
                <a href="{{ route('tournaments.show', $cupRow) }}" class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-hairline px-3 py-2 text-xs text-ink-2 hover:border-line hover:text-ink" data-test="cup-mention">
                    <span class="inline-flex h-6 items-center rounded-tag bg-raised px-2 font-bold">{{ __('Casual') }}</span>
                    <span class="min-w-0 font-bold text-ink">{{ $cupRow->name }}</span>
                    <span>{{ $cupRow->status->label() }}</span>
                    @if ($cupRow->status === TournamentStatus::Signup)
                        <span class="tabular-nums" aria-label="{{ __(':taken of :places spots taken', ['taken' => $cupPlaces['taken'], 'places' => $cupPlaces['places']]) }}">{{ $cupPlaces['taken'] }}/{{ $cupPlaces['places'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
@endif
