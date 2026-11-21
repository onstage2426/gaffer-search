<?php

declare(strict_types=1);

namespace GafferSearch\Support;

/**
 * What the server's PHP needs for Fuzor beyond the PHP version WordPress already checks:
 * a 64-bit build, mbstring, and pdo_sqlite linked against an SQLite that knows STRICT tables,
 * RETURNING and the rest Fuzor uses. An older SQLite fails on the first CREATE TABLE with a bare "near "STRICT": syntax
 * error", so activation, the dashboard and every build check this first.
 *
 * WP-cron from a system crontab, WP-CLI and the web server may each run a different PHP binary:
 * the check is about the PHP running it.
 *
 * @internal Not part of the public API (see API.md): may change in any version.
 */
final class Requirements
{
    /** The minimum of onstage2426/fuzor. */
    public const string SQLITE_MIN = \Fuzor\Index::MIN_SQLITE_VERSION;

    /** @var list<string>|null */
    private static ?array $problems = null;

    /**
     * What this PHP lacks, one sentence each.
     *
     * @return list<string> Empty when the requirements are met.
     */
    public static function problems(): array
    {
        return self::$problems ??= [...self::platformProblems(), ...self::problemsFor(self::sqliteVersion())];
    }

    /**
     * Fuzor refuses a 32-bit PHP (its facet value IDs are 64-bit) and needs mbstring.
     *
     * @return list<string>
     */
    private static function platformProblems(): array
    {
        $problems = [];
        if (PHP_INT_SIZE < 8) {
            $problems[] = 'Gaffer Search needs a 64-bit PHP; this server\'s PHP (' . self::php() . ') is 32-bit.';
        }
        if (!extension_loaded('mbstring')) {
            $problems[] = 'Gaffer Search needs the mbstring PHP extension, which this server\'s PHP (' . self::php() . ') does not have.';
        }
        return $problems;
    }

    /**
     * @param  string|null  $sqliteVersion The SQLite version pdo_sqlite links, null without pdo_sqlite.
     * @return list<string>
     */
    public static function problemsFor(?string $sqliteVersion): array
    {
        if ($sqliteVersion === null) {
            return ['Gaffer Search needs the pdo_sqlite PHP extension, which this server\'s PHP (' . self::php() . ') does not have.'];
        }
        if (version_compare($sqliteVersion, self::SQLITE_MIN, '<')) {
            return ['Gaffer Search needs SQLite ' . self::SQLITE_MIN . ' or newer; this server\'s PHP (' . self::php() . ') uses SQLite ' . $sqliteVersion . '. Use a PHP build linked against a newer SQLite.'];
        }
        return [];
    }

    /** @throws \RuntimeException When a requirement is missing. */
    public static function assertMet(): void
    {
        $problems = self::problems();
        if ($problems !== []) {
            throw new \RuntimeException(implode(' ', $problems)); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    /** Which PHP runs this: the web server's and the one of a system crontab or WP-CLI can differ. */
    private static function php(): string
    {
        return PHP_VERSION . ', ' . PHP_BINARY;
    }

    /** The SQLite version pdo_sqlite is linked against; null without pdo_sqlite. */
    public static function sqliteVersion(): ?string
    {
        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }
        try {
            $statement = (new \PDO('sqlite::memory:'))->query('SELECT sqlite_version()'); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite, not the WordPress database
            $version   = $statement === false ? false : $statement->fetchColumn();
        } catch (\PDOException) {
            return null;
        }
        return is_string($version) ? $version : null;
    }
}
