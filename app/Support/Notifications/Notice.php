<?php

namespace App\Support\Notifications;

/**
 * The content of one notification: a short title, one sentence, the link
 * into the app, and the match (game) number it is about. Never lobby data
 * (NIP "Notifications": "never lobby data").
 *
 * In the app (P5c) it also carries the toast's action label ("Play now") and
 * the sound to play; both default from the NotificationKind.
 */
final readonly class Notice
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public ?int $match = null,
        public ?string $action = null,
        public ?string $sound = null,
    ) {}

    /**
     * @return array{title: string, body: string, url: string, tag: string|null}
     */
    public function toPushPayload(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->match !== null ? 'game-'.$this->match : null,
        ];
    }

    /**
     * NIP "Notifications": plain text with a link into the app, and the
     * opt-out line last when there is one.
     */
    public function toDmText(?string $optOut = null): string
    {
        return $this->title."\n".$this->body."\n".$this->url.($optOut === null ? '' : "\n\n".$optOut);
    }
}
