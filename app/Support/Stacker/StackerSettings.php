<?php

namespace App\Support\Stacker;

use App\Models\User;

/**
 * A player's Blockfill controls (plan "Blockfill", P3): handling in ticks
 * (DAS 1-20, ARR 0-5, SDF 5-41, 41 = instant; the engine's own bounds in
 * resources/js/stacker/engine.js) and key bindings (KeyboardEvent.code, one
 * or two keys per action, no key twice). The same shape as
 * resources/js/stacker/keys.js normalizeControls(); anything off falls back
 * to the defaults, so a broken saved value never leaves an action unbound.
 * Saved on the gaming settings page; guests keep theirs in localStorage.
 */
final class StackerSettings
{
    public const LIMITS = ['das' => [1, 20], 'arr' => [0, 5], 'sdf' => [5, 41]];

    public const DEFAULT_HANDLING = ['das' => 10, 'arr' => 2, 'sdf' => 20];

    public const DEFAULT_KEYS = [
        'left' => ['ArrowLeft'],
        'right' => ['ArrowRight'],
        'soft' => ['ArrowDown'],
        'hard' => ['Space'],
        'cw' => ['ArrowUp', 'KeyX'],
        'ccw' => ['KeyZ'],
        'flip' => ['KeyA'],
        'hold' => ['KeyC', 'ShiftLeft'],
        'restart' => ['KeyR'],
    ];

    public const KEYS_PER_ACTION = 2;

    /**
     * Sound (P8): effects and music, each on/off with a volume 0-100. Effects
     * start on, music off; the same shape as resources/js/stacker/sound.js
     * normalizeSound(). Saved from the control on the game page.
     */
    public const DEFAULT_SOUND = ['effects' => 70, 'music' => 50, 'effectsOn' => true, 'musicOn' => false];

    /**
     * @return array{effects: int, music: int, effectsOn: bool, musicOn: bool}
     */
    public static function sound(?User $user): array
    {
        return self::normalizeSound($user?->stacker_sound);
    }

    /**
     * A saved sound setting, each field checked on its own: a broken volume
     * falls back to its default and leaves the other fields as saved.
     *
     * @return array{effects: int, music: int, effectsOn: bool, musicOn: bool}
     */
    public static function normalizeSound(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $sound = self::DEFAULT_SOUND;

        foreach (['effects', 'music'] as $key) {
            $value = $saved[$key] ?? null;
            if (is_int($value) && $value >= 0 && $value <= 100) {
                $sound[$key] = $value;
            }
        }

        foreach (['effectsOn', 'musicOn'] as $key) {
            if (is_bool($saved[$key] ?? null)) {
                $sound[$key] = $saved[$key];
            }
        }

        return $sound;
    }

    /**
     * A key code as people read it, as resources/js/stacker/keys.js keyLabel():
     * "ArrowLeft" as "←", "KeyJ" as "J", "Digit4" as "4", "ShiftLeft" as "Shift Left".
     */
    public static function label(string $code): string
    {
        $arrows = ['ArrowLeft' => '←', 'ArrowRight' => '→', 'ArrowUp' => '↑', 'ArrowDown' => '↓'];

        if (isset($arrows[$code])) {
            return $arrows[$code];
        }

        if (preg_match('/^(?:Key([A-Z])|Digit([0-9]))$/', $code, $match) === 1) {
            return $match[1] !== '' ? $match[1] : $match[2];
        }

        return trim((string) preg_replace('/(Left|Right)$/', ' $1', $code));
    }

    /**
     * @return array{das: int, arr: int, sdf: int, keys: array<string, list<string>>}
     */
    public static function of(?User $user): array
    {
        return self::normalize($user?->stacker_settings);
    }

    /**
     * @return array{das: int, arr: int, sdf: int, keys: array<string, list<string>>}
     */
    public static function normalize(mixed $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $handling = self::DEFAULT_HANDLING;

        foreach (self::LIMITS as $key => [$low, $high]) {
            $value = $saved[$key] ?? null;

            if (! is_int($value) || $value < $low || $value > $high) {
                $handling = self::DEFAULT_HANDLING;
                break;
            }

            $handling[$key] = $value;
        }

        return $handling + ['keys' => self::keys($saved['keys'] ?? null)];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function keys(mixed $keys): array
    {
        if (! is_array($keys)) {
            return self::DEFAULT_KEYS;
        }

        $seen = [];
        $out = [];

        foreach (array_keys(self::DEFAULT_KEYS) as $action) {
            $codes = $keys[$action] ?? null;

            if (! is_array($codes) || ! array_is_list($codes) || count($codes) < 1 || count($codes) > self::KEYS_PER_ACTION) {
                return self::DEFAULT_KEYS;
            }

            foreach ($codes as $code) {
                if (! is_string($code) || preg_match('/^[A-Za-z0-9]{1,24}$/', $code) !== 1 || isset($seen[$code])) {
                    return self::DEFAULT_KEYS;
                }

                $seen[$code] = true;
            }

            $out[$action] = $codes;
        }

        return $out;
    }
}
