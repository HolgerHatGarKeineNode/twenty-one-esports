<?php

use App\Support\Nostr\Nip05Names;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The held name as it reads (P47 re-audit N3, Nip05Names::skeleton()):
     * without separators, look-alike characters folded, repeated letters
     * once. A hold refuses every name with the same skeleton, so `odell`
     * held also holds `o.dell`, `odell_`, `0dell` and `odel1`.
     */
    public function up(): void
    {
        Schema::table('nip05_holds', function (Blueprint $table) {
            $table->string('skeleton', 32)->default('')->index();
        });

        foreach (DB::table('nip05_holds')->select(['id', 'name'])->get() as $hold) {
            DB::table('nip05_holds')->where('id', $hold->id)->update(['skeleton' => Nip05Names::skeleton((string) $hold->name)]);
        }
    }

    public function down(): void
    {
        Schema::table('nip05_holds', function (Blueprint $table) {
            $table->dropIndex(['skeleton']);
            $table->dropColumn('skeleton');
        });
    }
};
