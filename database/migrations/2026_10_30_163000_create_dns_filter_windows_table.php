<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_filter_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_session_id')->constrained('mikrotik_sessions')->cascadeOnDelete();
            $table->string('domain');
            $table->char('starts_at', 5);
            $table->char('ends_at', 5);
            $table->boolean('blocked');
            $table->string('label', 40);
            $table->timestamps();

            $table->index(['mikrotik_session_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_filter_windows');
    }
};
