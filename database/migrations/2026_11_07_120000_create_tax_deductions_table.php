<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deductions that are not an expense with a receipt: a home office worked
 * out from hours, car costs from kilometres, depreciation of equipment. They
 * sit beside the deductible expenses in a financial year's deductions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_deductions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('creator_id')->nullable()->index();
            $table->date('deduction_date')->index();
            $table->string('category', 32);
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_deductions');
    }
};
