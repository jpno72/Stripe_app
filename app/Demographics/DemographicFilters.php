<?php
declare(strict_types=1);
require_once __DIR__.'/AgeBrackets.php';
final class DemographicFilters
{
    public function __construct(public readonly string $status='',public readonly string $program='',public readonly string $age='',public readonly string $city='',public readonly string $zip='',public readonly ?string $from=null,public readonly ?string $to=null) {}
    public static function fromRequest(array $input,AgeBrackets $brackets): self
    {
        $v=[];foreach(['status','program','age','city','zip'] as $key){$value=$input[$key]??'';if(!is_string($value)||mb_strlen($value)>255)throw new InvalidArgumentException('Invalid demographic filter.');$v[$key]=trim($value);}
        if(!in_array($v['status'],['','active','inactive','unknown'],true))throw new InvalidArgumentException('Invalid member status filter.');
        if($v['age']!==''&&$v['age']!=='unknown'&&$brackets->find($v['age'])===null)throw new InvalidArgumentException('Invalid age bracket.');
        $from=self::date($input['from']??null);$to=self::date($input['to']??null);if($from!==null&&$to!==null&&$from>$to)throw new InvalidArgumentException('Cohort start must precede cohort end.');
        return new self($v['status'],$v['program'],$v['age'],$v['city'],mb_strtoupper($v['zip']),$from,$to);
    }
    private static function date(mixed $value): ?string
    {
        if($value===null||$value==='')return null;
        if(!is_string($value)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$value))throw new InvalidArgumentException('Invalid cohort date.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Invalid cohort date.');return $value;
    }
    public function query(): array {return ['status'=>$this->status,'program'=>$this->program,'age'=>$this->age,'city'=>$this->city,'zip'=>$this->zip,'from'=>$this->from??'','to'=>$this->to??''];}
}
