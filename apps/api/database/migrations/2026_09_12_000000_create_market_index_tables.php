<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which symbols belong to a published index, and since when.
 *
 * Two tables rather than a column on `assets`, for two reasons. An index
 * contains symbols this installation does not track -- that is most of the
 * point, since the list is where new candidates come from -- so membership
 * cannot hang off a row that does not exist. And membership changes: the IDX
 * reviews JII70 periodically, and a badge that silently flips with no record
 * of when leaves nobody able to answer "was this in the index when I bought
 * it?".
 *
 * Removal is a date, never a delete. A symbol dropped from the index keeps its
 * row with `removed_on` set, so the history survives and a symbol that returns
 * is recognisable as a return rather than a first sighting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_indexes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name')->nullable();
            $table->string('source_url', 512)->nullable();
            // What produced the last accepted membership list: 'browser' for
            // the scheduled fetch, 'manual' for a pasted list. A badge whose
            // provenance is unknown is a badge nobody can check.
            $table->string('last_source', 32)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->date('last_effective_on')->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();
        });

        Schema::create('index_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('index_code', 32);
            $table->string('symbol', 16);
            // The start of the current spell, not of the first ever. A symbol
            // that leaves and comes back starts a new spell, which is the
            // honest reading of "joined".
            $table->date('joined_on');
            $table->date('last_seen_on');
            $table->date('removed_on')->nullable();
            $table->timestamps();

            $table->unique(['index_code', 'symbol']);
            $table->index(['index_code', 'removed_on']);
            $table->index('symbol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_memberships');
        Schema::dropIfExists('market_indexes');
    }
};
