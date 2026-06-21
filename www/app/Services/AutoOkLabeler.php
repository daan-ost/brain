<?php

namespace App\Services;

use App\Models\CoinMomentLabel;
use App\Models\CoinMomentSell;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gedeelde auto-ok logica: selecteer sterke promising momenten (sell>=X%, >=M min in trade) en zet ze
 * op decision='yes'. Eén bron van waarheid voor zowel het artisan command (trades:auto-ok) als de
 * verificatie-tab in de Promising labeler.
 *
 * VEILIGHEID (hard): slaat elk moment met een bestaand handmatig label over — overschrijft NOOIT je
 * eigen ok/niet-ok. Schrijft set_by='auto-ok' + reden (category 'goed / top' + comment) zodat het
 * herkenbaar en in één klap terug te draaien is: DELETE FROM coin_moment_labels WHERE set_by='auto-ok'.
 */
class AutoOkLabeler
{
    public const REASON_CATEGORY = 'goed / top';

    /**
     * Dry-run: wat zou er gebeuren? Telt kandidaten, reeds-gelabelde (overgeslagen, met conflict =
     * jij zei niet-ok) en de nieuw te zetten momenten.
     *
     * @return array{candidates:int, skipYes:int, conflicts:int, skipOther:int, toMark:Collection<CoinMomentSell>}
     */
    public function preview(int|string $coin, float $sellMin, int $minMin, ?string $from = null, ?string $to = null): array
    {
        $sells = $this->candidateSells($coin, $sellMin, $minMin, $from, $to);
        $out = ['candidates' => $sells->count(), 'skipYes' => 0, 'conflicts' => 0, 'skipOther' => 0, 'toMark' => collect()];
        if ($sells->isEmpty()) {
            return $out;
        }
        $existing = CoinMomentLabel::where('trading_symbol_id', $coin)->where('source', 'manual')
            ->whereIn('datetime', $sells->pluck('datetime'))->get()
            ->keyBy(fn ($l) => CoinMomentLabel::momentKey($l->datetime));
        foreach ($sells as $s) {
            $lab = $existing->get(CoinMomentLabel::momentKey($s->datetime));
            if (! $lab) { $out['toMark']->push($s); continue; }
            match ($lab->decision) {                       // bestaand label = nooit overschrijven
                'yes' => $out['skipYes']++,
                'no'  => $out['conflicts']++,              // jij zei niet-ok — regel is het oneens
                default => $out['skipOther']++,
            };
        }
        return $out;
    }

    /** Schrijf de nieuwe ok-labels (met reden). Retourneert het aantal geschreven labels. */
    public function apply(int|string $coin, float $sellMin, int $minMin, string $reason, ?string $from = null, ?string $to = null): int
    {
        $toMark = $this->preview($coin, $sellMin, $minMin, $from, $to)['toMark'];
        if ($toMark->isEmpty()) {
            return 0;
        }
        $reason = trim($reason) ?: sprintf('sterk sell-resultaat (auto, sell>=%.0f%% / %dmin)', $sellMin, $minMin);
        return DB::transaction(function () use ($coin, $toMark, $reason) {
            $n = 0;
            foreach ($toMark as $s) {
                CoinMomentLabel::setManual($coin, $s->symbol, $s->datetime, [
                    'decision' => 'yes',
                    'category' => self::REASON_CATEGORY,
                    'comment'  => sprintf('%s — sell +%.1f%% na %d min', $reason, $s->profit_loss, $s->minutes_in_trade),
                ], 'auto-ok');
                $n++;
            }
            return $n;
        });
    }

    /** De promising momenten (coin_moment_sells = promising-universe) die aan de drempels voldoen. */
    private function candidateSells(int|string $coin, float $sellMin, int $minMin, ?string $from, ?string $to): Collection
    {
        $q = CoinMomentSell::query()->where('trading_symbol_id', $coin)
            ->where('profit_loss', '>=', $sellMin)->where('minutes_in_trade', '>=', $minMin);
        if ($from) $q->where('datetime', '>=', $from.' 00:00:00');
        if ($to) $q->where('datetime', '<=', $to.' 23:59:59');
        return $q->orderBy('datetime')->get();
    }
}
