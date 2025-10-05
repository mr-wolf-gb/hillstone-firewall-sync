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
        Schema::create('hillstone_object_data_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hillstone_object_data_id')
                  ->constrained('hillstone_object_data')
                  ->onDelete('cascade');
            $table->string('ip_addr');
            $table->string('ip_address', 45); // Support both IPv4 and IPv6
            $table->string('netmask', 45);
            $table->integer('flag')->default(0);
            $table->timestamps();

            // Indexes for performance
            $table->index('ip_address');
            $table->index('ip_addr');
            $table->index('hillstone_object_data_id');
            
            // Composite index for common queries
            $table->index(['ip_address', 'netmask']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hillstone_object_data_ips');
    }
};