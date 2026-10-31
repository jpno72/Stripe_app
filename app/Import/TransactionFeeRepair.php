<?php
declare(strict_types=1);
require_once __DIR__.'/BalanceFeeEvidence.php';

/** Batch-scoped, fee-only backfill. Never re-runs the transaction importer. */
final class TransactionFeeRepair
{
    public function __construct(private PDO $db) {}

    public function run(int $batch, string $fileHash, int $actor, bool $apply = false, ?array $document = null, ?string $evidenceHash = null): array
    {
        if ($batch < 1 || $actor < 1 || !preg_match('/\A[a-f0-9]{64}\z/D', $fileHash)
            || ($document !== null && ($evidenceHash === null || !preg_match('/\A[a-f0-9]{64}\z/D', $evidenceHash)))
            || $this->db->inTransaction()) throw new RuntimeException('INVALID_FEE_REPAIR_REQUEST');
        if ((int)$this->db->query("SELECT GET_LOCK('bma_csv_import',0)")->fetchColumn() !== 1) throw new RuntimeException('IMPORT_ALREADY_RUNNING');
        try {
            $this->db->beginTransaction();
            $q = $this->db->prepare("SELECT id FROM app_users WHERE id=? AND role='admin' AND is_active=1" . ($apply ? ' FOR UPDATE' : ''));
            $q->execute([$actor]); if (!$q->fetchColumn()) throw new RuntimeException('FEE_REPAIR_ADMIN_REQUIRED');
            $q = $this->db->prepare('SELECT * FROM import_batches WHERE id=?' . ($apply ? ' FOR UPDATE' : '')); $q->execute([$batch]); $source = $q->fetch();
            $options = $source ? (json_decode($source['import_options'],true) ?: []) : [];
            if (!$source || (int)$source['row_count'] > 5000 || $source['source_type'] !== 'stripe_transactions' || !in_array($source['status'],['completed','completed_with_errors'],true)
                || ($options['dry_run'] ?? null) !== false || !hash_equals($source['file_hash'],$fileHash)) throw new RuntimeException('INVALID_FEE_REPAIR_BATCH');
            $size = $this->db->prepare('SELECT COALESCE(SUM(OCTET_LENGTH(raw_values)),0) FROM raw_stripe_transactions WHERE import_batch_id=?');
            $size->execute([$batch]); if ((int)$size->fetchColumn() > 16777216) throw new RuntimeException('INVALID_FEE_REPAIR_BATCH');
            $context = $this->db->query('SELECT * FROM stripe_source_identity WHERE id=1')->fetch();
            if (!$context || $context['stripe_account_id'] === null || $context['stripe_livemode'] === null
                || $source['source_stripe_account_id'] !== $context['stripe_account_id'] || $source['source_stripe_livemode'] === null
                || (int)$source['source_stripe_livemode'] !== (int)$context['stripe_livemode']) throw new RuntimeException('FEE_REPAIR_SOURCE_IDENTITY_REQUIRED');
            $proofs = $document === null ? [] : BalanceFeeEvidence::manifest($document,$context['stripe_account_id'],(int)$context['stripe_livemode']);
            $q = $this->db->prepare("SELECT t.id,t.stripe_charge_id,t.customer_id,t.stripe_invoice_id,t.amount_minor,t.amount_refunded_minor,t.currency,t.status,t.captured,t.stripe_created_at,
                t.converted_amount_minor,t.converted_amount_refunded_minor,t.converted_currency,t.latest_raw_id,t.fee_minor,t.fee_currency,t.taxes_on_fee_minor,
                t.stripe_balance_transaction_id,t.balance_amount_minor,t.balance_fee_minor,t.balance_net_minor,t.balance_currency,
                c.stripe_customer_id linked_customer_external_id,r.raw_values,r.row_hash repair_hash,current_raw.row_hash current_hash
                FROM stripe_transactions t JOIN raw_stripe_transactions r ON r.source_record_id=t.stripe_charge_id AND r.import_batch_id=?
                LEFT JOIN raw_stripe_transactions current_raw ON current_raw.id=t.latest_raw_id
                LEFT JOIN stripe_customers c ON c.id=t.customer_id
                WHERE r.id=(SELECT MIN(x.id) FROM raw_stripe_transactions x WHERE x.import_batch_id=r.import_batch_id AND x.source_record_id=r.source_record_id)
                ORDER BY t.id" . ($apply ? ' FOR UPDATE' : ''));
            $q->execute([$batch]);$rows=$q->fetchAll();
            $result=['mode'=>$apply?'apply':'dry_run','selected_count'=>count($rows),'eligible_count'=>0,'updated_count'=>0,'unchanged_count'=>0,
                'blocked_missing_evidence'=>0,'blocked_source_drift'=>0,'blocked_balance_conflict'=>0,'blocked_existing_fee'=>0,
                'eligible_by_currency'=>[],'intended_columns'=>['fee_minor','fee_currency','taxes_on_fee_minor_when_supported']];
            foreach ($rows as $row) {
                if (!$this->sourceAgrees($row)) {++$result['blocked_source_drift'];continue;}
                try {$stored=BalanceFeeEvidence::stored($row);}catch(RuntimeException){++$result['blocked_balance_conflict'];continue;}
                $external=$proofs[$row['stripe_charge_id']]??null;
                if ($stored !== null && $external !== null) {
                    foreach (['id','source','amount','fee','net','currency'] as $key) {
                        if ($stored[$key] !== $external[$key]) {++$result['blocked_balance_conflict'];continue 2;}
                    }
                }
                $proof=$external??$stored;
                // No-op when a previously filled cache needs no new write. Never erase tax detail.
                if ($row['fee_minor'] !== null || $row['fee_currency'] !== null || $row['taxes_on_fee_minor'] !== null) {
                    if ($proof === null || ($row['fee_minor'] !== null && (int)$row['fee_minor']===$proof['fee'] && $row['fee_currency']===$proof['currency']
                        && ($proof['tax']===null || ($row['taxes_on_fee_minor']!==null && (int)$row['taxes_on_fee_minor']===$proof['tax'])))) ++$result['unchanged_count'];
                    else ++$result['blocked_existing_fee'];
                    continue;
                }
                if ($proof === null) {++$result['blocked_missing_evidence'];continue;}
                $sourceCosts=json_decode($row['raw_values'],true);
                if ($proof['fee']===0 && (preg_match('/[1-9]/', $sourceCosts['Fee'] ?? '') || preg_match('/[1-9]/', $sourceCosts['Taxes On Fee'] ?? ''))) {
                    // IC+ or incomplete evidence must not turn nonzero recorded costs into a factual zero.
                    ++$result['blocked_balance_conflict'];continue;
                }
                $balanceAmount=$proof['currency']===$row['currency'] ? $row['amount_minor']
                    : ($proof['currency']===$row['converted_currency'] ? $row['converted_amount_minor'] : null);
                if ($balanceAmount===null || (int)$balanceAmount!==$proof['amount']) {++$result['blocked_balance_conflict'];continue;}
                // Do not bind an external balance ID already attributed to another transaction.
                $check=$this->db->prepare('SELECT id FROM stripe_transactions WHERE stripe_balance_transaction_id=? AND id<>? LIMIT 1');
                $check->execute([$proof['id'],$row['id']]);if($check->fetchColumn()!==false){++$result['blocked_balance_conflict'];continue;}
                ++$result['eligible_count'];$result['eligible_by_currency'][$proof['currency']]=($result['eligible_by_currency'][$proof['currency']]??0)+1;
                if (!$apply) continue;
                $update=$this->db->prepare('UPDATE stripe_transactions SET fee_minor=?,fee_currency=?,taxes_on_fee_minor=? WHERE id=? AND latest_raw_id=? AND fee_minor IS NULL AND fee_currency IS NULL AND taxes_on_fee_minor IS NULL');
                $update->execute([$proof['fee'],$proof['currency'],$proof['tax'],$row['id'],$row['latest_raw_id']]);
                if($update->rowCount()!==1)throw new RuntimeException('FEE_REPAIR_CONCURRENT_CHANGE');
                $metadata=['reason'=>'verified_balance_fee_cache_v1','source_batch_id'=>$batch,'source_file_hash'=>$fileHash,
                    'balance_transaction_id'=>$proof['id'],'evidence_origin'=>$external!==null?'operator_verified_stripe_balance_document':'existing_balance_fields',
                    'evidence_sha256'=>$external!==null?$evidenceHash:null,
                    'previous'=>['fee_minor'=>null,'fee_currency'=>null,'taxes_on_fee_minor'=>null],
                    'new'=>['fee_minor'=>$proof['fee'],'fee_currency'=>$proof['currency'],'taxes_on_fee_minor'=>$proof['tax']]];
                $audit=$this->db->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,'transaction_fee_repaired','stripe_transaction',?,?)");
                $audit->execute([$actor,(string)$row['id'],json_encode($metadata,JSON_THROW_ON_ERROR)]);++$result['updated_count'];
            }
            ksort($result['eligible_by_currency']);
            if($apply)$this->db->commit();else $this->db->rollBack();
            return $result;
        } catch(Throwable $error) {if($this->db->inTransaction())$this->db->rollBack();throw $error;}
        finally{$this->db->query("SELECT RELEASE_LOCK('bma_csv_import')");}
    }

