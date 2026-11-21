<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The held account's name skeleton (App\Support\FairPlay\Impersonation)
     * is longer than the name it comes from when NFKC spells characters out
     * (U+FDFA is 18 letters): 100 characters of name may read as up to
     * Impersonation::MAX_NAME_KEY_LENGTH letters, which a varchar(100)
     * refused on Postgres (22001) and aborted the check.
     */
    public function up(): void
    {
        Schema::table('impersonation_holds', function (Blueprint $table) {
            $table->text('name_skeleton')->nullable()->change();
        });
    }

    /**
     * A skeleton longer than 100 characters would not fit back: the rollback
     * refuses instead of cutting an admin decision's record.
     */
    public function down(): void
    {
        $long = DB::table('impersonation_holds')->whereNotNull('name_skeleton')->get(['name_skeleton'])
            ->filter(fn (object $row): bool => mb_strlen((string) $row->name_skeleton) > 100)->count();

        if ($long > 0) {
            throw new RuntimeException("Cannot roll back: {$long} impersonation hold(s) carry a name skeleton longer than 100 characters.");
        }

        Schema::table('impersonation_holds', function (Blueprint $table) {
            $table->string('name_skeleton', 100)->nullable()->change();
        });
    }
};
