<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/src/AppTime.php';
require_once dirname(__DIR__) . '/app/Matching/WaiverSourceRole.php';
require_once dirname(__DIR__) . '/app/Views/layout.php';
require_once dirname(__DIR__) . '/app/Views/match-review.php';
require_once dirname(__DIR__) . '/app/Views/bulk-match-review.php';
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
// A synthetic navigation provider only; this fixture never authenticates or connects to a database.
final class DashboardPermissionService {
    public function getVisibleDashboards(array $user): array {
        $out=[]; foreach (uiNavigation() as $key=>$item) { $out[]=['dashboard_key'=>$key,'display_name'=>$item[0],'route'=>$item[1]]; } return $out;
    }
}
$GLOBALS['bma_dashboard_permissions']=new DashboardPermissionService();
$GLOBALS['bma_current_user']=['display_name'=>'Review fixture administrator'];
$_SESSION=['csrf'=>'synthetic-ui-token','match_review_csrf'=>'synthetic-ui-token'];
$base=['id'=>1,'match_score'=>0.78,'match_status'=>'possible','hard_conflict'=>1,'match_reason'=>'source_name_role_unverified',
    'waiver_role'=>'source_name_unresolved','source_format'=>'bma2_v1','source_name_role'=>'student','student_identity_reviewed'=>0,
    'stripe_name'=>'Jane Smith','stripe_email'=>'jane@example.invalid','stripe_customer_id'=>'cus_'.str_repeat('synthetic',9),
    'address_line1'=>'123 Sample Street','address_city'=>'Bloomsburg','address_postal_code'=>'17815','payer_bma'=>null,
    'source_name'=>'Johnny Smith','signer_print_name'=>'Jane Smith','contact_email'=>'jane@example.invalid','contact_phone'=>'5705551212',
    'address_text'=>'123 Sample Street, Bloomsburg PA 17815','relationship_to_student'=>'Mother','signer_bma'=>null,'student_bma'=>null,
    'evidence'=>json_encode(['signals'=>['exact_email','exact_phone','exact_last_name','explicit_student_name'],'conflicts'=>['different_full_name','source_name_role_unverified']])];
