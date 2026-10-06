<?php
declare(strict_types=1);

final class RevenueFilters
{
    public function __construct(
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $currency,
        public readonly string $program,
        public readonly string $status,
        public readonly string $person,
        public readonly bool $allDates = false,
    ) {}

    public static function fromRequest(array $input, ?string $latestMonth): self
    {
        $all = ($input['range'] ?? '') === 'all';
        $from = self::date($input['from'] ?? null);
        $to = self::date($input['to'] ?? null);
        if ($from !== null && $to !== null && $from > $to) { throw new InvalidArgumentException('Start date must precede end date.'); }
        if (!$all && $from === null && $to === null) {
            $month = $latestMonth ?? gmdate('Y-m-01');
            $from = $month;
            $to = (new DateTimeImmutable($month, new DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d');
        }
        $currency = strtolower(trim((string) ($input['currency'] ?? '')));
        if ($currency !== '' && !preg_match('/\A[a-z]{3}\z/D', $currency)) { throw new InvalidArgumentException('Invalid currency.'); }
        $program = trim((string) ($input['program'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));
        $person = trim((string) ($input['person'] ?? ''));
        if (mb_strlen($program) > 255 || mb_strlen($status) > 60 || mb_strlen($person) > 100) { throw new InvalidArgumentException('Filter is too long.'); }
        return new self($from, $to, $currency, $program, $status, $person, $all);
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value)) { throw new InvalidArgumentException('Invalid date.'); }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) { throw new InvalidArgumentException('Invalid date.'); }
        return $value;
    }

    public function label(): string
    {
        if ($this->from !== null && $this->to !== null) {
            if (substr($this->from, 0, 7) === substr($this->to, 0, 7) && substr($this->from, 8) === '01' && $this->to === (new DateTimeImmutable($this->from, new DateTimeZone('UTC')))->modify('last day of this month')->format('Y-m-d')) {
                return gmdate('F Y', strtotime($this->from . ' UTC')) . ' · UTC';
            }
            return $this->from . ' to ' . $this->to . ' · UTC';
        }
        if ($this->from !== null) { return 'From ' . $this->from . ' · UTC'; }
        if ($this->to !== null) { return 'Through ' . $this->to . ' · UTC'; }
        return 'All dated transactions · UTC';
    }

    public function query(): array
    {
        $result = [];
        if ($this->allDates) { $result['range'] = 'all'; }
        if ($this->from !== null) { $result['from'] = $this->from; }
        if ($this->to !== null) { $result['to'] = $this->to; }
        foreach (['currency','program','status','person'] as $key) { if ($this->$key !== '') { $result[$key] = $this->$key; } }
        return $result;
    }
}
