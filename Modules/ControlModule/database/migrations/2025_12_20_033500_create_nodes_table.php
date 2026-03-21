<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('gateway_id')->nullable();
            $table->string('external_id')->unique();
            $table->string('name')->nullable();

            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->enum('type', ['controller', 'sensor', 'node'])->default('node');

            $table->timestamps();
            $table->softDeletes();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
