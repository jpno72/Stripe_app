<?php
declare(strict_types=1);
require_once __DIR__.'/MemberProfileIdentifier.php';
final class MemberProfileRepository
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql,array $params=[]): array {$q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll();}
    public function find(MemberProfileIdentifier $identifier): ?array
    {
        $field=$identifier->memberId!==null?'m.member_id':'m.bma_person_id';$value=$identifier->memberId??$identifier->bma;
        $rows=$this->rows("SELECT m.member_id,m.person_id,m.bma_person_id,m.member_name,m.member_status,m.member_since,m.member_until,d.age_years,p.email,p.phone,p.city,p.state,p.postal_code,
          f.payer_count,f.payer_person_id,f.payer_member_count,f.financial_attribution_status,f.subscription_allocation_status,
          CASE WHEN m.member_status='active' AND (m.member_until IS NULL OR m.member_until>=CURRENT_DATE()) THEN 'Active' WHEN m.member_status='unknown' AND (m.member_until IS NULL OR m.member_until>=CURRENT_DATE()) THEN 'Unknown' ELSE 'Inactive / non-active' END activity_label
          FROM vw_member_summary m JOIN people p ON p.id=m.person_id JOIN vw_member_demographics d ON d.member_id=m.member_id JOIN vw_member_financial_summary f ON f.member_id=m.member_id WHERE $field=?",[$value]);
        return $rows[0]??null;
    }
    public function payers(int $member): array
    {
        $rows=$this->rows("SELECT scope.payer_person_id,p.bma_person_id,TRIM(CONCAT_WS(' ',p.first_name,p.middle_name,p.last_name)) payer_name,
          scope.payer_person_id=scope.member_person_id is_self,
          (SELECT GROUP_CONCAT(DISTINCT r.relationship_type ORDER BY r.relationship_type SEPARATOR ', ') FROM person_relationships r WHERE r.person_id=scope.payer_person_id AND r.related_person_id=scope.member_person_id) relationships,
          (SELECT COUNT(*) FROM vw_payer_member_scope x WHERE x.payer_person_id=scope.payer_person_id) member_count
          FROM vw_payer_member_scope scope JOIN people p ON p.id=scope.payer_person_id WHERE scope.member_id=? ORDER BY p.last_name,p.id",[$member]);
        foreach($rows as &$row){$row['customers']=$this->rows("SELECT c.stripe_customer_id FROM stripe_customers c JOIN identity_links l ON l.source_system='stripe' AND l.source_type='customer' AND l.source_role='customer' AND l.external_id=c.stripe_customer_id AND l.match_status IN ('automatic','confirmed') WHERE l.person_id=? ORDER BY c.stripe_customer_id",[$row['payer_person_id']]);}unset($row);return $rows;
    }
    public function subscriptions(int $member): array
    {
        $rows=$this->rows("SELECT s.subscription_id,s.stripe_subscription_id,s.stripe_customer_id,s.subscription_status,s.subscription_started_at,s.canceled_at,s.current_period_start,s.current_period_end,s.explicitly_linked_member_count,
          EXISTS(SELECT 1 FROM subscription_members current_map WHERE current_map.subscription_id=s.subscription_id AND current_map.member_id=? AND (current_map.valid_from IS NULL OR current_map.valid_from<=CURRENT_DATE()) AND (current_map.valid_until IS NULL OR current_map.valid_until>=CURRENT_DATE())) current_assignment
          FROM vw_subscription_summary s WHERE EXISTS(SELECT 1 FROM subscription_members map WHERE map.subscription_id=s.subscription_id AND map.member_id=?) ORDER BY s.subscription_id DESC LIMIT 100",[$member,$member]);
        foreach($rows as &$row){$row['items']=$this->rows('SELECT product_name,stripe_price_id,amount_minor,amount_basis,quantity,currency,billing_interval,billing_interval_count,monthly_equivalent_minor FROM vw_subscription_item_rates WHERE subscription_id=? ORDER BY subscription_item_id',[$row['subscription_id']]);}unset($row);return $rows;
    }
    public function waivers(int $person): array
    {
        return $this->rows("SELECT w.source_record_id,w.source_id_kind,w.form_namespace,w.source_locator,w.submitted_at,w.source_timezone,w.signed_date,w.signer_print_name,w.relationship_to_student,w.interpretation_status,
          TRIM(CONCAT_WS(' ',signer.first_name,signer.middle_name,signer.last_name)) signer_name,signer.bma_person_id signer_bma,
          student_link.match_status student_link_status,signer_link.match_status signer_link_status
          FROM cognito_waivers w JOIN identity_links student_link ON student_link.source_system='cognito' AND student_link.source_type='waiver' AND student_link.source_role='student' AND student_link.external_id=w.source_locator AND student_link.match_status IN ('automatic','confirmed')
          LEFT JOIN identity_links signer_link ON signer_link.source_system='cognito' AND signer_link.source_type='waiver' AND signer_link.source_role='signer' AND signer_link.external_id=w.source_locator AND signer_link.match_status IN ('automatic','confirmed')
          LEFT JOIN people signer ON signer.id=signer_link.person_id WHERE student_link.person_id=? ORDER BY w.submitted_at DESC,w.id DESC LIMIT 50",[$person]);
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
        $params[]=$member;$where=" WHERE EXISTS(SELECT 1 FROM vw_payer_member_scope scope WHERE scope.member_id=? AND scope.payer_person_id=t.payer_person_id)";
        if($currency!==''){$where.=' AND t.currency=?';$params[]=$currency;}
        if($search!==''){$where.=" AND (t.stripe_charge_id LIKE ? ESCAPE '!' OR t.stripe_invoice_id LIKE ? ESCAPE '!' OR t.status LIKE ? ESCAPE '!' OR TRIM(CONCAT_WS(' ',payer.first_name,payer.last_name)) LIKE ? ESCAPE '!')";$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr(trim($search),0,100)).'%';array_push($params,$like,$like,$like,$like);}
        return $where;
    }
    public function paymentCurrencies(int $member): array
    {
        $params=[];$where=$this->paymentWhere($member,'',$params);return array_column($this->rows('SELECT DISTINCT t.currency FROM vw_transaction_summary t'.$where.' ORDER BY t.currency',$params),'currency');
    }
    public function totals(int $member,string $currency): array
    {
        $params=[];$where=$this->paymentWhere($member,$currency,$params);
        return $this->rows('SELECT t.currency,COUNT(*) payment_count,SUM(t.gross_paid_minor) gross_paid_minor,
          CASE WHEN SUM(t.refund_known)=COUNT(*) THEN SUM(t.refunds_minor) ELSE NULL END refunds_minor,
          CASE WHEN SUM(t.fee_known)=COUNT(*) THEN SUM(t.stripe_fees_minor) ELSE NULL END stripe_fees_minor,
          CASE WHEN SUM(t.refund_known)=COUNT(*) AND SUM(t.fee_known)=COUNT(*) THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END net_minor,
          MIN(t.stripe_created_at) first_payment,MAX(t.stripe_created_at) last_payment,SUM(t.stripe_created_at IS NULL) undated_count
          FROM vw_transaction_summary t'.$where.' AND t.is_paid=1 GROUP BY t.currency ORDER BY t.currency',$params);
    }
    public function trend(int $member,string $currency): array
    {
        $params=[];$where=$this->paymentWhere($member,$currency,$params);
        return $this->rows('SELECT t.month_start_utc,t.currency,SUM(t.gross_paid_minor) gross_paid_minor,
          CASE WHEN SUM(t.refund_known)=COUNT(*) AND SUM(t.fee_known)=COUNT(*) THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END net_minor
          FROM vw_transaction_summary t'.$where.' AND t.is_paid=1 AND t.stripe_created_at IS NOT NULL GROUP BY t.month_start_utc,t.currency ORDER BY t.month_start_utc,t.currency',$params);
    }
    public function transactionPage(int $member,string $currency,string $search,int $offset,int $limit,int $column,string $direction): array
    {
        $base=' FROM vw_transaction_summary t LEFT JOIN people payer ON payer.id=t.payer_person_id';$p=[];$where=$this->paymentWhere($member,$currency,$p);
        $total=(int)$this->rows('SELECT COUNT(*) count'.$base.$where,$p)[0]['count'];$p=[];$where=$this->paymentWhere($member,$currency,$p,$search);$filtered=(int)$this->rows('SELECT COUNT(*) count'.$base.$where,$p)[0]['count'];
        $orders=['t.stripe_created_at','payer.last_name','t.amount_minor','t.stripe_fees_minor','t.net_after_recorded_costs_minor','t.status','t.stripe_charge_id','t.stripe_invoice_id'];$sort=$orders[$column]??$orders[0];$direction=strtolower($direction)==='asc'?'ASC':'DESC';$offset=max(0,min($offset,1000000));$limit=max(1,min($limit,100));
        $rows=$this->rows("SELECT t.stripe_created_at,t.stripe_charge_id,t.stripe_invoice_id,t.amount_minor,t.currency,t.stripe_fees_minor,t.net_after_recorded_costs_minor,t.status,t.is_paid,TRIM(CONCAT_WS(' ',payer.first_name,payer.last_name)) payer_name".$base.$where." ORDER BY $sort $direction,t.transaction_id DESC LIMIT $limit OFFSET $offset",$p);return compact('total','filtered','rows');
    }
}
