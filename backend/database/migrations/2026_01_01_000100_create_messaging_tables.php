<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->boolean('marketing_consent')->default(true)->index();
            $table->timestamps();
        });

        // Tags live in their own table rather than a JSON column: audience filters
        // stay indexable and portable across MySQL (prod) and SQLite (tests).
        Schema::create('contact_tags', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 64);
            $table->primary(['contact_id', 'tag']);
            $table->index('tag');
        });

        // A sender is one provider account / phone number / bot. Several senders
        // per channel let us rotate traffic and keep each number under the limit.
        Schema::create('senders', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16)->index();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('body');
            // Ordered channel chain: [{"channel":"whatsapp","fallback":false},{"channel":"sms","fallback":true}]
            $table->json('channels');
            // Audience filter: {"tags":["vip"]} — empty means everybody with consent.
            $table->json('audience')->nullable();
            $table->unsignedInteger('daily_cap');
            $table->string('status', 16)->default('draft')->index();
            $table->date('starts_on')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Planned audience of a campaign, spread over days by DailyBatchScheduler.
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->date('send_on');
            $table->timestamp('processed_at')->nullable();
            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['send_on', 'processed_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fallback_of_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('recipient')->nullable();
            $table->text('body');
            $table->string('status', 16)->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message')->nullable();
            // Guards against double sends when a dispatcher run is repeated.
            $table->string('idempotency_key')->unique();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'channel', 'status']);
        });

        // Append-only audit trail of every status change (timeline in the UI).
        Schema::create('message_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('source', 16); // dispatcher | job | webhook
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['message_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('senders');
        Schema::dropIfExists('contact_tags');
        Schema::dropIfExists('contacts');
    }
};
