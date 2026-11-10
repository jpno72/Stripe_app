<?php
declare(strict_types=1);
require_once __DIR__.'/MemberProfileRepository.php';
final class MemberProfileService
{
    public function __construct(private MemberProfileRepository $repository) {}
    public function profile(MemberProfileIdentifier $identifier,string $currency,bool $admin,?array $emergencyAccessConfig=null): ?array
    {
        $member=$this->repository->find($identifier,$admin);if(!$member)return null;
        $id=$member['member_id']===null?-(int)$member['person_id']:(int)$member['member_id'];$payers=$this->repository->payers($id);$subscriptions=$this->repository->subscriptions($id);
        $exclusive=$member['member_id']!==null&&count($payers)===1&&(int)$payers[0]['member_count']===1&&(int)$payers[0]['related_student_count']===1;
        $currencies=$this->repository->paymentCurrencies($id);if($currency===''&&count($currencies)===1&&$currencies[0]!==null)$currency=$currencies[0];
        $totals=$this->repository->totals($id,$currency);$programs=[];
        foreach($subscriptions as $subscription){if(!$subscription['current_assignment'])continue;foreach($subscription['items'] as $item){if($item['product_name'])$programs[$item['product_name']]=true;}}
        $waivers=$this->repository->waivers((int)$member['person_id'],$emergencyAccessConfig);
        $history=self::waiverHistory($waivers);
        return ['member'=>$member,'waiver_history'=>$history,'charge_descriptions'=>$this->repository->chargeDescriptions($id,$currency),'payer_subscriptions'=>$this->repository->payerSubscriptions($payers),'consolidated_sources'=>$emergencyAccessConfig===null?[]:$this->repository->consolidatedSources((int)$member['person_id'],$emergencyAccessConfig),'payers'=>$payers,'guardians'=>$this->repository->guardians((int)$member['person_id']),'subscriptions'=>$subscriptions,'exclusive'=>$exclusive,'currencies'=>$currencies,'currency'=>$currency,'totals'=>$totals,'account_totals'=>$this->repository->totals($id,$currency,true),'trend'=>$currency!==''?$this->repository->trend($id,$currency):[], 'programs'=>array_keys($programs),'waivers'=>$waivers,'identity_links'=>$admin?$this->repository->identityLinks($id,(int)$member['person_id']):[]];
    }
    /** Display-only fallbacks: contacts retain unverified ownership and never update people. */
    public static function waiverHistory(array $waivers): array
    {
        $contacts=[];
        foreach(['contact_email','contact_phone','address_text'] as $field){
            $values=[];$latest=null;
            foreach($waivers as $waiver){$value=trim((string)($waiver[$field]??''));if($value==='')continue;$values[mb_strtolower(preg_replace('/\s+/u',' ',$value))]=true;if($latest===null)$latest=['value'=>$value,'source_record_id'=>$waiver['source_record_id']];}
            $contacts[$field]=$latest===null?null:$latest+['conflicting_values'=>count($values)>1];
        }
        $dated=array_values(array_filter($waivers,static fn($w)=>$w['submitted_at']!==null));
        usort($dated,static fn($a,$b)=>strcmp($a['submitted_at'],$b['submitted_at']));
        return ['count'=>count($waivers),'first'=>$dated[0]??null,'last'=>$dated===[]?null:$dated[count($dated)-1],'contacts'=>$contacts];
    }
}
