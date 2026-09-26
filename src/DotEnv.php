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

    /**
     * @return array<string, array{0: string, 1: string}> MNFST_KEY and MNFST_URL,
     *         when a file sets them: the value, and the file it came from
     */
    public static function read(string $root): array
    {
        $found = [];
        foreach (self::FILES as $file) {
            foreach (self::variables($root . '/' . $file) as $name => $value) {
                $found[$name] ??= [$value, $file];
            }
        }

        return $found;
    }

    /**
     * The file's Manifest variables, read one line at a time so the file's
     * other values are never held. A later line wins within one file.
     *
     * @return array<string, string>
     */
    private static function variables(string $path): array
    {
        $handle = is_file($path) ? @fopen($path, 'r') : false;
        if ($handle === false) {
            return [];
        }
        $inFile = [];
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\s*(?:export\s+)?(MNFST_KEY|MNFST_URL)\s*=(.*)$/', rtrim($line, "\r\n"), $m) === 1) {
                $inFile[$m[1]] = self::value($m[2]);
            }
        }
        fclose($handle);

        return array_filter($inFile, static fn (string $value): bool => $value !== '');
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
