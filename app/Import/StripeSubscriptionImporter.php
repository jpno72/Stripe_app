<?php
declare(strict_types=1);

/** Conservative CSV observations; no source timestamp proves that a changed snapshot is newer. */
final class StripeSubscriptionImporter extends AbstractImporter
{
    private const SUB_FIELDS=['customer_id','customer_name_snapshot','customer_email_snapshot','amount_minor','currency','status','coupon_reference',
        'stripe_created_at','stripe_subscription_started_at','csv_start_at','current_period_start','current_period_end','canceled_at','cancel_at_period_end','ended_at'];
    private const ITEM_FIELDS=['subscription_id','price_id','legacy_plan_id','product_id','product_name_snapshot','quantity','amount_minor','amount_basis','currency','billing_interval','billing_interval_count'];

    public function import(PDO $db,ApprovedRecord $record,int $rawId,bool $dryRun,array $options):array
    {
        // Compatibility entry point; batch imports must call group() with every row for the subscription.
        $this->parse($record); // Reject invalid external references before any lookup/write.
        if(!$db->inTransaction())throw new RuntimeException('SUBSCRIPTION_GROUP_TRANSACTION_REQUIRED');
        $result=$this->group($db,[['record'=>$record,'id'=>$rawId]],$dryRun);
        return [$result[0]['status'],$result[0]['warnings']];
    }

    public function parse(ApprovedRecord $record):array
    {
        $v=$record->values();
        foreach(['id','Customer ID','Plan','Product ID'] as $key)if($v[$key]!==null&&str_contains($v[$key],"\0"))throw new RuntimeException('INVALID_IDENTIFIER');
        $external=Normalizers::stripeId($v['id'],'sub_',true);$customer=Normalizers::stripeId($v['Customer ID'],'cus_',true);
        $plan=Normalizers::text($v['Plan']);$kind=$plan!==null&&str_starts_with($plan,'plan_')?'plan_':'price_';$plan=Normalizers::stripeId($plan,$kind,true);
        $product=Normalizers::stripeId($v['Product ID'],'prod_');$currency=Normalizers::currency($v['Currency']);
        $quantity=Normalizers::text($v['Quantity']);
        if($quantity!==null && (!preg_match('/\A[1-9][0-9]*\z/D',$quantity)||filter_var($quantity,FILTER_VALIDATE_INT)===false))throw new RuntimeException('INVALID_COUNT');
        $quantity=$quantity===null?null:(int)$quantity;
        $reported=Normalizers::text($v['Amount']);
        if($reported!==null&&!preg_match('/\A(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]+)?\z/D',$reported))throw new RuntimeException('INVALID_AMOUNT');
        $amount=$currency===null?null:Normalizers::amount($v['Amount'],$currency);
        if($amount!==null&&$amount<0)throw new RuntimeException('INVALID_AMOUNT');
        $status=strtolower(Normalizers::text($v['Status'])??'');
        if(!in_array($status,['active','trialing','past_due','canceled','unpaid','incomplete','incomplete_expired','paused'],true))throw new RuntimeException('INVALID_STATUS');
        $interval=strtolower(Normalizers::text($v['Interval'])??'');
        if($interval!==''&&!in_array($interval,['day','week','month','year','quarter','daily','weekly','monthly','yearly','annual','quarterly'],true))throw new RuntimeException('SUBSCRIPTION_GROUP_CONFLICT');
        $parent=['customer_name_snapshot'=>Normalizers::text($v['Customer Name']),'customer_email_snapshot'=>Normalizers::text($v['Customer Email']),
            'currency'=>$currency,'status'=>$status,'coupon_reference'=>Normalizers::text($v['Coupon']),
            'stripe_created_at'=>Normalizers::utc($v['Created (UTC)']),'stripe_subscription_started_at'=>Normalizers::utc($v['Start Date (UTC)']),
            'csv_start_at'=>Normalizers::utc($v['Start (UTC)']),'current_period_start'=>Normalizers::utc($v['Current Period Start (UTC)']),
            'current_period_end'=>Normalizers::utc($v['Current Period End (UTC)']),'canceled_at'=>Normalizers::utc($v['Canceled At (UTC)']),
            'cancel_at_period_end'=>Normalizers::boolean($v['Cancel At Period End']),'ended_at'=>Normalizers::utc($v['Ended At (UTC)'])];
        if($parent['current_period_start']!==null&&$parent['current_period_end']!==null&&$parent['current_period_start']>$parent['current_period_end'])throw new RuntimeException('INVALID_DATE');
        foreach(['customer_name_snapshot','customer_email_snapshot','coupon_reference'] as $key)if($parent[$key]!==null&&mb_strlen($parent[$key])>($key==='customer_email_snapshot'?254:255))throw new RuntimeException('INVALID_ROW');
        $label=Normalizers::text($v['Product']);if($label!==null&&mb_strlen($label)>255)throw new RuntimeException('INVALID_ROW');
        return ['external'=>$external,'customer'=>$customer,'plan'=>$plan,'kind'=>$kind,'product'=>$product,'parent'=>$parent,
            'locator'=>'csv-item-v1-'.hash('sha256',json_encode([$plan,$product],JSON_THROW_ON_ERROR)),
            'item'=>['product_name_snapshot'=>$label,'quantity'=>$quantity,'amount_minor'=>$amount,'amount_basis'=>'csv_unresolved',
                'currency'=>$currency,'billing_interval'=>$interval===''?null:$interval,'billing_interval_count'=>null],
            'warnings'=>array_values(array_filter([Normalizers::text($v['Amount'])!==null?'SUBSCRIPTION_AMOUNT_UNRESOLVED':null,
                Normalizers::text($v['Amount'])!==null&&$currency===null?'CURRENCY_UNRESOLVED':null]))];
    }

