<?php

namespace App\Support\Hyper;

use App\Events\HyperEmoteSent;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Quick emotes at a Hyperbitcoinization table (plan "Hyperbitcoinization", Ansatz 6): a meme sticker or a
 * soundboard clip, over Reverb, never stored and never on Nostr. Only a seated player who has not left
 * sends one, also after the end (a "GG"). Throttled per player and match: `esports.hyper.stickers_per_minute`
 * stickers a minute and `esports.hyper.clips_per_turn` clips per turn.
 *
 * The clips are the soundboard's files the game page ships (`public/hyper/s/*.mp3`), named by basename.
 */
final class HyperEmotes
{
    /** @var list<string> */
    public const STICKERS = ['gg', 'brrr', 'hodl', 'rekt', 'wagmi', 'ngmi'];

    public const STICKER = 'sticker';

    public const CLIP = 'clip';

    /**
     * The soundboard clip ids, from the files the page plays.
     *
     * @return list<string>
     */
    public static function clips(): array
    {
        return array_map(fn (string $file): string => basename($file, '.mp3'), glob(public_path('hyper/s/*.mp3')) ?: []);
    }

    /**
     * Sends an emote to the table.
     *
     * @return array{seat: int, kind: string, emote: string}
     *
     * @throws HyperRuleViolation `not_seated`, `unknown_emote`, or `emote_throttled` (the seconds to wait in the message)
     */
    public function send(HyperMatch $match, User $user, string $emote): array
    {
        $match->loadMissing('seats');
        $seat = $match->seatOf($user);

        if ($seat === null || $seat->left_at !== null) {
            throw new HyperRuleViolation('not_seated', 'Only a seated player sends emotes.');
        }

        $kind = match (true) {
            in_array($emote, self::STICKERS, true) => self::STICKER,
            in_array($emote, self::clips(), true) => self::CLIP,
            default => throw new HyperRuleViolation('unknown_emote', 'No such sticker or clip.'),
        };

        [$key, $max, $decay] = $kind === self::STICKER
            ? ["hyper-sticker:{$match->id}:{$user->id}", (int) config('esports.hyper.stickers_per_minute', 3), 60]
            // Per turn: the turn's start time names it (a turn starts once); after the end the last turn's.
            : ["hyper-clip:{$match->id}:{$user->id}:{$match->turn_started_ms}", (int) config('esports.hyper.clips_per_turn', 1), 3600];

        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new HyperRuleViolation('emote_throttled', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $decay);
        Broadcasts::send(new HyperEmoteSent($match->ulid, $seat->seat, $kind, $emote));

        return ['seat' => $seat->seat, 'kind' => $kind, 'emote' => $emote];
    }
}
