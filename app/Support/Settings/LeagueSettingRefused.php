<?php

namespace App\Support\Settings;

use RuntimeException;

/**
 * A league settings change that was refused (P44): an unknown key, a value
 * of the wrong type or out of range, or a board-only key changed by an
 * admin who is not on the board. `errors`: config path => message.
 */
final class LeagueSettingRefused extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
