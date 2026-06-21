<?php

namespace App\Console\Commands;

use App\Services\AutoOkLabeler;
use Illuminate\Console\Command;

/**
 * Auto-ok: zet promising momenten met een sterk sell-resultaat automatisch op decision='yes' zodat je
 * niet elk moment handmatig hoeft te tikken. Werkt op coin_moment_sells (= de promising-universe waar de
 * sell-engine over draaide).
 *
 * VEILIGHEID (hard):
 *  - Slaat ELK moment over dat al een handmatig label heeft — overschrijft NOOIT jouw ok/niet-ok.
 *  - Schrijft set_by='auto-ok' zodat je het in één klap kunt terugdraaien:
 *      DELETE FROM coin_moment_labels WHERE set_by='auto-ok';
 *  - Default = DRY-RUN. Pas --run toe om echt te schrijven.
 *
 * Drempels uit de mark-analyse (DOGEAI, 8 dagen / 160 ok-marks): sell>=5% + min>=15 bootst ~61% van je
 * oordeel na met 1 conflict op 28 niet-ok-marks. De 15-min-grens weert de snelle pumps (sell in <15 min)
 * die je in de praktijk niet pakt; de cluster-eis bleek geen veiligheid toe te voegen en is weggelaten.
 */
class AutoOkLabels extends Command
{
    protected $signature = 'trades:auto-ok
        {coin=2525 : trading_symbol_id}
        {--sell=5 : minimale onze-sell-winst %}
        {--min=15 : minimale minuten-in-trade (weert snelle pumps)}
        {--from= : begindatum Y-m-d (optioneel)}
        {--to= : einddatum Y-m-d (optioneel)}
        {--run : daadwerkelijk schrijven (anders dry-run)}';

    protected $description = 'Zet sterke promising momenten (sell>=X%, >=M min) automatisch op ok — vult alleen lege momenten.';

    public function handle(AutoOkLabeler $labeler): int
    {
        $coin = $this->argument('coin');
        $sellMin = (float) $this->option('sell');
        $minMin = (int) $this->option('min');
        $run = (bool) $this->option('run');
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;

        $p = $labeler->preview($coin, $sellMin, $minMin, $from, $to);
        if ($p['candidates'] === 0) {
            $this->warn("Geen kandidaten (coin {$coin}, sell>={$sellMin}%, min>={$minMin}). Draaide sell_promising al voor deze coin?");
            return self::SUCCESS;
        }

        $this->info(sprintf('coin %s · regel: promising + sell>=%.0f%% + min>=%d', $coin, $sellMin, $minMin));
        $this->line(sprintf('  kandidaten:        %d', $p['candidates']));
        $this->line(sprintf('  al gelabeld (skip): %d  (ok:%d, niet-ok:%d, overig:%d)',
            $p['skipYes'] + $p['conflicts'] + $p['skipOther'], $p['skipYes'], $p['conflicts'], $p['skipOther']));
        $this->line(sprintf('  NIEUW op ok zetten: %d', $p['toMark']->count()));

        foreach ($p['toMark']->take(8) as $s) {
            $this->line(sprintf('    %s  sell=%.2f%%  %dmin', $s->datetime->format('Y-m-d H:i:s'), $s->profit_loss, $s->minutes_in_trade));
        }
        if ($p['toMark']->count() > 8) $this->line(sprintf('    … +%d meer', $p['toMark']->count() - 8));

        if (! $run) {
            $this->newLine();
            $this->comment('DRY-RUN — niets geschreven. Pas --run toe om te schrijven.');
            $this->comment('Terugdraaien na --run:  DELETE FROM coin_moment_labels WHERE set_by=\'auto-ok\';');
            return self::SUCCESS;
        }

        $written = $labeler->apply($coin, $sellMin, $minMin, '', $from, $to);
        $this->info("Geschreven: {$written} ok-labels (set_by='auto-ok').");
        return self::SUCCESS;
    }
}
