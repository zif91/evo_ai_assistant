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
        Schema::create('ai_assistant_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50); // resource, tv_value, tv, template
            $table->unsignedBigInteger('entity_id');
            $table->string('field_name', 100)->nullable();
            $table->longText('old_value')->nullable();
            $table->longText('new_value')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('is_rolled_back')->default(false);
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('session_id');
            $table->index('user_id');
            $table->index('is_rolled_back');
        });

        Schema::create('ai_assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 100);
            $table->unsignedInteger('user_id');
            $table->string('role', 20); // user, assistant, system
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('user_id');
        });

        Schema::create('ai_assistant_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_settings');
        Schema::dropIfExists('ai_assistant_conversations');
        Schema::dropIfExists('ai_assistant_checkpoints');
    }
};
