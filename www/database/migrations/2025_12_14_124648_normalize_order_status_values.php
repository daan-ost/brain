<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Normalize all order status values to lowercase for enum compatibility.
     */
    public function up(): void
    {
        // Map old mixed-case values to new lowercase values
        $statusMappings = [
            'Paid' => 'paid',
            'Pending' => 'pending',
            'Initiated' => 'initiated',
            'Failed' => 'failed',
            'Canceled' => 'canceled',
            'Expired' => 'expired',
            'Refunded' => 'refunded',
            'ChargedBack' => 'charged_back',
        ];

        foreach ($statusMappings as $old => $new) {
            DB::table('orders')
                ->where('status', $old)
                ->update(['status' => $new]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Map back to capitalized values
        $statusMappings = [
            'paid' => 'Paid',
            'pending' => 'Pending',
            'initiated' => 'Initiated',
            'failed' => 'Failed',
            'canceled' => 'Canceled',
            'expired' => 'Expired',
            'refunded' => 'Refunded',
            'charged_back' => 'ChargedBack',
        ];

        foreach ($statusMappings as $old => $new) {
            DB::table('orders')
                ->where('status', $old)
                ->update(['status' => $new]);
        }
    }
};
