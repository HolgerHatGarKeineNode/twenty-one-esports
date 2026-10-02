<?php

namespace App\Policies;

use App\Models\LeagueWeek;
use App\Models\User;

/**
 * Who plans the league weeks (admin page "League weeks"): admins only, the
 * board and the `admins` table (User::isAdmin()). Organizers and players
 * neither see a draft nor change or approve one.
 */
class LeagueWeekPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, LeagueWeek $week): bool
    {
        return $user->isAdmin() && ! $week->hasStarted();
    }

    public function approve(User $user, LeagueWeek $week): bool
    {
        return $user->isAdmin() && ! $week->hasStarted() && ! $week->isApproved();
    }
}
