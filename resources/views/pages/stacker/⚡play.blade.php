<?php

use App\Games\Blockfill;
use App\Games\ScoreMetric;
use App\Models\Tournament;
use App\Models\User;
use App\Support\PageMeta;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\StackerSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/*
 * Blockfill, the league's own stacking game (plan "Blockfill", P3): mine 40
 * blocks (clear 40 rows) as fast as you can. The game is Alpine around the
 * shared engine (resources/js/stacker/page.js) inside `wire:ignore`; this
 * component only hands it the player's controls, their best verified time
 * and the run endpoints, and never re-renders it.
 *
 * Practice for everyone, guests included (a local seed, nothing is sent);
 * ranked runs need a login and a keyboard and go through StackerRuns
 * (issue, start, submit, verdict). For a logged-in player with a keyboard
 * a ranked run is the main start button, its token issued at the click;
 * practice is the quiet one. Every ranked run is verified, a slower one too:
 * its result says it was verified but not faster than the week's best. The route exists only while `esports.blockfill.enabled` is on
 * (routes/stacker.php).
 *
 * Below the game, the casual weekly hunt (P4, BlockfillWeeks): this week's
 * leaderboard, the player's own place, and last week's winner. Refreshed
 * when the game reports a verified run (`stacker-verified`).
 */
new #[Layout('layouts::app', ['scripts' => ['resources/js/stacker/page.js']])] class extends Component {
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title('Blockfill');
        app(PageMeta::class)->describe('Blockfill', __('Mine 40 blocks as fast as you can: the league\'s own stacking game, every run replayed by the league before it counts.'))
            ->card(fn () => \App\Support\Cards\PageCard::page('blockfill'));
    }

    /**
     * What the Alpine component starts from.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $user = Auth::user();
        $signedIn = $user instanceof User;
        $token = '__TOKEN__';

        return [
            'signedIn' => $signedIn,
            'controls' => StackerSettings::of($signedIn ? $user : null),
            // P8: effects and music; a guest's own choice in localStorage wins over these defaults
            'sound' => StackerSettings::sound($signedIn ? $user : null),
            // P4: the week's best is the one a ranked run has to beat; the all-time best is shown beside it
            'best' => $signedIn ? app(StackerRuns::class)->best($user, StackerRuns::weekOf(now())) : null,
            'allTimeBest' => $signedIn ? app(StackerRuns::class)->best($user) : null,
            'testing' => app()->environment('testing'),
            'urls' => [
                'issue' => route('stacker.runs.issue'),
                'start' => route('stacker.runs.start', $token),
                'submit' => route('stacker.runs.submit', $token),
                'show' => route('stacker.runs.show', $token),
                'login' => route('login'),
            ],
            't' => [
                'tooFast' => __('Too many runs at once. Wait a moment and try again.'),
                'noRun' => __('The run could not be started. Try again.'),
                'firstTime' => __('Your first time.'),
                'newBest' => __('New best, :delta s faster than :previous'),
                'yourBest' => __('Your best: :best'),
                'status' => [
                    'practice' => __('Practice run, not sent'),
                    'held' => __('Held for the test'),
                    'submitting' => __('Sending your run…'),
                    'verifying' => __('The league is replaying your run…'),
                    'verified' => __('Verified: the league replayed your run to the same time'),
                    'verifiedSlower' => __('Verified, not faster than your best :best'),
                    'pending' => __('Received, not checked yet: it counts once the league has replayed it'),
                    'rejected' => __('Not counted: the replay did not match'),
                    // P5: verified, but with cheat hints: held until an admin approves it
                    'review' => __('Replayed to the same time, held for an admin\'s check: it counts once approved'),
                    'rejected_input' => __('Not counted: ranked runs need a keyboard'),
                    'toppedOut' => __('Topped out: the stack reached the top'),
                    'aborted' => __('Run stopped: you left the tab'),
                    'busy' => __('Not saved — the league is busy. Play the run again in a moment.'),
                    'unsent' => __('Not saved — the run did not reach the league. Play it again.'),
                ],
            ],
        ];
    }

    /**
     * The sound control on the game page (P8), for a logged-in player: effects
     * and music, each on/off with a volume 0-100. Renderless: the game sits in
     * `wire:ignore` and the leaderboard has nothing new to show.
     *
     * @param  array<string, mixed>  $sound
     */
    #[Renderless]
    public function saveSound(array $sound): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $valid = Validator::make(['sound' => $sound], [
            'sound' => ['required', 'array:effects,music,effectsOn,musicOn'],
            'sound.effects' => ['required', 'integer', 'min:0', 'max:100'],
            'sound.music' => ['required', 'integer', 'min:0', 'max:100'],
            'sound.effectsOn' => ['required', 'boolean:strict'],
            'sound.musicOn' => ['required', 'boolean:strict'],
        ])->validate()['sound'];

        $user->forceFill(['stacker_sound' => StackerSettings::normalizeSound([
            'effects' => (int) $valid['effects'],
            'music' => (int) $valid['music'],
            'effectsOn' => $valid['effectsOn'],
            'musicOn' => $valid['musicOn'],
        ])])->save();
    }

    /**
     * This week's leaderboard, if it was opened.
     */
    #[Computed]
    public function week(): ?Tournament
    {
        return app(BlockfillWeeks::class)->current();
    }

    /**
     * Its standings, best first.
     *
     * @return list<ScoreStanding>
     */
    #[Computed]
    public function standings(): array
    {
        return $this->week === null ? [] : app(ScoreRuns::class)->standings($this->week);
    }

    /**
     * The logged-in player's row of this week, if they are in.
     */
    #[Computed]
    public function mine(): ?ScoreStanding
    {
        $id = Auth::id();

        return $id === null ? null : collect($this->standings)->first(fn (ScoreStanding $row): bool => $row->participant->user_id === $id);
    }

    /**
     * Last week's winner: the first place of its leaderboard (final once it ended).
     */
    #[Computed]
    public function lastWinner(): ?ScoreStanding
    {
        $previous = app(BlockfillWeeks::class)->previous();
        $first = $previous === null ? null : (app(ScoreRuns::class)->standings($previous)[0] ?? null);

        return $first?->place === 1 ? $first : null;
    }
}; ?>

