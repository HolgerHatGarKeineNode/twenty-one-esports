<?php

use App\Models\User;
use App\Support\Chess\ChessSettings;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Chess settings (ChessSettings.dc.html): board, during the game, daily
 * chess. Every change is saved at once ("Saved, applies from your next
 * move"). The notifications moved to their own tab (P51,
 * pages/settings/⚡notifications); an old link to #notifications is sent there.
 *
 * Premoves and "Elo during the game" are shown switched off until premoves
 * and Elo exist.
 *
 * Sounds (P5c, the design's "Sounds" row in "During the game"): on/off and a
 * volume, with a button to hear the set. The page's sound player takes the
 * new values at once (`sound-settings` browser event).
 */
new #[Title('Chess settings')] #[Layout('layouts::app', ['scripts' => ['resources/js/chess.js']])] class extends Component {
    public bool $saved = false;

    public function toggle(string $key): void
    {
        abort_unless(in_array($key, ['coordinates', 'pieceNames', 'alwaysQueen', 'doubleCheck', 'sound'], true), 422);

        $settings = $this->settings()->toArray();
        $settings[$key] = ! $settings[$key];
        $this->store($settings);
    }

    public function setVolume(int $volume): void
    {
        abort_unless($volume >= 0 && $volume <= 100, 422);

        $this->store([...$this->settings()->toArray(), 'volume' => $volume]);
    }

    public function setBoard(string $board): void
    {
        abort_unless(in_array($board, ChessSettings::BOARDS, true), 422);

        // The Orange Pill board is a member perk (ChessSettings "Member").
        if ($board === 'orange' && ! $this->user()->is_member) {
            return;
        }

        $this->store([...$this->settings()->toArray(), 'board' => $board]);
    }

    public function settings(): ChessSettings
    {
        return $this->user()->chessSettings();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function store(array $settings): void
    {
        $stored = ChessSettings::fromArray($settings);
        $this->user()->forceFill(['chess_settings' => $stored->toArray()])->save();
        $this->saved = true;
        $this->dispatch('sound-settings', enabled: $stored->sound, volume: $stored->volume);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $settings = $this->settings();
    $user = auth()->user();
    $themes = [
        'house' => [__('House'), __('league default'), false, '#CFCFD4', '#62626C'],
        'wood' => [__('Wood'), __('warm, like a real board'), false, '#E4CCA2', '#8E5F3B'],
        'slate' => [__('Slate'), __('cool and calm'), false, '#DCE3EA', '#5E7891'],
        'orange' => [__('Orange Pill'), __('bitcoin orange'), true, '#F4D9B0', '#B9640A'],
    ];
    $theme = $themes[$settings->board];
    $switch = fn (bool $on) => $on;
@endphp

<div class="flex grow flex-col gap-5 px-4 pb-8 lg:px-12 lg:pb-10" data-test="chess-settings"
     x-data x-init="if (location.hash === '#notifications') { window.location.replace(@js(route('settings.notifications'))) }">
    <x-settings.header current="chess">
        <span role="status" class="flex items-center gap-1.5 text-[13px] text-win" x-data x-show="$wire.saved" x-cloak data-test="settings-saved"><x-icon name="check" :size="16" />{{ __('Saved, applies from your next move') }}</span>
    </x-settings.header>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,400px)_minmax(0,1fr)_minmax(0,1fr)] xl:grid-cols-[400px_452px_minmax(0,1fr)]">
        {{-- Preview + keyboard --}}
        <div class="flex flex-col gap-5">
            <section aria-labelledby="pv-h" class="flex flex-col gap-3.5 rounded-lg bg-card px-6 py-5">
                <span class="flex items-baseline justify-between"><h2 id="pv-h" class="m-0 text-[15px] font-bold">{{ __('Preview') }}</h2><span class="text-xs text-ink-2">{{ $theme[0] }}</span></span>
                <div class="pt-4 pr-4" wire:key="preview-{{ $settings->board }}-{{ $settings->coordinates ? 1 : 0 }}" x-data="{ cells: window.chessBoardCells?.('r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R w KQkq - 2 3', { last: ['b8', 'c6'], theme: @js($settings->board), noCoords: @js(! $settings->coordinates), coords: true }) ?? [], boardLabel: @js(__('Preview of the :name board', ['name' => $theme[0]])) }">
                    <x-chess.board class="max-w-[304px]" />
                </div>
                <span class="text-[13px] leading-normal text-ink-2">{{ __('Last move in orange, also marked in the move list.') }}</span>
                <span class="border-t border-hairline pt-3 text-xs leading-normal text-ink-3">{{ __('Saved to your account, on every device you log in with.') }}</span>
            </section>

            <section aria-labelledby="kb-h" class="flex flex-col gap-2 rounded-lg bg-card px-6 py-5">
                <h2 id="kb-h" class="m-0 text-[15px] font-bold">{{ __('Keyboard') }}</h2>
                @foreach ([['Enter', __('play the typed move, e.g. Rh4')], ['Esc', __('clear selection or the promotion picker')], ['F', __('flip board')], ['← →', __('step through moves')], [__('Home End'), __('first move, back to the current position')],['Q R B N', __('piece to promote to')]] as [$key, $text])
                    <div class="grid min-h-10 grid-cols-[100px_minmax(0,1fr)] items-center gap-3 border-b border-hairline text-[13px]"><kbd class="justify-self-start rounded-sm border border-edge px-2 py-0.5 font-mono text-xs">{{ $key }}</kbd><span class="text-ink-2">{{ $text }}</span></div>
                @endforeach
            </section>
        </div>

        {{-- Board --}}
        <section aria-labelledby="bd-h" class="flex flex-col gap-4 self-start rounded-lg bg-card px-6 py-5">
            <h2 id="bd-h" class="m-0 text-[15px] font-bold">{{ __('Board') }}</h2>
            <span class="text-[13px]" id="colors-h">{{ __('Colors') }}</span>
            <div role="radiogroup" aria-labelledby="colors-h" class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                @foreach ($themes as $key => [$name, $note, $memberOnly, $light, $dark])
                    @php($locked = $memberOnly && ! $user->is_member)
                    <button type="button" role="radio" aria-checked="{{ $settings->board === $key ? 'true' : 'false' }}" wire:click="setBoard('{{ $key }}')" @disabled($locked) data-test="board-{{ $key }}"
                            @class(['flex min-h-[190px] cursor-pointer flex-col gap-2 rounded-lg bg-ground p-2.5 text-left text-ink disabled:cursor-not-allowed disabled:opacity-60',
                                'shadow-[inset_0_0_0_2px_#F7931A]' => $settings->board === $key, 'shadow-ring' => $settings->board !== $key])>
                        <span aria-hidden="true" class="grid aspect-square w-full grid-cols-4 grid-rows-4 overflow-hidden rounded-xs">
                            @for ($i = 0; $i < 16; $i++)
                                <span style="background: {{ (intdiv($i, 4) + $i) % 2 === 0 ? $light : $dark }}"></span>
                            @endfor
                        </span>
                        <span class="flex items-start justify-between gap-1 text-[13px] font-bold">{{ $name }}@if ($settings->board === $key)<x-icon name="check" :size="16" class="shrink-0 text-btc" />@endif</span>
                        @if ($memberOnly)<x-member-badge class="self-start" />@endif
                        <span class="text-[11px] leading-snug text-ink-2">{{ $note }}</span>
                    </button>
                @endforeach
            </div>

            <div class="flex flex-col gap-2.5 border-t border-hairline pt-4">
                <span class="flex flex-wrap items-baseline justify-between gap-2"><span class="text-[13px]">{{ __('Pieces') }}</span><span class="flex items-center gap-2 text-xs text-ink-2">{{ __('Classic · more sets for members later') }} <x-member-badge /></span></span>
                <div aria-hidden="true" class="grid grid-cols-6 overflow-hidden rounded-md">
                    @foreach (['♔', '♕', '♖', '♗', '♘', '♙', '♚', '♛', '♜', '♝', '♞', '♟'] as $i => $glyph)
                        <span class="flex aspect-square items-center justify-center text-[34px] leading-none text-ground" style="background: {{ ($i + intdiv($i, 6)) % 2 === 0 ? $theme[3] : $theme[4] }}">{{ $glyph }}&#xFE0E;</span>
                    @endforeach
                </div>
                <span class="text-xs leading-normal text-ink-2">{{ __('White pieces always have a dark outline so they stay readable on light squares.') }}</span>
            </div>

            @include('pages.settings.partials.switch', ['label' => __('Coordinates'), 'hint' => __('a–h and 1–8 along the edge'), 'on' => $settings->coordinates, 'action' => "toggle('coordinates')", 'test' => 'coordinates'])
            @include('pages.settings.partials.switch', ['label' => __('Piece names'), 'hint' => __('the name of a piece when you point at it, a help for new players'), 'on' => $settings->pieceNames, 'action' => "toggle('pieceNames')", 'test' => 'piece-names'])
        </section>

        {{-- During the game, daily chess --}}
        <div class="flex flex-col gap-5">
            <section aria-labelledby="dg-h" class="flex flex-col rounded-lg bg-card px-6 py-5">
                <h2 id="dg-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('During the game') }}</h2>
                @include('pages.settings.partials.switch', ['label' => __('Premoves'), 'hint' => __('queue a move while your opponent thinks · coming later'), 'on' => false, 'action' => null, 'test' => 'premoves'])
                @include('pages.settings.partials.switch', ['label' => __('Always queen'), 'hint' => __('promote without asking'), 'on' => $settings->alwaysQueen, 'action' => "toggle('alwaysQueen')", 'test' => 'always-queen'])
                @include('pages.settings.partials.switch', ['label' => __('Elo during the game'), 'hint' => __('casual Elo only before Block 0: every game is casual'), 'on' => false, 'action' => null, 'test' => 'elo'])
                @include('pages.settings.partials.switch', ['label' => __('Sounds'), 'hint' => __('moves, check, 10 s left, game end, notifications'), 'on' => $settings->sound, 'action' => "toggle('sound')", 'test' => 'sound'])

                {{-- Volume, with a button to hear the set (resources/js/sounds.js). --}}
                <div class="flex min-h-[61px] flex-wrap items-center gap-x-4 gap-y-2 py-2" x-data="{ volume: @js($settings->volume) }"
                     x-on:sound-settings.window="window.esportsSounds?.configure($event.detail)" data-test="sound-volume">
                    <span class="flex min-w-0 grow flex-col gap-0.5"><label for="sound-volume" class="text-sm">{{ __('Volume') }}</label><span class="text-xs text-ink-2" x-text="volume + ' %'">{{ $settings->volume }} %</span></span>
                    <input id="sound-volume" type="range" min="0" max="100" step="5" x-model.number="volume" @disabled(! $settings->sound)
                           x-on:input="window.esportsSounds?.configure({ volume })" x-on:change="$wire.setVolume(volume)"
                           class="h-11 w-36 cursor-pointer accent-btc disabled:cursor-not-allowed disabled:opacity-50" data-test="volume-input">
                    <button type="button" @disabled(! $settings->sound) data-test="sound-test" x-on:click="window.esportsSounds?.sample()"
                            class="btn-w inline-flex h-11 cursor-pointer items-center rounded-md border border-line bg-well px-3 text-[13px] text-ink disabled:cursor-not-allowed disabled:opacity-50">{{ __('Play a sound') }}</button>
                </div>
            </section>

            <section aria-labelledby="dc-h" class="flex flex-col rounded-lg bg-card px-6 py-5">
                <h2 id="dc-h" class="m-0 mb-1 text-[15px] font-bold">{{ __('Daily chess') }}</h2>
                @include('pages.settings.partials.switch', ['label' => __('Double-check daily moves'), 'hint' => __('one extra tap before a move is final'), 'on' => $settings->doubleCheck, 'action' => "toggle('doubleCheck')", 'test' => 'double-check'])
                <a href="{{ route('settings.notifications') }}" class="inline-flex min-h-11 items-center self-start text-[13px] text-ink-2 underline decoration-edge underline-offset-4 hover:text-ink" data-test="chess-to-notifications">{{ __('Reminders before your move is due: Notifications') }}</a>

            </section>
        </div>
    </div>
</div>
