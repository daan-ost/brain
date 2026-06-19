<?php

use App\Livewire\Coins\Ranking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function seedMetric(int $sym, string $symbol, string $date, ?float $up7d, float $vol7d = 0.5, int $ticks = 400): void
{
    DB::table('coins')->updateOrInsert(
        ['id' => $sym],
        ['symbol' => $symbol, 'timeframe' => 5, 'stoploss_multiplier' => 0.9996, 'roundingup' => 5,
         'created_at' => now(), 'updated_at' => now()]
    );
    DB::table('coin_daily_metrics')->insert([
        'trading_symbol_id' => $sym, 'date' => $date, 'up_pct' => $up7d, 'vol_pct' => $vol7d,
        'n_ticks' => $ticks, 'up_7d' => $up7d, 'vol_7d' => $vol7d, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('rangschikt munten op kansrijkheid (hoogste up_7d eerst)', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    seedMetric(244, 'NOS', '2025-01-03', 5.9);
    seedMetric(2525, 'DOGEAI', '2025-07-01', 99.0);   // oude dag — mag NIET tellen
    seedMetric(2525, 'DOGEAI', '2025-07-14', 16.9);   // laatste dag — dit telt

    Livewire::actingAs($admin)
        ->test(Ranking::class)
        ->assertSeeInOrder(['DOGEAI', 'NOS'])           // DOGEAI (16,9%) boven NOS (5,9%)
        ->assertSee('16,9%')
        ->assertSee('5,9%')
        ->assertDontSee('99,0%');                        // alleen de laatste meting per coin
});

it('toont — wanneer een munt te weinig data heeft (up_7d null)', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    seedMetric(244, 'NOS', '2025-01-03', null);

    Livewire::actingAs($admin)
        ->test(Ranking::class)
        ->assertSee('te weinig data');
});

it('weigert niet-admins', function () {
    $user = User::factory()->create(['is_admin' => false]);
    Livewire::actingAs($user)->test(Ranking::class)->assertStatus(403);
});
