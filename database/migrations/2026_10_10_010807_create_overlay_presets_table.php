<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OBS overlay presets (plan "OBS-Broadcast-Overlays", P2): an admin picks a variant, a tournament for the
     * tournament and bracket variants, the language and the modules, and gets a secret URL `/broadcast/{token}`.
     * Only the SHA-256 of the token is stored (`token_hash`); rotating replaces it and stamps `rotated_at`.
     */
    public function up(): void
    {
        Schema::create('overlay_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('variant', 16);
            $table->foreignId('tournament_id')->nullable()->constrained()->nullOnDelete();
            $table->string('locale', 2);
            $table->json('modules');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('rotated_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overlay_presets');
    }
};
