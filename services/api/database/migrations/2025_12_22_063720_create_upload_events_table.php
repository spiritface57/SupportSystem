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
        Schema::create('upload_events', function (Blueprint $table) {
            $table->id();

            $table->string('event_name', 64);
            $table->unsignedTinyInteger('event_version');

            $table->uuid('upload_id');
            $table->string('source', 16);

            $table->json('payload');

            $table->timestamp('created_at')->useCurrent();

            $table->index('upload_id');
            $table->index('event_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upload_events');
    }
};