    /** All approved rows for one sub_; caller owns transaction/rollback, including dry runs. */
    public function group(PDO $db,array $rows,bool $dryRun):array
    {
        if(!$db->inTransaction())throw new RuntimeException('SUBSCRIPTION_GROUP_TRANSACTION_REQUIRED');
        $parsed=[];$items=[];$duplicates=[];$shared=null;
        foreach($rows as $index=>$row){
            $p=$this->parse($row['record']);$comparison=[$p['external'],$p['customer'],$p['parent']];
            if($shared!==null&&$shared!==$comparison)throw new RuntimeException('SUBSCRIPTION_GROUP_CONFLICT');$shared=$comparison;
            if(isset($items[$p['locator']])){
                $prior=$items[$p['locator']];
                if(!hash_equals($rows[$prior]['record']->contentHash(),$row['record']->contentHash()))throw new RuntimeException('SUBSCRIPTION_ITEM_AMBIGUOUS');
                $duplicates[$index]=true;
            }else $items[$p['locator']]=$index;
            $parsed[$index]=$p;
        }
        $first=$parsed[array_key_first($parsed)];$q=$db->prepare('SELECT * FROM stripe_subscriptions WHERE stripe_subscription_id=? FOR UPDATE');$q->execute([$first['external']]);$existing=$q->fetch()?:null;
        if($existing){
            $q=$db->prepare('SELECT stripe_customer_id FROM stripe_customers WHERE id=?');$q->execute([$existing['customer_id']]);
            if($q->fetchColumn()!==$first['customer'])throw new RuntimeException('STRIPE_REFERENCE_CONFLICT');
            if($existing['csv_state_hash']===null||!hash_equals($existing['csv_state_hash'],self::state($existing,self::SUB_FIELDS)))throw new RuntimeException('SUBSCRIPTION_REVIEW_REQUIRED');
        }
        $customer=$existing?(int)$existing['customer_id']:$this->customer($db,$first['customer'],false);
        $desired=['customer_id'=>$customer,'amount_minor'=>null]+$first['parent'];
        $values=$existing?$this->fillOnly($existing,$desired):$desired;
        $parentChanged=$existing===null||!hash_equals(self::state($existing,self::SUB_FIELDS),self::state($values,self::SUB_FIELDS));
        if($existing&&$existing['synced_at']!==null&&$parentChanged)throw new RuntimeException('SUBSCRIPTION_REVIEW_REQUIRED');
        $q=$db->prepare('SELECT * FROM stripe_subscription_items WHERE subscription_id=? FOR UPDATE');$q->execute([$existing['id']??0]);$old=$q->fetchAll();$oldByLocator=[];
        foreach($old as $item){
            if(!isset($items[$item['source_locator']]))throw new RuntimeException('SUBSCRIPTION_ITEM_SET_REVIEW_REQUIRED');
            if($item['csv_state_hash']===null||$item['stripe_subscription_item_id']!==null||!hash_equals($item['csv_state_hash'],self::state($item,self::ITEM_FIELDS)))throw new RuntimeException('SUBSCRIPTION_REVIEW_REQUIRED');
            $oldByLocator[$item['source_locator']]=$item;
        }
        if($parentChanged){$values['stripe_subscription_id']=$first['external'];$values['csv_state_hash']=self::state($values,self::SUB_FIELDS);$values['latest_raw_id']=$rows[array_key_first($rows)]['id'];$this->write($db,'stripe_subscriptions',$values,$existing?(int)$existing['id']:null);}
        $subscription=$existing?(int)$existing['id']:(int)$db->lastInsertId();$result=[];$firstUnique=true;
        foreach($parsed as $index=>$p){
            if(isset($duplicates[$index])){$result[$index]=['status'=>'skipped','warnings'=>['DUPLICATE_SUBSCRIPTION_ITEM']];continue;}
            $oldItem=$oldByLocator[$p['locator']]??null;
            // Existing catalog facts are never rewritten by CSV. Validate known price/product associations.
            $product=$this->catalogProduct($db,$p['product']);$price=null;
            if($p['kind']==='price_'){
                $q=$db->prepare('SELECT * FROM stripe_prices WHERE stripe_price_id=? FOR UPDATE');$q->execute([$p['plan']]);$known=$q->fetch();
                if($known){
                    if($known['product_id']!==null&&$product!==null&&(int)$known['product_id']!==$product)throw new RuntimeException('STRIPE_REFERENCE_CONFLICT');
                    if($known['currency']!==null&&$p['item']['currency']!==null&&$known['currency']!==$p['item']['currency'])throw new RuntimeException('STRIPE_REFERENCE_CONFLICT');
                    $price=(int)$known['id'];
                }else{$q=$db->prepare('INSERT INTO stripe_prices(stripe_price_id,product_id) VALUES (?,?)');$q->execute([$p['plan'],$product]);$price=(int)$db->lastInsertId();}
            }
            $desired=['subscription_id'=>$subscription,'price_id'=>$price,'legacy_plan_id'=>$p['kind']==='plan_'?$p['plan']:null,'product_id'=>$product]+$p['item'];
            $itemValues=$oldItem?$this->fillOnly($oldItem,$desired):$desired;
            $changed=$oldItem===null||!hash_equals(self::state($oldItem,self::ITEM_FIELDS),self::state($itemValues,self::ITEM_FIELDS));
            if($changed&&$existing&&$existing['synced_at']!==null)throw new RuntimeException('SUBSCRIPTION_REVIEW_REQUIRED');
            if($changed){$itemValues['source_locator']=$p['locator'];$itemValues['latest_raw_id']=$rows[$index]['id'];$itemValues['csv_state_hash']=self::state($itemValues,self::ITEM_FIELDS);$this->write($db,'stripe_subscription_items',$itemValues,$oldItem?(int)$oldItem['id']:null);}
            $status=$existing===null&&$firstUnique?'inserted':(($changed||($parentChanged&&$firstUnique))?'updated':'skipped');$firstUnique=false;
            $result[$index]=['status'=>$dryRun?'skipped':$status,'warnings'=>$p['warnings']];
        }
        return $result;
    }

