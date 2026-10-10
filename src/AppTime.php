<?php
declare(strict_types=1);

/** Display/calendar policy only. Source parsers and database sessions remain UTC. */
final class AppTime
{
    private static string $timezone = 'America/New_York';

    public static function configure(array $config): void
    {
        $name = $config['APP_DISPLAY_TIMEZONE'] ?? 'America/New_York';
        if (!is_string($name) || !in_array($name, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Invalid display timezone.');
        }
        self::$timezone = $name;
    }
    public static function zone(): DateTimeZone { return new DateTimeZone(self::$timezone); }
    public static function name(): string { return self::$timezone; }
    public static function now(): DateTimeImmutable { return new DateTimeImmutable('now', self::zone()); }
    public static function today(): string { return self::now()->format('Y-m-d'); }

    /** Call only for database timestamps known to represent UTC instants. */
    public static function utc(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))) return $date;
        }
        return null;
    }
    public static function display(?string $utc, string $missing = 'Unknown'): string
    {
        return self::utc($utc)?->setTimezone(self::zone())->format('M j, Y, g:i A T') ?? $missing;
    }
    public static function boundary(string $localDate, bool $nextDay = false): string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $localDate, self::zone());
        if (!$day || $day->format('Y-m-d') !== $localDate) throw new InvalidArgumentException('Invalid calendar date.');
        if ($nextDay) $day = $day->modify('+1 day');
        return $day->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    /** Trusted date literal for existing calendar-only queries; never uses request input. */
    public static function calendarSql(string $sql): string
    {
        return str_replace('CURRENT_DATE()', "CAST('" . self::today() . "' AS DATE)", $sql);
    }
    /** Local wall-clock SQL without depending on MariaDB named-timezone tables.
     * UTC range predicates remain on the indexed timestamp column.
     * Column names are internal constants. Transition boundaries are prepared parameters.
     */
    public static function localSql(string $column, string $minimum, string $maximum, array &$params): string
    {
        if (!preg_match('/\A[a-z_]+\.[a-z_]+\z/D', $column)) throw new InvalidArgumentException('Invalid timestamp column.');
        $start = self::utc($minimum); $end = self::utc($maximum);
        if (!$start || !$end) throw new RuntimeException('Invalid normalized timestamp range.');
        $transitions = self::zone()->getTransitions($start->getTimestamp(), $end->getTimestamp() + 1);
        $offset = $transitions[0]['offset']; $cases = [];
        foreach (array_slice($transitions, 1) as $transition) {
            $params[] = gmdate('Y-m-d H:i:s', $transition['ts']);
            $cases[] = "WHEN $column < ? THEN DATE_ADD($column, INTERVAL " . (int)$offset . ' SECOND)';
            $offset = $transition['offset'];
        }
        $last = "DATE_ADD($column, INTERVAL " . (int)$offset . ' SECOND)';
        return $cases ? 'CASE ' . implode(' ', $cases) . ' ELSE ' . $last . ' END' : $last;
    }
}
