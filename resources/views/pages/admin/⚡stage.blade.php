<?php

use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignerMessages;
use App\Support\PreSeason;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\SeasonRelease;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The Block 0 stage: the release of a season as one big button on a projected
 * screen, pressed by a guest. Three acts.
 *
 *   1. Arm. A board member reads the draft, retypes the supply and arms the
 *      stage, as a rehearsal or for the real release. The release label is
 *      signed here, so no signer asks in front of the audience, and stays in
 *      the page's memory only.
 *   2. The button. Fullscreen: the season, its supply and the button.
 *   3. Confirm. The button only opens a confirm; a press-and-hold there calls
 *      launch().
 *
 * The page adds no way to release and no rule: arm() and label() are
 * SeasonRelease::prepare(), launch() is SeasonRelease::release(), and who may
 * open the page is SeasonRelease::refusal(). A rehearsal runs the same show
 * and asks prepare() where the real mode calls release(): it never releases.
 *
 * The mode, the retyped supply, the end and the draft hash are locked: the
 * browser cannot turn a rehearsal into a release, only disarm() and a new
 * arm() can. One arming carries one launch. Nothing re-renders after the
 * first response (the stage is a dialog the browser holds open), so every
 * action answers with an array the page's script reads
 * (resources/js/seasonStage.js).
 */
