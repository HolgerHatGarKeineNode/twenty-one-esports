<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsPrizePot;
use Livewire\Component;

/**
 * A page whose main subject is a tournament's prize pot (the pool page,
 * pages::tournaments.pool): the pot section of
 * {@see EditsPrizePot} with nothing else to inherit. The create and edit
 * pages use the trait on top of TournamentFormatChooser.
 */
abstract class PrizePotPage extends Component
{
    use EditsPrizePot;
}
