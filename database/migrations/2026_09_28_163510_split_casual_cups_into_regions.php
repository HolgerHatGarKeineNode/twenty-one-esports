<?php

use App\Support\Tournaments\CasualCups;
use Illuminate\Database\Migrations\Migration;

/**
 * Data only: separate EU and US casual cups (user, 2026-09-28). The cups
 * opened before join each game's EU series (the open ones renamed "… Casual
 * Cup EU #n" and moved to the next EU slot, their calendar events
 * republished, their players told once), then every enabled game opens its
 * US cup right away (CasualCups::splitIntoRegions()). Idempotent; nothing
 * to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(CasualCups::class)->splitIntoRegions();
    }

    public function down(): void {}
};
