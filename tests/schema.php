<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/database.php';
foreach (['People','Retention','TemporaryUploads','CognitoIdentity','IdentityAssociations','StripeContext'] as $class) { require dirname(__DIR__) . '/src/' . $class . '.php'; }
$config = bmaConfig();
if (!str_starts_with($config['DB_DATABASE'] ?? '', 'bma_schema_test_')) { fwrite(STDERR, "Disposable bma_schema_test_* database required.\n"); exit(1); }
$db = bmaDatabase($config); $checks = 0;
function check(bool $condition, string $label): void { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function query(string $sql, array $args = []): PDOStatement { global $db; $q = $db->prepare($sql); $q->execute($args); return $q; }
function fails(callable $fn, string $label, ?string $message = null): void {
    try { $fn(); } catch (Throwable $e) {
        if ($e instanceof PDOException) { check(in_array((int)($e->errorInfo[1] ?? 0), [1062,1451,1452,4025,3819,1644,1054], true), $label . ': genuine constraint error'); }
        if ($message !== null) { check(str_contains($e->getMessage(), $message), $label . ': expected message'); }
        check(true, $label); return;
    }
    throw new RuntimeException('Expected rejection: ' . $label);
}
function record(array $values): ApprovedRecord { return ApprovedRecord::fromRow('cognito_waivers', array_keys($values), array_values($values)); }

try {
    check((int)query('SELECT COUNT(*) FROM people')->fetchColumn() === 0, 'Fresh disposable database');
    $tables = query('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll();
    check(count($tables) === 25, '25 tables');
    foreach ($tables as $t) { check($t['ENGINE'] === 'InnoDB' && str_starts_with($t['TABLE_COLLATION'], 'utf8mb4'), 'Storage: ' . $t['TABLE_NAME']); }
    $fks = query('SELECT k.TABLE_NAME, k.COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll();
    check(count($fks) === 33, '33 foreign keys');
    foreach ($fks as $fk) {
        check((int)query('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1', [$fk['TABLE_NAME'],$fk['COLUMN_NAME']])->fetchColumn() > 0 && $fk['DELETE_RULE'] === 'RESTRICT' && $fk['UPDATE_RULE'] === 'RESTRICT', 'Indexed restrictive FK');
    }
    check((int)query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('float','double')")->fetchColumn() === 0, 'No float columns');
    check((int)query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME LIKE '%\\_minor' AND DATA_TYPE <> 'bigint'")->fetchColumn() === 0, 'All money BIGINT');
    check((int)query("SELECT COUNT(*) FROM (SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' GROUP BY TABLE_NAME,INDEX_NAME) indexes_found")->fetchColumn() === 21, '21 additional unique indexes');
    $maps = require dirname(__DIR__) . '/config/import-mappings.php';
    foreach ($maps as $source => $fields) {
        $headings = [...array_keys($fields),'unexpected_column'];
        $cells = array_map(fn($heading)=>isset($fields[$heading]) && $fields[$heading]['action'] !== 'IGNORE' ? ' approved original value ' : 'EXCLUDED_SENTINEL', $headings);
        $approved = ApprovedRecord::fromRow($source,$headings,$cells);
        check(!str_contains($approved->json(),'EXCLUDED_SENTINEL'),'Every source filters unknown/ignored values');
        check(in_array(' approved original value ',$approved->values(),true),'Approved original string preserved exactly');
        query('INSERT INTO import_batches (source_type,filename,file_hash,source_headers) VALUES (?,?,?,?)',[$source,'synthetic.upload',str_repeat('a',64),json_encode($headings)]);
        $sourceBatch = (int)$db->lastInsertId(); $approved->stage($db,$sourceBatch,1);
        fails(fn()=>query('INSERT INTO raw_' . $source . ' (import_batch_id,source_row_number,raw_values,row_hash) VALUES (?,?,?,?)',[$sourceBatch,2,'{"unexpected_column":"EXCLUDED_SENTINEL"}',str_repeat('b',64)]),'Every raw table rejects unknown JSON keys');
        if ($source === 'stripe_customers' || $source === 'stripe_transactions') {
            check($fields['Card ID']['action'] === 'IGNORE' && $fields['Card ID']['approved'] === false,'Card ID is ignored before staging');
            fails(fn()=>query('INSERT INTO raw_' . $source . ' (import_batch_id,source_row_number,raw_values,row_hash) VALUES (?,?,?,?)',[$sourceBatch,2,'{"Card ID":"card_SENTINEL"}',str_repeat('b',64)]),'Stripe raw table rejects Card ID');
        }
        if ($source === 'stripe_customers' || $source === 'stripe_subscriptions' || $source === 'stripe_transactions') {
            $heading = $source === 'stripe_customers' ? 'Description' : 'Customer Description';
            check($fields[$heading]['action'] === 'IGNORE' && $fields[$heading]['approved'] === false,'Customer description ignored');
            fails(fn()=>query('INSERT INTO raw_' . $source . ' (import_batch_id,source_row_number,raw_values,row_hash) VALUES (?,?,?,?)',[$sourceBatch,2,json_encode([$heading=>'DESCRIPTION_SENTINEL']),str_repeat('b',64)]),'Stripe raw table rejects customer description');
        }
    }
    check((int)query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('stripe_customers','stripe_subscriptions','stripe_transactions') AND COLUMN_NAME IN ('card_or_payment_method_id','customer_description_snapshot')")->fetchColumn() === 0,'Removed columns absent in normalized schema');
    check((int)query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stripe_customers' AND COLUMN_NAME = 'description'")->fetchColumn() === 0,'Customer description column absent');

    $people = new People($db); $jane = $people->create('Jane','Smith'); $johnny = $people->create('Johnny','Smith');
    check($jane['bma_person_id'] === 'BMA-' . str_pad($jane['id'],6,'0',STR_PAD_LEFT), 'Public ID derives from allocated PK');
    foreach ([$jane,$johnny] as $p) { query('UPDATE people SET email_normalized = ?, phone_normalized = ? WHERE id = ?', ['shared@example.invalid','+15555550100',$p['id']]); }
    check($jane['id'] !== $johnny['id'], 'Shared email and phone do not merge people');
    fails(fn()=>query('UPDATE people SET bma_person_id = ? WHERE id = ?', ['BMA-OTHER',$jane['id']]), 'Immutable public ID', 'BMA person ID is immutable');
    fails(fn()=>query('UPDATE people SET id = ? WHERE id = ?', [999999,$jane['id']]), 'Immutable numeric ID');
    fails(fn()=>query('INSERT INTO people (id) VALUES (?)', [$jane['id']]), 'No explicit/recycled ID allocation');
    // Real simultaneous PHP processes, each with an independent PDO connection.
    $workers = [];
    for ($i=0; $i<8; $i++) {
        $pipes = []; $process = proc_open([PHP_BINARY, __DIR__ . '/person-worker.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Worker launch failed'); }
        fclose($pipes[0]); $workers[] = [$process,$pipes];
    }
    $ids = [];
    foreach ($workers as [$process,$pipes]) {
        $output = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $err === '', 'Concurrent worker succeeded');
        $p = json_decode($output,true,flags:JSON_THROW_ON_ERROR); $ids[] = $p['bma_person_id'];
        check($p['bma_person_id'] === 'BMA-' . str_pad($p['id'],6,'0',STR_PAD_LEFT), 'Concurrent ID correctness');
    }
    check(count(array_unique($ids)) === 8, 'Eight simultaneous unique BMA IDs');
    check((int)query('SELECT COUNT(*) FROM people WHERE bma_person_id IS NULL')->fetchColumn() === 0, 'No committed provisional identities from service');

    query('INSERT INTO app_users (email,email_normalized,display_name,password_hash,role) VALUES (?,?,?,?,?)', ['admin@example.invalid','admin@example.invalid','Test administrator',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),'admin']);
    $admin = (int)$db->lastInsertId();
    query('INSERT INTO stripe_customers (stripe_customer_id) VALUES (?)',['cus_JANE']); $customer = (int)$db->lastInsertId();
    fails(fn()=>query('UPDATE stripe_customers SET person_id = ? WHERE id = ?',[$jane['id'],$customer]), 'Direct cache association forbidden');
    $identity = new IdentityAssociations($db);
    $identity->decide('stripe','customer','cus_JANE','customer',(int)$jane['id'],'confirmed',$admin);
    check((string)query('SELECT person_id FROM stripe_customers WHERE id = ?',[$customer])->fetchColumn() === $jane['id'], 'Confirmed identity synchronizes FK');
    fails(fn()=>$identity->decide('stripe','customer','cus_JANE','customer',(int)$johnny['id'],'automatic'), 'Manual confirmation wins', 'MANUAL_DECISION_OVERRIDES_AUTOMATION');
    $identity->decide('stripe','customer','cus_JANE','customer',(int)$jane['id'],'rejected',$admin);
    check(query('SELECT person_id FROM stripe_customers WHERE id = ?',[$customer])->fetchColumn() === null, 'Rejected accepted link clears cached FK');
    fails(fn()=>$identity->decide('stripe','customer','cus_JANE','customer',(int)$jane['id'],'automatic'), 'Manual rejection wins');
    $identity->decide('stripe','customer','cus_JANE','customer',(int)$jane['id'],'confirmed',$admin);

    $base = ['Date Submitted'=>'1/3/2021 16:03','Name'=>'Johnny Smith','Print Name'=>'Jane Smith','Email'=>'shared@example.invalid','Phone'=>'+15555550100','Relationship to student'=>'Mother','considerations'=>'IGNORED_MEDICAL_SENTINEL','goals'=>'IGNORED_GOALS_SENTINEL','Future Field'=>'UNKNOWN_VALUE_SENTINEL'];
    $r = record($base); $key = CognitoIdentity::identify($r,'bma-waiver');
    check(!str_contains($r->json(),'SENTINEL') && !array_key_exists('considerations',$r->values()), 'Ignore/unknown values filtered before serialization');
    check($r->unknownHeaders === ['Future Field'], 'Unknown header metadata only');
    $reordered = record(array_reverse($base,true));
    check(CognitoIdentity::identify($reordered,'bma-waiver') === $key, 'Header order cannot change identity or hash');
    $ignoredChange = $base; $ignoredChange['considerations'] = 'DIFFERENT_IGNORED_VALUE';
    check(CognitoIdentity::identify(record($ignoredChange),'bma-waiver') === $key, 'Ignored value cannot affect identity/hash');
    $changed = $base; $changed['Phone'] = '+15555550101'; $changedKey = CognitoIdentity::identify(record($changed),'bma-waiver');
    check($changedKey['source_locator'] === $key['source_locator'] && $changedKey['content_hash'] !== $key['content_hash'], 'Contact edit updates same submission');
    $other = $base; $other['Date Submitted'] = '1/4/2021 16:03';
    check(CognitoIdentity::identify(record($other),'bma-waiver')['source_locator'] !== $key['source_locator'], 'Different submission time gives new locator');
    $forward = array_map(fn($v)=>CognitoIdentity::identify(record($v),'bma-waiver')['source_locator'],[$base,$other]);
    $backward = array_map(fn($v)=>CognitoIdentity::identify(record($v),'bma-waiver')['source_locator'],[$other,$base]);
    sort($forward); sort($backward); check($forward === $backward, 'Reordered CSV records retain the same locator set');
    check(CognitoIdentity::identify(record($changed),'bma-waiver','ENTRY-TEST')['source_locator'] === CognitoIdentity::identify($r,'bma-waiver','ENTRY-TEST')['source_locator'], 'Stable external ID outranks fallback');
    fails(fn()=>CognitoIdentity::identify(record(['Email'=>'shared@example.invalid']),'bma-waiver'), 'Ambiguous source cannot use email identity');
    check(CognitoIdentity::submissionTime('1/3/2021 16:03','')['utc'] === null, 'Naive timestamp unresolved without setting');
    check(CognitoIdentity::submissionTime('1/3/2021 16:03','America/New_York')['utc'] === '2021-01-03 21:03:00', 'Explicit configured source timezone');
    check(CognitoIdentity::submissionTime('2021-01-03T16:03:00-05:00',null)['utc'] === '2021-01-03 21:03:00', 'Source offset honored');
    check(CognitoIdentity::submissionTime('11/1/2026 1:30','America/New_York')['utc'] === null, 'Ambiguous DST time requires review');
    check(CognitoIdentity::submissionTime('3/8/2026 2:30','America/New_York')['utc'] === null, 'Nonexistent DST time requires review');

    query('INSERT INTO cognito_waivers (source_record_id,source_id_kind,form_namespace,source_locator,content_hash,source_name,signer_print_name,relationship_to_student) VALUES (?,?,?,?,?,?,?,?)',[$key['source_record_id'],$key['source_id_kind'],$key['form_namespace'],$key['source_locator'],$key['content_hash'],'Johnny Smith','Jane Smith','Mother']);
    $waiver = (int)$db->lastInsertId();
    $identity->decide('cognito','waiver',$key['source_locator'],'signer',(int)$jane['id'],'confirmed',$admin);
    $identity->decide('cognito','waiver',$key['source_locator'],'student',(int)$johnny['id'],'confirmed',$admin,true,[[(int)$jane['id'],(int)$johnny['id'],'parent'],[(int)$jane['id'],(int)$johnny['id'],'payer']]);
    $w = query('SELECT * FROM cognito_waivers WHERE id = ?',[$waiver])->fetch();
    check((string)$w['student_person_id'] === $johnny['id'] && (string)$w['signer_person_id'] === $jane['id'], 'Separate authoritative student/signer');
    check(query('SELECT member_since FROM members WHERE person_id = ?',[$johnny['id']])->fetchColumn() === null, 'Unknown member start stays NULL');
    $before = (int)query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    fails(fn()=>$identity->decide('cognito','waiver',$key['source_locator'],'student',(int)$jane['id'],'confirmed',$admin,false,[[999999,(int)$jane['id'],'payer']]), 'Atomic rollback when relationship invalid');
    check((string)query('SELECT student_person_id FROM cognito_waivers WHERE id = ?',[$waiver])->fetchColumn() === $johnny['id'] && (int)query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $before, 'Identity and audits roll back together');
    fails(fn()=>$people->rejectIdentifierChange((int)$jane['id'],$admin), 'Explicit service rejection audited', 'immutable');
    check((int)query("SELECT COUNT(*) FROM audit_log WHERE action = 'immutable_id_change_rejected'")->fetchColumn() === 1, 'Security event contains only record identifiers');

    query('INSERT INTO stripe_subscriptions (stripe_subscription_id,customer_id,status) VALUES (?,?,?)',['sub_TEST',$customer,'active']); $sub = (int)$db->lastInsertId();
    query('INSERT INTO stripe_products (stripe_product_id) VALUES (?)',['prod_TEST']); $product = (int)$db->lastInsertId();
    query('INSERT INTO stripe_prices (stripe_price_id,product_id) VALUES (?,?)',['price_TEST',$product]); $price = (int)$db->lastInsertId();
    foreach (['si_TEST1','si_TEST2'] as $item) { query('INSERT INTO stripe_subscription_items (subscription_id,stripe_subscription_item_id,source_locator,price_id,quantity) VALUES (?,?,?,?,?)',[$sub,$item,'stripe:' . $item,$price,1]); }
    check((int)query('SELECT COUNT(*) FROM stripe_subscription_items WHERE subscription_id = ?',[$sub])->fetchColumn() === 2, 'Multiple items including same price supported');
    fails(fn()=>query('INSERT INTO stripe_subscription_items (subscription_id,stripe_subscription_item_id,source_locator) VALUES (?,?,?)',[$sub,'si_TEST1','duplicate']), 'Unique external item ID');
    fails(fn()=>query('INSERT INTO stripe_subscription_items (subscription_id,source_locator) VALUES (?,?)',[999999,'missing']), 'Item FK');
    fails(fn()=>query('UPDATE stripe_subscription_items SET amount_minor = ? WHERE subscription_id = ?',[12500,$sub]), 'Unknown currency forbids invented minor units');
    query('UPDATE stripe_subscription_items SET amount_minor = ?,currency = ? WHERE subscription_id = ?',[12500,'usd',$sub]);
    fails(fn()=>query('UPDATE stripe_subscription_items SET currency = ? WHERE subscription_id = ?',['USD',$sub]), 'Lowercase database currency');
    check(StripeContext::currency('USD') === 'usd' && StripeContext::currency(null) === null, 'Currency canonicalization without USD default');
    fails(fn()=>StripeContext::requireSameId('cus_A','cus_B'), 'Stripe IDs outrank email');
    StripeContext::requireSameId(null,null);
    StripeContext::establish($db,'acct_TEST',false); StripeContext::establish($db,'acct_TEST',false);
    fails(fn()=>StripeContext::establish($db,'acct_OTHER',false), 'Different account refused');
    fails(fn()=>StripeContext::establish($db,'acct_TEST',true), 'Different environment refused');

    $temp = sys_get_temp_dir() . '/bma-retention-test-' . bin2hex(random_bytes(8)); mkdir($temp,0700);
    $log = $temp . '/diagnostics.log';
    query('INSERT INTO import_batches (source_type,filename,file_hash,source_headers) VALUES (?,?,?,?)',['cognito_waivers','generated-upload.upload',str_repeat('0',64),json_encode(array_keys($base))]); $batch = (int)$db->lastInsertId();
    $r->stage($db,$batch,1);
    fails(fn()=>query('INSERT INTO raw_cognito_waivers (import_batch_id,source_row_number,raw_values,row_hash) VALUES (?,?,?,?)',[$batch,2,'{"considerations":"IGNORED_MEDICAL_SENTINEL"}',str_repeat('1',64)]), 'Database rejects ignored JSON keys');
    fails(fn()=>query('INSERT INTO raw_cognito_waivers (import_batch_id,source_row_number,raw_values,row_hash) VALUES (?,?,?,?)',[$batch,2,'{"Future Field":"UNKNOWN_VALUE_SENTINEL"}',str_repeat('1',64)]), 'Database rejects unknown JSON keys');
    ImportDiagnostics::write($db,$batch,1,'CURRENCY_UNRESOLVED',$log);
    fails(fn()=>ImportDiagnostics::write($db,$batch,1,'IGNORED_MEDICAL_SENTINEL',$log), 'Free-form diagnostic rejected');
    fails(fn()=>query('INSERT INTO import_errors (import_batch_id,error_code,error_message,raw_values) VALUES (?,?,?,?)',[$batch,'TEST','TEST',$r->json()]), 'Errors cannot carry raw row column');
    $persisted = json_encode(query('SELECT * FROM raw_cognito_waivers')->fetchAll()) . json_encode(query('SELECT * FROM import_errors')->fetchAll()) . json_encode(query('SELECT * FROM audit_log')->fetchAll()) . file_get_contents($log);
    check(!str_contains($persisted,'SENTINEL'), 'No ignored values in staging/errors/audits/logs');
    $uploads = new TemporaryUploads($temp,dirname(__DIR__) . '/public');
    $stream = fopen('php://temp','w+b'); fwrite($stream,"considerations,Future Field\nIGNORED_MEDICAL_SENTINEL,UNKNOWN_VALUE_SENTINEL\n"); rewind($stream);
    $uploads->processStream($stream, function($input) use($uploads): void { check(is_resource($input), 'Private temp processing stream'); check($uploads->cleanup(1,time()+100) === 0, 'Active locked upload not cleaned'); });
    check(glob($temp . '/*.upload') === [], 'Success deletes temporary original');
    rewind($stream);
    try { $uploads->processStream($stream, fn()=>throw new RuntimeException('IGNORED_MEDICAL_SENTINEL')); }
    catch (Throwable $e) { check($e->getMessage() === 'PROCESSING_FAILED' && !str_contains((string)$e,'SENTINEL'), 'Failure exception sanitized'); }
    check(glob($temp . '/*.upload') === [], 'Failure deletes temporary original'); fclose($stream);
    $abandoned = $temp . '/' . str_repeat('a',48) . '.upload'; file_put_contents($abandoned,'ABANDONED'); touch($abandoned,time()-100);
    check($uploads->cleanup(50) === 1 && !file_exists($abandoned), 'Abandoned upload cleanup');
    fails(fn()=>new TemporaryUploads(dirname(__DIR__) . '/public',dirname(__DIR__) . '/public'), 'Public upload directory refused');
    $unowned = $temp . '/not-an-upload.txt'; file_put_contents($unowned,'test');
    fails(fn()=>$uploads->processUploadedFile($unowned,fn()=>null), 'HTTP wrapper refuses arbitrary files');
    check(is_file($unowned),'Rejected arbitrary file was not deleted'); unlink($unowned);
    unlink($log); rmdir($temp);
    echo "PASS: $checks assertions. Synthetic records exist only in this disposable test database.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n"); exit(1);
}
