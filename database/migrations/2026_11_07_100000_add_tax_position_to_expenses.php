<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses say how their amount was entered (tax included or added on top),
 * how much of the spend was for the business, and whether it can be claimed
 * as a deduction. Categories carry the starting values for new expenses.
 *
 * Every existing expense was entered tax-inclusive, wholly for the business
 * and, until someone says otherwise, deductible, which is what the defaults
 * record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->boolean('tax_included')->default(true);
            $table->decimal('business_use_percent', 5, 2)->default(100);
            $table->boolean('tax_deductible')->default(true);
            $table->text('deduction_note')->nullable();
        });

        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->boolean('tax_deductible')->default(true);
            $table->string('default_tax_treatment', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn(['tax_included', 'business_use_percent', 'tax_deductible', 'deduction_note']);
        });

        Schema::table('expense_categories', function (Blueprint $table): void {
            $table->dropColumn(['tax_deductible', 'default_tax_treatment']);
        });
    }
};
