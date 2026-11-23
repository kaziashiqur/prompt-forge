<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('balance_nanos')->default(0);
        });

        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();

            $table->string('status')->default('submitting')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images')->nullable();

            $table->bigInteger('estimate_min_nanos');
            $table->bigInteger('estimate_max_nanos');
            $table->bigInteger('estimate_expected_nanos');
            $table->bigInteger('hold_nanos');

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charge_nanos')->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('generation_provider_id')->nullable()->index();
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->bigInteger('amount_nanos');
            $table->timestamps();

            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('balance_nanos'));
    }
};
