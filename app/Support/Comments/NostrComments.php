<?php

namespace App\Support\Comments;

use App\Jobs\PublishNostrEvent;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Nostr\SignedEventGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Comments (NIP-22, kind 1111) and likes (NIP-25, kind 7) of a player on a
 * tournament, a rated game or a rated series (P48, NIP "Comments, likes and
 * RSVPs", rev. 9.9).
 *
 * The league builds the unsigned template, the browser shows it as the
 * preview and signs it only on the player's click, sends it to the player's
 * write relays and hands it in here; the league checks it against the same
 * template ({@see SignedEventGate}, validation rules 38 and 39), keeps it and
 * sends it to its relays. The league reads nothing back: the page reads the
 * comments from the relays.
 */
final class NostrComments
{
    public const COMMENT = 1111;

    public const REACTION = 7;

    /** NIP-25: `+` is a like. The app writes nothing else. */
    public const LIKE = '+';

    public function __construct(private SignedEventGate $gate) {}

    /**
     * The comment as the player wrote it, cleaned the same way for the preview
     * and the check: line breaks as `\n`, control characters dropped,
     * surrounding white space trimmed.
     */
    public static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $text);

        return trim($text);
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws CommentRefused
     */
    public function commentTemplate(User $user, string $type, string $id, string $text): array
    {
        $target = $this->target($user, $type, $id);
        $content = self::clean($text);
        $max = max(1, (int) config('esports.comments.max_length', 1000));

        if ($content === '') {
            throw new CommentRefused(__('Write something first.'));
        }

        if (mb_strlen($content) > $max) {
            throw new CommentRefused(__('Keep it to :max characters.', ['max' => $max]));
        }

        return [
            'kind' => self::COMMENT,
            'tags' => [...$target->commentTags(), ['alt', self::alt('Comment on', $target)]],
            'content' => $content,
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * @return array{kind: int, tags: list<list<string>>, content: string, created_at: int}
     *
     * @throws CommentRefused
     */
    public function reactionTemplate(User $user, string $type, string $id): array
    {
        $target = $this->target($user, $type, $id);

        return [
            'kind' => self::REACTION,
            'tags' => [...$target->reactionTags(), ['alt', self::alt('Like of', $target)]],
            'content' => self::LIKE,
            'created_at' => now()->getTimestamp(),
        ];
    }

    /**
     * @throws CommentRefused|RejectedEvent
     */
    public function submitComment(User $user, string $type, string $id, string $text, mixed $signed): NostrEvent
    {
        $this->count($user, 'comments');

        return $this->keep($this->gate->check($signed, $this->commentTemplate($user, $type, $id, $text), $user));
    }

    /**
     * @throws CommentRefused|RejectedEvent
     */
    public function submitReaction(User $user, string $type, string $id, mixed $signed): NostrEvent
    {
        $this->count($user, 'reactions');

        return $this->keep($this->gate->check($signed, $this->reactionTemplate($user, $type, $id), $user));
    }

    /**
     * Counted before anything else, like share posts: a refused or rejected
     * attempt counts too, and hit() is atomic in the cache store.
     *
     * @throws CommentRefused
     */
    public static function count(User $user, string $what): void
    {
        $limit = max(1, (int) config('esports.comments.per_hour.'.$what, 10));

        if (RateLimiter::hit('nostr-'.$what.':'.$user->id, 3600) > $limit) {
            throw new CommentRefused(__('That was a lot this hour. Try again later.'));
        }
    }

    public static function keep(SignedEvent $event): NostrEvent
    {
        return DB::transaction(function () use ($event): NostrEvent {
            $stored = NostrEvent::fromSigned($event);
            PublishNostrEvent::dispatch($stored);

            return $stored;
        });
    }

    /**
     * @throws CommentRefused
     */
    private function target(User $user, string $type, string $id): CommentTarget
    {
        return CommentTarget::resolve($type, $id) ?? throw new CommentRefused(__('There is nothing to comment on here.'));
    }

    /** NIP-31: what the event is, for clients that do not know the kind. English, like every `alt` of the league. */
    private static function alt(string $what, CommentTarget $target): string
    {
        return $what.' '.match ($target->type) {
            'tournament' => 'a tournament',
            'game' => 'a chess game',
            default => 'a series',
        }.' in TWENTY ONE Esports';
    }
}
