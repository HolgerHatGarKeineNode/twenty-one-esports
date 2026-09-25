@props(['item', 'nowMs', 'viewer'])

{{--
    The panel above a dock tab (MatchDock.dc.html sections 2 and 5): what it
    is, who, the one number that matters and one main action. The move itself
    happens on the board, and answers and results on their page: every action
    here is a link, so the dock never signs anything.
--}}
@php
    use App\Models\ChessGame;
    use App\Models\SeriesMatch;
    use App\Support\Series\SeriesPresenter;

    $model = $item->model;
    $urgent = $item->isUrgent($nowMs);
    $share = $item->fuseShare($nowMs);
    $isGame = $model instanceof ChessGame;
    $isSeries = $model instanceof SeriesMatch;
    $cta = match (true) {
        $isGame => $item->needsYou ? __('Play your move') : __('Open game'),
        $isSeries => match ($item->phase) {
            'accept' => __('Review the result'),
            'answer' => __('Answer the challenge'),
            default => __('Open match room'),
        },
        default => __('Answer'),
    };
@endphp
<div id="dock-panel-{{ $item->key }}" wire:key="panel-{{ $item->key }}" role="region" aria-label="{{ $item->sentence }}"
     x-show="open === @js('item:'.$item->key)" x-cloak
     class="dk-panel dk-rise" data-panel="{{ $item->key }}" data-test="dock-panel">
    <div class="flex h-[52px] items-center gap-2 border-b border-hairline pr-1 pl-4">
        @if ($item->isChess())<x-icon name="chess" :size="16" class="text-ink-2" />@else<span class="dk-slot text-ink-2">RL</span>@endif
        <span class="text-[13px] font-bold whitespace-nowrap">{{ $item->title }}</span>
        @if ($item->number !== '')
            <a href="{{ $item->href }}" class="inline-flex min-h-11 min-w-11 items-center justify-center text-[13px]">{{ $item->number }}</a>
        @endif
        @if ($isSeries)<span class="truncate text-xs text-ink-3">{{ SeriesPresenter::format($model) }}</span>@endif
        <span class="grow"></span>
        <button type="button" class="dk-x" x-on:click="close(true)" aria-label="{{ __('Close panel') }}"><x-icon name="close" :size="16" /></button>
    </div>

    <div class="flex flex-col gap-4 p-4">
        @if ($isGame)
            @php
                $color = $model->colorOf($viewer);
                $last = $model->moves()->reorder('ply', 'desc')->first();
                $lastLabel = $last ? intdiv($last->ply + 1, 2).($last->ply % 2 === 1 ? '. ' : '… ').$last->san : __('none yet');
            @endphp
            <div class="flex items-center gap-3">
                @if ($item->face)<x-avatar :user="$item->face" :size="40" class="rounded-lg" />@endif
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="flex min-w-0 items-center gap-2 text-sm font-bold"><span class="truncate">{{ $item->name }}</span>
                        @if ($item->face?->clanMember?->clan?->clantag)<x-clan-tag :tag="$item->face->clanMember->clan->clantag" size="sm" />@endif
                    </span>
                    <span class="text-xs text-ink-2">{{ $item->kind === 'daily' ? __('Daily chess, casual') : __('Blitz :control, casual', ['control' => intdiv($model->initial_ms, 60_000).'+'.intdiv($model->increment_ms, 1000)]) }}</span>
                </span>
            </div>
            <div class="flex items-start gap-4">
                <div class="shrink-0 pt-3 pr-3">
                    <x-match-dock.board class="w-[144px]" :fen="$model->fen" :flip="$color === 'b'" :last="$last ? [substr($last->uci, 0, 2), substr($last->uci, 2, 2)] : []"
                                        :label="__('Board of :number', ['number' => $model->number()])" />
                </div>
                <div class="flex min-w-0 grow flex-col gap-3 pt-2">
                    <span class="dk-kv"><span class="text-ink-3">{{ $color === 'b' ? __('You play Black') : __('You play White') }}</span><span>{{ __('move :n', ['n' => intdiv($model->ply, 2) + 1]) }}</span></span>
                    <span class="dk-kv"><span class="text-ink-3">{{ __('Last move') }}</span><b class="text-sm">{{ $lastLabel }}</b></span>
                    @if ($item->tick)
                        <span class="dk-kv"><span class="text-ink-3">{{ $item->kind === 'daily' ? __('Your deadline') : __('Your clock') }}</span>
                            <b @class(['text-[13px]', 'text-loss' => $urgent, 'text-ink' => ! $urgent]) data-tick='@json($item->tick)' data-suffix="{{ $item->kind === 'daily' ? __(':left left') : ':left' }}">{{ $item->kind === 'daily' ? __(':left left', ['left' => $item->trailing]) : $item->trailing }}</b>
                            <span aria-hidden="true" class="mt-1 block h-1 rounded-xs bg-line"><span @class(['block h-1 rounded-xs', 'bg-loss' => $urgent, 'bg-btc' => ! $urgent]) style="width: {{ round(($share ?? 0) * 100, 2) }}%" data-fuse='@json($item->tick)'></span></span>
                        </span>
                    @else
                        <span class="dk-kv"><span class="text-ink-3">{{ __('Their move') }}</span><span class="text-ink-2">{{ __(':name to move', ['name' => $item->name]) }}</span></span>
                    @endif
                </div>
            </div>
        @elseif ($isSeries)
            @php
                $side = $model->participantSideOf($viewer) ?? 'challenger';
                $other = SeriesMatch::otherSide($side);
                $games = $model->currentGames();
            @endphp
            <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3">
                @foreach ([$side, 'score', $other] as $column)
                    @if ($column === 'score')
                        <span class="flex flex-col items-center gap-1">
                            <span class="font-display text-[32px] leading-9 font-extrabold whitespace-nowrap">{{ in_array($item->phase, ['starts', 'answer'], true) ? '– : –' : $item->trailing }}</span>
                            <span class="flex items-center gap-1.5 text-xs text-btc">@if ($item->isLive())<span class="dk-live size-2 rounded-full bg-btc" aria-hidden="true"></span>@endif{{ $item->state }}</span>
                        </span>
                    @else
                        <span class="flex min-w-0 flex-col items-center gap-1.5 text-center text-xs">
                            <x-clan-tag :tag="$model->sideTag($column)" />
                            <span class="w-full truncate">{{ $model->sideName($column) }}</span>
                        </span>
                    @endif
                @endforeach
            </div>
            @if ($games !== [])
                <div class="flex flex-col text-xs">
                    @foreach ($games as $index => $game)
                        <span class="grid min-h-7 grid-cols-[64px_56px_minmax(0,1fr)] items-center gap-2 border-t border-hairline">
                            <span class="text-ink-3">{{ __('Game :n', ['n' => $index + 1]) }}</span>
                            <b>{{ ($game[$side] ?? '–').' : '.($game[$other] ?? '–') }}</b>
                            <span class="truncate text-ink-2">{{ ($game['winner'] ?? null) ? $model->sideName($game['winner']) : '' }}</span>
                        </span>
                    @endforeach
                </div>
            @endif
            <span class="text-xs text-ink-2">{{ $item->line }}</span>
        @else
            <div class="flex items-center gap-3">
                <x-match-dock.face :item="$item" :size="40" :badge="false" />
                <span class="flex min-w-0 flex-col gap-1">
                    <b class="text-sm">{{ $item->sentence }}</b>
                    @if ($item->tick)
                        <span @class(['text-xs', 'text-loss' => $urgent, 'text-btc' => ! $urgent]) data-tick='@json($item->tick)' data-suffix="{{ __(':left left') }}">{{ __(':left left', ['left' => $item->trailing]) }}</span>
                    @endif
                </span>
            </div>
        @endif

        <a href="{{ $item->href }}" @class(['w-full', 'dk-cta' => $item->needsYou, 'dk-sec' => ! $item->needsYou]) data-test="dock-panel-cta">{{ $cta }}</a>
    </div>
</div>
