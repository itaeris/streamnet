<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unifi_connections', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->text('api_key');
            $table->string('site')->nullable();
            $table->boolean('verify_tls')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unifi_connections');
    }
};
