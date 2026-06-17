<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A hand (or imported-legacy) label on one buy-MOMENT, keyed by (coin, datetime, rule, source).
 * Stored apart from coin_fires so it survives the persist_to_brain re-fire (which deletes fires).
 *
 * Daan's own labels (source='manual') are MOMENT-level: "was this datetime a good entry?" is
 * rule-independent (it feeds the per-datetime promising tuning), so they use rule=MOMENT_RULE (0).
 * Imported legacy labels (source='legacy') stay per-rule (a legacy trade had a rule) as reference.
 *
 * decision      = legacy ok_trade: yes / no / no_volume
 * manual_klasse = buy-moment quality override (goed/middel/slecht) on CoinFire::klasseKey()
 */
class CoinMomentLabel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'datetime' => 'datetime',
        'set_at' => 'datetime',
        'manual_set_at' => 'datetime',
        'best_sell_datetime' => 'datetime',
        'hard_sell_datetime' => 'datetime',
    ];

    public const DECISIONS = ['yes', 'no', 'no_volume'];
    public const KLASSES = ['goed', 'middel', 'slecht'];
    /** Manual labels are moment-level (rule-independent) — stored under this sentinel rule. */
    public const MOMENT_RULE = 0;

    /** Map a legacy result (1/2/3) to a klasse. */
    public static function klasseFromLegacy(?int $result): ?string
    {
        return [1 => 'goed', 2 => 'middel', 3 => 'slecht'][$result] ?? null;
    }

    /** The moment key screens use to attach labels in bulk: "Y-m-d H:i:s" (rule-independent). */
    public static function momentKey(\DateTimeInterface $dt): string
    {
        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Attach the manual (moment-level) labels to a fires collection in bulk, so CoinFire::klasseKey()
     * applies the override without an N+1. A label belongs to a (coin, datetime) MOMENT — every fire
     * on that datetime (executed or shadow, any rule) gets the same label.
     */
    public static function attachManual(Collection $fires, int|string $coin, $start, $end): void
    {
        if ($fires->isEmpty()) {
            return;
        }
        $labels = static::manualByMoment($coin, $start, $end);
        foreach ($fires as $f) {
            $f->manualLabel = $labels->get(static::momentKey($f->datetime));
        }
    }

    /** The day's manual labels, keyed by moment ("Y-m-d H:i:s"). One query, reused by both screens. */
    public static function manualByMoment(int|string $coin, $start, $end): Collection
    {
        return static::query()->where('trading_symbol_id', $coin)->where('source', 'manual')
            ->whereBetween('datetime', [$start, $end])->get()
            ->keyBy(fn ($l) => static::momentKey($l->datetime));
    }

    /**
     * The day's imported legacy labels, keyed by moment. Imported datetimes are snapped to our tick
     * grid (engine align.py), so they line up with the moment rows. If two legacy labels snap to the
     * same moment, the strongest verdict wins (goed > middel > slecht) so a good moment stays visible.
     */
    public static function legacyByMoment(int|string $coin, $start, $end): Collection
    {
        $rank = ['goed' => 3, 'middel' => 2, 'slecht' => 1];
        return static::query()->where('trading_symbol_id', $coin)->where('source', 'legacy')
            ->whereBetween('datetime', [$start, $end])->get()
            ->groupBy(fn ($l) => static::momentKey($l->datetime))
            ->map(fn ($g) => $g->sortByDesc(fn ($l) => $rank[$l->manual_klasse] ?? 0)->first());
    }

    /** Attach the manual moment-label to a single fire (detail views), so klasseKey() is correct. */
    public static function attachOne(CoinFire $f): ?self
    {
        $f->manualLabel = static::query()->where('trading_symbol_id', $f->trading_symbol_id)
            ->where('datetime', $f->datetime)->where('source', 'manual')->first();
        return $f->manualLabel;
    }

    /** Write/clear a moment-level manual label (rule=MOMENT_RULE). Returns the row, or null if cleared. */
    public static function setManual(int|string $coin, ?string $symbol, \DateTimeInterface $dt, array $fields, ?string $by = null): ?self
    {
        $key = ['trading_symbol_id' => $coin, 'datetime' => $dt, 'rule' => self::MOMENT_RULE, 'source' => 'manual'];
        $hasAny = ($fields['decision'] ?? null) || ($fields['manual_klasse'] ?? null)
            || ($fields['category'] ?? null) || trim((string) ($fields['comment'] ?? ''))
            || ($fields['best_sell_datetime'] ?? null) || ($fields['hard_sell_datetime'] ?? null);
        if (! $hasAny) {
            static::query()->where($key)->delete();   // intrekken i.p.v. een lege rij
            return null;
        }
        return static::updateOrCreate($key, array_merge($fields, [
            'symbol' => $symbol, 'set_by' => $by, 'set_at' => now(),
        ]));
    }
}
