<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/app/Matching/bootstrap.php';
require dirname(__DIR__) . '/src/People.php';

$config = bmaConfig();
if (($config['APP_ENV'] ?? '') !== 'test' || !str_starts_with((string)($config['DB_DATABASE'] ?? ''), 'bma_schema_test_')) { throw new RuntimeException('Disposable test database required.'); }
$db = bmaDatabase($config);
function checkMatch(bool $pass, string $name): void { if (!$pass) { throw new RuntimeException($name); } echo "PASS $name\n"; }
function countMatch(PDO $db, string $sql, array $params = []): int { $q = $db->prepare($sql); $q->execute($params); return (int)$q->fetchColumn(); }

$adminHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
$db->prepare("INSERT INTO app_users (email,email_normalized,display_name,password_hash,role) VALUES ('review@example.invalid','review@example.invalid','Review fixture',?,'admin')")->execute([$adminHash]);
$admin = (int)$db->lastInsertId();
$people = new People($db);
$jane = (int)$people->create('Jane','Smith')['id'];
$johnny = (int)$people->create('Johnny','Smith')['id'];
$db->prepare('UPDATE people SET email = ?, email_normalized = ?, phone = ?, phone_normalized = ? WHERE id IN (?, ?)')->execute(['shared@example.invalid','shared@example.invalid','(570) 555-1212','5705551212',$jane,$johnny]);
$db->prepare('INSERT INTO stripe_customers (stripe_customer_id,name,email,email_normalized,address_line1,address_postal_code) VALUES (?,?,?,?,?,?)')->execute(['cus_JaneFixture','Jane Smith','shared@example.invalid','shared@example.invalid','123 Main St','17815']);
$customer = (int)$db->lastInsertId();
$db->prepare('INSERT INTO cognito_waivers (source_id_kind,form_namespace,source_locator,content_hash,source_name,signer_print_name,contact_email,contact_phone,address_text,relationship_to_student) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
    'fingerprint','fixture','cog-v1-' . hash('sha256','fixture-jane-johnny'),hash('sha256','fixture-content'),
    'Johnny Smith','Jane Smith','shared@example.invalid','5705551212','123 Main St, Bloomsburg PA 17815','Mother']);
$waiver = (int)$db->lastInsertId();
$locator = (string)$db->query('SELECT source_locator FROM cognito_waivers WHERE id = ' . $waiver)->fetchColumn();

$matcher = new IdentityMatcher($db, $config);
$first = $matcher->run();
checkMatch($first['pairs_evaluated'] === 2 && $first['automatic_links'] === 0, 'household evidence produces review candidates without auto merge');
checkMatch(countMatch($db, 'SELECT COUNT(*) FROM identity_match_candidates WHERE stripe_customer_id = ? AND cognito_waiver_id = ? AND waiver_role = ? AND match_status = ?', [$customer,$waiver,'signer','possible']) === 1, 'signer candidate explained and stored');
checkMatch(countMatch($db, 'SELECT COUNT(*) FROM identity_links') === 0, 'candidate scoring does not assert identity');

$resolver = new PersonResolver($db);
$resolver->confirmReviewedHousehold('cus_JaneFixture',$locator,$jane,$jane,$johnny,$admin,true);
checkMatch($jane !== $johnny && countMatch($db, 'SELECT COUNT(*) FROM people WHERE email_normalized = ?', ['shared@example.invalid']) === 2, 'shared contact keeps separate people');
checkMatch(countMatch($db, 'SELECT COUNT(*) FROM stripe_customers WHERE id = ? AND person_id = ?', [$customer,$jane]) === 1
    && countMatch($db, 'SELECT COUNT(*) FROM cognito_waivers WHERE id = ? AND signer_person_id = ? AND student_person_id = ?', [$waiver,$jane,$johnny]) === 1, 'payer signer student roles remain distinct');
checkMatch(countMatch($db, 'SELECT COUNT(*) FROM members WHERE person_id = ? AND member_since IS NULL', [$johnny]) === 1, 'student membership has unknown start date');
foreach (['payer','parent'] as $kind) { checkMatch(countMatch($db, 'SELECT COUNT(*) FROM person_relationships WHERE person_id = ? AND related_person_id = ? AND relationship_type = ?', [$jane,$johnny,$kind]) === 1, $kind . ' relationship'); }
checkMatch(countMatch($db, 'SELECT COUNT(*) FROM person_relationships WHERE person_id = ? AND related_person_id = ? AND relationship_type = ?', [$johnny,$jane,'child']) === 1, 'inverse child relationship');
$again = $matcher->run();
checkMatch($again['linked'] >= 1 && countMatch($db, 'SELECT COUNT(*) FROM identity_links WHERE match_status = ?', ['confirmed']) === 3, 'rerun respects manual confirmations');
$q = $db->prepare('SELECT id,waiver_role FROM identity_match_candidates WHERE stripe_customer_id = ? AND cognito_waiver_id = ?'); $q->execute([$customer,$waiver]);
foreach ($q->fetchAll() as $candidate) { $resolver->reviewCandidate((int)$candidate['id'], $candidate['waiver_role'] === 'signer' ? 'confirmed' : 'rejected', $admin); }
$afterReview = $matcher->run();
checkMatch($afterReview['manual_preserved'] === 2 && countMatch($db, "SELECT COUNT(*) FROM identity_match_candidates WHERE stripe_customer_id = ? AND cognito_waiver_id = ? AND match_status IN ('confirmed','rejected')", [$customer,$waiver]) === 2, 'reviewed candidate decisions survive rerun');

