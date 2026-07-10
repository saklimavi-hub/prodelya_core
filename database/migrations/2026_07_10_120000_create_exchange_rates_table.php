<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('rate_type', 40);
            $table->string('source_currency', 3);
            $table->string('target_currency', 3);
            $table->date('rate_date');
            $table->unsignedInteger('source_unit')->default(1);
            $table->decimal('rate', 20, 8);
            $table->timestamp('fetched_at')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['provider', 'rate_type', 'source_currency', 'target_currency', 'rate_date'],
                'exchange_rates_provider_pair_date_unique'
            );
            $table->index(
                ['source_currency', 'target_currency', 'rate_date'],
                'exchange_rates_pair_date_index'
            );
            $table->index(['provider', 'rate_date'], 'exchange_rates_provider_date_index');
            $table->index(['rate_date'], 'exchange_rates_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
