<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unifi_connections', function (Blueprint $table) {
            $table->foreignId('mikrotik_session_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $sessionId = DB::table('mikrotik_sessions')->orderBy('id')->value('id');
        $keep = DB::table('unifi_connections')->orderByDesc('id')->value('id');

        if ($sessionId && $keep) {
            DB::table('unifi_connections')->where('id', '!=', $keep)->delete();
            DB::table('unifi_connections')->where('id', $keep)->update([
                'mikrotik_session_id' => $sessionId,
            ]);
        } else {
            DB::table('unifi_connections')->delete();
        }

        Schema::table('unifi_connections', function (Blueprint $table) {
            $table->unique('mikrotik_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('unifi_connections', function (Blueprint $table) {
            $table->dropUnique(['mikrotik_session_id']);
            $table->dropConstrainedForeignId('mikrotik_session_id');
        });
    }
};
