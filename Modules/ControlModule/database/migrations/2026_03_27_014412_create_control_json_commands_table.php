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
        Schema::create('control_json_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_url_id')->constrained('control_urls')->cascadeOnDelete();
            $table->string('name');
            $table->json('command');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('control_json_commands');
    }
};
