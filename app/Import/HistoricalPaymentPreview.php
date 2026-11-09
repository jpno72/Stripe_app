<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/** Read-only source coverage. No ImportService batches, financial writes or inferred identifiers. */
final class HistoricalPaymentPreview
{
    public const FILENAME='unified_payments(25).csv';
    public function __construct(private readonly PDO $db) {}
    public static function read(string $directory,array $roots=[]): ?array
    {
        $path=realpath($directory.'/'.self::FILENAME);if($path===false)return null;
        Paths::assertPrivate($path,$roots);if(!is_file($path)||!is_readable($path)||filesize($path)>20971520)throw new RuntimeException('HISTORICAL_SOURCE_UNAVAILABLE');
        $f=fopen($path,'rb');$out=['hash'=>hash_file('sha256',$path),'records'=>[],'rows'=>0,'invalid_rows'=>0,'modes'=>[]];
        try{if(fread($f,3)!=="\xEF\xBB\xBF")rewind($f);$headers=fgetcsv($f,0,',','"','');
            if(!is_array($headers)||count($headers)!==count(array_unique($headers))||array_diff(['id','PaymentIntent ID','Amount','Currency','Status','Created date (UTC)'],$headers)!==[])throw new RuntimeException('HISTORICAL_HEADERS_INVALID');$mode=array_search('Mode',$headers,true);$intentIndex=array_search('PaymentIntent ID',$headers,true);
            while(($row=fgetcsv($f,0,',','"',''))!==false){if($row===[null])continue;if(++$out['rows']>20000)throw new RuntimeException('HISTORICAL_SOURCE_LIMIT');
                if(count($row)!==count($headers)){++$out['invalid_rows'];continue;}
                $sourceMode=$mode===false?'unverified':strtolower(trim($row[$mode]));if(!in_array($sourceMode,['live','test'],true))$sourceMode='unverified';$out['modes'][$sourceMode]=true;
                try{$intent=Normalizers::stripeId($row[$intentIndex],'pi_');$out['records'][]=['record'=>ApprovedRecord::fromRow('stripe_transactions',$headers,$row),'payment_intent_id'=>$intent];}catch(Throwable){++$out['invalid_rows'];}
            }
        }finally{fclose($f);}return $out;
    }
    public function preview(array $references,?array $source): array
    {
        $refs=array_fill_keys(array_unique($references),true);$existing=$this->db->query('SELECT t.*,c.stripe_customer_id FROM stripe_transactions t LEFT JOIN stripe_customers c ON c.id=t.customer_id')->fetchAll();$charges=[];$intents=[];
        foreach($existing as $r){$charges[$r['stripe_charge_id']]=$r;if($r['stripe_payment_intent_id']!==null)$intents[$r['stripe_payment_intent_id']][]=$r;}
        $raw=[];$q=$this->db->query("SELECT source_record_id,JSON_UNQUOTE(JSON_EXTRACT(raw_values,'$.\"PaymentIntent ID\"')) intent FROM raw_stripe_transactions WHERE JSON_EXTRACT(raw_values,'$.\"PaymentIntent ID\"') IS NOT NULL");
        foreach($q as $r)if(isset($refs[$r['intent']]))$raw[$r['intent']][$r['source_record_id']??'']=true;
        $rawCharges=[];foreach($this->db->query('SELECT source_record_id FROM raw_stripe_transactions WHERE source_record_id IS NOT NULL') as $r)$rawCharges[$r['source_record_id']]=true;
        $summary=['historical_source_available'=>(int)($source!==null),'historical_rows_read'=>$source['rows']??0,'historical_invalid_rows'=>$source['invalid_rows']??0,'historical_identifiable_transactions'=>0,'historical_attempts_without_charge'=>0,'historical_duplicate_rows'=>0,'historical_conflicting_records'=>0,'historical_existing_charge_records'=>0,'historical_identifier_enrichments_proposed'=>0,'historical_transactions_available_for_backfill'=>0,'referenced_historical_payments_available_for_backfill'=>0,'payment_identifiers_found_in_existing_storage'=>0,'referenced_identifiers_in_raw_storage'=>count($raw),'historical_charges_present_in_staging_only'=>0,'referenced_payments_present_in_staging_only'=>0,'referenced_existing_charges_resolved_by_export'=>0,'remaining_missing_payments'=>0,'historical_environment_conflict'=>0,'historical_environment_unverified'=>0];
        $context=$this->db->query('SELECT stripe_livemode FROM stripe_source_identity WHERE id=1')->fetchColumn();$modes=array_keys($source['modes']??[]);
        if($source!==null){if(count($modes)!==1||$modes[0]==='unverified'||$context===null||$context===false)$summary['historical_environment_unverified']=1;elseif(($modes[0]==='live'?1:0)!==(int)$context)$summary['historical_environment_conflict']=1;}
        $catalog=[];$intentCharges=[];$blocked=[];$importer=new StripeTransactionImporter();
        foreach($source['records']??[] as $item){$record=$item['record'];$v=$record->values();$charge=null;
            if(StripeTransactionImporter::blankId($v['id'])){++$summary['historical_attempts_without_charge'];continue;}
            try{$charge=Normalizers::stripeTransactionId($v['id']);$intent=$item['payment_intent_id'];$currency=Normalizers::currency($v['Currency']);if($currency===null)throw new RuntimeException('INVALID_CURRENCY');
                $canonical=['stripe_payment_intent_id'=>$intent,'amount_minor'=>Normalizers::amount($v['Amount'],$currency),'amount_refunded_minor'=>Normalizers::amount($v['Amount Refunded'],$currency),'currency'=>$currency,'status'=>Normalizers::text($v['Status']),'captured'=>Normalizers::boolean($v['Captured']),'stripe_created_at'=>Normalizers::utc($v['Created date (UTC)']),'stripe_customer_id'=>Normalizers::stripeId($v['Customer ID'],'cus_'),'stripe_invoice_id'=>Normalizers::stripeId($v['Invoice ID'],'in_')];
                if(isset($catalog[$charge])){if($catalog[$charge]['canonical']!==$canonical)$blocked[$charge]='CONFLICTING_SOURCE_ROWS';else ++$summary['historical_duplicate_rows'];continue;}
                $catalog[$charge]=['canonical'=>$canonical,'status'=>'available'];if($intent!==null)$intentCharges[$intent][$charge]=true;
                // Reuse the existing importer validation only; dryRun does not stage, audit or create customer stubs.
                $importer->import($this->db,$record,0,true,[]);
            }catch(Throwable){++$summary['historical_invalid_rows'];if(isset($charge))$blocked[$charge]='SOURCE_VALIDATION_FAILED';continue;}
            if(isset($charges[$charge])){$old=$charges[$charge];$conflict=false;
                foreach($canonical as $field=>$value){$prior=$old[$field];if($prior===null||$value===null)continue;$a=is_string($prior)?trim($prior):$prior;$b=is_string($value)?trim($value):$value;if($field==='stripe_created_at'){$a=(new DateTimeImmutable((string)$a,new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');$b=(new DateTimeImmutable((string)$b,new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}if($field==='status'){$a=strtolower((string)$a);$b=strtolower((string)$b);}if((string)$a!==(string)$b)$conflict=true;}
                $catalog[$charge]['status']=$conflict?'conflict':($old['stripe_payment_intent_id']===null&&$intent!==null?'enrichment':'existing');
            }elseif($intent!==null&&isset($intents[$intent]))$catalog[$charge]['status']='conflict';
        }
        foreach($catalog as $charge=>&$entry){$intent=$entry['canonical']['stripe_payment_intent_id'];if(isset($blocked[$charge])||($intent!==null&&count($intentCharges[$intent])>1))$entry['status']='conflict';
            ++$summary['historical_identifiable_transactions'];$status=$entry['status'];if($status==='conflict')++$summary['historical_conflicting_records'];
            elseif(isset($charges[$charge])){++$summary['historical_existing_charge_records'];if($status==='enrichment')++$summary['historical_identifier_enrichments_proposed'];}
            elseif(!$summary['historical_environment_conflict']){++$summary['historical_transactions_available_for_backfill'];if(isset($rawCharges[$charge]))++$summary['historical_charges_present_in_staging_only'];}
        }unset($entry);
        foreach($refs as $id=>$_){$normalized=$intents[$id]??(isset($charges[$id])?[$charges[$id]]:[]);if($normalized!==[]){++$summary['payment_identifiers_found_in_existing_storage'];continue;}
            $sourceCharges=array_keys($intentCharges[$id]??(isset($catalog[$id])?[$id=>true]:[]));$usable=count($sourceCharges)===1?$sourceCharges[0]:null;
            if($usable!==null&&$catalog[$usable]['status']!=='conflict'&&!$summary['historical_environment_conflict']){
                if(isset($charges[$usable])){++$summary['referenced_existing_charges_resolved_by_export'];++$summary['payment_identifiers_found_in_existing_storage'];}
                else{++$summary['referenced_historical_payments_available_for_backfill'];if(isset($rawCharges[$usable]))++$summary['referenced_payments_present_in_staging_only'];}
            }else ++$summary['remaining_missing_payments'];
        }
        return ['summary'=>$summary,'source_hash'=>$source['hash']??null,'storage_tables_inspected'=>['stripe_transactions','raw_stripe_transactions'],'existing_transaction_count'=>count($existing)];
    }
}
