<?php
declare(strict_types=1);
require_once __DIR__.'/MemberProfileIdentifier.php';
require_once __DIR__.'/MemberProfileAccess.php';
require_once __DIR__.'/PeopleDirectoryFilters.php';
require_once __DIR__.'/StripeAssociationRepository.php';
require_once dirname(__DIR__, 2).'/src/AppTime.php';
final class MemberProfileRepository
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql,array $params=[]): array {$q=$this->db->prepare(AppTime::calendarSql($sql));$q->execute($params);return $q->fetchAll();}
    public function peoplePage(array $filters,int $offset,int $limit,int $column,string $direction): array
    {
        $f=PeopleDirectoryFilters::fromRequest($filters);
        $account="EXISTS(SELECT 1 FROM identity_links l WHERE l.source_system='stripe' AND l.source_type='customer' AND l.source_role='customer' AND l.external_id=c.stripe_customer_id AND l.person_id=c.person_id AND l.match_status IN ('confirmed','automatic') AND (l.person_id=p.id OR EXISTS(SELECT 1 FROM person_relationships r WHERE r.person_id=l.person_id AND r.related_person_id=p.id AND r.relationship_type='payer')))";
        $current="(map.valid_from IS NULL OR map.valid_from<=CURRENT_DATE()) AND (map.valid_until IS NULL OR map.valid_until>=CURRENT_DATE())";
        $subscription="EXISTS(SELECT 1 FROM stripe_customers c WHERE c.id=s.customer_id AND $account) OR EXISTS(SELECT 1 FROM subscription_members map WHERE map.subscription_id=s.id AND map.member_id=m.id AND $current)";
        $manual=StripeAssociationRepository::attributionReady($this->db)?" OR EXISTS(SELECT 1 FROM stripe_transaction_attributions a WHERE a.transaction_id=t.id AND (a.person_id=p.id OR (a.attribution_role='payer' AND EXISTS(SELECT 1 FROM person_relationships r WHERE r.person_id=a.person_id AND r.related_person_id=p.id AND r.relationship_type='payer'))))":'';
        $payment="EXISTS(SELECT 1 FROM stripe_customers c WHERE c.id=t.customer_id AND $account)$manual";
        $base="SELECT p.id,p.bma_person_id,p.first_name,p.middle_name,p.last_name,p.email,p.phone,m.id member_id,m.member_status,m.member_since,
          CASE WHEN p.birth_date<=CURRENT_DATE() THEN TIMESTAMPDIFF(YEAR,p.birth_date,CURRENT_DATE()) ELSE NULL END age_years,
          (SELECT GROUP_CONCAT(DISTINCT i.product_name ORDER BY i.product_name SEPARATOR ', ') FROM subscription_members map JOIN vw_subscription_item_rates i ON i.subscription_id=map.subscription_id WHERE map.member_id=m.id AND $current) program,
          (SELECT GROUP_CONCAT(DISTINCT c.stripe_customer_id ORDER BY c.stripe_customer_id SEPARATOR ', ') FROM stripe_customers c WHERE $account) customer_ids,
          (SELECT GROUP_CONCAT(DISTINCT COALESCE(s.status,'unknown') ORDER BY s.status SEPARATOR ', ') FROM stripe_subscriptions s WHERE $subscription) subscription_statuses,
          (SELECT MAX(t.stripe_created_at) FROM stripe_transactions t WHERE $payment) last_payment,
          EXISTS(SELECT 1 FROM stripe_transactions t WHERE $payment) has_payments
          FROM people p LEFT JOIN members m ON m.person_id=p.id";
        $params=[];$where=['1=1'];
        if($f['scope']==='members')$where[]='d.member_id IS NOT NULL';
        foreach(['member_status'=>'d.member_status','subscription_status'=>'d.subscription_statuses'] as $key=>$field)if($f[$key]!==''){
            if($key==='member_status'){$where[]="$field=?";$params[]=$f[$key];}
            else{$where[]="FIND_IN_SET(?,REPLACE($field,', ',','))>0";$params[]=$f[$key];}
        }
        if($f['program']!==''){$where[]="EXISTS(SELECT 1 FROM subscription_members map JOIN vw_subscription_item_rates i ON i.subscription_id=map.subscription_id WHERE map.member_id=d.member_id AND $current AND i.product_name=?)";$params[]=$f['program'];}
        foreach(['customer'=>'d.customer_ids','subscription'=>'d.subscription_statuses'] as $key=>$field)if($f[$key]!=='')$where[]=$field.($f[$key]==='yes'?' IS NOT NULL':' IS NULL');
        if($f['payments']!==''){$where[]='d.has_payments=?';$params[]=$f['payments']==='yes'?1:0;}
        foreach(['age_min'=>'>=','age_max'=>'<='] as $key=>$operator)if($f[$key]!==''){$where[]="d.age_years $operator ?";$params[]=(int)$f[$key];}
        if($f['search']!==''){$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr($f['search'],0,100)).'%';$where[]="(d.bma_person_id LIKE ? ESCAPE '!' OR CONCAT_WS(' ',d.first_name,d.middle_name,d.last_name) LIKE ? ESCAPE '!' OR CONCAT_WS(' ',d.first_name,d.last_name) LIKE ? ESCAPE '!' OR d.email LIKE ? ESCAPE '!' OR d.phone LIKE ? ESCAPE '!')";array_push($params,$like,$like,$like,$like,$like);}
        $from=' FROM ('.$base.') d';$w=' WHERE '.implode(' AND ',$where);
        $total=(int)$this->rows('SELECT COUNT(*) n FROM people')[0]['n'];$filtered=(int)$this->rows('SELECT COUNT(*) n'.$from.$w,$params)[0]['n'];
        $orders=['d.bma_person_id','d.first_name','d.last_name','d.age_years','d.member_status','d.member_since','d.program','d.customer_ids','d.subscription_statuses','d.last_payment'];
        $order=$orders[$column]??$orders[2];$direction=strtolower($direction)==='desc'?'DESC':'ASC';$offset=max(0,min($offset,10000000));$limit=max(1,min($limit,250));
        $rows=$this->rows('SELECT d.*'.$from.$w." ORDER BY $order $direction,d.id ASC LIMIT $limit OFFSET $offset",$params);
        return compact('total','filtered','rows');
    }
    public function searchPeople(string $search): array
    {
        $search=mb_substr(trim($search),0,100);$params=[];$where='';
        if($search!==''){$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$where=" WHERE p.bma_person_id LIKE ? ESCAPE '!' OR CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name) LIKE ? ESCAPE '!' OR p.email LIKE ? ESCAPE '!' OR p.phone LIKE ? ESCAPE '!'";$params=[$like,$like,$like,$like];}
        return $this->rows("SELECT p.id,p.bma_person_id,TRIM(CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name)) person_name,m.member_status FROM people p LEFT JOIN members m ON m.person_id=p.id".$where.' ORDER BY p.last_name,p.first_name,p.id LIMIT 50',$params);
    }
    public function find(MemberProfileIdentifier $identifier,bool $sensitive=false): ?array
    {
        if($identifier->personId!==null){$m=$this->rows('SELECT id FROM members WHERE person_id=?',[$identifier->personId]);return $m!==[]?$this->find(new MemberProfileIdentifier((int)$m[0]['id'],null),$sensitive):$this->findPerson($identifier,$sensitive);}
        $dob=$sensitive?'p.birth_date':'NULL';
        $field=$identifier->memberId!==null?'m.member_id':'m.bma_person_id';$value=$identifier->memberId??$identifier->bma;
        $rows=$this->rows("SELECT m.member_id,m.person_id,m.bma_person_id,m.member_name,m.member_status,m.member_since,m.member_until,$dob birth_date,p.street_address,CASE WHEN p.birth_date<=CURRENT_DATE() THEN TIMESTAMPDIFF(YEAR,p.birth_date,CURRENT_DATE()) ELSE NULL END age_years,p.email,p.phone,p.city,p.state,p.postal_code,
          f.payer_count,f.payer_person_id,f.payer_member_count,f.financial_attribution_status,f.subscription_allocation_status,
          CASE WHEN m.member_status='active' AND (m.member_until IS NULL OR m.member_until>=CURRENT_DATE()) THEN 'Active' WHEN m.member_status='unknown' AND (m.member_until IS NULL OR m.member_until>=CURRENT_DATE()) THEN 'Unknown' ELSE 'Inactive / non-active' END activity_label
          FROM vw_member_summary m JOIN people p ON p.id=m.person_id JOIN vw_member_demographics d ON d.member_id=m.member_id JOIN vw_member_financial_summary f ON f.member_id=m.member_id WHERE $field=?",[$value]);
        return $rows[0]??($identifier->bma!==null?$this->findPerson($identifier,$sensitive):null);
    }
    private function findPerson(MemberProfileIdentifier $id,bool $sensitive=false): ?array
    {
        $field=$id->personId!==null?'p.id':'p.bma_person_id';$value=$id->personId??$id->bma;
        $dob=$sensitive?'p.birth_date':'NULL';
        $rows=$this->rows("SELECT NULL member_id,p.id person_id,p.bma_person_id,TRIM(CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name)) member_name,'not_verified' member_status,NULL member_since,NULL member_until,$dob birth_date,p.street_address,CASE WHEN p.birth_date<=CURRENT_DATE() THEN TIMESTAMPDIFF(YEAR,p.birth_date,CURRENT_DATE()) ELSE NULL END age_years,p.email,p.phone,p.city,p.state,p.postal_code,'No member record' activity_label FROM people p WHERE $field=?",[$value]);return $rows[0]??null;
    }
    public function consolidatedSources(int $person,array $config): array
    {
        require_once dirname(__DIR__).'/Import/ConsolidatedInputs.php';
        $m=$this->rows("SELECT external_id FROM identity_links WHERE person_id=? AND source_system='manual' AND source_type='consolidated_waiver' AND match_status='confirmed'",[$person]);$ids=array_column($this->rows("SELECT w.source_record_id FROM cognito_waivers w JOIN identity_links l ON l.source_system='cognito' AND l.source_type='waiver' AND l.source_role='student' AND l.external_id=w.source_locator AND l.person_id=w.student_person_id AND l.match_status='confirmed' WHERE l.person_id=?",[$person]),'source_record_id');$groups=[];foreach($m as $row){$id=substr($row['external_id'],strlen('bma3y-v1:'));if(preg_match('/\ABMA-W-[0-9]{4,}\z/D',$id))$groups[$id]=$ids;}
        if($groups===[])return [];return ConsolidatedInputs::supplemental($groups,ConsolidatedInputs::directory(),Paths::servedRoots($config),MemberProfileAccess::canViewEmergencyContacts($this->db,$config),MemberProfileAccess::canReview($this->db,$config));
    }
    public function payers(int $member): array
    {
        if($member<0){$person=-$member;$rows=$this->rows("SELECT p.id payer_person_id,p.bma_person_id,TRIM(CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name)) payer_name,p.id=? is_self,CASE WHEN p.id=? THEN 'self account' ELSE 'payer' END relationships,(SELECT COUNT(*) FROM vw_payer_member_scope x WHERE x.payer_person_id=p.id) member_count FROM people p WHERE (p.id=? AND EXISTS(SELECT 1 FROM stripe_customers c JOIN identity_links own ON own.source_system='stripe' AND own.source_type='customer' AND own.source_role='customer' AND own.external_id=c.stripe_customer_id AND own.person_id=c.person_id AND own.match_status='confirmed' WHERE c.person_id=p.id)) OR EXISTS(SELECT 1 FROM person_relationships r WHERE r.person_id=p.id AND r.related_person_id=? AND r.relationship_type='payer')",[$person,$person,$person,$person]);}
        else
        $rows=$this->rows("SELECT scope.payer_person_id,p.bma_person_id,TRIM(CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name)) payer_name,
          scope.payer_person_id=scope.member_person_id is_self,
          (SELECT GROUP_CONCAT(DISTINCT r.relationship_type ORDER BY r.relationship_type SEPARATOR ', ') FROM person_relationships r WHERE r.person_id=scope.payer_person_id AND r.related_person_id=scope.member_person_id) relationships,
          (SELECT COUNT(*) FROM vw_payer_member_scope x WHERE x.payer_person_id=scope.payer_person_id) member_count
          FROM vw_payer_member_scope scope JOIN people p ON p.id=scope.payer_person_id WHERE scope.member_id=? ORDER BY p.last_name,p.id",[$member]);
        foreach($rows as &$row){$row['customers']=$this->rows("SELECT c.stripe_customer_id,c.name,c.email FROM stripe_customers c JOIN identity_links l ON l.source_system='stripe' AND l.source_type='customer' AND l.source_role='customer' AND l.external_id=c.stripe_customer_id AND l.match_status IN ('automatic','confirmed') WHERE l.person_id=? ORDER BY c.stripe_customer_id",[$row['payer_person_id']]); $row['related_student_count']=(int)$this->rows("SELECT COUNT(*) n FROM (SELECT related_person_id person_id FROM person_relationships WHERE person_id=? AND relationship_type='payer' UNION SELECT person_id FROM members WHERE person_id=?) participants",[$row['payer_person_id'],$row['payer_person_id']])[0]['n'];}unset($row);return $rows;
    }
    public function guardians(int $person): array
    {
        return $this->rows("SELECT p.bma_person_id,TRIM(CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name)) name,r.relationship_type FROM person_relationships r JOIN people p ON p.id=r.person_id WHERE r.related_person_id=? AND r.relationship_type IN ('parent','guardian') ORDER BY p.id,r.relationship_type",[$person]);
    }
    public function subscriptions(int $member): array
    {
        if($member<0)return [];
        $rows=$this->rows("SELECT s.subscription_id,s.stripe_subscription_id,s.stripe_customer_id,s.subscription_status,s.subscription_started_at,s.canceled_at,s.current_period_start,s.current_period_end,s.explicitly_linked_member_count,
          EXISTS(SELECT 1 FROM subscription_members current_map WHERE current_map.subscription_id=s.subscription_id AND current_map.member_id=? AND (current_map.valid_from IS NULL OR current_map.valid_from<=CURRENT_DATE()) AND (current_map.valid_until IS NULL OR current_map.valid_until>=CURRENT_DATE())) current_assignment
          FROM vw_subscription_summary s WHERE EXISTS(SELECT 1 FROM subscription_members map WHERE map.subscription_id=s.subscription_id AND map.member_id=?) ORDER BY s.subscription_id DESC",[$member,$member]);
        foreach($rows as &$row){$row['items']=$this->rows('SELECT product_name,stripe_price_id,amount_minor,amount_basis,quantity,currency,billing_interval,billing_interval_count,monthly_equivalent_minor FROM vw_subscription_item_rates WHERE subscription_id=? ORDER BY subscription_item_id',[$row['subscription_id']]);}unset($row);return $rows;
    }
    public function waivers(int $person,?array $emergencyAccessConfig=null): array
    {
        // No sensitive columns are selected unless the current session and canonical permissions allow it.
        $emergency=$emergencyAccessConfig!==null && MemberProfileAccess::canViewEmergencyContacts($this->db,$emergencyAccessConfig)
            ? ',w.emergency_contact_name,w.emergency_contact_phone' : '';
        return $this->rows("SELECT w.source_record_id,w.source_id_kind,w.form_namespace,w.source_locator,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(raw.raw_values, '$.\"Date Submitted\"')),JSON_UNQUOTE(JSON_EXTRACT(raw.raw_values, '$.Entry_DateSubmitted'))) original_submitted_at,w.submitted_at,w.source_timezone,w.source_name,w.contact_email,w.contact_phone,w.address_text,w.signed_date,w.signer_print_name,w.relationship_to_student,w.interpretation_status,
          TRIM(CONCAT_WS(' ',signer.first_name,signer.middle_name,signer.last_name)) signer_name,signer.bma_person_id signer_bma,
          student_link.match_status student_link_status,signer_link.match_status signer_link_status$emergency
          FROM cognito_waivers w LEFT JOIN raw_cognito_waivers raw ON raw.id=w.latest_raw_id JOIN identity_links student_link ON student_link.source_system='cognito' AND student_link.source_type='waiver' AND student_link.source_role='student' AND student_link.external_id=w.source_locator AND student_link.match_status IN ('automatic','confirmed')
          LEFT JOIN identity_links signer_link ON signer_link.source_system='cognito' AND signer_link.source_type='waiver' AND signer_link.source_role='signer' AND signer_link.external_id=w.source_locator AND signer_link.match_status IN ('automatic','confirmed')
          LEFT JOIN people signer ON signer.id=signer_link.person_id WHERE student_link.person_id=? ORDER BY w.submitted_at DESC,w.id DESC",[$person]);
    }
    public function identityLinks(int $member,int $person): array
    {
        return $this->rows("SELECT l.source_system,l.source_type,l.external_id,l.source_role,l.match_method,l.match_score,l.match_reason,l.match_status,l.reviewed_at,p.bma_person_id,u.display_name reviewer
          FROM identity_links l JOIN people p ON p.id=l.person_id LEFT JOIN app_users u ON u.id=l.reviewed_by
          WHERE l.person_id=? OR EXISTS(SELECT 1 FROM vw_payer_member_scope scope WHERE scope.member_id=? AND scope.payer_person_id=l.person_id)
          ORDER BY l.updated_at DESC,l.id DESC LIMIT 100",[$person,$member]);
    }
    private function paymentWhere(int $member,string $currency,array &$params,string $search=''): string
    {
        if($member<0){$person=-$member;$params[]=$person;$params[]=$person;$where=" WHERE (t.payer_person_id=? OR EXISTS(SELECT 1 FROM person_relationships r WHERE r.person_id=t.payer_person_id AND r.related_person_id=? AND r.relationship_type='payer'))";}
        else{$params[]=$member;$where=" WHERE EXISTS(SELECT 1 FROM vw_payer_member_scope scope WHERE scope.member_id=? AND scope.payer_person_id=t.payer_person_id)";}
        if(StripeAssociationRepository::attributionReady($this->db)){
            if($member<0){$manual="a.person_id=? OR (a.attribution_role='payer' AND EXISTS(SELECT 1 FROM person_relationships r WHERE r.person_id=a.person_id AND r.related_person_id=? AND r.relationship_type='payer'))";array_push($params,-$member,-$member);}
            else{$manual="a.person_id=(SELECT person_id FROM members WHERE id=?) OR (a.attribution_role='payer' AND EXISTS(SELECT 1 FROM vw_payer_member_scope scope WHERE scope.member_id=? AND scope.payer_person_id=a.person_id))";array_push($params,$member,$member);}
            $where=' WHERE ('.substr($where,7).' OR EXISTS(SELECT 1 FROM stripe_transaction_attributions a WHERE a.transaction_id=t.transaction_id AND ('.$manual.')))';
        }
        if($currency!==''){$where.=' AND t.currency=?';$params[]=$currency;}
        if($search!==''){$where.=" AND (t.stripe_charge_id LIKE ? ESCAPE '!' OR t.stripe_invoice_id LIKE ? ESCAPE '!' OR t.status LIKE ? ESCAPE '!' OR TRIM(CONCAT_WS(' ',payer.first_name,payer.last_name)) LIKE ? ESCAPE '!')";$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr(trim($search),0,100)).'%';array_push($params,$like,$like,$like,$like);}
        return $where;
    }
    public function chargeDescriptions(int $member,string $currency): array
    {
        $params=[];$where=$this->paymentWhere($member,$currency,$params);return $this->rows("SELECT t.currency,NULLIF(TRIM(tx.description),'') description,COUNT(*) occurrence_count FROM vw_transaction_summary t JOIN stripe_transactions tx ON tx.id=t.transaction_id".$where." AND t.is_paid=1 GROUP BY t.currency,NULLIF(TRIM(tx.description),'') ORDER BY occurrence_count DESC",$params);
    }
    public function payerSubscriptions(array $payers): array
    {
        $ids=array_map('intval',array_column($payers,'payer_person_id'));if($ids===[])return [];$marks=implode(',',array_fill(0,count($ids),'?'));return $this->rows("SELECT s.stripe_subscription_id,s.status,s.stripe_subscription_started_at,c.stripe_customer_id FROM stripe_subscriptions s JOIN stripe_customers c ON c.id=s.customer_id JOIN identity_links l ON l.source_system='stripe' AND l.source_type='customer' AND l.source_role='customer' AND l.external_id=c.stripe_customer_id AND l.person_id=c.person_id AND l.match_status='confirmed' WHERE l.person_id IN ($marks) ORDER BY s.id",$ids);
    }
    public function paymentCurrencies(int $member): array
    {
        $params=[];$where=$this->paymentWhere($member,'',$params);return array_column($this->rows('SELECT DISTINCT t.currency FROM vw_transaction_summary t'.$where.' ORDER BY t.currency',$params),'currency');
    }
    public function hasExplicitAllocations(int $member):bool
    {
        if(!StripeAssociationRepository::attributionReady($this->db))return false;
        $params=[];$where=$this->paymentWhere($member,'',$params);
        return $this->rows('SELECT 1 FROM vw_transaction_summary t JOIN stripe_transaction_attributions allocation ON allocation.transaction_id=t.transaction_id'.$where.' LIMIT 1',$params)!==[];
    }
    public function totals(int $member,string $currency,bool $accounts=false): array
    {
        $params=[];$where=$this->paymentWhere($member,$currency,$params);
        $account=$accounts?'t.stripe_customer_id,':'';$group=$accounts?',t.stripe_customer_id':'';
        return $this->rows("SELECT $account t.currency,COUNT(*) transaction_count,SUM(t.is_paid=1) payment_count,SUM(t.status='failed') failed_count,SUM(t.status='refunded') fully_refunded_count,SUM(t.gross_paid_minor) gross_paid_minor,
          CASE WHEN SUM(t.is_paid=1 AND t.refund_known=0)=0 THEN SUM(CASE WHEN t.is_paid=1 THEN t.gross_paid_minor-t.refunds_minor ELSE 0 END) ELSE NULL END collections_before_fees_minor,
          CASE WHEN COUNT(t.amount_refunded_minor)=COUNT(*) THEN SUM(t.amount_refunded_minor) ELSE NULL END refunds_minor,
          CASE WHEN SUM(t.is_paid)>0 AND SUM(t.fee_known)=SUM(t.is_paid) THEN SUM(t.stripe_fees_minor) ELSE NULL END stripe_fees_minor,
          CASE WHEN SUM(t.is_paid)>0 AND SUM(t.refund_known)=SUM(t.is_paid) AND SUM(t.fee_known)=SUM(t.is_paid) THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END net_minor,
          MIN(CASE WHEN t.is_paid=1 THEN t.stripe_created_at END) first_payment,MAX(CASE WHEN t.is_paid=1 THEN t.stripe_created_at END) last_payment,SUM(t.stripe_created_at IS NULL) undated_count
          FROM vw_transaction_summary t".$where." GROUP BY t.currency$group ORDER BY t.currency$group",$params);
    }
    public function trend(int $member,string $currency): array
    {
        $params=[];$where=$this->paymentWhere($member,$currency,$params);
        $range=$this->rows('SELECT MIN(t.stripe_created_at) first_at,MAX(t.stripe_created_at) last_at FROM vw_transaction_summary t'.$where.' AND t.is_paid=1',$params)[0];
        if($range['first_at']===null)return [];
        $groupParams=[];$local=AppTime::localSql('t.stripe_created_at',$range['first_at'],$range['last_at'],$groupParams);
        return $this->rows("SELECT DATE_FORMAT($local, '%Y-%m-01') month_start_local,t.currency,SUM(t.gross_paid_minor) gross_paid_minor,
          CASE WHEN SUM(t.refund_known)=COUNT(*) AND SUM(t.fee_known)=COUNT(*) THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END net_minor
          FROM vw_transaction_summary t".$where." AND t.is_paid=1 AND t.stripe_created_at IS NOT NULL GROUP BY month_start_local,t.currency ORDER BY month_start_local,t.currency",array_merge($groupParams,$params));
    }
    public function transactionPage(int $member,string $currency,string $search,int $offset,int $limit,int $column,string $direction): array
    {
        $base=' FROM vw_transaction_summary t LEFT JOIN people payer ON payer.id=t.payer_person_id';$p=[];$where=$this->paymentWhere($member,$currency,$p);
        $total=(int)$this->rows('SELECT COUNT(*) count'.$base.$where,$p)[0]['count'];$p=[];$where=$this->paymentWhere($member,$currency,$p,$search);$filtered=(int)$this->rows('SELECT COUNT(*) count'.$base.$where,$p)[0]['count'];
        $orders=['t.stripe_created_at','payer.last_name','t.amount_minor','t.stripe_fees_minor','t.net_after_recorded_costs_minor','t.status','t.stripe_charge_id','t.stripe_invoice_id'];$sort=$orders[$column]??$orders[0];$direction=strtolower($direction)==='asc'?'ASC':'DESC';$offset=max(0,min($offset,1000000));$limit=max(1,min($limit,100));
        $rows=$this->rows("SELECT t.stripe_created_at,t.stripe_charge_id,t.stripe_invoice_id,t.amount_minor,t.currency,t.stripe_fees_minor,t.net_after_recorded_costs_minor,t.status,t.is_paid,TRIM(CONCAT_WS(' ',payer.first_name,payer.last_name)) payer_name".$base.$where." ORDER BY $sort $direction,t.transaction_id DESC LIMIT $limit OFFSET $offset",$p);return compact('total','filtered','rows');
    }
}
