<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ai_assistant_jobs')) {
            Schema::create('ai_assistant_jobs', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->unsignedInteger('user_id')->index();
                $table->string('session_id', 100);
                $table->string('request_id', 64);
                $table->string('mode', 16);
                $table->string('status', 24)->default('queued')->index();
                $table->text('prompt');
                $table->longText('state');
                $table->text('error')->nullable();
                $table->boolean('hidden')->default(false);
                $table->timestamps();
                $table->unique(['user_id', 'request_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_jobs');
    }
};