    private function catalogProduct(PDO $db,?string $external):?int
    {
        if($external===null)return null;$q=$db->prepare('SELECT id FROM stripe_products WHERE stripe_product_id=? FOR UPDATE');$q->execute([$external]);$id=$q->fetchColumn();
        if($id!==false)return (int)$id;$db->prepare('INSERT INTO stripe_products(stripe_product_id) VALUES (?)')->execute([$external]);return (int)$db->lastInsertId();
    }

    /** Blank source cells cannot erase facts; differing populated values need freshness/review evidence. */
    private function fillOnly(array $existing,array $desired):array
    {
        $out=[];
        foreach($desired as $key=>$value){
            if($value===null){$out[$key]=$existing[$key];continue;}
            if($existing[$key]!==null&&self::scalar($key,$existing[$key])!==self::scalar($key,$value))throw new RuntimeException('SUBSCRIPTION_REVIEW_REQUIRED');
            $out[$key]=$value;
        }
        return $out;
    }
    public static function state(array $row,array $keys):string
    {
        $values=[];foreach($keys as $key)$values[$key]=self::scalar($key,$row[$key]??null);return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    private static function scalar(string $key,mixed $value):?string
    {
        if($value===null)return null;$value=(string)$value;
        return preg_match('/(_at|_start|_end)$/',$key)?preg_replace('/\.0{1,6}$/','',$value):$value;
    }
}
