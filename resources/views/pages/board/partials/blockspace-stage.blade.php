{{-- Blockspace's board: the fees, the block with the mempool ladder beside it, and what the player can do. resources/js/blockspace/board.js fills it; every size counts in --u, the side of a cell. --}}
<div class="bs-stage" style="--u: 24px" data-piece-stage>
    <div class="bs-head" data-piece-head></div>
    <div class="bs-row">
        <div class="bs-box" data-board-box>
            <svg x-ref="board" data-focus-board data-live-clear role="group" aria-label="{{ __('Game board') }}" data-test="board"></svg>
        </div>
        <div class="bs-ladder" data-mempool data-test="mempool"></div>
    </div>
    <div class="bs-dock" data-piece-dock></div>
    <p class="bs-help" data-piece-help></p>
</div>
