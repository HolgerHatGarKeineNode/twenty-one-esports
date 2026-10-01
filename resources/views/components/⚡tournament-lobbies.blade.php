<?php

use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\LobbyResults;
use App\Support\Tournaments\TournamentRuleViolation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * The lobby cards of a lobby tournament (plan "AoE2 und Trackmania", P10,
 * App\Support\Tournaments\Lobbies): one card per lobby with its players,
 * the settings fixed at the draw (the map size follows the players), the
 * league's lobby name and password (for the lobby's players and the
 * directors only, never published), and its places once decided.
 *
 * A player of the lobby reports the places with a screenshot of the end
 * screen until the report deadline; a director or admin without a stake
 * confirms or rejects the report, or enters the places, also after the
 * deadline (App\Support\Tournaments\LobbyResults).
 */
new class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $tournamentId;

    /** @var array<int|string, array<int|string, int|string>> match id => participant id => place */
    public array $places = [];

    /** The end screen of the viewer's own report. */
    public $shot = null;

    /** @var array<int|string, string> match id => why a director rejects the report */
    public array $reasons = [];

    /** @var array<int|string, string> match id => the last refusal */
    public array $messages = [];

    public function mount(Tournament $tournament): void
    {
        $this->tournamentId = $tournament->id;
    }

    #[Computed]
    public function tournament(): Tournament
    {
        return Tournament::query()->findOrFail($this->tournamentId);
    }

    /**
     * @return Collection<int, TournamentMatch>
     */
    #[Computed]
    public function lobbies(): Collection
    {
        return TournamentMatch::query()->where('tournament_id', $this->tournamentId)->whereNotNull('lobby')
            ->with(['slots.participant', 'tournament'])->orderBy('position')->get();
    }

    public function report(int $matchId): void
    {
        $this->validate(['shot' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:'.LobbyResults::SCREENSHOT_MAX_KB]], [], ['shot' => __('screenshot')]);

        if ($this->attempt($matchId, fn (TournamentMatch $match, User $user) => app(LobbyResults::class)->report($match, $user, $this->places[$matchId] ?? [], $this->shot))) {
            $this->reset('shot');
        }
    }

    /**
     * Confirm the report this card showed (`$shown`: LobbyResults::reportIdentity()); a report changed since is refused.
     */
    public function confirm(int $matchId, string $shown): void
    {
        $this->attempt($matchId, fn (TournamentMatch $match, User $user) => app(LobbyResults::class)->confirm($match, $user, $shown));
    }

    public function enter(int $matchId): void
    {
        $this->attempt($matchId, fn (TournamentMatch $match, User $user) => app(LobbyResults::class)->enter($match, $user, $this->places[$matchId] ?? []));
    }

    public function reject(int $matchId, string $shown): void
    {
        if ($this->attempt($matchId, fn (TournamentMatch $match, User $user) => app(LobbyResults::class)->reject($match, $user, (string) ($this->reasons[$matchId] ?? ''), $shown))) {
            unset($this->reasons[$matchId]);
        }
    }

    /**
     * Run one action on a lobby of this tournament; a refusal is shown on its card.
     *
     * @param  \Closure(TournamentMatch, User): mixed  $action
     */
    private function attempt(int $matchId, \Closure $action): bool
    {
        $user = auth()->user();
        $match = TournamentMatch::query()->where('tournament_id', $this->tournamentId)->whereNotNull('lobby')->find($matchId);
        unset($this->messages[$matchId]);

        if (! $user instanceof User || $match === null) {
            $this->messages[$matchId] = __('Log in to report a lobby.');

            return false;
        }

        try {
            $action($match, $user);
        } catch (TournamentRuleViolation $violation) {
            $this->messages[$matchId] = $violation->getMessage();

            return false;
        }

        unset($this->lobbies);

        return true;
    }
}; ?>

@php
    $tournament = $this->tournament;
    $viewer = auth()->user();
    $zone = (string) ($viewer?->timezone ?? config('esports.preseason.display_timezone'));
    $time = fn (?string $at): string => $at === null ? '' : \Carbon\CarbonImmutable::parse($at)->setTimezone($zone)->translatedFormat('D j M, H:i');
@endphp

<section aria-labelledby="lobbies-h" class="flex flex-col gap-4" data-test="lobbies">
    <div class="flex flex-col gap-1">
        <h2 id="lobbies-h" class="m-0 text-lg font-bold">{{ trans_choice(':count lobby|:count lobbies', $this->lobbies->count()) }}</h2>
        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ __('One diplomacy game per lobby. Join yours with the name and password on its card; the settings below are fixed for it.') }}</p>
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($this->lobbies as $match)
            @php
                $lobby = (array) $match->lobby;
                $plays = $viewer instanceof User && LobbyResults::plays($match, $viewer);
                $decides = LobbyResults::mayDecide($tournament, $match, $viewer);
                $sees = $plays || ($viewer instanceof User && \Illuminate\Support\Facades\Gate::forUser($viewer)->allows('direct-tournament', $tournament));
                $done = $match->result !== null;
                $report = is_array($match->lobby_report) ? $match->lobby_report : null;
                $open = ! $done && $match->status === 'ready' && $tournament->status === \App\Enums\TournamentStatus::Running;
                $reportOpen = LobbyResults::reportOpen($match);
                $count = $match->slots->count();
                $ranks = (array) ($match->result['ranks'] ?? []);
                $unplaced = array_map(intval(...), (array) ($match->result['unplaced'] ?? []));
                $sides = $match->slots->map(fn ($slot): array => [
                    'id' => (int) $slot->tournament_participant_id,
                    'name' => (string) ($slot->participant->name ?? '?'),
                    'place' => $done && ! in_array((int) $slot->tournament_participant_id, $unplaced, true) ? ($ranks[$slot->slot] ?? null) : null,
                    'reported' => $report['places'][$slot->tournament_participant_id] ?? null,
                ])->sortBy(fn (array $side): array => [$side['place'] ?? PHP_INT_MAX, $side['name']])->values();
            @endphp
            <article wire:key="lobby-{{ $match->id }}" class="flex min-w-0 flex-col gap-4 rounded-card bg-card p-4 lg:p-5" data-test="lobby-card" data-lobby="{{ $match->position }}" data-players="{{ $count }}">
                <header class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="m-0 text-[15px] font-bold">{{ __('Lobby :number', ['number' => $match->position]) }} <span class="font-normal text-ink-2">· {{ trans_choice(':count player|:count players', $count) }}</span></h3>
                    @if ($done)
                        <span class="inline-flex h-6 items-center rounded-xs bg-win-tint px-2 text-xs font-bold text-win" data-test="lobby-status">{{ __('decided') }}</span>
                    @elseif ($report !== null)
                        <span class="inline-flex h-6 items-center rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi" data-test="lobby-status">{{ __('reported, waiting for a director') }}</span>
                    @else
                        <span class="inline-flex h-6 items-center gap-1.5 rounded-xs bg-btc-chip px-2 text-xs font-bold text-btc-hi" data-test="lobby-status"><span class="size-1.5 animate-live rounded-full bg-btc"></span>{{ __('Up now') }}</span>
                    @endif
                </header>

                @if ($sees)
                    <div x-data="{ show: false }" class="flex flex-col rounded-md bg-ground px-3 shadow-ring-hairline" data-test="lobby-access">
                        <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)_auto] items-center gap-2 border-b border-hairline text-sm">
                            <span class="text-ink-2">{{ __('Name') }}</span><b class="min-w-0 break-all" data-test="lobby-name">{{ $lobby['name'] ?? '' }}</b>
                            <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) ($lobby['name'] ?? '')))" aria-label="{{ __('Copy lobby name') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="copy" :size="16" /></button>
                        </div>
                        <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)_auto_auto] items-center gap-2 text-sm">
                            <span class="text-ink-2">{{ __('Password') }}</span>
                            <span class="min-w-0"><span x-show="! show">••••••</span><b x-show="show" x-cloak class="break-all" data-test="lobby-password">{{ $match->lobby_password ?? '–' }}</b></span>
                            <button type="button" x-on:click="show = ! show" :aria-pressed="show ? 'true' : 'false'" aria-label="{{ __('Show password') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="eye" :size="16" /></button>
                            <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) $match->lobby_password))" aria-label="{{ __('Copy password') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="copy" :size="16" /></button>
                        </div>
                    </div>
                    <p class="m-0 -mt-2 flex items-center gap-2 text-xs text-ink-2"><x-icon name="lock" :size="14" class="text-win" />{{ __('Only this lobby\'s players and the directors see this. It is never published.') }}</p>
                @endif

                <dl class="m-0 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-[13px]" data-test="lobby-settings">
                    @foreach (Lobbies::facts($lobby) as [$label, $value])
                        <dt class="text-ink-2">{{ $label }}</dt>
                        <dd class="m-0 min-w-0 font-bold break-words">{{ $value }}</dd>
                    @endforeach
                </dl>

                <ol class="m-0 flex list-none flex-col gap-1 p-0 text-[13px]" data-test="lobby-players">
                    @foreach ($sides as $side)
                        <li class="flex min-w-0 items-center gap-2" data-test="lobby-player">
                            <span @class(['inline-flex h-6 min-w-9 items-center justify-center rounded-xs px-1.5 text-xs font-bold tabular-nums', 'bg-btc text-on-btc' => $side['place'] === 1, 'bg-raised text-ink-2' => $side['place'] !== 1])>{{ $side['place'] !== null ? '#'.$side['place'] : '–' }}</span>
                            <span @class(['min-w-0 grow truncate', 'font-bold' => $side['place'] === 1])>{{ $side['name'] }}</span>
                        </li>
                    @endforeach
                </ol>
                @if ($done)
                    <p class="m-0 text-xs text-ink-2" data-test="lobby-result">{{ LobbyResults::describe((array) $match->result) }}</p>
                @endif

                @if ($open)
                    @if (isset($lobby['report_by']))
                        <p class="m-0 text-xs leading-normal text-ink-2" data-test="lobby-deadline">{{ $reportOpen ? __('Players report by :time; after that a director decides.', ['time' => $time($lobby['report_by'])]) : __('The time to report is over: a director decides this lobby.') }}</p>
                    @endif
                    @if (isset($lobby['rejected']) && $report === null)
                        <p class="m-0 text-xs leading-normal text-loss" data-test="lobby-rejected">{{ __('A report was rejected by :name: :reason', ['name' => (string) ($lobby['rejected']['name'] ?? ''), 'reason' => (string) ($lobby['rejected']['reason'] ?? '')]) }}</p>
                    @endif
                    @if ($report !== null)
                        <p class="m-0 text-xs leading-normal text-ink-2" data-test="lobby-reported">{{ __('Reported by :name at :time.', ['name' => (string) ($report['name'] ?? ''), 'time' => $time($report['at'] ?? null)]) }}</p>
                    @endif

                    @if (($plays && $reportOpen) || $decides)
                        <form wire:submit="{{ $decides && ! $plays ? 'enter' : 'report' }}({{ $match->id }})" class="flex flex-col gap-3 border-t border-hairline pt-3" data-test="lobby-report-form">
                            <span class="text-[13px] font-bold">{{ $decides && ! $plays ? __('Enter the places') : __('Report the places') }}</span>
                            <span class="text-xs leading-normal text-ink-2">{{ __('Place 1 for every ally still standing at the end; everyone else by the order they were defeated, the first one out last.') }}</span>
                            @foreach ($sides as $side)
                                <label class="grid min-w-0 grid-cols-[minmax(0,1fr)_96px] items-center gap-3 text-[13px]" wire:key="place-{{ $match->id }}-{{ $side['id'] }}">
                                    <span class="min-w-0 truncate">{{ $side['name'] }}</span>
                                    <select wire:model="places.{{ $match->id }}.{{ $side['id'] }}" class="h-11 w-full rounded-md border border-edge bg-ground px-2 text-[13px] text-ink" data-test="lobby-place">
                                        <option value="">–</option>
                                        @foreach (range(1, $count) as $place)
                                            <option value="{{ $place }}">#{{ $place }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                            @if (! ($decides && ! $plays))
                                <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Screenshot of the end screen') }}
                                    <input type="file" wire:model="shot" accept="image/png,image/jpeg,image/webp" class="max-w-full text-xs text-ink-2 file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-well file:px-3 file:text-ink" data-test="lobby-shot">
                                </label>
                                @error('shot')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                            @endif
                            <div><x-button type="submit" icon="send" data-test="lobby-submit">{{ $decides && ! $plays ? __('Save the places') : __('Send report') }}</x-button></div>
                        </form>
                    @endif

                    @if ($decides && $report !== null)
                        <div class="flex flex-col gap-2 border-t border-hairline pt-3" data-test="lobby-review">
                            <span class="text-[13px] font-bold">{{ __('The reported places') }}</span>
                            <ul class="m-0 flex list-none flex-col gap-0.5 p-0 text-xs text-ink-2">
                                @foreach ($sides as $side)
                                    <li class="truncate">{{ $side['reported'] !== null ? '#'.$side['reported'] : '–' }} · {{ $side['name'] }}</li>
                                @endforeach
                            </ul>
                            <a href="{{ route('tournaments.lobby-screenshot', [$tournament, $match]) }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center self-start text-[13px]" data-test="lobby-screenshot">{{ __('Open the end screen') }}</a>
                            <div class="flex flex-wrap gap-3">
                                <x-button type="button" icon="check" wire:click="confirm({{ $match->id }}, '{{ LobbyResults::reportIdentity($report) }}')" data-test="lobby-confirm">{{ __('Confirm') }}</x-button>
                            </div>
                            <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Reason to reject') }}
                                <input wire:model="reasons.{{ $match->id }}" maxlength="300" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink" data-test="lobby-reject-reason">
                            </label>
                            <div><x-button variant="quiet" type="button" wire:click="reject({{ $match->id }}, '{{ LobbyResults::reportIdentity($report) }}')" data-test="lobby-reject">{{ __('Reject the report') }}</x-button></div>
                        </div>
                    @endif
                @endif

                @if (($this->messages[$match->id] ?? '') !== '')
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="lobby-error">{{ $this->messages[$match->id] }}</p>
                @endif
            </article>
        @endforeach
    </div>
</section>
