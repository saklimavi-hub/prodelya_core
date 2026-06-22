<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_print_productions', function (Blueprint $table): void {
            $table->decimal('subcontractor_cost', 15, 2)->nullable()->after('issue_note');
            $table->string('subcontractor_cost_currency', 3)->nullable()->default('TRY')->after('subcontractor_cost');
            $table->text('subcontractor_cost_note')->nullable()->after('subcontractor_cost_currency');
        });
    }

    public function down(): void
    {
        Schema::table('order_item_print_productions', function (Blueprint $table): void {
            $table->dropColumn([
                'subcontractor_cost',
                'subcontractor_cost_currency',
                'subcontractor_cost_note',
            ]);
        });
    }
};
