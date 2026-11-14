/**
 * A board game's record on the page (resources/js/boardGame.js follow()), apart from the board so that Node can test
 * it (tests/js/boardRecord.test.mjs, run by tests/Unit/Board/BoardRecordScriptsTest.php).
 *
 * A state that came without the record (a push, the answer to an own action, a state the server kept short) is joined
 * to the record the page holds: the move of the next change added, the same move kept, a move taken back cut off.
 * Null where it does not follow on from what the page has: then the whole record is asked for (fetchState(), a few
 * times a minute).
 *
 *   update   the state that came, unpacked (boardGame.js unpackStones())
 *   held     the state the page holds: its `version`, `moves` and `pieces`
 *   stones   whether the board is one of stones (Go): a push names nothing taken there
 */
export function followRecord(update, held, stones = false) {
    if (update.moves) return update;
    const moves = held.moves;
    const same = (ply) => ply === 0 || moves[ply - 1]?.move === update.lastMove?.move;
    // The next move only as the very next change (every change adds one to the version): one ply more after changes the
    // page missed may stand on a move taken back and replaced (a1 b2 on the page; b2 taken back, c2, a3: the list read
    // a1 b2 a3, security N4).
    if (update.ply === moves.length + 1 && update.lastMove && update.version === held.version + 1) {
        // A push of a board with its own input carries the pieces once (BoardGameService::snapshot()).
        // A push of stones names nothing taken (it carries the stones as they stand): what lay there before and is gone was taken.
        const taken = stones && !update.lastMove.taken?.length && update.pieces ? Object.keys(held.pieces).filter((id) => !update.pieces[id]) : update.lastMove.taken;

        return { ...update, moves: [...moves, { ...update.lastMove, ...(taken ? { taken } : {}), pieces: update.lastMove.pieces ?? update.pieces }] };
    }
    if (update.ply === moves.length && same(update.ply)) return { ...update, moves };
    if (update.ply < moves.length && same(update.ply)) return { ...update, moves: moves.slice(0, update.ply) };

    return null;
}
