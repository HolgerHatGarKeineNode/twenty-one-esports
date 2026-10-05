<?php

use App\Support\Moderation\LeagueMuteList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The keys the site lists that a client already held as its own public
     * `p` in the list a version carried over ({@see LeagueMuteList}): the site
     * keeps that tag verbatim instead of appending one, so `site_keys` holds
     * only the tags the site appended and a lift never touches the client's.
     * Null on versions written before this column existed.
     */
    public function up(): void
    {
        Schema::table('league_mute_list_versions', function (Blueprint $table) {
            $table->text('client_keys')->nullable()->after('site_keys');
        });
    }

    public function down(): void
    {
        Schema::table('league_mute_list_versions', function (Blueprint $table) {
            $table->dropColumn('client_keys');
        });
    }
};
