<?php
declare(strict_types=1);
final class AgeBrackets
{
    public readonly array $definitions;
    public function __construct(?array $definitions=null)
    {
        $definitions??=require dirname(__DIR__,2).'/config/demographic-age-brackets.php';
        if ($definitions===[] || count($definitions)>30) {throw new InvalidArgumentException('Invalid age bracket configuration.');}
        $next=0;$keys=[];
        foreach ($definitions as $index=>$row) {
            if (!is_array($row)||!isset($row['key'],$row['label'],$row['min'])||!array_key_exists('max',$row)||!is_string($row['key'])||!preg_match('/\A[a-z0-9_]+\z/D',$row['key'])||$row['key']==='unknown'||isset($keys[$row['key']])||!is_string($row['label'])||$row['label']===''||!is_int($row['min'])||$row['min']!==$next||($row['max']!==null&&(!is_int($row['max'])||$row['max']<$row['min']))||($row['max']===null&&$index!==count($definitions)-1)) {throw new InvalidArgumentException('Age brackets must have unique keys and contiguous nonoverlapping bounds.');}
            $keys[$row['key']]=true;$next=$row['max']===null?-1:$row['max']+1;
        }
        if ($next!==-1) {throw new InvalidArgumentException('Last age bracket must be open ended.');}
        $this->definitions=$definitions;
    }
    public function find(string $key): ?array {foreach($this->definitions as $row){if($row['key']===$key)return $row;}return null;}
    public function distribution(array $histogram): array
    {
        $buckets=[];foreach($this->definitions as $row){$buckets[$row['key']]=['label'=>$row['label'],'count'=>0];}
        $unknown=0;
        foreach($histogram as $row) {
            if($row['age_years']===null){$unknown+=(int)$row['count'];continue;}
            $age=(int)$row['age_years'];
            foreach($this->definitions as $bracket){if($age>=$bracket['min']&&($bracket['max']===null||$age<=$bracket['max'])){$buckets[$bracket['key']]['count']+=(int)$row['count'];break;}}
        }
        $result=array_values($buckets);if($unknown>0)$result[]=['label'=>'Unknown age','count'=>$unknown];return $result;
    }
    public static function median(array $histogram): ?float
    {
        $known=array_values(array_filter($histogram,fn($row)=>$row['age_years']!==null));
        usort($known,fn($a,$b)=>(int)$a['age_years']<=>(int)$b['age_years']);
        $count=array_sum(array_column($known,'count'));if($count===0)return null;
        $left=intdiv($count+1,2);$right=intdiv($count+2,2);$seen=0;$a=null;$b=null;
        foreach($known as $row){$seen+=(int)$row['count'];if($a===null&&$seen>=$left)$a=(int)$row['age_years'];if($seen>=$right){$b=(int)$row['age_years'];break;}}
        return ($a+$b)/2;
    }
}
