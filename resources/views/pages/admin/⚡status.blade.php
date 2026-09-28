<?php

use App\Support\Admin\AdminStatus;
use App\Support\Navigation\AdminNavigation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Admin status (P17, AdminStatus.dc.html): the admin home. One verdict, one
 * block per check, then the checks in the four groups of the admin nav,
 * each ending in the page where you act on it. Read-only: the page reads
 * the database, the cache, the queue and the log, and never writes, sends
 * or contacts a relay (App\Support\Admin\AdminStatus).
 */
new #[Title('Status')] #[Layout('layouts::app', ['section' => 'admin'])] class extends Component {
    public function mount(): void
    {
        Gate::authorize('admin');
    }

    /**
     * @return list<array{key: string, checks: list<array<string, mixed>>}>
     */
    #[Computed]
    public function groups(): array
    {
        return app(AdminStatus::class)->groups();
    }
}; ?>

@php
    $checks = collect($this->groups)->flatMap(fn (array $group): array => $group['checks']);
    $down = $checks->where('state', 'down')->count();
    $attention = $checks->where('state', 'attention')->count();
    $verdict = match (true) {
        $down > 0 && $attention > 0 => __(':down down, :attention need you.', ['down' => $down, 'attention' => $attention]),
        $down > 0 => trans_choice(':count check is down.|:count checks are down.', $down),
        $attention > 0 => trans_choice(':count check needs you.|:count checks need you.', $attention),
        default => __('Everything runs.'),
    };
    $label = ['ok' => __('OK'), 'attention' => __('Needs you'), 'down' => __('Down')];
    $icon = ['ok' => 'check', 'attention' => 'warn', 'down' => 'alert'];
    $chip = [
        'ok' => 'bg-win-tint text-win',
        'attention' => 'bg-btc-chip text-btc-hi',
        'down' => 'bg-loss-tint text-loss',
    ];
    $block = [
        'ok' => 'border-win-ring bg-win-tint text-win',
        'attention' => 'border-btc-ring bg-btc-chip text-btc-hi',
        'down' => 'border-[#5A2A2E] bg-loss-tint text-loss',
    ];
    $bar = ['ok' => '', 'attention' => 'shadow-[inset_3px_0_0_#F9B25F]', 'down' => 'shadow-[inset_3px_0_0_#F87171]'];
    $readAt = CarbonImmutable::now()->setTimezone((string) config('esports.preseason.display_timezone'))->format('H:i:s');
@endphp

<x-admin.page active="status" :title="__('Status')" :lead="__('What the league, the tournaments and the servers are doing. Read-only: nothing on this page writes, sends or contacts a relay.')" data-test="admin-status">
    {{-- The verdict, and one block per check: the strip is the page in one line, each block jumps to its row. --}}
    <section aria-labelledby="verdict-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:flex-row lg:items-center lg:justify-between lg:gap-8 lg:px-6" data-test="status-verdict" data-down="{{ $down }}" data-attention="{{ $attention }}">
        <div class="flex min-w-0 flex-col gap-1">
            <h2 id="verdict-h" class="m-0 font-display text-[22px] leading-[1.25] font-bold">{{ $verdict }}</h2>
            <p class="m-0 text-xs leading-normal text-ink-3">{{ __('Read at :time (Berlin). Relay answers and log errors are counted every 5 minutes.', ['time' => $readAt]) }}</p>
        </div>
        <ol class="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="{{ __('All checks') }}">
            @foreach ($checks as $check)
                <li>
                    <a href="#check-{{ $check['key'] }}" title="{{ $check['name'] }}: {{ $label[$check['state']] }}"
                       class="inline-flex size-7 items-center justify-center rounded-[3px] border {{ $block[$check['state']] }}" data-test="status-block" data-state="{{ $check['state'] }}">
                        <x-icon :name="$icon[$check['state']]" :size="14" />
                        <span class="sr-only">{{ $check['name'] }}: {{ $label[$check['state']] }}</span>
                    </a>
                </li>
            @endforeach
        </ol>
    </section>

    @foreach ($this->groups as $group)
        <x-admin.panel :title="AdminNavigation::groupLabel($group['key'])" :id="'group-'.$group['key']" flush data-test="status-group-{{ $group['key'] }}">
            <div class="hidden h-9 grid-cols-[120px_minmax(0,1.1fr)_minmax(0,0.9fr)_minmax(0,2fr)_128px] items-center gap-4 border-b border-hairline text-xs text-ink-3 lg:grid" aria-hidden="true">
                <span>{{ __('Status') }}</span><span>{{ __('Check') }}</span><span>{{ __('Now') }}</span><span>{{ __('Detail') }}</span><span></span>
            </div>
            <ul class="m-0 flex list-none flex-col p-0">
                @foreach ($group['checks'] as $check)
                    <li id="check-{{ $check['key'] }}" wire:key="check-{{ $check['key'] }}" data-test="status-check-{{ $check['key'] }}" data-state="{{ $check['state'] }}"
                        class="-mx-4 grid scroll-mt-24 grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-2 border-t border-hairline px-4 py-4 first:border-t-0 lg:-mx-6 lg:grid-cols-[120px_minmax(0,1.1fr)_minmax(0,0.9fr)_minmax(0,2fr)_128px] lg:items-center lg:gap-4 lg:px-6 {{ $bar[$check['state']] }}">
                        <span class="inline-flex h-7 w-fit items-center gap-1.5 rounded-sm px-2 text-xs font-bold whitespace-nowrap {{ $chip[$check['state']] }}">
                            <x-icon :name="$icon[$check['state']]" :size="14" />{{ $label[$check['state']] }}
                        </span>
                        <span class="flex min-w-0 flex-col">
                            <b class="text-[13px]">{{ $check['name'] }}</b>
                            <span class="text-xs text-ink-3">{{ $check['sub'] }}</span>
                        </span>
                        <span class="col-span-2 text-[13px] font-bold lg:col-span-1" data-test="status-value">{{ $check['value'] }}</span>
                        <div class="col-span-2 flex min-w-0 flex-col gap-2 lg:col-span-1">
                            <p class="m-0 max-w-[75ch] text-[13px] leading-normal text-ink-2 [overflow-wrap:anywhere]">{{ $check['detail'] }}</p>
                            @if ($check['items'] !== [])
                                <dl class="m-0 grid grid-cols-1 gap-x-6 gap-y-1 text-xs sm:grid-cols-2" data-test="status-items">
                                    @foreach ($check['items'] as $item)
                                        <div class="flex min-w-0 items-baseline gap-2">
                                            <dt class="shrink-0 text-ink-3">{{ $item['label'] }}</dt>
                                            <dd @class(['m-0 flex min-w-0 items-center gap-1', 'text-ink-2' => $item['ok'], 'font-bold text-btc-hi' => ! $item['ok']])>
                                                <x-icon :name="$item['ok'] ? 'check' : 'warn'" :size="12" class="shrink-0 self-center" />{{ $item['text'] }}
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </div>
                        <span class="col-span-2 lg:col-span-1 lg:justify-self-end">
                            @if ($check['href'])
                                <a href="{{ $check['href'] }}" class="inline-flex min-h-11 items-center text-[13px] text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="status-link">{{ $check['action'] }}</a>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-admin.panel>
    @endforeach
</x-admin.page>
