<?php

namespace App\Support\TwentyOne\Stream;

/**
 * Environment overrides for ffmpeg/ffprobe children: every variable that
 * looks like a secret is removed (Symfony Process drops a name mapped to
 * false). Laravel loads .env into the process environment, so without this
 * each child would inherit TWENTYONE_NOSTR_NSEC, APP_KEY and the rest and
 * show them in /proc/<pid>/environ.
 */
final class ChildEnvironment
{
    private const SECRET_NAME = '/NSEC|SECRET|PASSWORD|PASSWD|TOKEN|PRIVATE|CREDENTIAL|(^|_)KEY$|_KEY_/i';

    /**
     * @return array<string, false>
     */
    public static function withoutSecrets(): array
    {
        $names = array_keys([...getenv(), ...$_ENV, ...$_SERVER]);
        $secrets = ['TWENTYONE_NOSTR_NSEC' => false];

        foreach ($names as $name) {
            if (is_string($name) && preg_match(self::SECRET_NAME, $name) === 1) {
                $secrets[$name] = false;
            }
        }

        return $secrets;
    }
}
