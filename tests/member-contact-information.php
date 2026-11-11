<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/Members/MemberContactInformation.php';
require dirname(__DIR__).'/app/Views/components.php';
$checks=0;function contactCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
$person=['email'=>'member@example.invalid','phone'=>'(570) 555-1111','street_address'=>'10 Main St','address_line2'=>'Suite 2','city'=>'Bloomsburg','state'=>'PA','postal_code'=>'17815'];
$waivers=['contact_email'=>['value'=>'parent@example.invalid','source_record_id'=>'fixture-2','conflicting_values'=>true],'contact_phone'=>['value'=>'5705552222','source_record_id'=>'fixture-1','conflicting_values'=>false],'address_text'=>['value'=>'20 Other St, Bloomsburg PA 17815','source_record_id'=>'fixture-3','conflicting_values'=>true]];
$before=serialize([$person,$waivers]);$reviewed=array_keys($person);$rows=MemberContactInformation::build($person,$waivers,$reviewed);
contactCheck($rows[0]['value']===$person['email']&&$rows[0]['source']==='Reviewed BMA person contact','reviewed BMA email takes precedence');
contactCheck($rows[0]['alternative']===$waivers['contact_email']['value']&&$rows[0]['alternative_source']==='Waiver-provided — unverified','conflicting household email remains labeled alternative');
contactCheck($rows[1]['value']===$person['phone']&&$rows[1]['alternative']==='5705552222','reviewed telephone and differing source telephone preserved');
contactCheck(str_contains($rows[2]['value'],'Suite 2')&&str_contains($rows[2]['value'],'Bloomsburg, PA, 17815'),'complete structured mailing address retained');
contactCheck($rows[0]['historical_disagreement']&&$rows[2]['source_record_id']==='fixture-3','history warnings and per-field waiver attribution retained');
$rows=MemberContactInformation::build([],$waivers);
contactCheck($rows[0]['value']==='parent@example.invalid'&&$rows[0]['source']==='Waiver-provided — unverified','missing person email falls back without claiming student ownership');
$rows=MemberContactInformation::build($person,$waivers);
contactCheck($rows[0]['value']==='parent@example.invalid'&&$rows[0]['alternative_source']==='BMA person record — verification not recorded','unverified person contact does not acquire invented verification');
$same=$waivers;$same['contact_email']['value']='MEMBER@EXAMPLE.INVALID';$same['contact_phone']['value']='5705551111';$rows=MemberContactInformation::build($person,$same,$reviewed);
contactCheck($rows[0]['alternative']===null&&$rows[1]['alternative']===null,'equivalent email/phone values displayed once');
contactCheck($rows[0]['source_record_id']==='fixture-2'&&$rows[0]['has_waiver'],'identical values still retain source attribution');
$rows=MemberContactInformation::build($person,[]);
contactCheck($rows[0]['value']===$person['email']&&$rows[0]['source']==='BMA person record — verification not recorded','person-only values remain visible without inventing verification');
$rows=MemberContactInformation::build([],[]);contactCheck($rows[0]['value']===null&&$rows[2]['value']===null,'missing contact data stays unknown');
contactCheck(serialize([$person,$waivers])===$before,'display model does not mutate supplied person or waiver data');
$unsafe=$waivers;$unsafe['contact_email']['value']='<img src=x onerror=alert(1)>';$data=['contact_information'=>MemberContactInformation::build([],$unsafe)];ob_start();require dirname(__DIR__).'/app/Views/partials/member-contact-information.php';$html=ob_get_clean();
contactCheck(!str_contains($html,'<img')&&str_contains($html,'&lt;img'),'untrusted waiver contact text escaped');
contactCheck(!str_contains($html,'Emergency')&&!str_contains($html,'medical'),'ordinary contact section excludes emergency and medical fields');
contactCheck(substr_count($html,'Contact Information')===1&&!str_contains($html,'Latest waiver contacts — source information'),'single consolidated contact section');
echo "$checks contact information checks passed.\n";
