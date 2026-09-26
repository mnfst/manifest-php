<?php declare(strict_types=1);

namespace Mnfst;

/**
 * The Manifest variables a project keeps in its dotenv files. The app's
 * framework loads those files at boot; the doctor runs on its own, so it
 * reads them itself. Only MNFST_KEY and MNFST_URL, from plain `NAME=value`
 * lines: nothing is evaluated or expanded.
 */
final class DotEnv
{
    /** Symfony's .env.local overrides .env; CakePHP keeps its file in config/. An earlier file wins. */
    public const FILES = ['.env.local', '.env', 'config/.env'];

    /** @return array<string, string> MNFST_KEY and MNFST_URL, when a file sets them */
    public static function read(string $root): array
    {
        $found = [];
        foreach (self::FILES as $file) {
            $path = $root . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $inFile = [];
            foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^\s*(?:export\s+)?(MNFST_KEY|MNFST_URL)\s*=(.*)$/', $line, $m) === 1) {
                    $inFile[$m[1]] = self::value($m[2]);
                }
            }
            $found += array_filter($inFile, static fn (string $value): bool => $value !== '');
        }

        return $found;
    }

    /** A quoted value up to its closing quote; an unquoted one up to a ` #` comment. */
    private static function value(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
            $end = strpos($raw, $raw[0], 1);

            return $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
        }

        return trim(explode(' #', $raw, 2)[0]);
    }
}
