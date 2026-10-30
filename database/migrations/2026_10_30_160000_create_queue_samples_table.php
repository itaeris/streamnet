<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mikrotik_session_id')->constrained('mikrotik_sessions')->cascadeOnDelete();
            $table->timestamp('sampled_at')->index();
            $table->string('name');
            $table->unsignedBigInteger('rx_bps')->default(0);
            $table->unsignedBigInteger('tx_bps')->default(0);
            $table->timestamps();

            $table->index(['mikrotik_session_id', 'name', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_samples');
    }
};
