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
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('payment_id');
            $table->unsignedInteger('order_id');
            $table->decimal('amount_paid', 10, 2);
            $table->enum('payment_method', ['Cash', 'GCash', 'Bank Transfer', 'Other']);
            $table->enum('payment_status', ['Paid', 'Partial', 'Unpaid'])->default('Paid');
            $table->dateTime('payment_date')->useCurrent();
            $table->unsignedInteger('received_by');
            $table->timestamps();

            $table->foreign('order_id')->references('order_id')->on('orders');
            $table->foreign('received_by')->references('user_id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
