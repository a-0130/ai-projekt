<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('achievement_key', 80);
            $table->string('variant_key', 80);
            $table->string('name');
            $table->string('description');
            $table->unsignedInteger('threshold');
            $table->timestamp('earned_at')->useCurrent();
            $table->unique(['user_id', 'achievement_key', 'variant_key']);
        });

        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_achievement_id')->constrained('user_achievements')->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->unsignedTinyInteger('discount_percent');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('user_tickets', function (Blueprint $table) {
            $table->foreignId('discount_code_id')->nullable()->after('ticket_type_id')->constrained('discount_codes')->nullOnDelete();
            $table->decimal('discount_amount', 10, 2)->default(0)->after('purchase_date');
            $table->decimal('final_price', 10, 2)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('user_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_code_id');
            $table->dropColumn(['discount_amount', 'final_price']);
        });

        Schema::dropIfExists('discount_codes');
        Schema::dropIfExists('user_achievements');
    }
};
