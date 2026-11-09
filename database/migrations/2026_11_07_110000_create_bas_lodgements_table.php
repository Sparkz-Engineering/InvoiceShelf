<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record that a Business Activity Statement was lodged for a period, with
 * the figures as they stood at the time, so a later change to an invoice,
 * payment or expense in that period can be noticed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bas_lodgements', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('creator_id')->nullable()->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('basis', 16);
            $table->json('figures');
            $table->date('lodged_on');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bas_lodgements');
    }
};
