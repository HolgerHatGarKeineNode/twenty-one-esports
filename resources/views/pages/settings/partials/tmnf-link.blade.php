{{--
    Linking the saved TMNF login (plan "Trackmania und Restposten", P1;
    App\Support\Tmnf\TmnfLinks): linked, or three steps with the one-time code
    the player asks for. Only on the player's own settings page.
--}}
@php
    $serverName = (string) config('esports.tmnf.server.name');
    $serverAddress = config('esports.tmnf.server.address');
@endphp

@if ($linked)
    <span class="flex items-center gap-1.5 text-xs text-win" data-test="tmnf-linked"><x-icon name="shield-check" :size="14" class="shrink-0" />{{ __('Linked to our TMNF server: your finishes there count for you.') }}</span>
@else
    <div class="mt-1 flex flex-col gap-3 rounded-md border border-line bg-well px-3 py-3" data-test="tmnf-link">
        <span class="text-[13px] font-bold text-ink">{{ __('Link your login') }}</span>
        <ol class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-3">
            <li class="flex items-start gap-2 text-[13px] leading-normal text-ink-2">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-card font-mono text-xs font-bold text-ink">1</span>
                <span>{{ __('Join :server in TMNF', ['server' => $serverName]) }} <a href="{{ route('scores.show', 'tmnf') }}#join" class="font-bold text-ink underline underline-offset-2" data-test="tmnf-link-howto">{{ __('How to join') }}</a></span>
            </li>
            <li class="flex items-start gap-2 text-[13px] leading-normal text-ink-2">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-card font-mono text-xs font-bold text-ink">2</span>
                {{-- Measured by the league's first player (2026-10-02): Space opens the chat; T did not. T is the documented default in some setups. --}}
                <span data-test="tmnf-chat-keys">{!! __('On the server press :space to open the chat, type the line below and press :enter. Nothing opens? Try :t.', ['space' => '<kbd class="rounded-sm bg-card px-1.5 font-mono text-ink">'.e(__('Space')).'</kbd>', 'enter' => '<kbd class="rounded-sm bg-card px-1.5 font-mono text-ink">Enter</kbd>', 't' => '<kbd class="rounded-sm bg-card px-1.5 font-mono text-ink">T</kbd>']) !!}</span>
            </li>
            <li class="flex items-start gap-2 text-[13px] leading-normal text-ink-2">
                <span class="grid size-6 shrink-0 place-items-center rounded-full bg-card font-mono text-xs font-bold text-ink">3</span>
                <span>{{ __('The server confirms it, and this page shows your login as linked') }}</span>
            </li>
        </ol>
        @if ($code !== null)
            <div class="flex flex-wrap items-center gap-3">
                <code class="rounded-md bg-ground px-3 py-2 font-mono text-base font-bold tracking-widest text-ink" data-test="tmnf-link-code">link {{ $code }}</code>
                <span class="text-xs text-ink-2">{{ __('Valid for :minutes minutes, for your saved login only.', ['minutes' => (int) config('esports.tmnf.link.code_minutes')]) }}</span>
            </div>
        @else
            <x-button variant="quiet" icon="key" wire:click="showTmnfCode" class="self-start" data-test="tmnf-link-show">{{ __('Show my link code') }}</x-button>
        @endif
    </div>
@endif
