<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/Paths.php';
final class ConsolidatedInputs
{
    public const FILES=['groups'=>'bma_consolidated_waivers_3years.csv','payments'=>'bma_stripe_association_evidence.csv','duplicates'=>'bma_consolidated_waiver_duplicates.csv'];
    private const FIELDS=[
      'groups'=>['Group ID','Student name','Student name variants','Student birthdate(s)','Waiver count','Waiver IDs','Contact email(s)','Primary phone(s)','Address(es)','Address line 1','Address line 2','City','State','Postal code','Waiver signer(s)','Relationship(s) to student','Stripe association status','Stripe customer ID(s)'],
      'payments'=>['Group ID','Student','Payment ID','Customer ID'],
      'duplicates'=>['Group ID','Student','Waivers consolidated','Waiver IDs'],
    ];
    public static function directory(): string { return dirname(__DIR__,2).'/storage/reconciliation/input'; }
    public static function parts(?string $s): array { return array_values(array_unique(array_filter(array_map('trim',explode('|',(string)$s)),static fn($s)=>$s!==''))); }
    public static function read(string $directory,array $servedRoots=[]): array
    {
        $out=['groups'=>[],'payments'=>[],'duplicates'=>[],'hashes'=>[]];
        foreach(self::FILES as $kind=>$name){$path=realpath($directory.'/'.$name);if($path===false||!is_file($path)||!is_readable($path)||filesize($path)>20971520)throw new RuntimeException('RECONCILIATION_INPUT_UNAVAILABLE');Paths::assertPrivate($path,$servedRoots);$out['hashes'][$kind]=hash_file('sha256',$path);
            $f=fopen($path,'rb');if(fread($f,3)!=="\xEF\xBB\xBF")rewind($f);try{$header=fgetcsv($f,0,',','"','');if(!is_array($header))throw new RuntimeException('RECONCILIATION_HEADER_INVALID');$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);if(count($header)!==count(array_unique($header))||array_diff(self::FIELDS[$kind],$header)!==[])throw new RuntimeException('RECONCILIATION_HEADER_INVALID');$positions=array_flip($header);$n=0;
                while(($row=fgetcsv($f,0,',','"',''))!==false){if($row===[null])continue;if(++$n>10000||count($row)!==count($header))throw new RuntimeException('RECONCILIATION_ROW_INVALID');$approved=[];foreach(self::FIELDS[$kind] as $field)$approved[$field]=$row[$positions[$field]]??'';if(!preg_match('/\ABMA-W-[0-9]{4,}\z/D',$approved['Group ID']))throw new RuntimeException('RECONCILIATION_GROUP_INVALID');$out[$kind][]=$approved;}
            }finally{fclose($f);}
        }
        return $out;
    }
    public static function supplemental(array $groups,string $directory,array $roots,bool $emergency,bool $medical=false): array
    {
        if(!$emergency&&!$medical)return [];
        $path=realpath($directory.'/'.self::FILES['groups']);if($path===false)return [];Paths::assertPrivate($path,$roots);if(filesize($path)>20971520)throw new RuntimeException('RECONCILIATION_INPUT_UNAVAILABLE');$f=fopen($path,'rb');if(fread($f,3)!=="\xEF\xBB\xBF")rewind($f);$out=[];
        try{$headers=fgetcsv($f,0,',','"','');if(!is_array($headers))return [];$headers[0]=preg_replace('/^\xEF\xBB\xBF/','',$headers[0]);$index=array_flip($headers);if(!isset($index['Group ID'],$index['Waiver IDs']))return [];
            while(($row=fgetcsv($f,0,',','"',''))!==false){$id=$row[$index['Group ID']]??'';if(!isset($groups[$id]))continue;$ids=self::parts($row[$index['Waiver IDs']]??'');if(array_diff($ids,$groups[$id])!==[])continue;
                $fields=['Group ID'];if($medical)$fields=array_merge($fields,['Goals (all unique)','Considerations (all unique)','Student name','Waiver signer(s)','Relationship(s) to student','Stripe association status','Stripe customer ID(s)']);if($emergency)$fields=array_merge($fields,['Emergency contact(s)','Emergency phone(s)']);$approved=[];foreach($fields as $field)if(isset($index[$field]))$approved[$field]=$row[$index[$field]]??'';$out[]=$approved;
            }
        }finally{fclose($f);}return $out;
    }

}
