<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/src/Migrations.php';

final class CognitoEmergencyBackfillFailure extends RuntimeException
{
    public function __construct(string $code, public readonly array $counts) { parent::__construct($code); }
}

/** Private CLI service: exact original batch + namespace + native ID; never an identity matcher. */
final class CognitoEmergencyBackfill
{
    private array $counts;
    public function __construct(private readonly PDO $db, private readonly array $config) {}

    public function run(string $file, string $namespace, int $batch, int $actor, int $expectedRows = 1074, bool $apply = false): array
    {
        $this->counts = ['csv_rows'=>0,'unique_ids'=>0,'matched_rows'=>0,'unmatched_rows'=>0,
            'missing_emergency_contacts'=>0,'proposed_updates'=>0,'unchanged_rows'=>0,'updated_rows'=>0,'failures'=>0];
        $stream = null; $locks = [];
        try {
            $base = (string)($this->config['COGNITO_FORM_NAMESPACE'] ?? 'bma-waiver');
            if (!preg_match('/\A[a-zA-Z0-9_.-]{1,100}\z/D',$namespace) || $namespace !== $base.'.BloomsburgMartialArtsAcademy2'
                || $batch < 1 || $actor < 1 || $expectedRows < 1 || $expectedRows > 10000 || $this->db->inTransaction()) throw new RuntimeException('INVALID_BACKFILL_SCOPE');
            $path = Paths::isAbsolute($file) ? realpath($file) : false;
            if ($path === false || !is_file($path) || !is_readable($path) || strtolower(pathinfo($path,PATHINFO_EXTENSION)) !== 'csv'
                || filesize($path) > 104857600) throw new RuntimeException('INVALID_PRIVATE_CSV');
            Paths::assertPrivate($path,Paths::servedRoots($this->config));
            $database = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
            foreach (['bma_csv_import','bma_migrate_'.substr(hash('sha256',$database),0,40)] as $lock) {
                $q=$this->db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
                if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('BACKFILL_BUSY');
                $locks[]=$lock;
            }
            if (Migrations::run($this->db,dirname(__DIR__,2).'/sql/migrations',false) !== []) throw new RuntimeException('BACKFILL_SCHEMA_REQUIRED');
            $columns=$this->db->query('SHOW COLUMNS FROM cognito_waivers')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
            foreach (['emergency_contact_name'=>'varchar(255)','emergency_contact_phone'=>'varchar(100)'] as $key=>$type) {
                if (($columns[$key]['Type']??null)!==$type || ($columns[$key]['Null']??null)!=='YES') throw new RuntimeException('BACKFILL_SCHEMA_REQUIRED');
            }
            $stream=fopen($path,'rb');
            if ($stream===false || !flock($stream,LOCK_SH)) throw new RuntimeException('PRIVATE_CSV_UNAVAILABLE');
            $hash=hash_init('sha256');hash_update_stream($hash,$stream);$fileHash=hash_final($hash);rewind($stream);
            $this->db->beginTransaction();
            $q=$this->db->prepare("SELECT id FROM app_users WHERE id=? AND role='admin' AND is_active=1".($apply?' FOR UPDATE':''));
            $q->execute([$actor]);if(!$q->fetchColumn())throw new RuntimeException('BACKFILL_ADMIN_REQUIRED');
            $q=$this->db->prepare('SELECT * FROM import_batches WHERE id=?'.($apply?' FOR UPDATE':''));$q->execute([$batch]);$source=$q->fetch();
            $options=$source?json_decode($source['import_options'],true):null;
            if (!$source || $source['source_type']!=='cognito_waivers' || $source['status']!=='completed' || (int)$source['row_count']!==$expectedRows
                || (int)$source['inserted_count']!==$expectedRows || (int)$source['updated_count']!==0 || (int)$source['skipped_count']!==0 || (int)$source['error_count']!==0
                || !hash_equals($source['file_hash'],$fileHash) || ($options['dry_run']??null)!==false
                || ($options['cognito_format']??null)!=='cognito_waivers_bma2_v1' || ($options['input_encoding']??null)!=='Windows-1252'
                || ($options['cognito_namespace']??null)!==$base) throw new RuntimeException('ORIGINAL_BATCH_MISMATCH');
            $headers=fgetcsv($stream,1048576,',','"','');
            if (!is_array($headers) || count($headers)>256) throw new RuntimeException('INVALID_CSV_HEADER');
            $headers[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$headers[0]);
            foreach ($headers as $header) if(!is_string($header)||!mb_check_encoding($header,'UTF-8')||strlen($header)>255||preg_match('/[\x00-\x1f\x7f]/',$header))throw new RuntimeException('INVALID_CSV_HEADER');
            if(count(array_unique($headers))!==count($headers) || CognitoSource::mappingKey($headers)!=='cognito_waivers_bma2_v1'
                || array_diff(['EmergencyContact','Phone2'],$headers))throw new RuntimeException('INVALID_CSV_HEADER');
            $ids=[];$plan=[];
            $q=$this->db->prepare('SELECT id,source_locator,source_id_kind,source_format,emergency_contact_name,emergency_contact_phone FROM cognito_waivers WHERE form_namespace=? AND source_record_id=?'.($apply?' FOR UPDATE':''));
            while (($cells=fgetcsv($stream,1048576,',','"',''))!==false) {
                ++$this->counts['csv_rows'];
                if ($this->counts['csv_rows']>$expectedRows || count($cells)!==count($headers)) throw new RuntimeException('UNEXPECTED_CSV_ROWS');
                $record=ApprovedRecord::fromRow('cognito_waivers',$headers,$cells,'Windows-1252');unset($cells);
                $id=CognitoSource::entryId($record->values()[CognitoSource::ID]);
                if(isset($ids[$id]))throw new RuntimeException('DUPLICATE_NATIVE_ID');
                $ids[$id]=true;++$this->counts['unique_ids'];
                $contact=CognitoSource::emergencyContacts($record);
                if(in_array(null,$contact,true))++$this->counts['missing_emergency_contacts'];
                $identity=CognitoSource::identity($record,$base);
                $q->execute([$namespace,$id]);$matches=$q->fetchAll();
                if(count($matches)===0){++$this->counts['unmatched_rows'];continue;}
                if(count($matches)!==1)throw new RuntimeException('AMBIGUOUS_NATIVE_ID');
                $waiver=$matches[0];
                if($waiver['source_id_kind']!=='external'||$waiver['source_format']!=='bma2_v1'||$waiver['source_locator']!==$identity['source_locator'])throw new RuntimeException('NATIVE_IDENTITY_CONFLICT');
                ++$this->counts['matched_rows'];$changed=[];
                foreach($contact as $key=>$value){
                    if($waiver[$key]!==null && $waiver[$key]!==$value)throw new RuntimeException('EXISTING_EMERGENCY_CONFLICT');
                    if($waiver[$key]!==$value)$changed[$key]=['previous'=>$waiver[$key],'new'=>$value];
                }
                if(!$changed){++$this->counts['unchanged_rows'];continue;}
                ++$this->counts['proposed_updates'];$plan[]=['id'=>(int)$waiver['id'],'contact'=>$contact,'fields'=>array_keys($changed)];
            }
            if(!feof($stream))throw new RuntimeException('CSV_READ_FAILED');
            if($this->counts['csv_rows']!==$expectedRows||$this->counts['unique_ids']!==$expectedRows
                ||$this->counts['matched_rows']!==$expectedRows||$this->counts['unmatched_rows']!==0)throw new RuntimeException('UNEXPECTED_MATCH_COUNTS');
            rewind($stream);$check=hash_init('sha256');hash_update_stream($check,$stream);
            if(!hash_equals($fileHash,hash_final($check)))throw new RuntimeException('CSV_CHANGED_DURING_READ');
            if($apply){
                $update=$this->db->prepare('UPDATE cognito_waivers SET emergency_contact_name=?,emergency_contact_phone=? WHERE id=?');
                $audit=$this->db->prepare("INSERT INTO audit_log(actor_user_id,action,entity_type,entity_id,metadata) VALUES (?,'cognito_emergency_backfilled','cognito_waivers',?,?)");
                foreach($plan as $item){
                    $update->execute([$item['contact']['emergency_contact_name'],$item['contact']['emergency_contact_phone'],$item['id']]);
                    if($update->rowCount()!==1)throw new RuntimeException('BACKFILL_CONCURRENT_CHANGE');
                    // Audit provenance and NULL-to-present field transitions, never contact values or value hashes.
                    $metadata=['reason'=>'native_emergency_recovery_v1','source_batch_id'=>$batch,'source_file_hash'=>$fileHash,
                        'form_namespace'=>$namespace,'changed_fields'=>$item['fields'],'previous_state'=>'null','new_state'=>'present',
                        'historical_evidence'=>'unchanged'];
                    $audit->execute([$actor,(string)$item['id'],json_encode($metadata,JSON_THROW_ON_ERROR)]);
                    ++$this->counts['updated_rows'];
                }
                $this->db->commit();
            }else $this->db->rollBack();
            return $this->counts;
        }catch(Throwable $error){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->counts['updated_rows']=0;$this->counts['failures']=1;
            $safe=['INVALID_BACKFILL_SCOPE','INVALID_PRIVATE_CSV','BACKFILL_BUSY','BACKFILL_SCHEMA_REQUIRED','PRIVATE_CSV_UNAVAILABLE','BACKFILL_ADMIN_REQUIRED',
                'ORIGINAL_BATCH_MISMATCH','INVALID_CSV_HEADER','UNEXPECTED_CSV_ROWS','DUPLICATE_NATIVE_ID','AMBIGUOUS_NATIVE_ID','NATIVE_IDENTITY_CONFLICT',
                'EXISTING_EMERGENCY_CONFLICT','CSV_READ_FAILED','UNEXPECTED_MATCH_COUNTS','CSV_CHANGED_DURING_READ','BACKFILL_CONCURRENT_CHANGE',
                'INVALID_ENCODING','INVALID_IDENTIFIER','INVALID_HEADERS_OR_WIDTH','INVALID_EMERGENCY_CONTACT'];
            throw new CognitoEmergencyBackfillFailure(in_array($error->getMessage(),$safe,true)?$error->getMessage():'BACKFILL_VALIDATION_FAILED',$this->counts);
        }finally{
            if(is_resource($stream))fclose($stream);
            foreach(array_reverse($locks) as $lock){$q=$this->db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
        }
    }
}
