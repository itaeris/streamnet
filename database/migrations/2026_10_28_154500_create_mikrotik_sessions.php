<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('host');
            $table->string('scheme', 8)->default('http');
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('username');
            $table->text('password');
            $table->string('wan_interface')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('live_snapshots', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->foreignId('mikrotik_session_id')->nullable()->constrained('mikrotik_sessions')->cascadeOnDelete();
            $table->unique(['mikrotik_session_id', 'key']);
        });

        Schema::table('system_samples', function (Blueprint $table) {
            $table->foreignId('mikrotik_session_id')->nullable()->constrained('mikrotik_sessions')->cascadeOnDelete();
        });

        Schema::table('interface_samples', function (Blueprint $table) {
            $table->foreignId('mikrotik_session_id')->nullable()->constrained('mikrotik_sessions')->cascadeOnDelete();
        });

        Schema::table('dhcp_leases', function (Blueprint $table) {
            $table->dropUnique(['router_id']);
            $table->foreignId('mikrotik_session_id')->nullable()->constrained('mikrotik_sessions')->cascadeOnDelete();
            $table->unique(['mikrotik_session_id', 'router_id']);
        });

        Schema::table('router_logs', function (Blueprint $table) {
            $table->dropUnique(['router_id']);
            $table->foreignId('mikrotik_session_id')->nullable()->constrained('mikrotik_sessions')->cascadeOnDelete();
            $table->unique(['mikrotik_session_id', 'router_id']);
        });

        Schema::table('security_events', function (Blueprint $table) {
            $table->foreignId('mikrotik_session_id')->nullable()->constrained('mikrotik_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mikrotik_session_id');
        });

        Schema::table('router_logs', function (Blueprint $table) {
            $table->dropUnique(['mikrotik_session_id', 'router_id']);
            $table->dropConstrainedForeignId('mikrotik_session_id');
            $table->unique('router_id');
        });

        Schema::table('dhcp_leases', function (Blueprint $table) {
            $table->dropUnique(['mikrotik_session_id', 'router_id']);
            $table->dropConstrainedForeignId('mikrotik_session_id');
            $table->unique('router_id');
        });

        Schema::table('interface_samples', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mikrotik_session_id');
        });

        Schema::table('system_samples', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mikrotik_session_id');
        });

        Schema::table('live_snapshots', function (Blueprint $table) {
            $table->dropUnique(['mikrotik_session_id', 'key']);
            $table->dropConstrainedForeignId('mikrotik_session_id');
            $table->unique('key');
        });

        Schema::dropIfExists('mikrotik_sessions');
    }
};
