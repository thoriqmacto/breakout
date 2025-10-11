<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scraper_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('base_url', 512);
            $table->string('endpoint', 512)->nullable();
            $table->string('query', 1024)->nullable();
            $table->text('bearer_token')->nullable();
            $table->text('request_url');
            $table->text('curl_command');
            $table->string('response_status', 191)->nullable();
            $table->longText('response_body');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraper_requests');
    }
};
