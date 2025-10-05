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
        Schema::create('hillstone_object_data', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('ip');
            $table->boolean('is_ipv6')->default(false);
            $table->boolean('predefined')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('name');
            $table->index('last_synced_at');
            $table->index(['is_ipv6', 'predefined']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hillstone_object_data');
    }
};