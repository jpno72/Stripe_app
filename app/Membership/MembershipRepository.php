<?php
declare(strict_types=1);
require_once __DIR__.'/MembershipFilters.php';
require_once dirname(__DIR__, 2).'/src/AppTime.php';
final class MembershipRepository
{
    public function __construct(private PDO $db) {}
    private function rows(string $sql,array $params=[]): array { $q=$this->db->prepare(AppTime::calendarSql($sql)); $q->execute($params); return $q->fetchAll(); }
    private function memberWhere(MembershipFilters $f,array &$params,string $search=''): string
    {
        $w=['1=1'];
        if ($f->memberStatus!=='') {$w[]='m.member_status=?';$params[]=$f->memberStatus;}
        if ($f->program!=='' || $f->subscriptionStatus!=='') {
            $s=['map.member_id=m.member_id','(map.valid_from IS NULL OR map.valid_from<=CURRENT_DATE())','(map.valid_until IS NULL OR map.valid_until>=CURRENT_DATE())'];
            if ($f->program!=='') {$s[]='EXISTS (SELECT 1 FROM vw_subscription_item_rates i WHERE i.subscription_id=sub.subscription_id AND i.product_name=?)';$params[]=$f->program;}
            if ($f->subscriptionStatus!=='') {$s[]='sub.subscription_status=?';$params[]=$f->subscriptionStatus;}
            $w[]='EXISTS (SELECT 1 FROM subscription_members map JOIN vw_subscription_summary sub ON sub.subscription_id=map.subscription_id WHERE '.implode(' AND ',$s).')';
        }
        if ($search!=='') {
            $w[]="(m.bma_person_id LIKE ? ESCAPE '!' OR m.member_name LIKE ? ESCAPE '!' OR f.payer_name LIKE ? ESCAPE '!' OR f.stripe_subscription_id LIKE ? ESCAPE '!' OR f.membership_product LIKE ? ESCAPE '!')";
            $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr($search,0,100)).'%';
            array_push($params,$like,$like,$like,$like,$like);
        }
        return ' WHERE '.implode(' AND ',$w);
    }
    private function subscriptionWhere(MembershipFilters $f,array &$params): string
    {
        $w=['1=1'];
        if ($f->subscriptionStatus!=='') {$w[]='s.subscription_status=?';$params[]=$f->subscriptionStatus;}
        if ($f->program!=='') {$w[]='EXISTS (SELECT 1 FROM vw_subscription_item_rates i WHERE i.subscription_id=s.subscription_id AND i.product_name=?)';$params[]=$f->program;}
        if ($f->memberStatus!=='') {
            $w[]='EXISTS (SELECT 1 FROM subscription_members map JOIN vw_member_summary m ON m.member_id=map.member_id WHERE map.subscription_id=s.subscription_id AND m.member_status=? AND (map.valid_from IS NULL OR map.valid_from<=CURRENT_DATE()) AND (map.valid_until IS NULL OR map.valid_until>=CURRENT_DATE()))';
            $params[]=$f->memberStatus;
        }
        return ' WHERE '.implode(' AND ',$w);
    }
    public function options(): array
    {
        return ['programs'=>$this->db->query('SELECT DISTINCT product_name FROM vw_subscription_item_rates WHERE product_name IS NOT NULL ORDER BY product_name')->fetchAll(PDO::FETCH_COLUMN),
            'member_statuses'=>$this->db->query('SELECT DISTINCT member_status FROM vw_member_summary ORDER BY member_status')->fetchAll(PDO::FETCH_COLUMN),
            'subscription_statuses'=>$this->db->query('SELECT DISTINCT subscription_status FROM vw_subscription_summary WHERE subscription_status IS NOT NULL ORDER BY subscription_status')->fetchAll(PDO::FETCH_COLUMN)];
    }
    public function overview(MembershipFilters $f): array
    {
        $p=[];$mw=$this->memberWhere($f,$p);
        $members=$this->rows("SELECT COUNT(*) member_count, COALESCE(SUM(m.member_status='active' AND (m.member_until IS NULL OR m.member_until>=CURRENT_DATE())),0) active_members,
          COALESCE(SUM(m.member_since IS NULL),0) unknown_starts,
          COALESCE(SUM(m.member_since>=CAST(DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') AS DATE) AND m.member_since<=CURRENT_DATE()),0) known_new_members,
          SUM(CASE WHEN m.member_since IS NOT NULL AND m.member_until IS NOT NULL AND m.member_until<=CURRENT_DATE() THEN DATEDIFF(m.member_until,m.member_since) END)/NULLIF(SUM(m.member_since IS NOT NULL AND m.member_until IS NOT NULL AND m.member_until<=CURRENT_DATE()),0) average_completed_days,
          COALESCE(SUM(m.member_since IS NOT NULL AND m.member_until IS NOT NULL AND m.member_until<=CURRENT_DATE()),0) completed_spells
          FROM vw_member_summary m".$mw,$p)[0];
        $members['new_members']=$members['unknown_starts']>0 ? null : $members['known_new_members'];
        $monthStart=$this->db->quote(AppTime::boundary(AppTime::now()->format('Y-m-01')));
        $dayEnd=$this->db->quote(AppTime::boundary(AppTime::today(),true));
        $p=[];$sw=$this->subscriptionWhere($f,$p);
        $subscriptions=$this->rows("SELECT COUNT(*) subscription_count, COUNT(DISTINCT s.customer_id) customer_count,
          COALESCE(SUM(s.subscription_status='active'),0) active_subscriptions, COALESCE(SUM(s.subscription_status='past_due'),0) past_due,
          COALESCE(SUM(s.subscription_status='canceled' AND s.canceled_at IS NULL),0) unknown_cancellations,
          COALESCE(SUM(s.canceled_at>=$monthStart AND s.canceled_at<$dayEnd),0) known_canceled
          FROM vw_subscription_summary s".$sw,$p)[0];
        $subscriptions['canceled_this_month']=$subscriptions['unknown_cancellations']>0 ? null : $subscriptions['known_canceled'];
        $statuses=$this->rows('SELECT COALESCE(s.subscription_status,\'unknown\') label,COUNT(*) count FROM vw_subscription_summary s'.$sw.' GROUP BY s.subscription_status ORDER BY count DESC,label',$p);
        $mrr=$this->rows("SELECT s.currency,COUNT(*) count,COUNT(s.active_mrr_minor) known_count,SUM(s.active_mrr_minor) amount_minor FROM vw_subscription_summary s".$sw." AND s.subscription_status='active' GROUP BY s.currency ORDER BY s.currency",$p);
        // One product bucket per subscription; multi-item and unknown labels are explicit.
        $mix=$this->rows("SELECT CASE WHEN s.item_count>1 THEN 'Multiple items' WHEN s.single_product_name IS NULL THEN 'Unassigned' ELSE s.single_product_name END label,COUNT(*) count FROM vw_subscription_summary s".$sw.' GROUP BY label ORDER BY count DESC,label',$p);
        $trendStart=AppTime::boundary(AppTime::now()->modify('first day of this month')->modify('-11 months')->format('Y-m-d'));
        $trendEnd=AppTime::boundary(AppTime::today(),true);
        $groupParams=[];$cancelLocal=AppTime::localSql('s.canceled_at',$trendStart,$trendEnd,$groupParams);
        $cancellations=$this->rows("SELECT DATE_FORMAT($cancelLocal,'%Y-%m-01') month,COUNT(*) count FROM vw_subscription_summary s".$sw." AND s.canceled_at>=? AND s.canceled_at<? GROUP BY month ORDER BY month",array_merge($groupParams,$p,[$trendStart,$trendEnd]));
        $p=[];$mw=$this->memberWhere($f,$p);
        $retentionCalendar='(SELECT member_id,' . implode(',',array_map(static fn($months)=>"CASE WHEN member_since IS NULL OR member_since>CURRENT_DATE() OR DATE_ADD(member_since,INTERVAL $months MONTH)>CURRENT_DATE() THEN NULL WHEN member_until IS NOT NULL THEN IF(member_until>=DATE_ADD(member_since,INTERVAL $months MONTH),1,0) WHEN member_status='active' THEN 1 ELSE NULL END retained_{$months}_months",[3,6,12])) . ' FROM vw_member_summary)';
        $retention=$this->rows('SELECT COUNT(*) total_members,COUNT(r.retained_3_months) eligible_3,SUM(r.retained_3_months) retained_3,COUNT(r.retained_6_months) eligible_6,SUM(r.retained_6_months) retained_6,COUNT(r.retained_12_months) eligible_12,SUM(r.retained_12_months) retained_12 FROM vw_member_summary m JOIN '.$retentionCalendar.' r ON r.member_id=m.member_id'.$mw,$p)[0];
        $events=$this->rows("SELECT DATE_FORMAT(m.member_since,'%Y-%m-01') month,COUNT(*) starts FROM vw_member_summary m".$mw." AND m.member_since>=DATE_SUB(CAST(DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') AS DATE),INTERVAL 11 MONTH) AND m.member_since<=CURRENT_DATE() GROUP BY month ORDER BY month",$p);
        $period=['label'=>AppTime::now()->format('F Y'),'as_of'=>AppTime::today()];
        return compact('members','subscriptions','statuses','mrr','mix','retention','events','cancellations','period');
    }
    public function memberPage(MembershipFilters $f,string $search,int $offset,int $limit,int $column,string $direction): array
    {
        $base=' FROM vw_member_summary m LEFT JOIN vw_member_financial_summary f ON f.member_id=m.member_id';
        $p=[];$w=$this->memberWhere($f,$p);$total=(int)$this->rows('SELECT COUNT(*) count'.$base.$w,$p)[0]['count'];
        $p=[];$w=$this->memberWhere($f,$p,trim($search));$filtered=(int)$this->rows('SELECT COUNT(*) count'.$base.$w,$p)[0]['count'];
        $orders=['m.bma_person_id','m.member_name','f.payer_name','f.membership_product','m.member_status','m.member_since','f.stripe_subscription_id','f.monthly_equivalent_minor','f.last_payment_at'];
        $sort=$orders[$column]??$orders[1];$direction=strtolower($direction)==='desc'?'DESC':'ASC';
        $offset=max(0,min($offset,1000000));$limit=max(1,min($limit,100));
        $rows=$this->rows('SELECT m.bma_person_id,m.member_name,m.member_status,m.member_since,f.payer_name,f.payer_count,f.membership_product,f.stripe_subscription_id,f.subscription_status,f.monthly_equivalent_minor,s.currency rate_currency,f.last_payment_at,f.financial_attribution_status,f.subscription_allocation_status'.$base.' LEFT JOIN vw_subscription_summary s ON s.stripe_subscription_id=f.stripe_subscription_id'.$w." ORDER BY $sort $direction,m.member_id ASC LIMIT $limit OFFSET $offset",$p);
        return compact('total','filtered','rows');
    }
}
