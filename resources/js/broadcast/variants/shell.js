/**
 * The shell (plan P2) for the variants that have no scene of their own yet (break, bracket: P5, P6): the preset's
 * name as the first lower third, the ticker crawling the snapshot, the league's feed as lower thirds.
 */

import { describeFeed, tickerItems } from './shared.js';

export function runShell(ctx) {
    const { b, director, texts } = ctx;
    const lower = director.lowerThirds();
    lower.push({ name: ctx.snapshot().preset.name, line: texts.onAir });
    if (ctx.snapshot().ticker.length > 0) {
        b.ticker({ start: b.stage.now() + 600, label: texts.live, items: tickerItems(ctx, []), source: () => tickerItems(ctx, []) });
    }
    ctx.onFeed((item) => {
        if (!ctx.snapshot().preset.modules.pride) return;
        const text = describeFeed(ctx, item);
        if (text) lower.push({ name: text.name, line: text.line, emblem: ctx.art(text.emblem) === 'mark' ? null : ctx.art(text.emblem) });
    });

    return () => ({});
}