@php
    $config = $this->config();
    $actions = [
        'left' => __('Move left'),
        'right' => __('Move right'),
        'soft' => __('Soft drop'),
        'hard' => __('Hard drop'),
        'ccw' => __('Turn left'),
        'cw' => __('Turn right'),
        'flip' => __('Turn 180°'),
        'hold' => __('Hold'),
        'restart' => __('Restart at once'),
    ];
    $fees = ['#7383A6', '#3B82E0', '#0FA394', '#5AAE3C', '#F2D45C', '#F7931A', '#F9A8D4'];
@endphp

<div class="flex grow flex-col px-4 pb-8 lg:px-12 lg:pb-10">
    <div wire:ignore x-data="stackerGame(@js($config))" class="mx-auto flex w-full max-w-[1340px] flex-col gap-5 lg:gap-8" data-test="stacker">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between lg:gap-8">
            <div class="flex flex-col gap-1 lg:flex-row lg:items-baseline lg:gap-4">
                <h1 class="m-0 font-display text-[28px] leading-[1.1] font-extrabold lg:text-[30px]">Blockfill</h1>
                <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Mine 40 blocks as fast as you can.') }}</p>
            </div>

            @include('pages.stacker.partials.sound-control', ['class' => 'hidden lg:flex'])
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-12">
            <section class="flex min-w-0 flex-col gap-5" aria-label="{{ __('Game') }}">
                <div class="flex items-start justify-center gap-3 lg:gap-10">
                    {{-- Hold and the clock --}}
                    <div class="flex w-[76px] shrink-0 flex-col gap-4 lg:w-[184px] lg:gap-6">
                        <div class="flex flex-col gap-2 bg-card p-2 lg:p-4">
                            <span class="text-[12px] font-bold text-ink-2 lg:text-sm">{{ __('Hold') }}</span>
                            <canvas x-ref="hold" class="block h-[36px] w-full lg:h-[56px]" aria-hidden="true"></canvas>
                        </div>
                        <dl class="m-0 flex flex-col gap-3 lg:gap-4" data-test="hud">
                            <div>
                                <dt class="text-[12px] text-ink-3 lg:text-sm">{{ __('Time') }}</dt>
                                <dd class="m-0 text-[17px] leading-tight font-bold tabular-nums lg:text-[36px]" x-ref="time" data-test="time">0:00.00</dd>
                            </div>
                            <div>
                                <dt class="text-[12px] text-ink-3 lg:text-sm">{{ __('Pieces per second') }}</dt>
                                <dd class="m-0 text-[15px] leading-tight font-bold tabular-nums lg:text-[30px]" x-ref="pps">0.00</dd>
                            </div>
                            @auth
                                {{-- P4: the week's best is the one a ranked run has to beat; the all-time best is information only --}}
                                <div>
                                    <dt class="text-[12px] text-ink-3 lg:text-sm">{{ __('Best this week') }}</dt>
                                    <dd class="m-0 text-[15px] leading-tight font-bold tabular-nums lg:text-[20px]" x-text="time(rankedBest)" data-test="best"></dd>
                                </div>
                                <div>
                                    <dt class="text-[12px] text-ink-3 lg:text-sm">{{ __('All-time best') }}</dt>
                                    <dd class="m-0 text-[13px] leading-tight font-bold text-ink-2 tabular-nums lg:text-[16px]" x-text="time(allTimeBest)" data-test="best-all-time"></dd>
                                </div>
                                {{-- This browser's practice best: information only, the quietest line --}}
                                <div>
                                    <dt class="text-[12px] text-ink-3 lg:text-sm">{{ __('Practice best') }}</dt>
                                    <dd class="m-0 text-[12px] leading-tight text-ink-3 tabular-nums lg:text-[14px]" x-text="time(practiceBest)" data-test="best-practice"></dd>
                                </div>
                            @else
                                <div>
                                    <dt class="text-[12px] text-ink-3 lg:text-sm">{{ __('Your best') }}</dt>
                                    <dd class="m-0 text-[15px] leading-tight font-bold tabular-nums lg:text-[20px]" x-text="time(practiceBest)" data-test="best"></dd>
                                </div>
                            @endauth
                        </dl>
                    </div>

                    {{-- The well: the mempool block template the pieces fill --}}
                    <div x-ref="wellSlot" class="relative flex min-w-0 max-w-[300px] grow justify-center pt-3 lg:pt-6">
                        <div class="relative border-2 border-[#24242B] bg-[#0E0E11]" style="box-shadow: -8px -8px 0 #141418;">
                            <canvas x-ref="well" class="block" role="img" aria-label="{{ __('The well with the falling piece') }}" data-test="well"></canvas>

                            {{-- Start, countdown --}}
                            <div x-show="mode === 'idle' || mode === 'countdown'" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-[#0A0A0B]/80 p-3 text-center" data-test="overlay">
                                <template x-if="mode === 'countdown'">
                                    <span class="font-display text-[56px] font-extrabold text-btc" x-text="countdown" data-test="countdown"></span>
                                </template>
                                <template x-if="mode === 'idle'">
                                    <div class="flex flex-col items-stretch gap-2">
                                        {{-- Ranked runs need a keyboard (plan): on a touch screen only practice --}}
                                        <x-button x-on:click="startRanked()" class="pointer-coarse:hidden" data-test="start-ranked">{{ __('Ranked run') }}</x-button>
                                        <p class="m-0 hidden max-w-[24ch] text-[12px] leading-normal text-ink-2 pointer-coarse:block" data-test="ranked-needs-keyboard">{{ __('Ranked runs need a keyboard. Here you can practise with touch.') }}</p>
                                        <x-button variant="quiet" x-on:click="startPractice()" data-test="start-practice">{{ __('Practice') }}</x-button>
                                        <p x-show="!signedIn" class="m-0 max-w-[24ch] text-[12px] leading-normal text-ink-2 pointer-coarse:hidden" data-test="guest-login-note">{{ __('Practice needs no login. Log in for ranked runs.') }}</p>
                                        <p x-show="error" x-text="error" class="m-0 max-w-[24ch] text-[12px] leading-normal text-loss" role="alert" data-test="error"></p>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <div x-show="minedTag > 0 && mode === 'playing'" class="pointer-events-none absolute right-0 bottom-6 hidden translate-x-full flex-col pl-3 text-sm font-bold text-btc lg:flex" aria-hidden="true">
                            <b class="font-display text-[20px] text-ink" x-text="'+' + minedTag"></b>{{ __('blocks mined') }}
                        </div>
                    </div>

                    {{-- Next --}}
                    <div class="flex w-[56px] shrink-0 flex-col gap-2 pt-0 lg:w-[136px] lg:gap-4 lg:pt-4">
                        <span class="text-[12px] font-bold text-ink-2 lg:text-sm">{{ __('Next') }}</span>
                        @foreach (range(0, 4) as $i)
                            <canvas data-next="{{ $i }}" @class(['block w-full bg-card', 'h-[44px] lg:h-[72px]' => $i === 0, 'h-[34px] lg:h-[56px]' => $i > 0]) aria-hidden="true"></canvas>
                        @endforeach
                    </div>
                </div>

                {{-- The chain: one cube per mined block --}}
                <div class="flex items-center justify-center gap-3" data-test="chain">
                    <span class="shrink-0 text-[15px] font-bold whitespace-nowrap tabular-nums lg:text-[20px]" data-test="chain-count"><span x-text="lines" data-test="lines">0</span><span class="font-normal text-ink-3"> / 40</span></span>
                    <div class="flex min-w-0 flex-wrap gap-[3px]" role="progressbar" aria-valuemin="0" aria-valuemax="40" x-bind:aria-valuenow="lines" aria-label="{{ __('Blocks mined') }}">
                        <template x-for="(state, i) in chain()" :key="i">
                            <i class="block size-[7px] lg:size-[12px]" x-bind:class="state === 'done' ? 'bg-btc' : 'border border-dashed border-[#63636A]'"></i>
                        </template>
                    </div>
                </div>

                @include('pages.stacker.partials.sound-control', ['class' => 'flex lg:hidden'])

                {{-- Touch controls: practice on a phone or tablet --}}
                {{--
                    Touch controls: practice on a phone or tablet. Fixed right above the tab bar
                    (--tabbar-h holds its height and the safe area), so all of them are in reach
                    without scrolling; the spacer keeps the page's end clear of them.
                --}}
                <div class="hidden h-[136px] pointer-coarse:block" x-show="kind === 'practice' && (mode === 'playing' || mode === 'countdown')" aria-hidden="true"></div>
                <div class="fixed inset-x-0 bottom-[var(--tabbar-h)] z-30 hidden grid-cols-4 gap-2 border-t border-hairline bg-bar px-4 py-2 pointer-coarse:grid" x-show="kind === 'practice' && (mode === 'playing' || mode === 'countdown')" data-test="touch">
                    @foreach (['left' => '←', 'soft' => '↓', 'right' => '→', 'hard' => '⤓', 'ccw' => '↺', 'flip' => '180', 'cw' => '↻', 'hold' => __('Hold')] as $action => $label)
                        <button type="button" class="h-12 rounded-md border border-line bg-well text-[15px] font-bold text-ink select-none" data-test="touch-{{ $action }}"
                                x-on:pointerdown.prevent="touch('{{ $action }}', true)" x-on:pointerup.prevent="touch('{{ $action }}', false)" x-on:pointerleave="touch('{{ $action }}', false)"
                                aria-label="{{ $actions[$action] }}">{{ $label }}</button>
                    @endforeach
                </div>
            </section>

            <aside class="flex min-w-0 flex-col gap-6">
                {{-- The result of the last run --}}
                <section x-ref="result" x-show="mode === 'result' && result" class="flex scroll-mt-4 flex-col gap-3 bg-card p-4 lg:p-5" aria-live="polite" data-test="result">
                    <span class="text-sm text-ink-2" x-text="result && result.status !== 'toppedOut' && result.status !== 'aborted' ? @js(__('40 blocks mined in')) : @js(__('Run over at'))"></span>
                    <span class="font-display text-[40px] leading-none font-extrabold tabular-nums lg:text-[48px]" x-text="result ? time(result.ticks) : ''" data-test="result-time"></span>
                    <span class="text-[13px] font-bold" x-bind:class="{ 'text-win': result?.status === 'verified', 'text-loss': result?.status === 'rejected', 'text-btc': ['verifying', 'pending', 'review', 'submitting', 'busy', 'unsent'].includes(result?.status) }" x-text="statusText()" data-test="result-status"></span>
                    <span class="text-[13px] text-ink-2" x-text="bestLine()" data-test="result-best"></span>
                    {{-- P5: the replay of this run, once the league keeps it: its own full-width line, right under the verdict --}}
                    <a x-show="result?.replay" x-cloak x-bind:href="result?.replay" href="#" data-test="result-replay"
                       class="btn-s inline-flex h-12 w-full items-center justify-center gap-2 rounded-md border border-btc px-5 text-[15px] font-bold text-btc-hi hover:text-btc-hi">
                        <x-icon name="play" :size="18" />{{ __('Watch replay') }}
                    </a>
                    {{-- A verified run that is a moment (a personal best, a first place, a week place): the share sheet --}}
                    <div x-show="result?.status === 'verified' && result?.moment" x-cloak data-test="result-share">
                        <x-button variant="secondary" icon="send" x-on:click="shareMoment()" data-test="result-share-open">{{ __('Share this moment') }}</x-button>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <x-button x-on:click="restart()" data-test="play-again">{{ __('Play again') }} <kbd class="rounded-sm border border-on-btc/40 px-1.5 text-[11px]" x-text="keyText('restart')"></kbd></x-button>
                        <x-button variant="quiet" x-on:click="startPractice()" x-show="kind === 'ranked'">{{ __('Practice') }}</x-button>
                        {{-- Ranked is the default for a player with a keyboard: after a practice run it is one click away again --}}
                        @auth
                            <x-button variant="quiet" x-on:click="startRanked()" x-show="kind === 'practice'" class="pointer-coarse:hidden" data-test="result-ranked">{{ __('Ranked run') }}</x-button>
                        @endauth
                    </div>
                    <p class="m-0 text-[12px] leading-normal text-ink-3" x-show="kind === 'ranked'">{{ __('A ranked run counts once the league has replayed its inputs and reached the same time.') }}</p>
                </section>

                <section class="flex flex-col gap-3" aria-labelledby="stacker-keys-h">
                    <h2 id="stacker-keys-h" class="m-0 font-display text-[18px] font-bold">{{ __('Keyboard') }}</h2>
                    <dl class="m-0 grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-1.5 text-sm text-ink-2" data-test="keys">
                        @foreach ($actions as $action => $label)
                            <dt><kbd class="rounded-sm border border-b-2 border-line px-1.5 font-mono text-[12px] text-ink" x-text="keyText('{{ $action }}')"></kbd></dt>
                            <dd class="m-0">{{ $label }}</dd>
                        @endforeach
                    </dl>
                    @auth
                        <a href="{{ route('gaming.edit') }}#blockfill" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="controls-link">{{ __('Change keys and handling') }}</a>
                    @else
                        <details class="text-[13px] text-ink-2" data-test="guest-controls">
                            <summary class="inline-flex min-h-11 cursor-pointer items-center font-bold text-ink">{{ __('Handling (this browser)') }}</summary>
                            <div class="flex flex-col gap-3 pt-2" x-data="{ das: controls.das, arr: controls.arr, sdf: controls.sdf }">
                                @foreach (['das' => [__('DAS: ticks before a held key repeats'), 1, 20], 'arr' => [__('ARR: ticks between repeats (0 = to the wall)'), 0, 5], 'sdf' => [__('Soft drop speed (41 = instant)'), 5, 41]] as $field => [$label, $min, $max])
                                    <label class="flex flex-col gap-1">{{ $label }}
                                        <input type="number" min="{{ $min }}" max="{{ $max }}" x-model.number="{{ $field }}" class="h-11 w-24 rounded-md border border-edge bg-ground px-3 text-ink" data-test="guest-{{ $field }}">
                                    </label>
                                @endforeach
                                <x-button variant="quiet" x-on:click="saveControls({ ...controls, das, arr, sdf })" data-test="guest-save">{{ __('Save in this browser') }}</x-button>
                            </div>
                        </details>
                    @endauth
                </section>

                <section class="flex flex-col gap-2" aria-labelledby="stacker-fees-h">
                    <h2 id="stacker-fees-h" class="m-0 text-sm font-bold text-ink-2">{{ __('Fee rate of a piece') }}</h2>
                    <div class="flex items-center gap-1 text-[12px] text-ink-3">
                        {{ __('low') }}
                        @foreach ($fees as $fee)
                            <i class="block h-3 w-5" style="background: {{ $fee }}"></i>
                        @endforeach
                        {{ __('high sat/vB') }}
                    </div>
                </section>
            </aside>
        </div>
    </div>

    {{-- The casual weekly hunt (P4): this week's leaderboard, your place, last week's winner --}}
    @php
        $week = $this->week;
        $metric = ScoreMetric::time();
        $mine = $this->mine;
        $winner = $this->lastWinner;
        $window = $week === null ? null : ScoreWindow::of($week);
    @endphp
    <section x-data x-on:stacker-verified.window="$wire.$refresh()" class="mx-auto mt-8 grid w-full max-w-[1340px] grid-cols-1 gap-6 lg:mt-10 lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-12" aria-labelledby="stacker-week-h" data-test="stacker-week">
        <div class="flex min-w-0 flex-col gap-3 rounded-lg bg-card px-2 py-4 lg:px-5">
            <div class="flex flex-col gap-1 px-2 lg:px-0">
                <h2 id="stacker-week-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('This week\'s hunt') }}</h2>
                <p class="m-0 text-xs leading-normal text-ink-2">{{ __('Your best verified ranked run of the week counts. The fastest time wins, a tie goes to the earlier run. A new week starts every Monday at 00:00 Berlin time.') }}</p>
                @if ($window)
                    <p class="m-0 text-xs text-ink-3 tabular-nums" data-test="stacker-week-window">{{ \App\Support\LeagueTime::stamp($window->start) }} – {{ \App\Support\LeagueTime::stamp($window->end) }}</p>
                @endif
            </div>
            @if ($this->standings === [])
                <p class="m-0 px-2 py-4 text-[13px] text-ink-2 lg:px-0" data-test="stacker-week-empty">{{ __('Nobody has a verified run this week yet. Yours could be the first.') }}</p>
            @else
                @include('pages.scores.partials.leaderboard', ['standings' => $this->standings, 'metric' => $metric, 'limit' => 10, 'viewerId' => auth()->id(), 'staff' => false,
                    'shareMoment' => $week === null ? null : app(\App\Support\Stacker\BlockfillMoments::class)->shareableOn(auth()->user(), $week)])
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-4">
            <div class="flex flex-col gap-2 rounded-lg bg-card p-4" data-test="stacker-week-mine">
                <h3 class="m-0 text-sm font-bold text-ink-2">{{ __('Your place') }}</h3>
                @auth
                    @if ($mine?->place !== null)
                        <p class="m-0 flex items-baseline gap-3">
                            <b class="font-display text-[32px] leading-none font-extrabold tabular-nums" data-test="stacker-week-place">#{{ $mine->place }}</b>
                            <span class="font-mono text-[15px] tabular-nums">{{ $metric->format((int) $mine->value) }}</span>
                        </p>
                        {{-- P5: the replay of the run that holds the place --}}
                        @php($myReplay = app(\App\Support\Stacker\StackerReplays::class)->forStandings([$mine], auth()->user())[$mine->participant->id] ?? null)
                        @if ($myReplay)
                            <a href="{{ $myReplay }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="stacker-week-replay">{{ __('Watch your replay') }}</a>
                        @endif
                    @else
                        <p class="m-0 text-[13px] text-ink-2">{{ __('Your first verified ranked run this week puts you on the board.') }}</p>
                    @endif
                @else
                    <p class="m-0 text-[13px] text-ink-2">{{ __('Log in and play a ranked run to get on the board.') }}</p>
                @endauth
            </div>

            <div class="flex flex-col gap-2 rounded-lg bg-card p-4" data-test="stacker-week-winner">
                <h3 class="m-0 text-sm font-bold text-ink-2">{{ __('Last week\'s winner') }}</h3>
                @if ($winner)
                    @php($winnerUser = $winner->participant->user)
                    <p class="m-0 flex min-w-0 items-center gap-2">
                        @if ($winnerUser)
                            <x-avatar :user="$winnerUser" :size="32" class="shrink-0 rounded-sm" />
                            <a href="{{ route('players.show', $winnerUser->npub) }}" class="min-w-0 truncate font-bold text-ink hover:text-btc-hi" data-test="stacker-week-winner-name">{{ $winner->participant->name }}</a>
                        @else
                            <span class="min-w-0 truncate font-bold text-ink-2" data-test="stacker-week-winner-name">{{ $winner->participant->name }}</span>
                        @endif
                        <span class="ml-auto shrink-0 font-mono text-[13px] tabular-nums">{{ $metric->format((int) $winner->value) }}</span>
                    </p>
                @else
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No winner last week.') }}</p>
                @endif
            </div>

            @if (\Illuminate\Support\Facades\Route::has('scores.show'))
                <a href="{{ route('scores.show', Blockfill::SLUG) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="stacker-week-all">{{ __('All weeks and the points ladder') }}</a>
            @endif
            {{-- P6: how a week, ranked runs, practice and the league's check work, on the rules page --}}
            <a href="{{ route('rules') }}#blockfill" class="-mt-3 inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="stacker-week-rules">{{ __('How a week works') }}</a>
        </div>
    </section>

    {{-- The share sheet of a moment: opened by the result screen and by the player's own row above --}}
    @auth
        <livewire:blockfill-share key="blockfill-share" />
    @endauth
</div>
