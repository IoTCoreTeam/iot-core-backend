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
        Schema::table('nodes', function (Blueprint $table) {
            $table->double('latest_lat')->nullable()->after('type');
            $table->double('latest_lng')->nullable()->after('latest_lat');
            $table->double('latest_heading_deg')->nullable()->after('latest_lng');
            $table->string('latest_heading_cardinal')->nullable()->after('latest_heading_deg');
            $table->timestamp('latest_gps_recorded_at')->nullable()->after('latest_heading_cardinal');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'latest_lat',
                'latest_lng',
                'latest_heading_deg',
                'latest_heading_cardinal',
                'latest_gps_recorded_at',
            ]);
        });
    }
};
