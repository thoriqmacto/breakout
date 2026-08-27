<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A persistent, in-app attention state. The project has no mail or
        // push infrastructure and this feature is not a good reason to add a
        // third-party notification dependency, so a warning that survives a
        // page reload and is visible in the dashboard header is the delivery
        // mechanism.
        Schema::create('automation_alerts', function (Blueprint $table) {
            $table->id();

            // Identifies what the alert is about, e.g. "stockbit_token".
            // Combined with `key` it is what makes raising an alert
            // idempotent, so a daily check cannot pile up duplicates.
            $table->string('type', 48);
            $table->string('key', 191);

            // info | warning | critical
            $table->string('severity', 16)->default('warning');

            $table->string('title', 191);
            $table->text('message');
            $table->json('context')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'key'], 'automation_alerts_type_key_unique');
            $table->index(['resolved_at', 'severity'], 'automation_alerts_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_alerts');
    }
};
