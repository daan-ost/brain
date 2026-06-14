<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * indicator_metrics — the precomputed calculation cache (rule-independent). One row per
 * (coin, datetime, indicator, lookback) holding all ~29 "Test type" calculations as columns.
 * Filled by engine/src/build_indicator_metrics.py for every datetime in a promising period +
 * every trade. The substrate for the per-rule precision band-analysis. Mirrored to Parquet.
 */
return new class extends Migration
{
    /** The 29 window calculations (must match engine/src/calc.py WINDOW_METRIC_KEYS). */
    private array $metrics = [
        'current_value', 'first_value', 'last_value', 'diff_previous_value', 'diff_previous_number',
        'max_diff_number', 'max_diff_percentage', 'diff_number_prev_max', 'diff_number_prev_min',
        'diff_percentage_prev_max', 'diff_percentage_prev_min', 'sum_average_positive_percentage',
        'lowest_value', 'highest_value', 'sum_value', 'diff_lowest_value_period', 'diff_highest_value_period',
        'standard_deviation', 'volatility', 'range_percentage', 'consecutive_increases',
        'consecutive_decreases', 'reversal_count', 'average_reversal_size', 'median_value', 'skewness',
        'count_positive', 'count_negative', 'max_same_value',
    ];

    public function up(): void
    {
        Schema::create('indicator_metrics', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('trading_symbol_id');
            $table->string('symbol', 50)->nullable();
            $table->dateTime('datetime');
            $table->string('indicator', 30);
            $table->unsignedTinyInteger('lookback');             // 1..20
            foreach ($this->metrics as $m) {
                $table->double($m)->nullable();
            }
            $table->unique(['trading_symbol_id', 'datetime', 'indicator', 'lookback'], 'uq_metric');
            $table->index(['trading_symbol_id', 'indicator', 'lookback'], 'idx_sym_ind_lb');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_metrics');
    }
};
