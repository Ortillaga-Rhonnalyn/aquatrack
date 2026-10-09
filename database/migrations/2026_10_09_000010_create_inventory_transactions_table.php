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
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->increments('transaction_id');
            $table->unsignedInteger('inventory_id');
            $table->enum('transaction_type', ['Stock In', 'Stock Out', 'Adjustment']);
            $table->integer('quantity');
            $table->dateTime('transaction_date')->useCurrent();
            $table->string('reference', 100)->nullable();
            $table->unsignedInteger('recorded_by');
            $table->timestamps();

            $table->foreign('inventory_id')->references('inventory_id')->on('inventory');
            $table->foreign('recorded_by')->references('user_id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