$rejectedLocator = 'cog-v1-' . hash('sha256','fixture-rejected-signer');
$db->prepare('INSERT INTO cognito_waivers (source_id_kind,form_namespace,source_locator,content_hash,source_name,signer_print_name,contact_email,relationship_to_student) VALUES (?,?,?,?,?,?,?,?)')->execute([
    'fingerprint','fixture',$rejectedLocator,hash('sha256','fixture-rejected-content'),'Johnny Smith','Jane Smith','shared@example.invalid','Mother']);
(new IdentityAssociations($db))->decide('cognito','waiver',$rejectedLocator,'signer',$jane,'rejected',$admin);
$matcher->run();
checkMatch(countMatch($db, "SELECT COUNT(*) FROM identity_links WHERE source_system = 'cognito' AND external_id = ? AND source_role = 'signer' AND person_id = ? AND match_status = 'rejected'", [$rejectedLocator,$jane]) === 1
    && countMatch($db, 'SELECT COUNT(*) FROM cognito_waivers WHERE source_locator = ? AND signer_person_id IS NULL', [$rejectedLocator]) === 1, 'manual rejection survives rerun');

$candidateRejectLocator = 'cog-v1-' . hash('sha256','fixture-candidate-rejection');
$db->prepare('INSERT INTO cognito_waivers (source_id_kind,form_namespace,source_locator,content_hash,source_name,signer_print_name,contact_email,relationship_to_student) VALUES (?,?,?,?,?,?,?,?)')->execute([
    'fingerprint','fixture',$candidateRejectLocator,hash('sha256','fixture-candidate-rejection-content'),'Johnny Smith','Jane Smith','shared@example.invalid','Mother']);
$candidateRejectWaiver = (int)$db->lastInsertId();
$matcher->run();
$q = $db->prepare("SELECT id FROM identity_match_candidates WHERE cognito_waiver_id = ? AND waiver_role = 'signer' AND stripe_customer_id = ?"); $q->execute([$candidateRejectWaiver,$customer]);
$resolver->reviewCandidate((int)$q->fetchColumn(),'rejected',$admin);
$blocked = false;
try { (new IdentityAssociations($db))->decide('cognito','waiver',$candidateRejectLocator,'signer',$jane,'automatic'); }
catch (RuntimeException $e) { $blocked = $e->getMessage() === 'MANUAL_DECISION_OVERRIDES_AUTOMATION'; }
checkMatch($blocked, 'candidate rejection blocks direct automatic association');

$john = (int)$people->create('John','Smith')['id'];
$db->prepare('INSERT INTO stripe_customers (stripe_customer_id,name,email,email_normalized,address_line1,address_postal_code) VALUES (?,?,?,?,?,?)')->execute([
    'cus_JohnFixture','John Smith','john@example.invalid','john@example.invalid','123 Lake St 17815','17815']);
$johnLocator = 'cog-v1-' . hash('sha256','fixture-john-self');
$db->prepare('INSERT INTO cognito_waivers (source_id_kind,form_namespace,source_locator,content_hash,source_name,signer_print_name,contact_email,address_text,relationship_to_student) VALUES (?,?,?,?,?,?,?,?,?)')->execute([
    'fingerprint','fixture',$johnLocator,hash('sha256','fixture-john-content'),'John Smith','John Smith','john@example.invalid','123 Lake St 17815','Self']);
(new IdentityAssociations($db))->decide('stripe','customer','cus_JohnFixture','customer',$john,'confirmed',$admin);
$automatic = $matcher->run();
checkMatch($automatic['automatic_links'] === 1 && countMatch($db, "SELECT COUNT(*) FROM identity_links WHERE source_system = 'cognito' AND external_id = ? AND source_role = 'signer' AND person_id = ? AND match_status = 'automatic'", [$johnLocator,$john]) === 1, 'unique high-confidence signer attaches to confirmed payer person');
checkMatch(countMatch($db, 'SELECT COUNT(*) FROM cognito_waivers WHERE source_locator = ? AND student_person_id IS NULL', [$johnLocator]) === 1
    && countMatch($db, 'SELECT COUNT(*) FROM members WHERE person_id = ?', [$john]) === 0, 'automatic signer link does not invent student membership');

$scorer = new MatchScorer();
$conflict = $scorer->score(['name'=>'Jane Smith','birth_date'=>'1980-01-01'], ['name'=>'Jane Smith','birth_date'=>'1990-01-01'], 'signer');
checkMatch($conflict['hard_conflict'] && in_array('conflicting_birth_date',$conflict['evidence']['conflicts'],true), 'birth-date conflict blocks automation');
$fuzzy = $scorer->score(['name'=>'Jane Smyth'], ['name'=>'Jane Smith'], 'signer');
checkMatch($fuzzy['score'] < 0.45, 'fuzzy name alone stays below review threshold');
echo "Matching integration checks passed.\n";
