<?php

namespace App\Support\SeasonChain;

/**
 * `resolution` of a rated League Attestation (`2154`). All three are rated;
 * a forfeit never mines (consensus rule 2).
 */
enum Resolution: string
{
    case Confirmed = 'confirmed';
    case Admin = 'admin';
    case Forfeit = 'forfeit';
}
