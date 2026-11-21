<?php

/**
 * Minimal WP_CLI stub for PHPStan — the real class only exists at runtime
 * when a site is actually running under `wp` (see Bootstrap::lateInit()'s
 * `defined('WP_CLI') && WP_CLI` guard). Not a full wp-cli/wp-cli dependency,
 * just enough surface for phpstan-wordpress to resolve the calls we make.
 */
class WP_CLI
{
    /** @param string|\Throwable $message */
    public static function error($message, bool $exit = true): void {}

    public static function log(string $message): void {}

    public static function warning(string $message): void {}

    public static function success(string $message): void {}

    /** @param array<string, mixed> $assoc_args Skips the question with --yes. */
    public static function confirm(string $question, array $assoc_args = []): void {}

    public static function halt(int $returnCode): never
    {
        exit($returnCode);
    }

    public static function colorize(string $string): string
    {
        return $string;
    }

    public static function add_command(string $name, callable|object $callable, array $args = []): void {}
}
