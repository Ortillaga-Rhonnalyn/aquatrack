<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('credits', function (Blueprint $table) {
            $table->increments('credit_id');
            $table->unsignedInteger('customer_id');
            $table->unsignedInteger('sale_id');
            $table->dateTime('credit_date')->useCurrent();
            $table->decimal('credit_amount', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0.00);
            $table->decimal('remaining_balance', 10, 2);
            $table->date('due_date')->nullable();
            $table->enum('credit_status', ['Unpaid', 'Partial', 'Paid', 'Overdue'])->default('Unpaid');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('customer_id')->on('customers');
            $table->foreign('sale_id')->references('sale_id')->on('sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credits');
    }
};
