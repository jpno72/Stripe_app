<?php
declare(strict_types=1);

/** Trusted deployment paths only; HTTP_* identity/path headers are never inputs. */
final class Paths
{
    public static function deploymentValue(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value) && trim($value, " \t\n\r\v\f") !== '') return $value;
        $value = $_SERVER[$name] ?? null;
        return is_string($value) && trim($value, " \t\n\r\v\f") !== '' ? $value : null;
    }

    public static function isAbsolute(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0")
            && (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1 || str_starts_with($path, '\\\\'));
    }

    public static function directory(string $path): string
    {
        if (!self::isAbsolute($path)) throw new RuntimeException('Absolute deployment directory required.');
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !is_readable($real)) throw new RuntimeException('Deployment directory unavailable.');
        return $real;
    }

    /** All applicable roots are checked, not just the first configured root. */
    public static function servedRoots(array $config = [], bool $required = true): array
    {
        $configured = $config['PUBLIC_WEB_ROOT'] ?? self::deploymentValue('PUBLIC_WEB_ROOT');
        $candidates = [$configured, $_SERVER['DOCUMENT_ROOT'] ?? null];
        if (defined('BMA_PUBLIC_ROOT')) $candidates[] = BMA_PUBLIC_ROOT;
        $roots = [];
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') continue;
            if (!is_string($candidate)) throw new RuntimeException('Invalid public root.');
            $roots[] = self::directory($candidate);
        }
        $roots = array_values(array_unique($roots));
        if ($required && !$roots) throw new RuntimeException('Actual public web root required.');
        return $roots;
    }

    private static function comparable(string $canonical): string
    {
        $path = rtrim(str_replace('\\', '/', $canonical), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /** Callers supply canonical files/directories (or a canonical parent + leaf). */
    public static function assertPrivate(string $canonical, array $roots): void
    {
        $path = self::comparable($canonical);
        foreach ($roots as $root) {
            $web = self::comparable($root);
            if ($path === $web || str_starts_with($path . '/', $web . '/')) throw new RuntimeException('Path must be outside served web roots.');
        }
    }

    public static function privateWritableDirectory(string $path, array $config): string
    {
        $directory = self::directory($path);
        self::assertPrivate($directory, self::servedRoots($config));
        if (!is_writable($directory)) throw new RuntimeException('Private directory is not writable.');
        return $directory;
    }
}
