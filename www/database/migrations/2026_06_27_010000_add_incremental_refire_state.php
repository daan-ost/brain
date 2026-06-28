<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic I — incrementele refire. Breidt coin_refire_state uit zodat persist_to_brain weet of de
 * vorige refire-data nog een onveranderde PREFIX is (aangroei) of dat oude data wijzigde (volledig).
 *
 *  - last_max_datetime — de MAX(datetime) van de indicators-reeks bij de vorige refire. De grens
 *    tussen de stabiele prefix en de nieuwe staart.
 *  - prefix_checksum — CRC32-checksum van de indicators-prefix t/m last_max_datetime (zelfde
 *    bouwstenen als fires_cache.fires_fingerprint). Matcht 'm de huidige prefix => aangroei =>
 *    incrementeel; mismatch (oude data gewijzigd/herladen) => volledige refire.
 *
 * coin_refire_state is ad-hoc aangemaakt (geen eerdere migratie) — daarom hier defensief: maak de
 * tabel aan als die nog niet bestaat, anders alleen de twee kolommen toevoegen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coin_refire_state')) {
            Schema::create('coin_refire_state', function (Blueprint $table) {
                $table->unsignedInteger('trading_symbol_id')->primary();
                $table->string('fingerprint', 64);
                $table->dateTime('last_refired_at');
                $table->dateTime('last_max_datetime')->nullable();
                $table->string('prefix_checksum', 64)->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('coin_refire_state', function (Blueprint $table) {
            if (! Schema::hasColumn('coin_refire_state', 'last_max_datetime')) {
                $table->dateTime('last_max_datetime')->nullable()->after('last_refired_at');
            }
            if (! Schema::hasColumn('coin_refire_state', 'prefix_checksum')) {
                $table->string('prefix_checksum', 64)->nullable()->after('last_max_datetime');
            }
        });
    }

    public function down(): void
    {
        Schema::table('coin_refire_state', function (Blueprint $table) {
            $table->dropColumn(['last_max_datetime', 'prefix_checksum']);
        });
    }
};
