<?php
declare(strict_types=1);
final class MembershipFilters
{
    public function __construct(public readonly string $program='', public readonly string $memberStatus='', public readonly string $subscriptionStatus='') {}
    public static function fromRequest(array $input): self
    {
        $values=[];
        foreach (['program','member_status','subscription_status'] as $key) {
            $value=$input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value)>255) { throw new InvalidArgumentException('Invalid membership filter.'); }
            $values[]=trim($value);
        }
        return new self(...$values);
    }
    public function query(): array { return ['program'=>$this->program,'member_status'=>$this->memberStatus,'subscription_status'=>$this->subscriptionStatus]; }
}
