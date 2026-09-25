<?php

namespace App\Enums;

/**
 * A result report (NIP 2152) and the other side's answer to it (2153).
 * A newer report of the same match supersedes an open or disputed one.
 */
enum ReportStatus: string
{
    case Open = 'open';
    case Confirmed = 'confirmed';
    case Disputed = 'disputed';
    case Superseded = 'superseded';
}
