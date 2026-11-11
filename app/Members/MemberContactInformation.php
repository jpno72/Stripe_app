<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Import/Normalizers.php';

/** Display-only precedence. Contact ownership is never inferred from identity links. */
final class MemberContactInformation
{
    public static function build(array $person,array $waiverContacts,array $reviewedFields=[]):array
    {
        $addressFields=['street_address','address_line2','city','state','postal_code'];$address=[];$addressReviewed=true;
        foreach($addressFields as $key){$v=trim((string)($person[$key]??''));if($v==='')continue;$address[]=$v;if(!in_array($key,$reviewedFields,true))$addressReviewed=false;}
        $rows=[];
        foreach(['email'=>['Contact email','contact_email'],'phone'=>['Contact phone','contact_phone'],'address'=>['Mailing / street address, city, state, ZIP','address_text']] as $key=>[$label,$source]){
            $bma=$key==='address'?implode(', ',$address):trim((string)($person[$key]??''));$waiver=$waiverContacts[$source]??null;$value=trim((string)($waiver['value']??''));
            $reviewed=$bma!==''&&($key==='address'?$addressReviewed:in_array($key,$reviewedFields,true));
            $equal=$bma!==''&&$value!==''&&self::compare($key,$bma)===self::compare($key,$value);
            $primary=$reviewed||$value===''?$bma:$value;$fromBma=$reviewed||$value==='';
            $rows[]=['label'=>$label,'value'=>$primary?:null,'source'=>$primary===''?'Unavailable':($fromBma?($reviewed?'Reviewed BMA person contact':'BMA person record — verification not recorded'):'Waiver-provided — unverified'),
                'alternative'=>$equal||$bma===''||$value===''?null:($fromBma?$value:$bma),'alternative_source'=>$fromBma?'Waiver-provided — unverified':'BMA person record — verification not recorded',
                'source_record_id'=>$waiver['source_record_id']??null,'has_waiver'=>$value!=='','historical_disagreement'=>(bool)($waiver['conflicting_values']??false),'different_values'=>$bma!==''&&$value!==''&&!$equal];
        }
        return $rows;
    }
    private static function compare(string $kind,string $v):string
    {
        return match($kind){'email'=>(string)Normalizers::email($v),'phone'=>(string)Normalizers::phone($v),default=>mb_strtolower(trim(preg_replace('/[\s,]+/u',' ',$v)??$v))};
    }
}
