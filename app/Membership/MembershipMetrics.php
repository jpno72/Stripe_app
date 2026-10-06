<?php
declare(strict_types=1);
final class MembershipMetrics
{
    public static function rate(mixed $amount,?string $currency): string
    {
        if ($amount===null || $currency===null) {return 'Unavailable';}
        $raw=(string)$amount;
        if (!preg_match('/\A-?\d+(?:\.\d+)?\z/D',$raw)) {throw new InvalidArgumentException('Invalid minor-unit rate.');}
        [$whole,$fraction]=array_pad(explode('.',$raw,2),2,'');
        $whole=preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$whole);$fraction=rtrim($fraction,'0');
        return $whole.($fraction!==''?'.'.$fraction:'').' '.strtoupper($currency);
    }
    public static function cards(array $data): array
    {
        $m=$data['members'];$s=$data['subscriptions'];$month=$data['period']['label'];
        $mrr=[];$unknown=false;
        foreach ($data['mrr'] as $row) {
            if ($row['currency']===null || (int)$row['known_count']!==(int)$row['count']) {$unknown=true;}
            else {$mrr[]=self::rate($row['amount_minor'],$row['currency']);}
        }
        return [
            ['Active members',number_format((int)$m['active_members']),'Current explicit member status'],
            ['Active subscriptions',number_format((int)$s['active_subscriptions']),'Current Stripe status - separate from members'],
            ['New members this month',$m['new_members']===null?'Unavailable':number_format((int)$m['new_members']),$month.' - '.$m['known_new_members'].' recorded starts; '.$m['unknown_starts'].' unknown start dates'],
            ['Canceled subscriptions this month',$s['canceled_this_month']===null?'Unavailable':number_format((int)$s['canceled_this_month']),$month.' - '.$s['known_canceled'].' recorded; '.$s['unknown_cancellations'].' canceled subscriptions lack dates'],
            ['Past-due subscriptions',number_format((int)$s['past_due']),'Current Stripe past_due status'],
            ['Active subscription MRR',$unknown||$mrr===[]?'Unavailable':implode(' / ',$mrr),'Current snapshot - minor units/month; currencies kept separate'.($unknown?' - unresolved active rates or currencies':'')],
            ['Average completed membership length',$m['average_completed_days']===null?'Unavailable':number_format((float)$m['average_completed_days'],1).' days','Known completed spells only - '.$m['completed_spells'].' records; excludes ongoing/unknown dates'],
        ];
    }
    public static function retention(array $row): array
    {
        $result=[];
        foreach ([3,6,12] as $months) {
            $eligible=(int)$row['eligible_'.$months];$retained=(int)($row['retained_'.$months]??0);
            $result[]=['label'=>$months.'-month milestone','value'=>$eligible>0?number_format(100*$retained/$eligible,1).'%':'Unavailable','detail'=>$retained.' of '.$eligible.' known milestone outcomes; '.((int)$row['total_members']-$eligible).' unresolved/not yet eligible'];
        }
        return $result;
    }
}
