<?php

namespace App\Games;

final class EaSportsFc26 extends EaSportsFc
{
    protected function edition(): int
    {
        return 26;
    }

    /** The supplied art is 480 × 270 and is never scaled up. */
    protected function coverWidths(): array
    {
        return [480];
    }
}
