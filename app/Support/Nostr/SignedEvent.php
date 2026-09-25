<?php

namespace App\Support\Nostr;

use swentel\nostr\Event\Event;
use Throwable;

/**
 * A NIP-01 event received from a client, with its shape checked here.
 *
 * The shape rules are ours and do not lean on the library: lowercase hex for
 * id, pubkey and sig (an uppercase pubkey would otherwise verify and create a
 * second identity for the same key), integer created_at and kind, tags as
 * lists of strings. The signature is a separate, deliberately last step
 * ({@see hasValidSignature()}), so cheap rejections never pay for Schnorr.
 */
final readonly class SignedEvent
{
    /**
     * @param  list<list<string>>  $tags
     */
    private function __construct(
        public string $id,
        public string $pubkey,
        public int $createdAt,
        public int $kind,
        public array $tags,
        public string $content,
        public string $sig,
    ) {}

    public static function fromInput(mixed $input): ?self
    {
        if (! is_array($input)) {
            return null;
        }

        $id = $input['id'] ?? null;
        $pubkey = $input['pubkey'] ?? null;
        $createdAt = $input['created_at'] ?? null;
        $kind = $input['kind'] ?? null;
        $tags = $input['tags'] ?? null;
        $content = $input['content'] ?? null;
        $sig = $input['sig'] ?? null;

        if (! is_string($id) || preg_match('/^[0-9a-f]{64}$/', $id) !== 1
            || ! NostrKeys::isHexPubkey($pubkey)
            || ! is_string($sig) || preg_match('/^[0-9a-f]{128}$/', $sig) !== 1
            || ! is_int($createdAt) || $createdAt < 0
            || ! is_int($kind) || $kind < 0 || $kind > 65535
            || ! is_string($content)
            || ! is_array($tags) || ! array_is_list($tags)
        ) {
            return null;
        }

        foreach ($tags as $tag) {
            if (! is_array($tag) || ! array_is_list($tag)) {
                return null;
            }

            foreach ($tag as $value) {
                if (! is_string($value)) {
                    return null;
                }
            }
        }

        /** @var list<list<string>> $tags */
        return new self($id, $pubkey, $createdAt, $kind, $tags, $content, $sig);
    }

    /**
     * Value of the first tag with this name, or null.
     */
    public function tag(string $name): ?string
    {
        foreach ($this->tags as $tag) {
            if (($tag[0] ?? null) === $name) {
                return $tag[1] ?? null;
            }
        }

        return null;
    }

    /**
     * All tags with this name, each without the name.
     *
     * @return list<list<string>>
     */
    public function tagsNamed(string $name): array
    {
        $found = [];

        foreach ($this->tags as $tag) {
            if (($tag[0] ?? null) === $name) {
                $found[] = array_slice($tag, 1);
            }
        }

        return $found;
    }

    /**
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: list<list<string>>, content: string, sig: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pubkey' => $this->pubkey,
            'created_at' => $this->createdAt,
            'kind' => $this->kind,
            'tags' => $this->tags,
            'content' => $this->content,
            'sig' => $this->sig,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Recompute the id and verify the Schnorr signature (NIP-01).
     */
    public function hasValidSignature(): bool
    {
        $event = (object) [
            'id' => $this->id,
            'pubkey' => $this->pubkey,
            'created_at' => $this->createdAt,
            'kind' => $this->kind,
            'tags' => $this->tags,
            'content' => $this->content,
            'sig' => $this->sig,
        ];

        try {
            return (new Event)->verify($event);
        } catch (Throwable) {
            return false;
        }
    }
}
