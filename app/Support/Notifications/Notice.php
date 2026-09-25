<?php

namespace App\Support\Notifications;

/**
 * The content of one notification: a short title, one sentence, the link
 * into the app, and the match (game) number it is about. Never lobby data
 * (NIP "Notifications": "never lobby data").
 */
final readonly class Notice
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public ?int $match = null,
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
     * NIP "Notifications": plain text with a link into the app.
     */
    public function toDmText(): string
    {
        return $this->title."\n".$this->body."\n".$this->url;
    }
}
