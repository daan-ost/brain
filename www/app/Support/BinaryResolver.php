<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Lost externe binaries op naar een absoluut pad.
 *
 * Onder MAMP/php-fpm bevat de PATH vaak niet /opt/homebrew/bin (en soms zelfs niet
 * /usr/local/bin). Daardoor faalt exec()/shell_exec() van een binary op bare naam
 * stilzwijgend in webserver-context (return != 0, geen output, geen exception),
 * terwijl het in de CLI prima werkt. Door het pad absoluut op te lossen voorkomen we
 * die silent failures.
 *
 * Bron: docs/propagation/2026-05-27-inbox-matcher-improvements.md (#4).
 *
 * Beperking: onder een actieve open_basedir-restrictie geeft is_executable() false
 * voor paden buiten de basedir, ook al kan de shell de binary wél draaien. Op zulke
 * hosts geeft resolve() null en valt de call-site terug op zijn native fallback. Zet
 * in dat geval een expliciete env-override binnen de basedir of verruim open_basedir.
 */
class BinaryResolver
{
    /**
     * Standaard zoekpaden, in volgorde van voorkeur.
     *
     * @var list<string>
     */
    private const SEARCH_PATHS = [
        '/opt/homebrew/bin',
        '/usr/local/bin',
        '/usr/bin',
        '/bin',
        '/usr/sbin',
        '/sbin',
    ];

    /**
     * Geef het absolute pad naar $binary, of null als de binary niet gevonden is.
     *
     * @param  string|null  $configKey  Optionele config-sleutel met een expliciet pad-override,
     *                                   bijv. 'services.binaries.dig'.
     */
    public static function resolve(string $binary, ?string $configKey = null): ?string
    {
        // Expliciete config/env-override heeft voorrang.
        $override = $configKey !== null ? config($configKey) : null;
        if (is_string($override) && $override !== '') {
            // @ dempt een E_WARNING wanneer open_basedir actief is.
            if (@is_executable($override)) {
                return $override;
            }

            // Niet stilzwijgend negeren: een gezette-maar-onbruikbare override is een misconfig.
            Log::debug("BinaryResolver: override voor '{$binary}' is niet uitvoerbaar, val terug op zoekpaden.", [
                'config_key' => $configKey,
                'override'   => $override,
            ]);
        }

        foreach (static::SEARCH_PATHS as $dir) {
            $path = $dir.'/'.$binary;
            if (@is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
