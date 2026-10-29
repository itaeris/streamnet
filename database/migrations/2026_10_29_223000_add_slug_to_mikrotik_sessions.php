<?php

use App\Models\MikrotikSession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_sessions', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
        });

        MikrotikSession::query()->orderBy('id')->each(function (MikrotikSession $session): void {
            $session->slug = MikrotikSession::uniqueSlug($session->name, $session->id);
            $session->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_sessions', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
