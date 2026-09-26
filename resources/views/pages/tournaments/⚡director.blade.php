<?php

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentResultEntry;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentView;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The director desk (TournamentDirector.dc.html, TOURNAMENT-FORMATS.md,
 * section 6): the creator and the named directors enter each result of the
 * open round with one tap (chess) or the goals of each game (Rocket League),
 * correct it until the round is closed, and close the round once every
 * result is in. Every entry and correction goes to the public log. The
 * route checks `direct-tournament`; every action checks it again (a direct
 * Livewire call cannot skip the route), and changing the directors checks
 * `manage-tournament`.
 */
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
    public Tournament $tournament;

    public string $error = '';

    public string $directorKey = '';

    /** @var array<int, array{games: array<int, array{0: string, 1: string}>, unknown: bool, winners: array<int, string>}> */
    public array $series = [];

    public function mount(Tournament $tournament): void
    {
        Gate::authorize('direct-tournament', $tournament);

        $this->tournament = $tournament;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Director desk').': '.$this->tournament->name);
    }

    /**
     * @return \Illuminate\Support\Collection<int, TournamentMatch>
     */
    #[Computed]
    public function matches(): \Illuminate\Support\Collection
    {
        $round = $this->round;

        return $round === null ? collect() : TournamentMatch::query()->where('tournament_round_id', $round->id)
            ->where('bracket', '!=', 'bye')->with(['slots.participant', 'round.stage', 'seriesMatch', 'chessGame'])->orderBy('position')->get();
    }

    #[Computed]
    public function round(): ?\App\Models\TournamentRound
    {
        return TournamentRunner::currentRound($this->tournament);
    }

    /**
     * @return \Illuminate\Support\Collection<int, TournamentResultEntry>
     */
    #[Computed]
    public function log(): \Illuminate\Support\Collection
    {
        return $this->tournament->resultEntries()->with('match')->limit(30)->get();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function enter(int $matchId, array $input): void
    {
        $match = TournamentMatch::query()->where('tournament_id', $this->tournament->id)->findOrFail($matchId);

        $this->attempt(fn () => app(TournamentRunner::class)->enterResult($match, $this->user(), $input));
    }

    public function enterSeries(int $matchId): void
    {
        $form = $this->series[$matchId] ?? ['games' => [], 'unknown' => false, 'winners' => []];
        $input = ($form['unknown'] ?? false)
            ? ['winners' => array_values(array_filter($form['winners'] ?? [], fn ($winner) => $winner !== '' && $winner !== null))]
            : ['games' => array_values(array_map(fn ($pair) => [$pair[0] ?? '', $pair[1] ?? ''], $form['games'] ?? []))];

        $this->enter($matchId, $input);
    }

    public function closeRound(): void
    {
        $round = $this->round;

        if ($round === null) {
            return;
        }

        $this->attempt(fn () => app(TournamentRunner::class)->closeRound($round, $this->user()));
    }

    public function addDirector(): void
    {
        Gate::authorize('manage-tournament', $this->tournament);

        $pubkey = NostrKeys::toHex(trim($this->directorKey));
        $user = $pubkey === null ? null : User::query()->where('pubkey', $pubkey)->first();

        if ($user === null) {
            $this->addError('directorKey', __('Enter the npub of a player who has logged in here.'));

            return;
        }

        $this->tournament->directors()->syncWithoutDetaching([$user->id => ['added_by_id' => auth()->id()]]);
        $this->reset('directorKey');
    }

    public function removeDirector(int $userId): void
    {
        Gate::authorize('manage-tournament', $this->tournament);

        $this->tournament->directors()->detach($userId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function attempt(callable $action): void
    {
        $this->error = '';

        try {
            $action();
        } catch (TournamentRuleViolation $violation) {
            $this->error = $violation->getMessage();
        }

        $this->tournament->refresh();
        unset($this->matches, $this->round, $this->log);
    }
}; ?>

@php
    $tournament = $this->tournament;
    $chess = $tournament->profile()->isChess();
    $round = $this->round;
    $matches = $this->matches;
    $left = $matches->whereNotIn('status', ['done', 'skipped'])->count();
    $zone = (string) (auth()->user()->timezone ?? config('esports.preseason.display_timezone'));
    $view = new TournamentView($tournament);
    $canManage = Gate::allows('manage-tournament', $tournament);
    $chip = $tournament->format->label().', '.trans_choice(':count player|:count players', $tournament->participants()->count()).', '.($tournament->on_site ? trans_choice('on site with :count board|on site with :count boards', (int) $tournament->stations) : __('Online'));
@endphp

<div class="flex flex-col gap-5 px-4 pt-6 pb-10 lg:px-12" data-test="director-desk">
    <a href="{{ route('tournaments.show', $tournament) }}" class="inline-flex items-center gap-1 self-start text-[13px]"><x-icon name="prev" :size="14" />{{ $tournament->name }}</a>
    <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-4">
        <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Director desk') }}</h1>
        <span class="inline-flex min-h-7 items-center self-start rounded-sm bg-raised px-2.5 text-xs text-ink-2 lg:self-auto">{{ $chip }}</span>
    </div>
    <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('Results in this tournament are entered by the tournament directors. Players can’t report or accept results. Every entry and every correction is saved with your name and the time.') }}</p>

    @if (! $tournament->isDirectorMode())
        <p class="m-0 rounded-lg bg-card px-4 py-4 text-[13px] text-ink-2">{{ __('In this tournament the players report their results.') }}</p>
    @endif

    @if ($error !== '')
        <p class="m-0 rounded-md px-4 py-3 text-[13px] text-loss shadow-[inset_0_0_0_1px_#5A2A2E]" role="alert" data-test="desk-error">{{ $error }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div class="flex min-w-0 flex-col gap-4">
            @if ($round === null)
                <p class="m-0 rounded-lg bg-card px-4 py-5 text-[13px] text-ink-2" data-test="no-open-round">{{ $tournament->status === \App\Enums\TournamentStatus::Finished ? __('The tournament is finished.') : __('No round is open right now.') }}</p>
            @else
                <section aria-labelledby="round-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="round-results">
                    <span class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="round-h" class="m-0 text-[15px] font-bold">{{ __('Round :round results', ['round' => $round->number]) }}@if ($tournament->format === \App\Enums\TournamentFormat::TwoStage) <span class="text-xs font-normal text-ink-3">({{ $round->stage->number === 1 ? __('Group stage') : __('Final stage') }})</span>@endif</h2>
                        <span class="text-xs text-ink-2">{{ __(':done of :total in', ['done' => $matches->count() - $left, 'total' => $matches->count()]) }}</span>
                    </span>

                    @foreach ($matches as $match)
                        @php
                            $box = $view->box($match);
                            [$a, $b] = [$box['sides'][0]['name'] ?? '?', $box['sides'][1]['name'] ?? '?'];
                            $entered = $match->isDirectorResult();
                            $playable = in_array($match->status, ['ready', 'done'], true);
                        @endphp
                        <div class="flex flex-col gap-2 border-t border-hairline pt-3" wire:key="m-{{ $match->id }}" data-test="desk-match" x-data="{ edit: {{ $entered ? 'false' : 'true' }} }">
                            <span class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px]">
                                <span class="font-bold text-ink-3">{{ $match->position }}</span>
                                <span class="min-w-0 grow truncate"><b>{{ $a }}</b> <span class="text-ink-3">{{ __('vs') }}</span> <b>{{ $b }}</b></span>
                                @if ($match->status === 'skipped')
                                    <span class="text-xs text-ink-3">{{ __('not needed') }}</span>
                                @elseif ($entered)
                                    <span class="font-bold text-btc" data-test="entered-label">{{ $match->result['label'] ?? '' }}</span>
                                @elseif (! $playable)
                                    <span class="text-xs text-ink-3">{{ __('waiting') }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-xs text-win"><span class="size-1.5 rounded-full bg-win"></span>{{ __('Playing') }}</span>
                                @endif
                            </span>

                            @if ($entered)
                                <div class="flex flex-wrap items-center gap-2">
                                    @include('pages.tournaments.partials.marker', ['marker' => TournamentView::marker((array) $match->result)])
                                    <button type="button" x-show="! edit" x-on:click="edit = true" class="inline-flex h-9 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-xs text-ink" data-test="correct">{{ __('Correct result') }}</button>
                                </div>
                            @endif

                            @if ($playable)
                                <div x-show="edit" class="flex flex-col gap-2">
                                    @if ($chess)
                                        <div class="flex flex-wrap gap-2">
                                            @foreach (array_filter(['1-0' => '1–0', '1/2-1/2' => TournamentRunner::allowsDraw($match) ? '½–½' : null, '0-1' => '0–1']) as $value => $label)
                                                <button type="button" wire:click="enter({{ $match->id }}, { result: '{{ $value }}' })" class="inline-flex h-11 min-w-16 cursor-pointer items-center justify-center rounded-md border border-line bg-raised px-3 text-sm font-bold text-ink" data-test="result-{{ $value }}">{{ $label }}</button>
                                            @endforeach
                                        </div>
                                        <details class="text-xs">
                                            <summary class="cursor-pointer text-btc">{{ __('Player didn’t show up') }}</summary>
                                            <div class="flex flex-wrap gap-2 pt-2">
                                                <button type="button" wire:click="enter({{ $match->id }}, { result: 'noshow-0' })" class="inline-flex h-9 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-xs text-ink">{{ __(':name didn’t show up', ['name' => $a]) }}</button>
                                                <button type="button" wire:click="enter({{ $match->id }}, { result: 'noshow-1' })" class="inline-flex h-9 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-xs text-ink">{{ __(':name didn’t show up', ['name' => $b]) }}</button>
                                            </div>
                                        </details>
                                    @else
                                        @php $bestOf = $match->seriesMatch?->best_of ?? (TournamentRunner::isFinal($match) ? $tournament->formatOptions()->finalBestOf : $tournament->formatOptions()->bestOf); @endphp
                                        <div class="flex flex-col gap-2" x-data="{ unknown: false }">
                                            <div class="flex flex-wrap gap-3" x-show="! unknown">
                                                @for ($game = 0; $game < $bestOf; $game++)
                                                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Game :number', ['number' => $game + 1]) }}
                                                        <span class="flex items-center gap-1">
                                                            <input type="number" min="0" max="99" inputmode="numeric" wire:model="series.{{ $match->id }}.games.{{ $game }}.0" class="h-11 w-14 rounded-md border border-line bg-well text-center text-[13px] text-ink" aria-label="{{ $a }}">
                                                            <span>:</span>
                                                            <input type="number" min="0" max="99" inputmode="numeric" wire:model="series.{{ $match->id }}.games.{{ $game }}.1" class="h-11 w-14 rounded-md border border-line bg-well text-center text-[13px] text-ink" aria-label="{{ $b }}">
                                                        </span>
                                                    </label>
                                                @endfor
                                            </div>
                                            <div class="flex flex-wrap gap-3" x-show="unknown" x-cloak>
                                                @for ($game = 0; $game < $bestOf; $game++)
                                                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Game :number', ['number' => $game + 1]) }}
                                                        <select wire:model="series.{{ $match->id }}.winners.{{ $game }}" class="h-11 rounded-md border border-line bg-well px-2 text-[13px] text-ink">
                                                            <option value="">–</option>
                                                            <option value="0">{{ $a }}</option>
                                                            <option value="1">{{ $b }}</option>
                                                        </select>
                                                    </label>
                                                @endfor
                                            </div>
                                            <label class="flex min-h-9 items-center gap-2 text-xs text-ink-2"><input type="checkbox" x-model="unknown" wire:model="series.{{ $match->id }}.unknown" class="accent-[#F7931A]">{{ __('Goals unknown, enter winners only') }}</label>
                                            <div class="flex flex-wrap gap-2">
                                                <x-button wire:click="enterSeries({{ $match->id }})" data-test="save-series">{{ __('Save series result') }}</x-button>
                                                <details class="text-xs">
                                                    <summary class="flex h-11 cursor-pointer items-center text-btc">{{ __('Team didn’t show up') }}</summary>
                                                    <div class="flex flex-wrap gap-2 pt-1">
                                                        <button type="button" wire:click="enter({{ $match->id }}, { noshow: 0 })" class="inline-flex h-9 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-xs text-ink">{{ __(':name didn’t show up', ['name' => $a]) }}</button>
                                                        <button type="button" wire:click="enter({{ $match->id }}, { noshow: 1 })" class="inline-flex h-9 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-xs text-ink">{{ __(':name didn’t show up', ['name' => $b]) }}</button>
                                                    </div>
                                                </details>
                                            </div>
                                        </div>
                                    @endif
                                    @if ($entered)
                                        <p class="m-0 text-xs text-btc">{{ __('Pick the right result. The change is saved with your name.') }}</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div class="flex flex-col gap-3 border-t border-hairline pt-4 lg:flex-row lg:items-center">
                        <p class="m-0 grow text-xs text-ink-2" data-test="close-hint">
                            {{ $left > 0
                                ? __(':count boards are still playing. You can close the round once every result is in.', ['count' => $left])
                                : __('All results are in. Closing locks round :round and moves the tournament on.', ['round' => $round->number]) }}
                        </p>
                        <x-button wire:click="closeRound" :disabled="$left > 0" class="disabled:cursor-not-allowed disabled:opacity-50" data-test="close-round">{{ __('Close round :round', ['round' => $round->number]) }}</x-button>
                    </div>
                </section>
            @endif
        </div>

        <div class="flex flex-col gap-4">
            <section aria-labelledby="log-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="director-log">
                <span class="flex items-baseline justify-between gap-3">
                    <h2 id="log-h" class="m-0 text-[15px] font-bold">{{ __('Log') }}</h2>
                    <span class="text-xs text-ink-3">{{ __('public, newest first') }}</span>
                </span>
                @forelse ($this->log as $entry)
                    <p class="m-0 flex gap-3 border-t border-hairline pt-2 text-xs leading-normal" data-test="log-entry">
                        <span class="shrink-0 text-ink-3">{{ $entry->created_at->copy()->timezone($zone)->format('H:i') }}</span>
                        <span>
                            <b>{{ strtoupper($entry->match->key) }} {{ $entry->result['label'] ?? '' }}</b>
                            {{ __('by :name', ['name' => $entry->user_name]) }}@if ($entry->isCorrection()), <span class="text-btc">{{ __('correction, was :old', ['old' => $entry->previous['label'] ?? '']) }}</span>@endif
                        </span>
                    </p>
                @empty
                    <p class="m-0 text-xs text-ink-2">{{ __('No result entered yet.') }}</p>
                @endforelse
            </section>

            <section aria-labelledby="dirs-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
                <h2 id="dirs-h" class="m-0 text-[15px] font-bold">{{ __('Tournament directors') }}</h2>
                <ul class="m-0 flex list-none flex-col gap-2 p-0 text-[13px]">
                    @if ($tournament->creator)
                        <li class="flex items-center gap-2">{{ $tournament->creator->displayName() }} <span class="text-xs text-ink-3">{{ __('creator') }}</span></li>
                    @endif
                    @foreach ($tournament->directors as $director)
                        <li class="flex items-center gap-2" wire:key="d-{{ $director->id }}">{{ $director->displayName() }} <span class="grow text-xs text-ink-3">{{ __('director') }}</span>
                            @if ($canManage)
                                <button type="button" wire:click="removeDirector({{ $director->id }})" class="cursor-pointer text-xs text-loss">{{ __('Remove') }}</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($canManage)
                    <form wire:submit="addDirector" class="flex flex-col gap-2">
                        <input type="text" wire:model="directorKey" placeholder="npub1…" class="h-11 rounded-md border border-line bg-well px-3 text-[13px] text-ink" aria-label="{{ __('Director npub') }}">
                        @error('directorKey')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                        <div><x-button type="submit" variant="quiet">{{ __('Add director') }}</x-button></div>
                    </form>
                @endif
            </section>
        </div>
    </div>
</div>