    private function sourceAgrees(array $row): bool
    {
        try {
            if ($row['current_hash']===null || !hash_equals($row['repair_hash'],$row['current_hash'])) return false;
            $v=json_decode($row['raw_values'],true,512,JSON_THROW_ON_ERROR);if(!is_array($v))return false;
            $record=ApprovedRecord::fromRow('stripe_transactions',array_keys($v),array_values($v));
            if(!hash_equals($row['repair_hash'],$record->contentHash()))return false;
            $v=$record->values();$currency=Normalizers::currency($v['Currency']);if($currency===null)return false;
            $converted=Normalizers::currency($v['Converted Currency']);
            Normalizers::validateDecimal($v['Fee']);Normalizers::validateDecimal($v['Taxes On Fee']);
            $expected=['stripe_charge_id'=>Normalizers::stripeTransactionId($v['id']),'currency'=>$currency,
                'amount_minor'=>Normalizers::amount($v['Amount'],$currency),'amount_refunded_minor'=>Normalizers::amount($v['Amount Refunded'],$currency),
                'status'=>Normalizers::text($v['Status']),'captured'=>Normalizers::boolean($v['Captured']),
                'stripe_invoice_id'=>Normalizers::stripeId($v['Invoice ID'],'in_'),
                'linked_customer_external_id'=>Normalizers::stripeId($v['Customer ID'],'cus_'),
                'converted_currency'=>$converted,'converted_amount_minor'=>$converted===null?null:Normalizers::amount($v['Converted Amount'],$converted),
                'converted_amount_refunded_minor'=>$converted===null?null:Normalizers::amount($v['Converted Amount Refunded'],$converted)];
            foreach($expected as $key=>$value) {
                if($value===null ? $row[$key]!==null : ($row[$key]===null || (string)$row[$key] !== (string)$value))return false;
            }
            $date=Normalizers::utc($v['Created date (UTC)']);
            if($date===null)return $row['stripe_created_at']===null;
            return $row['stripe_created_at']!==null && preg_match('/\A'.preg_quote($date,'/').'(?:\.0{1,6})?\z/D',$row['stripe_created_at'])===1;
        }catch(Throwable){return false;}
    }
}
