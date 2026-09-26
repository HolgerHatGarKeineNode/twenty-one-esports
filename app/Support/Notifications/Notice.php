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
     *
     * Title and body carry names and messages of other players, so both are
     * cleaned to one line without links (PlainText): only the league's own
     * lines (the link, the opt-out) stand on a line of their own. The link
     * points at config('app.url'), whatever host the triggering request used.
     */
    public function toDmText(?string $optOut = null): string
    {
        return PlainText::line($this->title)."\n".PlainText::line($this->body)."\n".self::onApp($this->url).($optOut === null ? '' : "\n\n".$optOut);
    }

    /**
     * The same path, query and fragment on config('app.url'): a URL built
     * during someone else's request carries that request's host.
     */
    public static function onApp(string $url): string
    {
        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];

        return rtrim((string) config('app.url'), '/')
            .($parts['path'] ?? '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
