<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_session_id')->constrained('mikrotik_sessions')->cascadeOnDelete();
            $table->string('router_id');
            $table->timestamp('logged_at')->index();
            $table->string('address', 45)->index();
            $table->string('domain');
            $table->string('qtype', 16);
            $table->string('app', 64);
            $table->string('browser', 32)->nullable();
            $table->timestamps();

            $table->unique(['mikrotik_session_id', 'router_id']);
            $table->index(['mikrotik_session_id', 'logged_at']);
            $table->index(['mikrotik_session_id', 'address', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_queries');
    }
};