new #[Title('Block 0 stage')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    /**
     * How long an armed stage offers its button. The release refuses an end
     * that is an hour old (SeasonRelease::check()); the page stops two
     * minutes earlier, so the button never outlives what the server takes.
     */
    public const ARMED_SECONDS = 3480;

    /** The planned end: fixed when the page opens, fixed again when the stage is armed. */
    #[Locked]
    public int $endsAt = 0;

    /** The draft this page showed (SeasonRelease::draftHash()): a draft that changed since is refused. */
    #[Locked]
    public string $draftHash = '';

    #[Locked]
    public bool $armed = false;

    #[Locked]
    public bool $rehearsal = true;

    /** The supply as the board member retyped it when arming. */
    #[Locked]
    public string $supply = '';

    public function mount(): void
    {
        Gate::authorize('admin');

        // Whoever is refused for the reason a guest is refused (not on the board) has no stage.
        abort_if(SeasonRelease::refusal($this->admin()) === SeasonRelease::refusal(null), 403);

        $this->endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());
        $this->draftHash = SeasonRelease::draftHash();
    }

    /** Why nothing can be released now (a season is live, no plan, a missing key), or null. */
    #[Computed]
    public function refusal(): ?string
    {
        return SeasonRelease::refusal($this->admin());
    }

    /**
     * Act 1: arm the stage. Answers with the unsigned release label for the
     * board member's signer.
     *
     * @return array{ok: bool, reason?: string, rehearsal?: bool, template?: array<string, mixed>, seconds?: int, end?: string}
     */
    #[Renderless]
    public function arm(string $supply, bool $rehearsal): array
    {
        $endsAt = SeasonRelease::plannedEnd(CarbonImmutable::now());

        try {
            $template = app(SeasonRelease::class)->prepare($this->admin(), $supply, $endsAt, $this->draftHash);
        } catch (SeasonReleaseRefused $refused) {
            $this->disarm();

            return ['ok' => false, 'reason' => $refused->getMessage()];
        }

        $this->armed = true;
        $this->rehearsal = $rehearsal;
        $this->supply = $supply;
        $this->endsAt = $endsAt;

        return ['ok' => true, 'rehearsal' => $rehearsal, 'template' => $template, 'seconds' => self::ARMED_SECONDS, 'end' => $this->seasonEnd()];
    }

    /**
     * The release label of the armed stage once more, for a new signature (a
     * signed label is good for ten minutes, SignedEventGate::MAX_AGE_SECONDS).
     *
     * @return array{ok: bool, reason?: string, template?: array<string, mixed>}
     */
    #[Renderless]
    public function label(): array
    {
        if (! $this->armed) {
            return ['ok' => false, 'reason' => __('The stage is not armed. Arm it again.')];
        }

        try {
            return ['ok' => true, 'template' => app(SeasonRelease::class)->prepare($this->admin(), $this->supply, $this->endsAt, $this->draftHash)];
        } catch (SeasonReleaseRefused $refused) {
            return ['ok' => false, 'reason' => $refused->getMessage(), 'live' => Seasons::isLive()];
        }
    }

    #[Renderless]
    public function disarm(): void
    {
        $this->armed = false;
        $this->rehearsal = true;
        $this->supply = '';
    }

    /**
     * Act 3: the completed hold. The real mode releases Block 0 with the
     * signed label; a rehearsal asks the release's checks and stops there.
     *
     * @return array{ok: bool, rehearsal?: bool, reason?: string, live?: bool}
     */
    #[Renderless]
    public function launch(string $signed = ''): array
    {
        if (! $this->armed) {
            return ['ok' => false, 'reason' => __('The stage is not armed. Arm it again.'), 'live' => Seasons::isLive()];
        }

        $rehearsal = $this->rehearsal;
        $supply = $this->supply;
        // One arming carries one launch, whatever its outcome.
        $this->disarm();

        try {
            if ($rehearsal) {
                app(SeasonRelease::class)->prepare($this->admin(), $supply, $this->endsAt, $this->draftHash);

                return ['ok' => true, 'rehearsal' => true];
            }

            app(SeasonRelease::class)->release($this->admin(), $supply, $this->endsAt, $this->draftHash, json_decode($signed, true));
        } catch (SeasonReleaseRefused $refused) {
            return ['ok' => false, 'reason' => $refused->getMessage(), 'live' => Seasons::isLive()];
        } catch (RejectedEvent) {
            return ['ok' => false, 'reason' => __('The signed release was refused. Please start again.'), 'live' => Seasons::isLive()];
        }

        return ['ok' => true, 'rehearsal' => false];
    }

    /**
     * What the arming step shows of the draft beyond its supply: the share
     * of every game group with the games and modes that mine
     * (ChainDraft::mining()), and every warning of the draft
     * (ChainDraft::warnings(), the estimator's included), none left out. A
     * saved draft overrides the league defaults wholesale, so this is where
     * the board sees a draft that lacks a game before it arms. A forecast
     * that fails is reported and said, never passed over.
     *
     * @return array{rows: list<array{key: string, label: string, share: int|null, games: list<string>}>, warnings: list<string>}
     */
    public function signs(): array
    {
        $chain = ChainDraft::current();
        $failed = [];

        try {
            $estimator = app(ChainOverview::class)->draft()['estimate']['warnings'];
        } catch (Throwable $exception) {
            report($exception);
            $estimator = [];
            $failed = [__('The forecast could not be computed, so its warnings are missing here. Check the season page before you arm.')];
        }

        return ['rows' => ChainDraft::mining($chain), 'warnings' => [...ChainDraft::warnings($chain, $estimator), ...$failed]];
    }

    /** The planned end in the board member's time zone. */
    public function seasonEnd(): string
    {
        return CarbonImmutable::createFromTimestamp($this->endsAt)
            ->setTimezone(PreSeason::timezoneFor($this->admin()))
            ->locale(app()->getLocale())
            ->translatedFormat('D j M Y, H:i');
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $draft = SeasonRelease::draft();
    $name = SeasonRelease::seasonName();
    $supply = PreSeason::formatSats($draft['supply']);
    $message = $draft['message'];
    $refusal = $this->refusal;
    $live = Seasons::isLive();
    $block = "Block\u{00A0}0";
    $chancellor = 'Chancellor on brink of second bailout for banks';
@endphp

<x-admin.page active="seasons" :title="__('Block 0 stage')" :crumbs="[[__('Seasons'), route('admin.season')]]"
              :lead="__('One big button for the release in front of an audience. You arm it here; a guest presses it on the projected screen and confirms by holding a second control. Nothing is released before that hold is complete.')"
              data-test="season-stage" data-state="{{ $refusal === null ? 'ready' : ($live ? 'live' : 'blocked') }}">
    @if ($refusal !== null)
        {{-- Nothing to release: the reason, and the live page when a season runs. Never a button. --}}
        <section class="flex flex-col items-start gap-4 rounded border border-hairline bg-card p-4 lg:p-6" data-test="stage-blocked">
            <h2 class="rv-h3">{{ $live ? __('The season is already live.') : __('Block 0 cannot be released now.') }}</h2>
            <p class="m-0 max-w-[68ch] text-sm text-ink-2" data-test="stage-blocked-reason">{{ $refusal }}</p>
            @if ($live)
                <a href="{{ route('home') }}" class="rv-b" data-test="stage-live-link">{{ __('Open the live home page') }}</a>
            @else
                <a href="{{ route('admin.season') }}" class="rv-b2">{{ __('Back to the season page') }}</a>
            @endif
        </section>
    @else
        <div class="flex flex-col gap-6" wire:ignore
             x-data="seasonStage({ pubkey: @js(auth()->user()->pubkey), messages: @js(SignerMessages::labels()), labels: @js([
                 'expired' => __('The stage has expired and is disarmed. Arm it again.'),
                 'unknown' => __('The answer did not arrive, so this page does not know whether Block 0 was released. Check the season page before you try again.'),
                 'notSigned' => __('Nothing was sent: the release was not signed.'),
             ]) })">
            {{-- Act 1: read, retype, arm. --}}
            <section x-show="! armed" aria-labelledby="arm-h" class="grid grid-cols-1 gap-6 @4xl/admin:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]" data-test="stage-arm">
                <div class="flex flex-col gap-4 rounded border border-hairline bg-card p-4 lg:p-6">
                    <h2 class="rv-h3">{{ __('What Block 0 releases') }}</h2>
                    <dl class="m-0 flex flex-col text-[13px]">
                        @foreach ([
                            [__('Season'), $name],
                            [__('Supply, the most it pays out'), $supply.' sats'],
                            [__('Base subsidy'), PreSeason::formatSats($draft['subsidy']).' sats'],
                            [__('Length'), trans_choice(':count week|:count weeks', $draft['weeks'])],
                            [__('Season end'), $this->seasonEnd()],
                        ] as [$term, $value])
                            <div class="flex justify-between gap-4 border-b border-hairline py-2 last:border-0"><dt class="text-ink-2">{{ $term }}</dt><dd class="m-0 text-right">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    {{-- What mines: the shares and games of the draft as it is saved, and every warning of it. --}}
                    @php($signs = $this->signs())
                    <div class="flex flex-col gap-1" data-test="stage-mining">
                        <b class="text-[13px]">{{ __('Shares and games that mine') }}</b>
                        <dl class="m-0 flex flex-col text-[13px]">
                            @foreach ($signs['rows'] as $row)
                                <div class="flex flex-col gap-0.5 border-b border-hairline py-2 last:border-0" data-test="stage-share-{{ $row['key'] }}">
                                    <dt class="flex justify-between gap-4"><span>{{ $row['label'] }}</span><span class="tabular-nums" data-test="stage-share-percent">{{ $row['share'] === null ? '–' : $row['share']."\u{00A0}%" }}</span></dt>
                                    <dd class="m-0 text-xs text-ink-2">{{ $row['games'] === [] ? __('no game with a weight') : implode(', ', $row['games']) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                    @foreach ($signs['warnings'] as $warning)
                        <p class="m-0 rounded-md bg-btc-chip px-3.5 py-2.5 text-[13px] text-btc-hi" data-test="stage-warning">{{ $warning }}</p>
                    @endforeach
                    <div class="flex flex-col gap-1">
                        <b class="text-[13px]">{{ __('Genesis message, published with Block 0') }}</b>
                        <p class="m-0 text-sm break-words" data-test="stage-message">{{ $message ?? __('not written yet: write it in the chain draft on the season page') }}</p>
                    </div>
                    <a href="{{ route('admin.season') }}" class="rv-lk self-start text-[13px]">{{ __('Change the draft on the season page') }}</a>
                </div>

                <form class="flex flex-col gap-5 rounded border border-hairline bg-card p-4 shadow-[inset_0_0_0_1px_#3A2A12] lg:p-6" x-on:submit.prevent="arm()">
                    <h2 id="arm-h" class="rv-h3">{{ __('Arm the stage') }}</h2>

                    <div role="radiogroup" aria-labelledby="mode-h" class="flex flex-col gap-2">
                        <b id="mode-h" class="text-[13px]">{{ __('What the button does') }}</b>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <button type="button" role="radio" class="stg-mode" :aria-checked="mode === 'rehearsal' ? 'true' : 'false'" aria-checked="true" x-on:click="mode = 'rehearsal'" data-test="mode-rehearsal">
                                <b>{{ __('Rehearsal') }}</b>
                                <span>{{ __('The whole show, marked as a rehearsal. Nothing is released.') }}</span>
                            </button>
                            <button type="button" role="radio" class="stg-mode" :aria-checked="mode === 'real' ? 'true' : 'false'" aria-checked="false" x-on:click="mode = 'real'" data-test="mode-real">
                                <b>{{ __('Real release') }}</b>
                                <span>{{ __('The hold releases :block for good.', ['block' => $block]) }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="stage-supply" class="text-[13px] font-bold">{{ __('Type the supply to confirm') }}</label>
                        <input id="stage-supply" type="text" inputmode="numeric" autocomplete="off" x-model="supply" placeholder="{{ $supply }}" class="rv-in" data-test="stage-supply">
                    </div>

                    <p class="m-0 text-[13px] text-loss" role="alert" x-show="error" x-text="error" x-cloak data-test="stage-arm-error"></p>
                    <p class="m-0 text-[13px] text-ink-2" role="status" x-show="notice" x-text="notice" x-cloak data-test="stage-notice"></p>

                    <div class="flex flex-col gap-2">
                        <button type="submit" class="rv-b self-start" x-bind:disabled="busy" data-test="stage-arm-button">
                            <span x-show="mode === 'rehearsal'">{{ __('Arm the rehearsal') }}</span>
                            <span x-show="mode === 'real'" x-cloak>{{ __('Arm the real release') }}</span>
                        </button>
                        <span class="text-xs text-ink-2">{{ __('Arming asks your signer for the release signature now, so it does not ask on stage.') }}</span>
                    </div>
                </form>
            </section>

            {{-- Act 1, armed: what is loaded, how long, and the way onto the stage. --}}
            <section x-show="armed" x-cloak aria-labelledby="armed-h" class="flex flex-col gap-5 rounded border border-hairline bg-card p-4 shadow-[inset_0_0_0_1px_#3A2A12] lg:p-6" data-test="stage-armed" :data-mode="rehearsal ? 'rehearsal' : 'real'">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <h2 id="armed-h" class="rv-h3">
                        <span x-show="rehearsal">{{ __('Armed as a rehearsal') }}</span>
                        <span x-show="! rehearsal">{{ __('Armed for the real release') }}</span>
                    </h2>
                    <span class="stg-reh-chip" x-show="rehearsal" data-test="armed-rehearsal">{{ __('Rehearsal. Nothing is released.') }}</span>
                    <span class="rv-tag is-btc" x-show="! rehearsal">{{ __('The hold releases :block for good.', ['block' => $block]) }}</span>
                </div>

                <dl class="m-0 grid grid-cols-1 gap-3 text-[13px] @2xl/admin:grid-cols-3">
                    <div class="flex flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <dt class="text-ink-2">{{ __('Disarms itself in') }}</dt>
                        <dd class="m-0 font-display text-2xl font-bold tabular-nums" x-text="clock(expiresAt)" data-test="armed-left">58:00</dd>
                    </div>
                    <div class="flex flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <dt class="text-ink-2">{{ __('Release signature') }}</dt>
                        <dd class="m-0" data-test="armed-signature" :data-signed="signatureFresh ? 'yes' : 'no'">
                            <span x-show="signing">{{ __('Waiting for your signer…') }}</span>
                            <span x-show="! signing && signatureFresh">{{ __('Signed. Good for another') }} <b class="tabular-nums" x-text="clock(signedUntil)"></b>. {{ __('After that your signer is asked on stage, after the hold.') }}</span>
                            <span x-show="! signing && ! signatureFresh">{{ __('Not signed. Your signer is asked on stage, after the hold.') }}</span>
                        </dd>
                    </div>
                    <div class="flex flex-col gap-1 rounded-md bg-ground px-3.5 py-3 shadow-ring">
                        <dt class="text-ink-2">{{ __('Season end') }}</dt>
                        <dd class="m-0" x-text="armedEnd"></dd>
                    </div>
                </dl>

                <p class="m-0 text-[13px] text-loss" role="alert" x-show="signerError" x-text="signerError" x-cloak data-test="armed-signer-error"></p>
                <p class="m-0 text-[13px] text-loss" role="alert" x-show="error" x-text="error" x-cloak></p>

                <ol class="m-0 flex max-w-[68ch] list-decimal flex-col gap-1 pl-5 text-[13px] text-ink-2">
                    <li>{{ __('Open the stage: the browser goes fullscreen and shows the season, the supply and the button.') }}</li>
                    <li>{{ __('The guest clicks the button. That only opens the confirm.') }}</li>
                    <li>{{ __('The guest presses and holds the confirm for two seconds. Letting go earlier does nothing.') }}</li>
                </ol>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="rv-b" x-on:click="openStage()" data-test="stage-open">{{ __('Open the stage') }}</button>
                    <button type="button" class="rv-b2" x-on:click="signAgain()" x-bind:disabled="busy || signing" data-test="stage-sign-again">{{ __('Sign again') }}</button>
                    <button type="button" class="rv-b2" x-on:click="disarm()" x-bind:disabled="busy" data-test="stage-disarm">{{ __('Disarm') }}</button>
                </div>
            </section>

            {{-- Acts 2 and 3: the stage. A modal dialog, so the admin page under it is inert and out of sight. --}}
            <dialog class="stg" x-ref="stage" tabindex="-1" x-on:cancel.prevent="leaveStage()" x-on:close="onStageClosed()" aria-labelledby="stg-name" style="--len: {{ max(10, mb_strlen($name)) }}; --tlen: {{ mb_strlen(__(':block is mined.', ['block' => $block])) }}" data-test="stage" :data-scene="scene" :data-rehearsal="rehearsal ? 'yes' : 'no'">
                <p class="stg-reh" x-show="rehearsal" data-test="stage-rehearsal">{{ __('Rehearsal. Nothing is released.') }}</p>

                <p class="stg-brand"><x-logo :size="36" class="stg-logo" /><span>TWENTY ONE Esports</span></p>

                {{-- Act 2: the button. --}}
                <section class="stg-scene" x-show="scene === 'idle' || scene === 'confirm'" data-test="stage-idle">
                    <h1 id="stg-name" class="stg-name">{{ $name }}</h1>
                    <p class="stg-supply"><b>{{ $supply }}</b> {{ __('sats to mine') }}</p>
                    <div class="stg-chain">
                        <button type="button" class="stg-cube" x-ref="big" x-on:click="openConfirm()" data-test="stage-button">
                            <span class="stg-face">{{ __('Mine :block', ['block' => $block]) }}</span>
                        </button>
                        @foreach ([1, 2, 3, 4, 5] as $slot)
                            <svg class="stg-ghost" viewBox="0 0 100 100" aria-hidden="true" focusable="false"><rect x="1.5" y="1.5" width="97" height="97"></rect></svg>
                        @endforeach
                    </div>
                </section>

                {{-- Block 0 is mined: stays until a click, then the way to the live page. --}}
                <section class="stg-scene stg-done" x-show="scene === 'done'" x-on:click="revealed = true" data-test="stage-done">
                    <h1 class="stg-title" tabindex="-1" x-ref="doneTitle">{{ __(':block is mined.', ['block' => $block]) }}</h1>
                    <div class="stg-chain">
                        <div class="stg-cube is-mined" aria-hidden="true">
                            <span class="stg-face"><b>0</b><span>{{ $name }}</span></span>
                            @foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $spark)
                                <i class="stg-spark"></i>
                            @endforeach
                        </div>
                        <div class="stg-told">
                            <p class="stg-reh-chip" x-show="rehearsal" data-test="done-rehearsal">{{ __('Rehearsal. Nothing was released.') }}</p>
                            <blockquote @class(['stg-quote', 'is-long' => mb_strlen((string) $message) > 120]) data-test="done-message">{{ $message }}</blockquote>
                            <p class="stg-line">{{ __(':supply sats can be mined from now on, one fair rated win at a time. They are paid out once, after the season review.', ['supply' => $supply]) }}</p>
                            <p class="stg-next" x-show="revealed" x-cloak>
                                <a x-show="! rehearsal" href="{{ route('home') }}" class="stg-btn" data-test="done-home">{{ __('Open the live home page') }}</a>
                                <button x-show="rehearsal" type="button" class="stg-btn" x-on:click.stop="leaveStage()" data-test="done-back">{{ __('End the rehearsal') }}</button>
                            </p>
                        </div>
                    </div>
                </section>

                {{-- Refused, failed or expired: the reason in plain words, and the way back. --}}
                <section class="stg-scene stg-failed" x-show="scene === 'failed' || scene === 'expired'" role="alert" data-test="stage-failed">
                    <h1 class="stg-title">
                        <span x-show="scene === 'expired'">{{ __('This stage has expired.') }}</span>
                        <span x-show="scene === 'failed' && live">{{ __('The season is already live.') }}</span>
                        <span x-show="scene === 'failed' && ! live">{{ __(':block was not mined.', ['block' => $block]) }}</span>
                    </h1>
                    <p class="stg-reason" x-text="failure" data-test="failed-reason"></p>
                    <p class="stg-line" x-show="! unknown && ! live">{{ __('Nothing was released.') }}</p>
                    <p class="stg-next">
                        <a x-show="live" href="{{ route('home') }}" class="stg-btn" data-test="failed-home">{{ __('Open the live home page') }}</a>
                        <button type="button" class="stg-btn" :class="live && 'is-quiet'" x-on:click="leaveStage()" data-test="failed-back">{{ __('Back to arming') }}</button>
                    </p>
                    {{-- What stands there now: the frame of the block nobody mined, or the block when the season runs. --}}
                    <div class="stg-chain" aria-hidden="true">
                        <div class="stg-unmined" x-show="! live"></div>
                        <div class="stg-cube is-mined" x-show="live"><span class="stg-face"><b>0</b><span>{{ $name }}</span></span></div>
                    </div>
                </section>

                {{-- The browser refused fullscreen: the stage runs in the window. --}}
                <p class="stg-fs" x-show="fsRefused" x-cloak data-test="stage-fs-refused">
                    <span>{{ __('The browser did not go fullscreen. Press F11, or carry on in this window.') }}</span>
                    <button type="button" class="stg-btn is-quiet" x-on:click="enterFullscreen()">{{ __('Try again') }}</button>
                    <button type="button" class="stg-btn is-quiet" x-on:click="fsRefused = false" data-test="stage-fs-hide">{{ __('Hide') }}</button>
                </p>

                {{-- Act 3: the confirm. It sits beside the button, never on it. --}}
                <dialog class="stg-confirm" x-ref="confirm" x-on:cancel="onConfirmCancel($event)" x-on:close="onConfirmClosed()" x-on:pointerdown="backdropPressed($event)" x-on:click.self="backdrop()" aria-labelledby="stg-ask" aria-describedby="stg-final" data-test="stage-confirm">
                    <div class="stg-sheet">
                        <div class="stg-ask">
                            <p class="stg-reh-chip" x-show="rehearsal" data-test="confirm-rehearsal">{{ __('Rehearsal. Nothing is released.') }}</p>
                            <h2 id="stg-ask">{!! str_replace('@@', '<span class="stg-nb">'.e($name).'</span>', e(__('Mine :block of the :season?', ['block' => $block, 'season' => '@@']))) !!}</h2>
                            <p id="stg-final">{{ __('This cannot be undone. Rated play and mining start for everyone.') }}</p>
                            <div class="stg-acts">
                                <button type="button" class="stg-hold" x-ref="hold" :class="{ 'is-cold': ! holdReady, 'is-holding': holding, 'is-sent': sending }" :aria-disabled="(! holdReady || sending) ? 'true' : 'false'" aria-describedby="stg-hold-how"
                                        x-on:pointerdown.prevent="$event.button === 0 && startHold()" x-on:pointerup="cancelHold()" x-on:pointerleave="cancelHold()" x-on:pointercancel="cancelHold()" x-on:contextmenu.prevent
                                        x-on:keydown.space.prevent="$event.repeat || startHold()" x-on:keydown.enter.prevent="$event.repeat || startHold()" x-on:keyup.space="cancelHold()" x-on:keyup.enter="cancelHold()" x-on:blur="cancelHold()"
                                        data-test="stage-hold">
                                    <i class="stg-hold-fill" aria-hidden="true"></i>
                                    <span x-show="! sending">{{ __('Hold to mine :block', ['block' => $block]) }}</span>
                                    <span x-show="sending && wait === 'signer'" x-cloak data-test="hold-signer">{{ __('Waiting for the signer…') }}</span>
                                    <span x-show="sending && wait === 'release'" x-cloak data-test="hold-release">{{ __('Mining :block…', ['block' => $block]) }}</span>
                                </button>
                                <span id="stg-hold-how" class="stg-how" :class="sending && 'is-off'">{{ __('Press and hold for two seconds.') }}</span>
                                <button type="button" class="stg-btn is-quiet" x-ref="cancel" x-on:click="closeConfirm()" x-bind:disabled="sending" data-test="stage-cancel">{{ __('Cancel') }}</button>
                            </div>
                        </div>

                        {{-- The meme (voice canon `btc-genesis-chancellor`): the headline in Bitcoin's Block 0, beside the one this Block 0 carries. --}}
                        <div class="stg-meme" data-test="stage-meme">
                            <figure class="stg-clip">
                                <blockquote lang="en">{{ $chancellor }}</blockquote>
                                <figcaption><i class="stg-mini" aria-hidden="true"></i><span>{{ __("In Bitcoin's :block, 3 January 2009", ['block' => $block]) }}</span></figcaption>
                            </figure>
                            <figure class="stg-clip is-ours">
                                <blockquote>{{ $message }}</blockquote>
                                <figcaption><i class="stg-mini" aria-hidden="true"></i><span>{{ __('In this :block, today', ['block' => $block]) }}</span></figcaption>
                            </figure>
                        </div>
                    </div>
                </dialog>
            </dialog>
        </div>
    @endif
</x-admin.page>
