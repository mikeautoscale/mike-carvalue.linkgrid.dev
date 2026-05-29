<?php

declare(strict_types=1);

namespace CarValue;

/**
 * Parses a free-text "Year Make Model" string (e.g. "2015 Toyota Camry")
 * into its components. Make matching is data-driven: the longest known make
 * (uppercased) that prefixes the remaining text wins, so multi-word makes like
 * "Land Rover" or "Mercedes-Benz" are handled. The remainder is the model.
 */
final class YmmParser
{
    /** @var string[] known make keys (uppercase), longest first */
    private array $makeKeys;

    /** @param string[] $makeKeys uppercase make keys from the data set */
    public function __construct(array $makeKeys)
    {
        usort($makeKeys, static fn($a, $b) => strlen($b) <=> strlen($a));
        $this->makeKeys = $makeKeys;
    }

    /**
     * @return array{year:int,make:string,makeKey:string,model:string,modelKey:string}|null
     *         null when a year + make + model cannot be identified.
     */
    public function parse(string $input): ?array
    {
        $s = trim(preg_replace('/\s+/', ' ', $input));
        if ($s === '') {
            return null;
        }

        // First standalone 1900–2099 token is the model year.
        if (!preg_match('/(?:^|\s)((?:19|20)\d{2})(?=\s|$)/', $s, $m)) {
            return null;
        }
        $year = (int) $m[1];

        // Drop that year token; remainder is "make model".
        $rest = trim(preg_replace('/(?:^|\s)' . preg_quote($m[1], '/') . '(?=\s|$)/', ' ', $s, 1));
        if ($rest === '') {
            return null;
        }

        $restUpper = mb_strtoupper($rest);

        foreach ($this->makeKeys as $makeKey) {
            if ($makeKey === '') {
                continue;
            }
            // Exact "make only" with no model -> not enough to estimate.
            if ($restUpper === $makeKey) {
                return null;
            }
            if (strncmp($restUpper, $makeKey . ' ', strlen($makeKey) + 1) === 0) {
                $make  = substr($rest, 0, strlen($makeKey));
                $model = trim(substr($rest, strlen($makeKey) + 1));
                if ($model === '') {
                    return null;
                }
                return $this->result($year, $make, $makeKey, $model);
            }
        }

        // Fallback: first token is the make, the rest is the model.
        $parts = explode(' ', $rest, 2);
        if (count($parts) < 2 || $parts[1] === '') {
            return null;
        }
        return $this->result($year, $parts[0], mb_strtoupper($parts[0]), $parts[1]);
    }

    private function result(int $year, string $make, string $makeKey, string $model): array
    {
        return [
            'year'     => $year,
            'make'     => $make,
            'makeKey'  => $makeKey,
            'model'    => $model,
            'modelKey' => mb_strtoupper($model),
        ];
    }
}
