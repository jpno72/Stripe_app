<?php
declare(strict_types=1);

/** Consume filtered staging after the complete file has been read, one bounded subscription at a time. */
final class StripeSubscriptionBatch
{
    public const ERRORS=['SUBSCRIPTION_GROUP_CONFLICT','SUBSCRIPTION_ITEM_AMBIGUOUS','SUBSCRIPTION_REVIEW_REQUIRED','SUBSCRIPTION_ITEM_SET_REVIEW_REQUIRED',
        'STRIPE_REFERENCE_CONFLICT','INVALID_IDENTIFIER','INVALID_AMOUNT','INVALID_DATE','INVALID_CURRENCY','INVALID_STATUS','INVALID_COUNT','INVALID_BOOLEAN','INVALID_ROW'];
    public function __construct(private PDO $db){}
    public function process(int $batch,bool $dryRun,string $logPath,array $blocked,array &$counts):void
    {
        $importer=new StripeSubscriptionImporter();
        $next=$this->db->prepare("SELECT source_record_id FROM raw_stripe_subscriptions WHERE import_batch_id=? AND processing_status='pending' ORDER BY id LIMIT 1");
        while(true){
            $next->execute([$batch]);$external=$next->fetchColumn();if($external===false)break;
            $q=$this->db->prepare("SELECT COUNT(*) n,COALESCE(SUM(OCTET_LENGTH(raw_values)),0) bytes FROM raw_stripe_subscriptions WHERE import_batch_id=? AND source_record_id=? AND processing_status='pending'");$q->execute([$batch,$external]);$size=$q->fetch();
            if(isset($blocked[$external])||(int)$size['n']>1000||(int)$size['bytes']>8388608){$this->reject($batch,$external,'SUBSCRIPTION_GROUP_CONFLICT',$logPath,$counts);continue;}
            $q=$this->db->prepare("SELECT id,source_row_number,raw_values,row_hash FROM raw_stripe_subscriptions WHERE import_batch_id=? AND source_record_id=? AND processing_status='pending' ORDER BY id");$q->execute([$batch,$external]);$rows=$q->fetchAll();
            try{
                foreach($rows as &$row){$v=json_decode($row['raw_values'],true,512,JSON_THROW_ON_ERROR);$row['record']=ApprovedRecord::fromRow('stripe_subscriptions',array_keys($v),array_values($v));if(!hash_equals($row['row_hash'],$row['record']->contentHash()))throw new RuntimeException('SUBSCRIPTION_GROUP_CONFLICT');unset($row['raw_values']);}unset($row);
                $this->db->beginTransaction();$results=$importer->group($this->db,$rows,$dryRun);
                if($dryRun)$this->db->rollBack();else $this->db->commit();
            }catch(Throwable $error){
                if($this->db->inTransaction())$this->db->rollBack();
                $code=in_array($error->getMessage(),self::ERRORS,true)?$error->getMessage():'PROCESSING_FAILED';
                $this->reject($batch,$external,$code,$logPath,$counts);continue;
            }
            foreach($rows as $index=>$row){$result=$results[$index];$this->db->prepare('UPDATE raw_stripe_subscriptions SET processing_status=? WHERE id=?')->execute([$result['status'],$row['id']]);++$counts[$result['status'].'_count'];
                foreach($result['warnings'] as $warning)ImportDiagnostics::write($this->db,$batch,(int)$row['source_row_number'],$warning,$logPath);
            }
        }
    }
    private function reject(int $batch,string $external,string $code,string $logPath,array &$counts):void
    {
        // Bound memory even for an excessive/hostile duplicate group.
        $q=$this->db->prepare("SELECT id,source_row_number FROM raw_stripe_subscriptions WHERE import_batch_id=? AND source_record_id=? AND processing_status='pending' ORDER BY id LIMIT 100");
        do{$q->execute([$batch,$external]);$rows=$q->fetchAll();foreach($rows as $row){$this->db->prepare("UPDATE raw_stripe_subscriptions SET processing_status='error' WHERE id=?")->execute([$row['id']]);ImportDiagnostics::write($this->db,$batch,(int)$row['source_row_number'],$code,$logPath,'error');++$counts['error_count'];}}while($rows!==[]);
    }
}
