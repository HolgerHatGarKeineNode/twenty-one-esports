<?php

namespace App\Enums;

/**
 * Life of a tournament: a draft only its organizer, its directors and admins
 * see, then sign-up (P8b), the running brackets and the end. Payouts from the
 * tournament's own pot follow the end (P9).
 */
enum TournamentStatus: string
{
    case Draft = 'draft';
    case Signup = 'signup';
    case Running = 'running';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Signup => __('Sign-up open'),
            self::Running => __('Running'),
            self::Finished => __('Finished'),
            self::Cancelled => __('Called off'),
        };
    }
}