$long=$base;$long['id']=2;$long['source_name']='Zoë '.str_repeat('Verylongstudentname',8).' <img src=x onerror="window.fixtureXss=1">';$long['stripe_name']='Renée & Family';$long['contact_email']=str_repeat('household',18).'@example.invalid';$long['match_status']='conflict';
$legacy=$base;$legacy['id']=3;$legacy['source_format']='legacy';$legacy['source_name_role']='unresolved';$legacy['match_status']='unmatched';
$reviewed=$base;$reviewed['id']=4;$reviewed['student_identity_reviewed']=1;$reviewed['match_status']='confirmed';$reviewed['student_bma']='BMA-000002';$reviewed['payer_bma']='BMA-000001';
$signer=$base;$signer['id']=5;$signer['waiver_role']='signer';$signer['match_status']='rejected';
$high=$base;$high['id']=6;$high['match_status']='linked';$high['hard_conflict']=0;$high['match_score']=0.96;$high['student_identity_reviewed']=1;
$rows=[$base,$long,$legacy,$reviewed,$signer,$high];
$events=[['created_at'=>'2026-11-02 18:00:00','display_name'=>'Fixture admin <script>alert(1)</script>','action'=>'match_review','metadata'=>json_encode(['action'=>'later','before'=>['status'=>'possible'],'after'=>['status'=>'possible'],'notes'=>'Review notes <svg onload="window.fixtureXss=1">'])]];
$history=fn(int $id): array=>$id===1?$events:[];
function fixtureRender(array $rows, callable $history): string {
    ob_start();uiLayoutStart('Identity match review','matches','',['/assets/css/match-review.css?v=2']);
    echo '<div class="admin-page">';renderMatchReview(['rows'=>$rows,'total'=>count($rows)],'', 'all',1,$history);echo '</div>';uiLayoutEnd();return (string)ob_get_clean();
}
function fixtureBulk(int $eligible): string {
    $summary=['total_candidates'=>12,'eligible_candidates'=>$eligible,'payer_signer_candidates'=>4,'self_student_candidates'=>4,'people_to_create'=>2,'people_to_reuse'=>1,'members_to_create'=>2,'additional_waivers'=>3,'associations_to_confirm'=>9,'excluded_candidates'=>4,'already_decided'=>0,'remaining_manual'=>4];
    $preview=['summary'=>$summary,'exclusions'=>['student_rule_not_met'=>4]];$pending=['operation'=>str_repeat('a',32)];
    ob_start();uiLayoutStart('Bulk confirm exact matches','matches','',['/assets/css/match-review.css?v=3']);echo '<div class="admin-page">';renderBulkMatchPreview($preview,$pending);echo '</div>';uiLayoutEnd();return (string)ob_get_clean();
}
$bulkHtml=fixtureBulk(8);$bulkEmpty=fixtureBulk(0);
$before=serialize([$rows,$events]);$html=fixtureRender($rows,$history);$empty=fixtureRender([],$history);
$checks=0;$check=function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;};
$doc=new DOMDocument();@$doc->loadHTML($html);$x=new DOMXPath($doc);
$check(preg_match('//u',$html)===1&&!preg_match('/(?:âœ|âš|Â·|â€¦|â†|\x{FFFD})/u',$html),'valid UTF-8 markup with no interface mojibake');
$check(str_contains($html,'&lt;img')&&str_contains($html,'&lt;script&gt;')&&$x->query('//img')->length===0&&$x->query('//svg[@onload]')->length===0,'source names and audit text escaped, no injected elements');
$check(str_contains($html,'Zoë')&&str_contains($html,'Renée &amp; Family'),'legitimate accented source names preserved');
$ids=[];foreach($x->query('//*[@id]') as $node){$id=$node->getAttribute('id');$check(!isset($ids[$id]),'unique ID '.$id);$ids[$id]=true;}
foreach($x->query('//label[@for]') as $label)$check(isset($ids[$label->getAttribute('for')]),'label targets an existing field');
$check($x->query('//article')->length===6,'all candidate statuses render');
$check(str_contains($html,'STUDENT (SOURCE LABEL)')&&str_contains($html,'BMA identity unverified')&&str_contains($html,'identity reviewed'),'source student labels and BMA verification remain separate');
$check(str_contains($html,'Form Name role unresolved')&&str_contains($html,'UNRESOLVED NAME'),'legacy ambiguity remains visible');
$check(str_contains($html,'Supporting evidence')&&str_contains($html,'Conflicting evidence')&&str_contains($html,'Missing or unverified'),'evidence categories have explicit text labels');
$check(str_contains($html,'Comparison evidence, not proof of identity.'),'confidence is not presented as identity proof');
$check(str_contains($html,'Historical review flag; student BMA identity now has administrator confirmation'),'historical evidence gets contextual reviewed identity label');
$check($x->query('//link[contains(@href,"bootstrap") and contains(@href,"5.3.3")]')->length===1&&!str_contains($html,'cdn.jsdelivr.net'),'one local Bootstrap version and no shell CDN dependency');
$check($x->query('//script[contains(@src,"bootstrap.bundle.min.js")]')->length===1,'local Bootstrap JavaScript bundle loaded once');
foreach($x->query('//form[@class="review-form"]') as $form){
    $fx=new DOMXPath($doc);$check($fx->query('.//select[@name="action"]/option[@value!=""]',$form)->length===7,'all seven existing review actions retained');
    foreach(['csrf','candidate_id','action','person_bma','side','payer_bma','signer_bma','student_bma','confirm_source_relationship','notes'] as $name)$check($fx->query('.//*[@name="'.$name.'"]',$form)->length===1,'existing submission field retained: '.$name);
    $check($fx->query('.//*[@hidden or @disabled]',$form)->length===0,'no hidden or disabled action-specific controls');
}
$check(serialize([$rows,$events])===$before,'rendering leaves fixture records and historical metadata unchanged');
$check(str_contains($empty,'No candidates found')&&!str_contains($empty,'class="review-candidate"'),'empty state renders without phantom candidates');
foreach(['app/Views/match-review.php','public/admin/matches.php','app/Views/layout.php','public/admin/_common.php'] as $file){$bytes=file_get_contents(dirname(__DIR__).'/'.$file);$check(preg_match('//u',$bytes)===1&&!str_starts_with($bytes,"\xEF\xBB\xBF"),'UTF-8 source without BOM: '.$file);}
$check(str_contains(file_get_contents(dirname(__DIR__).'/public/assets/js/match-review.js'),'window.confirm'),'existing decision confirmation JavaScript retained');
$check(str_contains($html,'href="/admin/bulk-matches.php"'),'bulk preview linked from the review queue');
$check(str_contains($bulkHtml,'Confirm 8 exact matches')&&str_contains($bulkHtml,'Read-only preview'),'bulk preview displays explicit confirmation count');
$check(str_contains($bulkHtml,'name="csrf"')&&str_contains($bulkHtml,'name="confirm_exact"')&&str_contains($bulkHtml,'name="operation_id"'),'bulk form preserves CSRF, operation nonce and explicit confirmation');
$check(!str_contains($bulkEmpty,'type="submit"')||substr_count($bulkEmpty,'type="submit"')===1,'zero eligible preview has no bulk submit (shared logout only)');
$out=getenv('BMA_REVIEW_RENDER_DIR');if($out!==false&&$out!==''){if(!is_dir($out))throw new RuntimeException('Existing private render directory required');file_put_contents($out.'/matches.html',$html);file_put_contents($out.'/empty.html',$empty);file_put_contents($out.'/bulk.html',$bulkHtml);file_put_contents($out.'/bulk-empty.html',$bulkEmpty);}
echo 'Presentation checks: '.$checks.PHP_EOL;
