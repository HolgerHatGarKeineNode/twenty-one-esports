<?php

use App\Enums\ReportStatus;
use App\Enums\SeriesStatus;
use App\Models\ChatMute;
use App\Models\LineupSeat;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Series\SeriesPresenter;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * The match room of the two lineups, 1:1 from MatchRoom.dc.html and
 * MobileMatchRoom.dc.html, with the Submit dialog and the win moment of
 * Overlays.dc.html. Only players of the two lineups get in; everyone else is
 * sent to the public match page, so the lobby never reaches them.
 *
 * Casual until Block 0: no Elo, no mining, no league check; the "can mine"
 * line of the design is the "Casual until Block 0" line of States.dc.html.
 * Signed steps go through nostrAction (resources/js/nostrSign.js): for a
 * casual match every prepare returns no template and nothing is signed.
 */
new #[Title('Match room')] #[Layout('layouts::app', ['section' => 'matches', 'scripts' => ['resources/js/matchRoom.js']])] class extends Component {
    use WithFileUploads;

    public SeriesMatch $match;

    /** @var list<array{c: int|string|null, d: int|string|null, unknown: bool, winner: string|null}> */
    public array $sheet = [];

    public ?int $pickedStart = null;

    public string $reason = '';

    /** @var list<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $shots = [];

    public bool $editLobby = false;

    public string $lobbyName = '';

    public string $lobbyPassword = '';

    public ?string $lobbyRegion = null;

    public string $error = '';

    public function mount(SeriesMatch $match): mixed
    {
        $this->match = $match;

        if ($this->fresh()->participantSideOf($this->user()) === null) {
            return $this->redirectRoute('matches.show', $match);
        }

        $this->pickedStart = $match->proposals[0] ?? null;
        $this->sync();

        return null;
    }

    /** Pull the live sheet written by the other captain (called every few seconds). */
    public function sync(): void
    {
        $live = $this->fresh()->live_games ?? [];
        $this->sheet = [];

        for ($i = 0; $i < $this->match->best_of; $i++) {
            $game = $live[$i] ?? null;
            $this->sheet[] = [
                'c' => $game['challenger'] ?? null,
                'd' => $game['challenged'] ?? null,
                'unknown' => $game !== null && ($game['winner'] ?? null) !== null && ($game['challenger'] ?? null) === null,
                'winner' => $game['winner'] ?? null,
            ];
        }

        unset($this->room);
    }

    public function updatedSheet(mixed $value, string $key): void
    {
        $index = (int) explode('.', $key)[0];
        $row = $this->sheet[$index] ?? null;

        if ($row === null) {
            return;
        }

        $goal = fn (mixed $v): ?int => $v === null || $v === '' ? null : (is_numeric($v) ? (int) $v : -1);
        $c = $row['unknown'] ? null : $goal($row['c']);
        $d = $row['unknown'] ? null : $goal($row['d']);

        // Half a score is still being typed: wait for the other box.
        if (! $row['unknown'] && ($c === null) !== ($d === null)) {
            return;
        }

        $this->attempt(fn () => app(SeriesService::class)->saveLiveGame($this->match, $this->user(), $index, $c, $d, $row['unknown'] ? $row['winner'] : null));
        $this->sync();
    }

    public function toggleRoster(int $userId): void
    {
        $match = $this->fresh();
        $side = $match->captainSideOf($this->user());

        if ($side === null) {
            return;
        }

        $current = array_map(fn (LineupSeat $seat) => $seat->user_id, app(SeriesService::class)->rosterSeats($match, $side));
        $next = in_array($userId, $current, true) ? array_values(array_diff($current, [$userId])) : [...$current, $userId];

        $this->attempt(fn () => app(SeriesService::class)->setRoster($match, $this->user(), $next));
        unset($this->room);
    }

    public function openLobbyEditor(): void
    {
        $match = $this->fresh();

        if ($match->captainSideOf($this->user()) === null) {
            return;
        }

        $this->lobbyName = (string) $match->lobby_name;
        $this->lobbyPassword = (string) $match->lobby_password;
        $this->lobbyRegion = $match->lobby_region ?? 'EU';
        $this->editLobby = true;
    }

    public function saveLobby(): void
    {
        if ($this->attempt(fn () => app(SeriesService::class)->setLobby($this->match, $this->user(), $this->lobbyName, $this->lobbyPassword, $this->lobbyRegion))) {
            $this->editLobby = false;
            $this->reset('lobbyName', 'lobbyPassword');
        }
    }

    public function reportNoShow(): void
    {
        $this->attempt(fn () => app(SeriesService::class)->reportNoShow($this->match, $this->user()));
    }

    /* Two-phase actions for nostrAction: prepare returns the templates (none when casual). */

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareAnswer(string $status, ?int $start = null): ?array
    {
        return $this->attempt(fn () => app(SeriesService::class)->prepareAnswer($this->match, $this->user(), $status, $start));
    }

    public function answer(string $status, ?int $start, string $signed): void
    {
        $this->attempt(fn () => app(SeriesService::class)->answer($this->match, $this->user(), $status, $start, $this->decode($signed)));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareReport(): ?array
    {
        return $this->attempt(fn () => app(SeriesService::class)->prepareReport($this->match, $this->user()));
    }

    public function report(string $signed): void
    {
        $this->attempt(fn () => app(SeriesService::class)->report($this->match, $this->user(), $this->decode($signed)));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    public function prepareResponse(string $status): ?array
    {
        if ($status === 'disputed') {
            $this->validate(['reason' => ['required', 'string', 'max:'.SeriesService::REASON_MAX], 'shots' => ['array', 'max:3'], 'shots.*' => ['image', 'max:5120']]);
        }

        return $this->attempt(fn () => app(SeriesService::class)->prepareResponse($this->match, $this->user(), $status, $this->reason));
    }

    public function respond(string $status, string $signed): void
    {
        $done = $this->attempt(fn () => app(SeriesService::class)->respond($this->match, $this->user(), $status, $this->reason, $this->decode($signed)));

        if ($done !== null && $status === 'disputed') {
            foreach ($this->shots as $shot) {
                app(SeriesService::class)->addEvidence($this->match, $this->user(), $shot);
            }

            $this->reset('reason', 'shots');
        }
    }

    public function setMuted(string $pubkey, bool $mute): bool
    {
        $user = $this->user();
        $members = array_column($this->chatConfig()['members'], 'pubkey');

        // Only players of this room, never oneself.
        if ($pubkey === $user->pubkey || ! in_array($pubkey, $members, true)) {
            return false;
        }

        if ($mute) {
            ChatMute::query()->firstOrCreate(['user_id' => $user->id, 'muted_pubkey' => $pubkey]);
        } else {
            ChatMute::query()->where('user_id', $user->id)->where('muted_pubkey', $pubkey)->delete();
        }

        return true;
    }

    /**
     * Everything the view needs, read once per render.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function room(): array
    {
        $match = $this->fresh();
        $user = $this->user();
        $series = app(SeriesService::class);
        $draft = null;
        $draftError = null;

        if (in_array($match->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true)) {
            try {
                $draft = $series->draftReport($match);
            } catch (SeriesRuleViolation $violation) {
                $draftError = $violation->getMessage();
            }
        }

        $rosters = [];

        foreach (SeriesMatch::SIDES as $side) {
            $chosen = array_map(fn (LineupSeat $seat) => $seat->user_id, $series->rosterSeats($match, $side));
            $rosters[$side] = array_map(fn (LineupSeat $seat) => ['seat' => $seat, 'on' => in_array($seat->user_id, $chosen, true)], $match->lineup($side)?->activeSeats() ?? []);
        }

        return [
            'match' => $match,
            'mySide' => $match->participantSideOf($user),
            'captainSide' => $match->captainSideOf($user),
            'report' => $match->latestReport,
            'draft' => $draft,
            'draftError' => $draftError,
            'rosters' => $rosters,
        ];
    }

    /**
     * Config for the room chat (resources/js/roomChat.js): every active
     * player of both lineups, the chat relays, the viewer's mutes.
     *
     * @return array<string, mixed>
     */
    public function chatConfig(): array
    {
        $match = $this->room['match'];
        $members = [];

        foreach (SeriesMatch::SIDES as $side) {
            foreach ($match->lineup($side)?->activeSeats() ?? [] as $seat) {
                $members[$seat->user->pubkey] = ['pubkey' => $seat->user->pubkey, 'name' => $seat->user->displayName(), 'side' => $side];
            }
        }

        return [
            'me' => $this->user()->pubkey,
            'members' => array_values($members),
            'match' => $match->number,
            'relays' => array_values(config('esports.chat.relays', [])),
            'muted' => $this->user()->mutedPubkeys(),
            'labels' => [
                'you' => __('you'),
                'mute' => __('Mute'),
                'muted' => __('Muted'),
                'noSigner' => __('No Nostr signer found. Install a Nostr browser extension or use a remote signer.'),
                'notSent' => __('The message did not reach any relay. Please try again.'),
                'failed' => __('That did not work. Please try again.'),
            ],
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|null
     */
    private function attempt(callable $action): mixed
    {
        $this->error = '';

        try {
            $result = $action();
            unset($this->room);

            return $result ?? true;
        } catch (SeriesRuleViolation $violation) {
            $this->error = $violation->getMessage();
        } catch (RejectedEvent) {
            $this->error = __('The signed event was refused. Please try again.');
        }

        unset($this->room);

        return null;
    }

    /**
     * @return list<mixed>
     */
    private function decode(string $signed): array
    {
        $events = json_decode($signed, true);

        return is_array($events) && array_is_list($events) ? $events : [['malformed']];
    }

    private function fresh(): SeriesMatch
    {
        return SeriesMatch::query()
            ->with(['challengerLineup.clan', 'challengerLineup.seats.user.clanMember', 'challengedLineup.clan', 'challengedLineup.seats.user.clanMember',
                'latestReport.event', 'latestReport.responseEvent', 'latestReport.user', 'challengeEvent', 'answerEvent', 'createdBy', 'answeredBy'])
            ->findOrFail($this->match->id);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    [
        'match' => $m, 'mySide' => $mySide, 'captainSide' => $captainSide, 'report' => $report,
        'draft' => $draft, 'draftError' => $draftError, 'rosters' => $rosters,
    ] = $this->room;
    $viewer = auth()->user();
    $other = $mySide === null ? 'challenged' : SeriesMatch::otherSide($mySide);
    $games = $m->currentGames();
    $wins = SeriesMatch::seriesScore($games);
    $chip = SeriesPresenter::chip($m);
    $editable = $captainSide !== null && in_array($m->status, [SeriesStatus::Accepted, SeriesStatus::Disputed], true) && ! $m->start_at?->isFuture();
    $toAnswer = $m->status === SeriesStatus::Reported && $report?->status === ReportStatus::Open && $captainSide !== null && $captainSide !== $report->side;
    $playing = count(array_filter($this->sheet, fn ($g) => $g['winner'] !== null));
    $captainOf = fn (string $side) => $m->lineup($side)?->clan?->owner?->displayName() ?? '';
    $noshowFrom = $m->start_at?->copy()->addMinutes((int) config('esports.series.noshow_minutes', 15));
    $checks = ($report !== null && $report->status !== ReportStatus::Superseded ? 1 : 0) + ($m->status->hasResult() ? 1 : 0);
    $status = match ($m->status) {
        SeriesStatus::Open => [__('Waiting for an answer'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Accepted => $m->start_at?->isFuture() ? [__('Starts :time', ['time' => SeriesPresenter::time($m->start_at, $viewer, 'D H:i')]), 'bg-well text-ink-2'] : [__('Score to submit'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Reported => [__('Not confirmed yet'), 'bg-btc-press text-btc-hi'],
        SeriesStatus::Disputed => [__('Disputed'), 'bg-loss-tint text-loss'],
        SeriesStatus::Confirmed, SeriesStatus::Resolved => [__('Result saved'), 'bg-win-tint text-win'],
        default => [$chip['label'], 'bg-well text-ink-2'],
    };
    $sideColor = ['challenger' => '#F7931A', 'challenged' => '#3A3A42'];
    $sideInk = ['challenger' => 'text-on-btc', 'challenged' => 'text-ink'];
    $seriesMeta = implode(', ', array_filter([
        'BO'.$m->best_of,
        $m->mode,
        ! $m->status->hasResult() && $games !== [] ? __('provisional') : null,
        $m->status === SeriesStatus::Accepted && ! $m->start_at?->isFuture() ? __('game :n playing', ['n' => min($m->best_of, $playing + 1)]) : null,
    ]));
    $steps = [
        [__('Challenge'), SeriesPresenter::time($m->created_at ?? now(), $viewer, 'D H:i'), __('sent by :clan', ['clan' => $m->challenger_name]), true],
        [__('Accepted'), $m->answered_at && $m->start_at ? SeriesPresenter::time($m->answered_at, $viewer, 'D H:i') : '', $m->answered_at && $m->start_at ? __('by :clan', ['clan' => $m->challenged_name]) : __('waiting'), $m->start_at !== null],
        [__('Result'), $report ? SeriesPresenter::time($report->created_at ?? now(), $viewer, 'H:i') : __('now'), $report ? __(':clan submitted, 1 of 2', ['clan' => $m->sideName($report->side)]) : __('a captain submits, 1 of 2'), $report !== null],
        [__('Their OK'), $m->finished_at && $m->status->hasResult() ? SeriesPresenter::time($m->finished_at, $viewer, 'H:i') : '', $m->status === SeriesStatus::Disputed ? __('problem reported') : __('the other captain, 2 of 2'), $m->status->hasResult()],
    ];
@endphp

<div class="flex grow flex-col gap-5 px-4 pt-5 pb-28 lg:mx-auto lg:w-full lg:max-w-[1200px] lg:px-0 lg:pt-8 lg:pb-10" data-test="match-room"
     x-data="{ submit: false }"
     x-init="setInterval(() => { if (! document.activeElement?.matches('input, textarea, select') && ! submit) $wire.sync() }, 8000)">

    {{-- Header --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="m-0 font-display text-[26px] font-bold lg:text-[34px]"><span class="lg:hidden">{{ __('Match room') }}</span><span class="max-lg:hidden">{{ __('Match') }}</span></h1>
        <span class="font-display text-xl font-bold text-ink-2 max-lg:hidden lg:text-[28px]">{{ $m->label() }}</span>
        <span class="inline-flex h-[26px] items-center rounded-sm bg-btc-chip px-2.5 text-xs font-bold text-btc-hi shadow-[inset_0_0_0_1px_#B9640A]">{{ $m->rated ? __('Rated') : __('Casual') }}</span>
        <span class="grow"></span>
        <span class="inline-flex h-[34px] items-center gap-2 rounded-md px-3.5 text-[13px] font-bold {{ $status[1] }}" data-test="room-status"><span class="size-[7px] animate-live rounded-full bg-current"></span>{{ $status[0] }}</span>
        <span class="text-[13px] text-ink-2 max-lg:hidden">{{ __(':n of 2 checks', ['n' => $checks]) }}</span>
        <span class="w-full text-[13px] text-ink-2 lg:hidden">{{ __('Match :number, Rocket League', ['number' => $m->label()]) }}</span>
    </div>

    {{-- Versus --}}
    <section aria-label="{{ __('Series') }}" class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 rounded-lg px-4 py-4 lg:grid-cols-[minmax(0,1fr)_280px_minmax(0,1fr)] lg:px-8 lg:py-5" style="background: linear-gradient(90deg, #1E1A12, #121215 38%, #121215 62%, #17171B)">
        @foreach (['challenger', 'score', 'challenged'] as $cell)
            @if ($cell === 'score')
                <span class="flex flex-col items-center gap-1 text-center">
                    <b class="font-display text-[34px] leading-[1.1] font-extrabold lg:text-[56px]" data-test="series-score">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b>
                    <span class="text-xs text-ink-2 max-lg:hidden lg:text-[13px]" data-test="series-meta">{{ $seriesMeta }}</span>
                </span>
            @else
                <span @class(['flex items-center gap-3 lg:gap-4', 'flex-row-reverse text-right' => $cell === 'challenged'])>
                    <span class="cube hidden size-16 shrink-0 items-center justify-center font-display text-[15px] font-extrabold lg:mt-2.5 lg:flex {{ $sideInk[$cell] }}" style="background: {{ $sideColor[$cell] }}">{{ $m->sideTag($cell) }}</span>
                    <span @class(['flex min-w-0 flex-col gap-1', 'lg:ml-2.5' => $cell === 'challenger', 'items-end lg:mr-6' => $cell === 'challenged'])>
                        <b class="font-display text-[26px] font-extrabold lg:hidden" style="color: {{ $cell === 'challenger' ? '#F7931A' : '#ADADB0' }}">{{ $m->sideTag($cell) }}</b>
                        <b class="truncate text-[13px] lg:font-display lg:text-xl">{{ $m->sideName($cell) }}</b>
                        <span class="text-xs text-ink-2 lg:text-[13px]">{{ $captainSide === $cell ? __('you are captain') : __('captain :name', ['name' => $captainOf($cell)]) }}</span>
                    </span>
                </span>
            @endif
        @endforeach
        <span class="col-span-3 text-center text-xs text-ink-2 lg:hidden">{{ $seriesMeta }}</span>
    </section>

    {{-- Casual until Block 0 (the design's "can mine" line, States.dc.html "Locked until Block 0") --}}
    <section aria-label="{{ __('Season chain') }}" class="flex min-h-14 items-center gap-3.5 rounded-lg bg-card px-5 py-3 shadow-[inset_0_0_0_1px_#2A2A30]" data-test="casual-line">
        <x-icon name="lock" :size="18" class="shrink-0 text-ink-2" />
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <b class="text-sm leading-[1.4]">{{ $m->rated ? __('Rated series') : __('Casual until Block 0 · no rating, no reward') }}</b>
            <span class="text-xs leading-normal text-ink-2">{{ __('Rated play and mining start at Block 0. Until then every series is casual: it counts for no rating, Block Height or Clan Hashrate, and it stays casual even if it ends later.') }}</span>
        </span>
        <a href="{{ route('rules') }}" class="inline-flex min-h-11 shrink-0 items-center text-xs whitespace-nowrap max-lg:hidden">{{ __('How mining works') }}</a>
    </section>

    @if ($error)
        <p class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" role="alert" data-test="room-error">{{ $error }}</p>
    @endif

    {{-- Win moment (Overlays.dc.html "Win, once the other captain accepts") --}}
    @if ($m->status->hasResult())
        @php($won = $m->winner === $mySide)
        <section aria-label="{{ __('Result') }}" class="grid grid-cols-1 items-center gap-4 rounded-lg px-5 py-6 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] lg:px-8" style="background: linear-gradient(90deg, {{ $won ? '#111A14' : '#17171B' }}, #121215 60%); box-shadow: inset 0 0 0 1px {{ $won ? '#1F5A34' : '#2A2A30' }}" data-test="win-moment">
            <span class="flex items-center gap-4">
                @if ($m->winner === 'challenger' || $m->winner === 'challenged')
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-md font-display text-[15px] font-extrabold {{ $sideInk[$m->winner] }}" style="background: {{ $sideColor[$m->winner] }}">{{ $m->sideTag($m->winner) }}</span>
                    <span class="flex flex-col gap-1"><b class="font-display text-[22px]">{{ $m->sideName($m->winner) }}</b><span class="inline-flex items-center gap-1 text-[13px] text-win"><x-icon name="check" :size="14" />{{ __('Win') }}</span></span>
                @else
                    <b class="font-display text-[22px]">{{ __('No winner') }}</b>
                @endif
            </span>
            <span class="flex flex-col items-center gap-2">
                <span class="inline-flex h-7 items-center gap-1.5 rounded-sm bg-win-tint px-2.5 text-xs font-bold text-win shadow-[inset_0_0_0_1px_#1F5A34]"><x-icon name="check" :size="14" />{{ $m->resolution?->label() ?? __('Accepted') }}</span>
                <b class="font-display text-[56px] leading-none font-extrabold lg:text-[72px]">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b>
                <span class="text-[13px] text-ink-2">BO{{ $m->best_of }}, {{ $m->mode }}, {{ __('match :number', ['number' => $m->label()]) }}, {{ $m->rated ? __('saved') : __('casual, saved') }}</span>
            </span>
            <span class="flex flex-col gap-1 text-xs leading-normal text-ink-2 lg:items-end lg:text-right">
                <span>{{ $m->rated ? __('The league record follows.') : __('Casual: no Elo, no Hashrate, no block. Rated series start at Block 0.') }}</span>
                @if ($m->resolution_reason)<span>{{ __('Admin decision') }}: {{ $m->resolution_reason }}</span>@endif
                <a href="{{ route('matches.show', $m) }}" class="inline-flex min-h-11 items-center">{{ __('Open the match page') }}</a>
            </span>
        </section>
    @endif

    {{-- Challenge, still open: answer, withdraw or wait --}}
    @if ($m->status === SeriesStatus::Open)
        <section aria-labelledby="answer-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6" x-data="nostrAction({ pubkey: @js($viewer->pubkey) })" data-test="answer-card">
            <h2 id="answer-h" class="m-0 text-[15px] font-bold">{{ __('Challenge from :clan', ['clan' => $m->challenger_name]) }}</h2>
            @if ($m->message)<p class="m-0 text-[13px] text-ink-2">“{{ $m->message }}”</p>@endif
            <span class="text-xs text-ink-2">{{ __('Answer by :time', ['time' => SeriesPresenter::time($m->respond_by, $viewer)]) }}</span>
            @if ($captainSide === 'challenged')
                <div role="radiogroup" aria-label="{{ __('Suggested times') }}" class="flex flex-wrap gap-2">
                    @foreach ($m->proposals as $proposal)
                        <button type="button" role="radio" wire:click="$set('pickedStart', {{ $proposal }})" aria-checked="{{ $pickedStart === $proposal ? 'true' : 'false' }}"
                                @class(['h-11 cursor-pointer rounded-md border bg-ground px-4 text-[13px] text-ink', 'border-btc' => $pickedStart === $proposal, 'border-line' => $pickedStart !== $proposal])>{{ SeriesPresenter::time(now()->setTimestamp($proposal), $viewer) }}</button>
                    @endforeach
                </div>
                <div class="flex flex-wrap gap-3">
                    <x-button icon="shield-check" x-on:click="run('prepareAnswer', 'answer', 'accepted', $wire.pickedStart)" ::disabled="busy" data-test="accept-challenge">{{ __('Accept challenge') }}</x-button>
                    <x-button variant="quiet" x-on:click="run('prepareAnswer', 'answer', 'declined', null)" ::disabled="busy" data-test="decline-challenge">{{ __('Decline') }}</x-button>
                </div>
            @elseif ($captainSide === 'challenger')
                <p class="m-0 text-[13px] text-ink-2">{{ __(':clan\'s captains see it right away. You can withdraw it while it\'s open.', ['clan' => $m->challenged_name]) }}</p>
                <div><x-button variant="quiet" x-on:click="run('prepareAnswer', 'answer', 'withdrawn', null)" ::disabled="busy" data-test="withdraw-challenge">{{ __('Withdraw challenge') }}</x-button></div>
            @else
                <p class="m-0 text-[13px] text-ink-2">{{ __('Your captain answers this challenge.') }}</p>
            @endif
            <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
        </section>
    @endif

    {{-- Facts --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([
                [__('Challenge'), __(':from to :to, :time', ['from' => $m->created_by_id === $viewer->id ? __('you') : $m->challenger_name, 'to' => $m->challenged_name, 'time' => SeriesPresenter::time($m->created_at ?? now(), $viewer, 'D H:i')])],
                [__('Start'), $m->start_at ? SeriesPresenter::time($m->start_at, $viewer, 'D H:i') : __('one of :n suggested times', ['n' => count($m->proposals)])],
                [__('Format'), 'Rocket League, '.$m->mode.', BO'.$m->best_of],
                [__('Ladder'), $m->rated ? $m->mode : __('none, casual until Block 0')],
            ] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-sm last:border-0 lg:grid-cols-[150px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
            @endforeach
        </div>
        <div class="flex flex-col rounded-lg bg-card px-4 py-2 lg:px-6">
            @foreach ([[__('Elo before'), __('–, casual')], [__('Expected'), '–'], [__('At stake'), __('nothing, casual')]] as [$key, $value])
                <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-sm lg:grid-cols-[150px_minmax(0,1fr)]"><span class="text-ink-2">{{ $key }}</span><span class="text-ink-2">{{ $value }}</span></div>
            @endforeach
            <div class="flex min-h-14 flex-wrap items-center gap-3 py-2 text-sm">
                <span class="w-[110px] text-ink-2 lg:w-[150px]">{{ __('Status') }}</span>
                <span class="text-btc-hi">{{ $wins['challenger'] }} : {{ $wins['challenged'] }} {{ $m->status->hasResult() ? '' : __('so far') }}</span>
                <span class="grow"></span>
                @if ($editable)
                    <button type="button" x-on:click="submit = true" data-test="open-submit" class="btn-w max-lg:hidden inline-flex h-11 cursor-pointer items-center gap-2 rounded-md border border-line bg-raised px-4 text-sm font-bold text-ink"><x-icon name="shield-check" :size="18" />{{ __('Submit final score') }}</button>
                @endif
            </div>
        </div>
    </div>

    {{-- Timeline + Proof --}}
    <section aria-labelledby="tl-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6">
        <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="tl-h" class="m-0 text-[15px] font-bold">{{ __('Timeline') }}</h2><span class="text-xs text-ink-2">{{ __('your result and their OK make 2 of 2') }}</span></span>
        <ol class="m-0 grid list-none grid-cols-2 gap-y-4 p-0 lg:grid-cols-4">
            @foreach ($steps as $index => [$label, $when, $who, $done])
                <li @class(['relative flex flex-col items-center gap-1 text-center', 'lg:after:absolute lg:after:top-[27px] lg:after:left-1/2 lg:after:h-0.5 lg:after:w-full' => $index < 3, 'lg:after:bg-ink' => $index < 3 && ($steps[$index + 1][3] ?? false), 'lg:after:bg-line' => $index < 3 && ! ($steps[$index + 1][3] ?? false)])>
                    <span class="h-4 text-[11px] text-ink-3">{{ $when }}</span>
                    <span @class(['relative z-10 size-3.5 rounded-full border-2', 'border-ink bg-ink' => $done, 'border-btc bg-btc' => ! $done && ($steps[$index - 1][3] ?? true), 'border-edge bg-transparent' => ! $done && ! ($steps[$index - 1][3] ?? true)])></span>
                    <b class="text-xs">{{ $label }}</b>
                    <span class="text-[11px] text-ink-2">{{ $who }}</span>
                </li>
            @endforeach
        </ol>
        <p class="m-0 flex items-start gap-2 text-xs leading-normal text-ink-2"><x-icon name="retry" :size="14" class="mt-0.5 shrink-0" />{{ __('If a result is disputed, either side can submit again. The new one replaces the old; the timeline keeps both.') }}</p>
        <x-proof :rows="SeriesPresenter::proofRows($m)" />
    </section>

    {{-- Games + Who played --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section aria-labelledby="games-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="games">
            <span class="flex flex-wrap items-baseline justify-between gap-2"><h2 id="games-h" class="m-0 text-[15px] font-bold">{{ __('Games') }}</h2><span class="text-xs text-ink-2">{{ __('after each game, enter the team goals from the end screen') }}</span></span>
            <div class="grid grid-cols-[64px_56px_12px_56px_minmax(0,1fr)] items-center gap-2 text-xs text-ink-3 lg:grid-cols-[72px_60px_12px_60px_minmax(0,1fr)_130px]">
                <span>{{ __('Game') }}</span><span class="text-center">{{ $m->challenger_tag }}</span><span></span><span class="text-center">{{ $m->challenged_tag }}</span><span>{{ __('Winner') }}</span><span class="max-lg:hidden"></span>
            </div>
            @foreach ($this->sheet as $index => $row)
                @php($decided = $index > 0 && max(SeriesMatch::seriesScore(array_slice(array_map(fn ($r) => ['winner' => $r['winner']], $this->sheet), 0, $index))) >= intdiv($m->best_of, 2) + 1)
                @php($current = $row['winner'] === null && ! $decided && ($index === 0 || $this->sheet[$index - 1]['winner'] !== null))
                <div wire:key="g-{{ $index }}" @class(['grid grid-cols-[64px_56px_12px_56px_minmax(0,1fr)] items-center gap-2 border-b border-hairline py-2 text-[13px] lg:grid-cols-[72px_60px_12px_60px_minmax(0,1fr)_130px]', 'opacity-50' => $decided]) data-test="game-row">
                    <b>{{ __('Game :n', ['n' => $index + 1]) }}</b>
                    @foreach (['c', 'd'] as $box)
                        <input type="number" inputmode="numeric" min="0" max="99" wire:model.live.blur="sheet.{{ $index }}.{{ $box }}" @disabled(! $editable || $row['unknown'] || $decided)
                               aria-label="{{ __('Goals of :clan in game :n', ['clan' => $m->sideTag($box === 'c' ? 'challenger' : 'challenged'), 'n' => $index + 1]) }}" data-test="goals-{{ $index }}-{{ $box }}"
                               @class(['h-11 w-full rounded-md border bg-ground px-2 text-center text-[15px] text-ink disabled:opacity-60', 'border-btc' => $current && $editable, 'border-edge' => ! ($current && $editable)])>
                        @if ($box === 'c')<span class="text-center text-ink-3">:</span>@endif
                    @endforeach
                    <span @class(['text-[13px]', 'text-win' => $row['winner'] === 'challenger', 'text-loss' => $row['winner'] === 'challenged', 'text-btc' => $row['winner'] === null && $current && ! $m->start_at?->isFuture(), 'text-ink-3' => $row['winner'] === null && ! $current])>
                        @if ($row['winner'] !== null)
                            {{ __(':tag win', ['tag' => $m->sideTag($row['winner'])]) }}
                        @elseif ($decided)
                            {{ __('not needed') }}
                        @else
                            {{ $current && $m->status === SeriesStatus::Accepted && ! $m->start_at?->isFuture() ? __('playing now') : __('not played') }}
                        @endif
                    </span>
                    <span class="col-span-5 flex flex-wrap items-center gap-3 lg:col-span-1">
                        @if ($editable && ! $decided)
                            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-xs text-ink-2"><input type="checkbox" wire:model.live="sheet.{{ $index }}.unknown" class="size-4 accent-[#F7931A]">{{ __('Goals unknown') }}</label>
                            @if ($row['unknown'])
                                <select wire:model.live="sheet.{{ $index }}.winner" aria-label="{{ __('Winner of game :n', ['n' => $index + 1]) }}" class="h-9 rounded-md border border-edge bg-ground px-2 text-xs text-ink">
                                    <option value="">{{ __('winner?') }}</option>
                                    @foreach (SeriesMatch::SIDES as $side)<option value="{{ $side }}">{{ $m->sideTag($side) }}</option>@endforeach
                                </select>
                            @endif
                        @elseif ($row['unknown'])
                            <span class="text-xs text-ink-3">{{ __('Goals unknown') }}</span>
                        @endif
                    </span>
                </div>
            @endforeach
            <div class="flex flex-wrap items-center gap-x-6 gap-y-1 pt-1 text-xs text-ink-2">
                <span>{{ __('Series after game :n', ['n' => $playing]) }} <b class="text-ink">{{ $wins['challenger'] }} : {{ $wins['challenged'] }}</b></span>
                <span>{{ __('shown publicly as provisional until both captains confirm') }}</span>
            </div>
            <p class="m-0 flex items-start gap-2 text-xs leading-normal text-ink-3"><x-icon name="alert" :size="14" class="mt-0.5 shrink-0" />{{ __('Forgot the goals? Tick "Goals unknown" and just pick the winner. The game counts for the series but not for goal stats.') }}</p>
        </section>

        <section aria-labelledby="who-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="who-played">
            @php($mine = $captainSide ?? $mySide ?? 'challenger')
            @php($set = count(array_filter($rosters[$mine], fn ($r) => $r['on'])))
            <span class="flex items-baseline justify-between"><h2 id="who-h" class="m-0 text-[15px] font-bold">{{ __('Who played') }}</h2><span @class(['text-xs', 'text-win' => $set >= $m->gameMode()->teamSize, 'text-btc-hi' => $set < $m->gameMode()->teamSize])>{{ __(':n of :size set', ['n' => $set, 'size' => $m->gameMode()->teamSize]) }}</span></span>
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Regulars are preselected. If a sub played, switch them on and a regular off.') }}</p>
            @foreach ($rosters[$mine] as ['seat' => $seat, 'on' => $on])
                <div wire:key="r-{{ $seat->id }}" class="flex min-h-12 items-center justify-between gap-3 border-b border-hairline py-1">
                    <span class="flex min-w-0 flex-col">
                        <span class="flex items-center gap-2 text-[13px]"><span class="truncate">{{ $seat->user->displayName() }}</span>@if ($seat->user->is_member)<x-member-badge />@endif</span>
                        <span class="text-[11px] text-ink-3">{{ $seat->role->label() }}@if ($seat->user_id === $viewer->id), {{ __('you') }}@endif</span>
                    </span>
                    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ __(':name played', ['name' => $seat->user->displayName()]) }}"
                            @if ($editable) wire:click="toggleRoster({{ $seat->user_id }})" @else disabled @endif
                            @class(['relative h-7 w-[46px] shrink-0 cursor-pointer rounded-full border-0 disabled:cursor-default', 'bg-btc' => $on, 'bg-raised' => ! $on])>
                        <span @class(['absolute top-1 size-5 rounded-full transition-all', 'left-[22px] bg-on-btc' => $on, 'left-1 bg-ink-2' => ! $on])></span>
                    </button>
                </div>
            @endforeach
            <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 pt-1 text-xs leading-normal text-ink-2" data-test="opponent-roster">{{ $m->sideName(SeriesMatch::otherSide($mine)) }}:
                @foreach (array_filter($rosters[SeriesMatch::otherSide($mine)], fn ($r) => $r['on']) as ['seat' => $seat])
                    <span class="inline-flex items-center gap-1">{{ $seat->user->displayName() }}<x-copy-npub :npub="$seat->user->npub" :name="$seat->user->displayName()" /></span>
                @endforeach
            </p>
            <p class="m-0 text-xs text-ink-3">{{ __('their captain sets this') }}</p>
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('This list goes out with your result.') }}</p>
        </section>
    </div>

    {{-- Lobby + Chat --}}
    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        <section aria-labelledby="lobby-h" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="lobby">
            <span class="flex items-baseline justify-between"><h2 id="lobby-h" class="m-0 text-[15px] font-bold">{{ __('Private lobby') }}</h2><span class="text-xs text-ink-2">{{ __('host :clan', ['clan' => $m->challenger_name]) }}</span></span>
            @if ($editLobby)
                <form wire:submit="saveLobby" class="flex flex-col gap-3">
                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Name') }}<input wire:model="lobbyName" maxlength="32" required data-test="lobby-name-input" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink"></label>
                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Password') }}<input wire:model="lobbyPassword" maxlength="32" autocomplete="off" data-test="lobby-password-input" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink"></label>
                    <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Region') }}
                        <select wire:model="lobbyRegion" class="h-11 rounded-md border border-edge bg-ground px-3 text-sm text-ink">@foreach (config('esports.series.regions') as $region)<option value="{{ $region }}">{{ $region }}</option>@endforeach</select>
                    </label>
                    <div class="flex gap-3"><x-button type="submit" data-test="save-lobby">{{ __('Save lobby') }}</x-button><x-button variant="quiet" wire:click="$set('editLobby', false)">{{ __('Cancel') }}</x-button></div>
                </form>
            @elseif ($m->lobby_name === null)
                <p class="m-0 text-[13px] text-ink-2">{{ $m->status->isRunning() ? __('No lobby yet. The host sets name and password here.') : __('The lobby opens once the challenge is accepted.') }}</p>
            @else
                <div x-data="{ show: false }" class="flex flex-col">
                    <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)_auto] items-center gap-2 border-b border-hairline text-sm">
                        <span class="text-ink-2">{{ __('Name') }}</span><b data-test="lobby-name">{{ $m->lobby_name }}</b>
                        <button type="button" x-on:click="navigator.clipboard?.writeText(@js($m->lobby_name))" aria-label="{{ __('Copy lobby name') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="copy" :size="16" /></button>
                    </div>
                    <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)_auto_auto] items-center gap-2 border-b border-hairline text-sm">
                        <span class="text-ink-2">{{ __('Password') }}</span>
                        <span><span x-show="! show">••••••••</span><b x-show="show" x-cloak data-test="lobby-password">{{ $m->lobby_password ?? '–' }}</b></span>
                        <button type="button" x-on:click="show = ! show" :aria-pressed="show ? 'true' : 'false'" aria-label="{{ __('Show password') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="eye" :size="16" /></button>
                        <button type="button" x-on:click="navigator.clipboard?.writeText(@js((string) $m->lobby_password))" aria-label="{{ __('Copy password') }}" class="btn-w inline-flex size-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink-2"><x-icon name="copy" :size="16" /></button>
                    </div>
                    <div class="grid min-h-12 grid-cols-[80px_minmax(0,1fr)] items-center gap-2 border-b border-hairline text-sm"><span class="text-ink-2">{{ __('Region') }}</span><span>{{ $m->lobby_region ?? '–' }}</span></div>
                </div>
            @endif
            <p class="m-0 flex items-center gap-2 py-1 text-xs text-ink-2"><x-icon name="lock" :size="14" class="text-win" />{{ __('Only the two lineups see this. It is never published.') }}</p>
            @if ($captainSide !== null && $m->status->isRunning() && ! $editLobby)
                <div><x-button variant="quiet" icon="brush" wire:click="openLobbyEditor" data-test="change-lobby">{{ $m->lobby_name === null ? __('Set lobby') : __('Change lobby') }}</x-button></div>
            @endif
            @if ($m->status === SeriesStatus::Accepted && $noshowFrom)
                <div class="mt-2 flex flex-col gap-2 border-t border-hairline pt-3">
                    <b class="text-[13px]">{{ __('Opponent not in the lobby?') }}</b>
                    <p class="m-0 text-xs leading-normal text-ink-2">{{ __('From :time, :minutes minutes after start, you can report it. An admin checks and scores the match as a forfeit.', ['time' => SeriesPresenter::time($noshowFrom, $viewer, 'H:i'), 'minutes' => (int) config('esports.series.noshow_minutes', 15)]) }}</p>
                    <span class="flex flex-wrap items-center gap-3">
                        <x-button variant="secondary" icon="user" wire:click="reportNoShow" class="disabled:cursor-not-allowed disabled:opacity-50" :disabled="$captainSide === null || $noshowFrom->isFuture() || $games !== [] || $m->noshow_reported_at !== null" data-test="report-noshow">{{ __('Opponent didn\'t show') }}</x-button>
                        <span class="text-xs text-ink-3">{{ $m->noshow_reported_at ? __('reported, an admin decides') : ($games !== [] ? __('not needed, games are entered') : '') }}</span>
                    </span>
                </div>
            @endif
        </section>

        <section aria-labelledby="chat-h" class="flex min-h-[420px] flex-col rounded-lg bg-card" x-data="roomChat(@js($this->chatConfig()))" data-test="room-chat" wire:ignore>
            <span class="flex items-center justify-between gap-2 border-b border-hairline px-4 py-3 lg:px-6"><h2 id="chat-h" class="m-0 text-[15px] font-bold">{{ __('Chat') }}</h2><span class="inline-flex items-center gap-1.5 text-xs text-ink-2"><x-icon name="lock" :size="14" />{{ __('private to both lineups') }}</span></span>
            <ol aria-live="polite" class="m-0 flex min-h-0 grow list-none flex-col justify-end gap-3 overflow-y-auto px-4 py-3 text-[13px] leading-normal lg:px-6" data-test="chat-messages">
                <template x-for="m in messages" :key="m.id">
                    <li class="flex max-w-[80%] flex-col gap-1 rounded-md px-3 py-2" :class="m.from === 'me' ? 'self-end bg-btc-press' : 'self-start bg-well'" :data-from="m.from">
                        <span class="flex items-center gap-2 text-[11px]" :class="m.from === 'me' ? 'text-btc-hi' : 'text-ink-2'">
                            <span x-text="m.name + ', ' + time(m.at)"></span>
                            <button type="button" x-show="m.from !== 'me'" x-on:click="toggleMute(m.pubkey)" class="btn-w inline-flex h-6 cursor-pointer items-center rounded-sm border border-line bg-transparent px-2 text-[10px] text-ink-2" x-text="t.mute"></button>
                        </span>
                        <span class="break-words" x-text="m.text"></span>
                    </li>
                </template>
                <li x-show="status === 'live' && messages.length === 0" class="text-ink-3">{{ __('No messages yet. Say hello.') }}</li>
                <li x-show="status === 'starting'" class="text-ink-3">{{ __('Connecting to the chat …') }}</li>
                <li x-show="status === 'needs-signer'" class="flex flex-col items-start gap-2 text-ink-2">
                    <span>{{ __('The chat is end-to-end encrypted with your Nostr key. Open it to read and write messages.') }}</span>
                    <x-button variant="quiet" icon="chat" x-on:click="connect()">{{ __('Open chat') }}</x-button>
                </li>
                <li x-show="status === 'no-nip44'" class="text-ink-2">{{ __('Your signer cannot encrypt messages (NIP-44), so the chat is off. A Nostr extension or signer app with NIP-44 turns it on; the match itself works as usual.') }}</li>
                <li x-show="status === 'no-relays'" class="text-ink-2">{{ __('The chat has no relay here, so it is off.') }}</li>
                <li x-show="error" class="text-loss" role="alert" x-text="error"></li>
            </ol>
            <form x-show="status === 'live'" x-on:submit.prevent="send()" class="flex gap-2 border-t border-hairline px-4 pt-3 pb-4 lg:px-6">
                <label for="roomchat" class="sr-only">{{ __('Message to both lineups') }}</label>
                <input id="roomchat" x-model="input" placeholder="{{ __('Message') }}" autocomplete="off" maxlength="500" class="h-11 min-w-0 grow rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
                <button type="submit" aria-label="{{ __('Send message') }}" :disabled="sending" class="btn-w inline-flex size-11 shrink-0 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:opacity-50"><x-icon name="send" :size="16" /></button>
            </form>
        </section>
    </div>

    {{-- Check the result (the other captain) --}}
    @if ($toAnswer)
        @php($reportWins = $report->score())
        <section id="check" aria-labelledby="check-h" class="grid grid-cols-1 gap-5 rounded-lg bg-card px-4 py-5 shadow-[inset_0_0_0_1px_#3A2A12] lg:grid-cols-2 lg:px-6" x-data="nostrAction({ pubkey: @js($viewer->pubkey) })" data-test="check-result">
            <div class="flex flex-col gap-3">
                <h2 id="check-h" class="m-0 text-[15px] font-bold">{{ __('Check the result from :clan', ['clan' => $m->sideName($report->side)]) }}</h2>
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="reported-score">
                    {{ __(':name submitted :a : :b for :clan', ['name' => $report->user?->displayName() ?? '', 'a' => $reportWins[$report->side], 'b' => $reportWins[SeriesMatch::otherSide($report->side)], 'clan' => $m->sideName($report->side)]) }}:
                    @foreach ($report->games as $index => $game){{ $index > 0 ? ', ' : '' }}{{ $game['challenger'] !== null ? $game['challenger'].' : '.$game['challenged'] : __(':tag win', ['tag' => $m->sideTag($game['winner'])]) }}@endforeach.
                </p>
                <p class="m-0 text-xs text-ink-2">{{ __('Played') }}: {{ implode(', ', array_column($report->roster, 'name')) }}</p>
                <div class="flex flex-wrap gap-3">
                    <x-button variant="quiet" icon="shield-check" x-on:click="run('prepareResponse', 'respond', 'confirmed')" ::disabled="busy" data-test="accept-result">{{ __('Accept result') }}</x-button>
                    <button type="button" x-on:click="$refs.reason.focus()" class="inline-flex h-11 cursor-pointer items-center rounded-md border border-[#5A2A2E] bg-transparent px-4 text-[13px] text-loss">{{ __('Report a problem') }}</button>
                </div>
                <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
            </div>
            <div class="flex flex-col gap-2">
                <label for="reason" class="text-xs text-ink-2">{{ __('What went wrong? Shown publicly, up to :max characters', ['max' => SeriesService::REASON_MAX]) }}</label>
                <textarea id="reason" x-ref="reason" wire:model="reason" maxlength="{{ SeriesService::REASON_MAX }}" rows="3" data-test="dispute-reason" class="w-full resize-none rounded-lg border border-edge bg-ground px-3.5 py-3 text-[13px] text-ink"></textarea>
                @error('reason')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                <label class="flex flex-col gap-1 text-xs text-ink-2">{{ __('Evidence for a dispute: a screenshot of the end screen or the in-game match history. Only admins see it.') }}
                    <input type="file" wire:model="shots" multiple accept="image/*" class="text-xs text-ink-2 file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-well file:px-3 file:text-ink">
                </label>
                @error('shots.*')<p class="m-0 text-xs text-loss">{{ $message }}</p>@enderror
                <div><button type="button" x-on:click="run('prepareResponse', 'respond', 'disputed')" :disabled="busy" data-test="send-dispute" class="inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-loss px-5 text-[13px] font-bold text-on-btc">{{ __('Send report') }}</button></div>
            </div>
        </section>
    @elseif ($m->status === SeriesStatus::Reported && $report !== null)
        <p class="m-0 rounded-md bg-card px-4 py-3 text-[13px] text-ink-2" data-test="waiting-for-ok">{{ __(':clan submitted the final score. Waiting for :other to accept it or report a problem.', ['clan' => $m->sideName($report->side), 'other' => $m->sideName(SeriesMatch::otherSide($report->side))]) }}</p>
    @elseif ($m->status === SeriesStatus::Disputed)
        <p class="m-0 rounded-md bg-loss-tint px-4 py-3 text-[13px] text-loss" data-test="disputed-note">
            {{ __('A problem was reported: “:reason” An admin decides, or either captain submits a corrected score.', ['reason' => (string) $report?->response_reason]) }}
            @if ($m->new_report_requested_at) {{ __('An admin asked for a new report.') }}@endif
        </p>
    @endif

    {{-- Sticky score bar (MobileMatchRoom) --}}
    @if ($editable)
        <div class="fixed inset-x-0 bottom-0 z-20 flex items-center gap-3 bg-bar px-4 py-3 shadow-[0_-1px_0_#2A2A30] lg:hidden">
            <span class="flex flex-col"><b class="font-display text-[22px]">{{ $wins['challenger'] }}:{{ $wins['challenged'] }}</b><span class="text-[11px] text-ink-2">{{ $wins['challenger'] === $wins['challenged'] ? __('level') : __(':clan lead the series', ['clan' => $m->sideName($wins['challenger'] > $wins['challenged'] ? 'challenger' : 'challenged')]) }}</span></span>
            <span class="grow"></span>
            <button type="button" x-on:click="submit = true" class="btn-p inline-flex h-[52px] cursor-pointer items-center gap-2 rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc"><x-icon name="shield-check" :size="18" />{{ __('Submit final score') }}</button>
        </div>
    @endif

    {{-- Submit dialog (Overlays.dc.html) --}}
    @if ($editable)
        <div x-show="submit" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-[rgba(10,10,11,.7)] lg:items-center" x-on:keydown.escape.window="submit = false">
            <section role="dialog" aria-modal="true" aria-labelledby="submit-h" class="flex max-h-[90svh] w-full max-w-[560px] flex-col gap-4 overflow-y-auto rounded-t-2xl bg-card px-5 py-6 shadow-ring lg:rounded-lg lg:px-6"
                     x-data="nostrAction({ pubkey: @js($viewer->pubkey) })" x-on:click.outside="submit = false" data-test="submit-dialog">
                <span class="flex items-center gap-3"><span class="flex size-10 items-center justify-center rounded-md bg-win-tint text-win"><x-icon name="shield-check" :size="20" /></span><h2 id="submit-h" class="m-0 text-lg font-bold">{{ __('Submit final score') }}</h2></span>
                @if ($draft !== null)
                    @php($draftWins = SeriesMatch::seriesScore($draft['games']))
                    @php($leader = $draftWins['challenger'] > $draftWins['challenged'] ? 'challenger' : 'challenged')
                    <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('You report that :winner beat :loser :a : :b. They accept it or report a problem. You can\'t edit it after this.', ['winner' => $m->sideName($leader), 'loser' => $m->sideName(SeriesMatch::otherSide($leader)), 'a' => max($draftWins), 'b' => min($draftWins)]) }}</p>
                    <div class="flex flex-col rounded-md px-4 shadow-ring">
                        @foreach ([
                            [__('Match'), $m->label().', '.$m->challenger_name.' vs '.$m->challenged_name.', '.$m->mode],
                            [__('Match kind'), ($m->rated ? __('Rated') : __('Casual')).', Rocket League '.$m->mode],
                            [__('Games'), implode(', ', array_map(fn ($g) => $g['challenger'] !== null ? $g['challenger'].' : '.$g['challenged'] : __(':tag win', ['tag' => $m->sideTag($g['winner'])]), $draft['games']))],
                            [__('Played'), implode(', ', array_column(array_filter($draft['roster'], fn ($r) => $r['side'] === ($captainSide ?? 'challenger')), 'name'))],
                            [__('Rating'), $m->rated ? __('with the league record') : __('none, casual')],
                            [__('Result'), __('Series :a : :b for :clan', ['a' => max($draftWins), 'b' => min($draftWins), 'clan' => $m->sideName($leader)])],
                        ] as [$key, $value])
                            <div class="grid min-h-11 grid-cols-[110px_minmax(0,1fr)] items-center gap-3 border-b border-hairline py-2 text-[13px] last:border-0"><span class="text-ink-2">{{ $key }}</span><span>{{ $value }}</span></div>
                        @endforeach
                    </div>
                    <x-proof :rows="$m->rated ? [[__('Record'), __('kind 2152 result, with score and roster')], [__('From'), $viewer->shortNpub()]] : [[__('Record'), __('casual: no Nostr event, league data only')], [__('From'), $viewer->shortNpub()]]" />
                    <p x-show="error" x-text="error" class="m-0 text-[13px] text-loss" role="alert"></p>
                    <div class="flex justify-end gap-3">
                        <x-button variant="quiet" x-on:click="submit = false">{{ __('Back') }}</x-button>
                        <x-button icon="shield-check" x-on:click="run('prepareReport', 'report').then(() => { if (! error) submit = false })" ::disabled="busy" data-test="confirm-submit">{{ __('Submit final score') }}</x-button>
                    </div>
                @else
                    <p class="m-0 text-[13px] text-loss" role="alert" data-test="draft-error">{{ $draftError }}</p>
                    <div class="flex justify-end"><x-button variant="quiet" x-on:click="submit = false">{{ __('Back') }}</x-button></div>
                @endif
            </section>
        </div>
    @endif
</div>
