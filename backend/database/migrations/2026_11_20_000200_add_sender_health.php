<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sender health: warm-up, quarantine, account linking; message deadlines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('senders', function (Blueprint $table) {
            // Second pace pointer for deadline-bound messages (see SenderPool).
            $table->timestamp('priority_last_used_at')->nullable()->after('last_used_at');

            // Warm-up: days are counted from warmup_started_at, not by calendar date.
            // NULL profile = not warming up.
            $table->string('warmup_profile', 32)->nullable();
            $table->timestamp('warmup_started_at')->nullable();

            // Kept on the row (not only in the history table) because every
            // reservation and every send asks for it.
            $table->timestamp('quarantined_until')->nullable();

            // Provider account behind the sender and the one-time link token.
            $table->string('account_id')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->string('link_token_hash', 64)->nullable()->unique();
            $table->timestamp('link_token_expires_at')->nullable();
        });

        // Reservations per sender per warm-up day. Keyed by the day's start, not by
        // its index: a warm-up restarted after quarantine starts at "day 0" again.
        Schema::create('sender_daily_usage', function (Blueprint $table) {
            $table->foreignId('sender_id')->constrained()->cascadeOnDelete();
            $table->timestamp('day_starts_at');
            $table->unsignedInteger('reserved')->default(0);
            $table->primary(['sender_id', 'day_starts_at']);
        });

        // Raw "account restricted" answers from the provider.
        Schema::create('sender_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->timestamp('occurred_at');
            $table->index(['sender_id', 'occurred_at']);
        });

        // Quarantine history. A repeated quarantine of the same account is the
        // signal that it should be replaced rather than warmed up again, so the
        // history must outlive the sender row: nullable FK + account copied as text.
        Schema::create('sender_quarantines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_id')->nullable();
            $table->string('reason', 64);
            $table->unsignedInteger('signals');
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->index(['account_id', 'started_at']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            // bulk: may use the daily warm-up limit minus the urgent reserve;
            // urgent: the whole limit.
            $table->string('priority', 16)->default('bulk')->after('daily_cap');
            // Messages older than this are not worth sending (a reminder for a
            // visit that already happened). NULL = no deadline.
            $table->unsignedInteger('ttl_minutes')->nullable()->after('priority');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('scheduled_for');
            $table->timestamp('deleted_at')->nullable()->after('failed_at');
            $table->index(['sender_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['sender_id', 'status']);
            $table->dropColumn(['expires_at', 'deleted_at']);
        });
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn(['priority', 'ttl_minutes']));
        Schema::dropIfExists('sender_quarantines');
        Schema::dropIfExists('sender_restrictions');
        Schema::dropIfExists('sender_daily_usage');
        Schema::table('senders', function (Blueprint $table) {
            $table->dropUnique(['link_token_hash']);
            $table->dropColumn([
                'priority_last_used_at', 'warmup_profile', 'warmup_started_at', 'quarantined_until',
                'account_id', 'linked_at', 'link_token_hash', 'link_token_expires_at',
            ]);
        });
    }
};
