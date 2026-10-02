@props(['tournament', 'except' => [], 'compact' => false])

{{--
    The management actions of one tournament, the same set wherever it is
    managed (user, 2026-09-28: the prize pool was a small text link and hard
    to reach): the admin tournaments list, the tournament page and the edit
    page. Each action is gated like the page it leads to:

    - Publish tournament: gate `manage-tournament`, only for a draft, first and
      orange (user, 2026-10-01: an admin could not find how to publish). It
      leads to the draft banner with the publish form at the top of the
      tournament page (#publish).
    - Prize pool / Set up the prize pool: gate `manage-tournament` (the pool
      page's own gate), not for a draft or a called-off tournament.
    - Edit: gate `manage-tournament`.
    - Payouts: gate `admin`, once the pool is open in the tournament's own
      wallet (the payouts page lists only those).

    `except`: the actions a page leaves out, e.g. ['edit'] on the edit page,
    ['publish'] on the tournament page (its draft banner holds the form).
    `compact`: 36 px buttons on a phone (the tournament page's bar above the hero).
    Renders nothing when no action is left for this viewer.
--}}
@php
    use App\Enums\TournamentStatus;
    use Illuminate\Support\Facades\Gate;

    $manageUser = auth()->user();
    $manages = $manageUser !== null && Gate::forUser($manageUser)->allows('manage-tournament', $tournament);
    $manageActions = array_values(array_filter([
        $manages && $tournament->status === TournamentStatus::Draft && ! in_array('publish', $except, true)
            ? ['publish', route('tournaments.show', $tournament).'#publish', __('Publish tournament'), 'send', 'primary'] : null,
        $manages && ! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Cancelled], true) && ! in_array('pool', $except, true) && ! $tournament->isLeagueWeek()
            ? ['pool', route('tournaments.pool', $tournament), $tournament->pool_opened_at === null ? __('Set up the prize pool') : __('Prize pool'), 'bolt', 'secondary'] : null,
        $manages && ! in_array('edit', $except, true)
            ? ['edit', route('admin.tournaments.edit', $tournament), __('Edit'), 'settings', 'quiet'] : null,
        $manageUser !== null && Gate::forUser($manageUser)->allows('admin') && $tournament->pool_opened_at !== null && $tournament->hasOwnWallet() && ! in_array('payouts', $except, true)
            ? ['payouts', route('admin.payouts', ['tournament' => $tournament->id]), __('Payouts'), 'send', 'quiet'] : null,
    ]));
@endphp

@if ($manageActions !== [])
    <div {{ $attributes->class('flex flex-wrap gap-2') }} data-test="manage-actions">
        @foreach ($manageActions as [$manageKey, $manageHref, $manageLabel, $manageIcon, $manageVariant])
            <x-button :variant="$manageVariant" :href="$manageHref" :icon="$manageIcon" :class="$compact ? 'whitespace-nowrap max-sm:h-9 max-sm:px-3' : 'whitespace-nowrap'" data-test="manage-{{ $manageKey }}">{{ $manageLabel }}</x-button>
        @endforeach
    </div>
@endif
