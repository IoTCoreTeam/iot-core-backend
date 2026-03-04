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
        Schema::create('control_analog_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('control_url_id')->constrained('control_urls')->cascadeOnDelete();
            $table->decimal('min_value', 12, 4)->default(0);
            $table->decimal('max_value', 12, 4);
            $table->string('unit');
            $table->string('signal_type');
            $table->unsignedTinyInteger('resolution_bits');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('control_analog_signals');
    }
};
