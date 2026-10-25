<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete(); // who initiated it
            $table->foreignUuid('from_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignUuid('to_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->unsignedBigInteger('amount'); // minor units (kobo)
            $table->string('currency', 3);
            $table->string('note', 140)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamps();

            // The database is the last line of defence against a duplicate, whatever the app code does.
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
