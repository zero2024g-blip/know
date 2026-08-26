<?php

/**
 * Re-apply the PHP 8.4/8.5 compatibility patch to the vendored framework.
 *
 * Run this after any `composer update`, which restores vendor/ from the
 * package and drops the edit:
 *
 *     php tools/patch-php85.php
 *
 * Exit codes: 0 patched or already patched, 1 nothing to do (file changed
 * upstream - stop and look), 2 file missing.
 *
 * WHY
 * PHP 8.4 added a native DateTime::createFromTimestamp(int|float): static.
 * CodeIgniter 4.1.5's Time extends DateTime and declares the same method with
 * a narrower `int` parameter and no return type. PHP treats that as an
 * incompatible override and raises a fatal error while loading the class, so
 * every request that touches Time fails - including login. Production mode
 * cannot mask a fatal.
 *
 * The replacement matches what upstream shipped in later versions. Every
 * existing caller passes an int, so nothing else changes.
 *
 * This is a stopgap. The real fix is upgrading the framework; see
 * DEPLOY.md, section "PHP version".
 */

const TARGET = __DIR__ . '/../vendor/codeigniter4/framework/system/I18n/Time.php';

const NEEDLE = <<<'PHP_OLD'
    public static function createFromTimestamp(int $timestamp, $timezone = null, ?string $locale = null)
    {
        return new self(gmdate('Y-m-d H:i:s', $timestamp), $timezone ?? 'UTC', $locale);
    }
PHP_OLD;

const REPLACEMENT = <<<'PHP_NEW'
    /**
     * LOCAL PATCH - php85-time-signature - re-apply with tools/patch-php85.php
     * Widened for PHP 8.4+, which added a native DateTime::createFromTimestamp.
     */
    public static function createFromTimestamp(float|int $timestamp, $timezone = null, ?string $locale = null): static
    {
        return new static(gmdate('Y-m-d H:i:s', (int) $timestamp), $timezone ?? 'UTC', $locale);
    }
PHP_NEW;

if (! is_file(TARGET)) {
    fwrite(STDERR, "not found: " . TARGET . "\n");
    exit(2);
}

$source = file_get_contents(TARGET);

if (str_contains($source, 'php85-time-signature')) {
    echo "already patched\n";
    exit(0);
}

if (! str_contains($source, NEEDLE)) {
    fwrite(STDERR, "the expected code is not in Time.php.\n");
    fwrite(STDERR, "the framework version probably changed - check whether this patch is still needed.\n");
    exit(1);
}

file_put_contents(TARGET, str_replace(NEEDLE, REPLACEMENT, $source));

// Never leave behind a file that will not compile.
exec('php -l ' . escapeshellarg(TARGET) . ' 2>&1', $out, $rc);
if ($rc !== 0) {
    file_put_contents(TARGET, $source);
    fwrite(STDERR, "patch produced invalid PHP, reverted:\n" . implode("\n", $out) . "\n");
    exit(1);
}

echo "patched " . realpath(TARGET) . "\n";
exit(0);
