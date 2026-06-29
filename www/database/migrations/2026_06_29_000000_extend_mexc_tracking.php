<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEXC coin-tracking — brei de brain-DB gelijk met het server-schema (engine/sql/mexc_schema.sql,
 * docs/findings/mexc-coin-tracking-2026-06-29.md):
 *   1. mexc_market_scan uitbreiden: rang + spread/orderboek-druk + candle-trend + auto_flag
 *   2. mexc_snapshots — 4-uurs geheugen (append)
 *   3. mexc_coin_labels — handmatige classificatie per munt (overleeft de truncate)
 *
 * Lokaal draait de scan default tegen brain; op de server tegen de eigen `mexc`-DB. Beide schema's
 * blijven hierdoor identiek. Bestaande snapshot-data blijft staan (kolommen nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mexc_market_scan', function (Blueprint $t) {
            $t->unsignedInteger('rank_volat')->nullable()->after('quote');
            // liquiditeit / orderboek-top (uit ticker/24hr)
            $t->decimal('bid_price', 24, 10)->nullable()->after('vol24h_usd');
            $t->decimal('ask_price', 24, 10)->nullable()->after('bid_price');
            $t->decimal('bid_qty', 24, 6)->nullable()->after('ask_price');
            $t->decimal('ask_qty', 24, 6)->nullable()->after('bid_qty');
            $t->decimal('spread_pct', 10, 4)->nullable()->after('ask_qty');
            $t->decimal('book_pressure', 6, 4)->nullable()->after('spread_pct');
            // candle-trend (klines 1d)
            $t->decimal('ret_7d_pct', 10, 2)->nullable()->after('book_pressure');
            $t->decimal('ret_14d_pct', 10, 2)->nullable()->after('ret_7d_pct');
            $t->decimal('avg_day_range_pct', 10, 2)->nullable()->after('ret_14d_pct');
            $t->unsignedTinyInteger('up_days')->nullable()->after('avg_day_range_pct');
            $t->unsignedTinyInteger('down_days')->nullable()->after('up_days');
            $t->unsignedTinyInteger('trend_window_d')->nullable()->after('down_days');
            $t->string('auto_flag', 20)->nullable()->after('trend_window_d');
            $t->index('auto_flag', 'mms_flag');
        });

        Schema::create('mexc_snapshots', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('symbol', 40);
            $t->string('base', 30);
            $t->unsignedInteger('rank_volat')->nullable();
            $t->decimal('price', 24, 10)->nullable();
            $t->decimal('change24h_pct', 10, 2)->nullable();
            $t->decimal('volat_pct', 10, 2)->nullable();
            $t->decimal('vol24h_usd', 20, 2)->nullable();
            $t->decimal('bid_price', 24, 10)->nullable();
            $t->decimal('ask_price', 24, 10)->nullable();
            $t->decimal('bid_qty', 24, 6)->nullable();
            $t->decimal('ask_qty', 24, 6)->nullable();
            $t->decimal('spread_pct', 10, 4)->nullable();
            $t->decimal('book_pressure', 6, 4)->nullable();
            $t->timestamp('snapshot_at');
            $t->timestamp('created_at')->nullable();
            $t->index(['base', 'snapshot_at'], 'ms_base_time');
            $t->index('snapshot_at', 'ms_time');
        });

        Schema::create('mexc_coin_labels', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('base', 30);
            $t->string('symbol', 40)->nullable();
            $t->enum('classification', ['unrated', 'good', 'bad'])->default('unrated');
            $t->json('reasons')->nullable();         // alleen bij 'bad': 1+ reden-codes
            $t->text('note')->nullable();
            $t->string('updated_by', 120)->nullable();
            $t->timestamps();
            $t->unique('base', 'mcl_base');
            $t->index('classification', 'mcl_class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mexc_coin_labels');
        Schema::dropIfExists('mexc_snapshots');
        Schema::table('mexc_market_scan', function (Blueprint $t) {
            $t->dropIndex('mms_flag');
            $t->dropColumn([
                'rank_volat', 'bid_price', 'ask_price', 'bid_qty', 'ask_qty', 'spread_pct',
                'book_pressure', 'ret_7d_pct', 'ret_14d_pct', 'avg_day_range_pct', 'up_days',
                'down_days', 'trend_window_d', 'auto_flag',
            ]);
        });
    }
};
