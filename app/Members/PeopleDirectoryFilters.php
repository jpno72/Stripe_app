<?php
declare(strict_types=1);
final class PeopleDirectoryFilters
{
    public static function fromRequest(array $input): array
    {
        $out=[];
        foreach(['scope','search','member_status','program','customer','subscription','subscription_status','payments','age_min','age_max','associations'] as $key){
            $v=$input[$key]??'';if(!is_string($v)||mb_strlen($v)>255)throw new InvalidArgumentException('Invalid directory filter.');$out[$key]=trim($v);
        }
        if(!in_array($out['scope'],['','people','members'],true))throw new InvalidArgumentException('Invalid directory scope.');
        foreach(['customer','subscription','payments'] as $key)if(!in_array($out[$key],['','yes','no'],true))throw new InvalidArgumentException('Invalid association filter.');
        foreach(['age_min','age_max'] as $key)if($out[$key]!==''&&(!ctype_digit($out[$key])||(int)$out[$key]>130))throw new InvalidArgumentException('Invalid age range.');
        if($out['age_min']!==''&&$out['age_max']!==''&&(int)$out['age_min']>(int)$out['age_max'])throw new InvalidArgumentException('Invalid age range.');
        if(!in_array($out['associations'],['','people','roles','multiple','review'],true))throw new InvalidArgumentException('Invalid association count filter.');
        return $out;
    }
}
