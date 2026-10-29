<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('payload');
            $table->timestamp('sampled_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('system_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at')->index();
            $table->string('identity')->nullable();
            $table->string('board')->nullable();
            $table->string('version')->nullable();
            $table->string('uptime')->nullable();
            $table->unsignedSmallInteger('cpu_load')->default(0);
            $table->unsignedBigInteger('memory_used')->default(0);
            $table->unsignedBigInteger('memory_total')->default(0);
            $table->unsignedBigInteger('disk_used')->default(0);
            $table->unsignedBigInteger('disk_total')->default(0);
            $table->decimal('temperature', 5, 1)->nullable();
            $table->decimal('voltage', 6, 2)->nullable();
            $table->unsignedInteger('connections')->default(0);
            $table->timestamps();
        });

        Schema::create('interface_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('sampled_at')->index();
            $table->string('name')->index();
            $table->string('type')->nullable();
            $table->boolean('running')->default(false);
            $table->boolean('disabled')->default(false);
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('rx_bps')->default(0);
            $table->unsignedBigInteger('tx_bps')->default(0);
            $table->unsignedBigInteger('rx_error')->default(0);
            $table->unsignedBigInteger('tx_error')->default(0);
            $table->unsignedBigInteger('rx_drop')->default(0);
            $table->unsignedBigInteger('tx_drop')->default(0);
            $table->unsignedInteger('link_downs')->default(0);
            $table->timestamps();

            $table->index(['name', 'sampled_at']);
        });

        Schema::create('dhcp_leases', function (Blueprint $table) {
            $table->id();
            $table->string('router_id')->unique();
            $table->string('address')->nullable()->index();
            $table->string('mac_address')->nullable()->index();
            $table->string('host_name')->nullable();
            $table->string('server')->nullable();
            $table->string('status')->nullable()->index();
            $table->boolean('blocked')->default(false);
            $table->boolean('dynamic')->default(false);
            $table->string('last_seen')->nullable();
            $table->string('expires_after')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('router_logs', function (Blueprint $table) {
            $table->id();
            $table->string('router_id')->unique();
            $table->timestamp('logged_at')->index();
            $table->string('topics')->nullable();
            $table->text('message');
            $table->string('severity')->default('info')->index();
            $table->timestamps();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('severity')->index();
            $table->string('category')->index();
            $table->string('title');
            $table->text('detail')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('router_logs');
        Schema::dropIfExists('dhcp_leases');
        Schema::dropIfExists('interface_samples');
        Schema::dropIfExists('system_samples');
        Schema::dropIfExists('live_snapshots');
    }
};
