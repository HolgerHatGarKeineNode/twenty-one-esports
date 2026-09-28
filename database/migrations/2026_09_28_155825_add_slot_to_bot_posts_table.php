<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A subject may get more than one note of a kind: the free-places notes
     * (P49, FreePlaceNotes) post one per tournament and slot ("168h", "3h").
     * The slot joins the unique key; it is an empty string, never null, for
     * the one-per-subject notes, because unique indexes treat nulls as
     * distinct and would no longer dedupe them.
     */
    public function up(): void
    {
        Schema::table('bot_posts', function (Blueprint $table) {
            $table->string('slot', 16)->default('')->after('kind');
            $table->dropUnique(['subject_type', 'subject_id', 'kind']);
            $table->unique(['subject_type', 'subject_id', 'kind', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::table('bot_posts', function (Blueprint $table) {
            $table->dropUnique(['subject_type', 'subject_id', 'kind', 'slot']);
            $table->unique(['subject_type', 'subject_id', 'kind']);
            $table->dropColumn('slot');
        });
    }
};
