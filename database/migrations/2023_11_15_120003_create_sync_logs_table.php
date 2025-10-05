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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('operation_type', ['full_sync', 'partial_sync', 'object_sync']);
            $table->enum('status', ['started', 'completed', 'failed']);
            $table->integer('objects_processed')->default(0);
            $table->integer('objects_created')->default(0);
            $table->integer('objects_updated')->default(0);
            $table->integer('objects_deleted')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['operation_type', 'status']);
            $table->index('started_at');
            $table->index('status');
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};