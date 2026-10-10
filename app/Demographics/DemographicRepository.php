<?php
declare(strict_types=1);
require_once __DIR__.'/DemographicFilters.php';
require_once dirname(__DIR__, 2).'/src/AppTime.php';
final class DemographicRepository
{
    public function __construct(private PDO $db,private AgeBrackets $brackets) {}
    private function rows(string $sql,array $params=[]): array {$age='CASE WHEN d.birth_date<=CURRENT_DATE() THEN TIMESTAMPDIFF(YEAR,d.birth_date,CURRENT_DATE()) ELSE NULL END';$sql=str_replace('d.age_years',$age,$sql);$sql=str_replace('SELECT '.$age.',','SELECT '.$age.' AS age_years,',$sql);$q=$this->db->prepare(AppTime::calendarSql($sql));$q->execute($params);return $q->fetchAll();}
    private function where(DemographicFilters $f,array &$params): string
    {
        $w=['1=1'];
        if($f->status==='active')$w[]="m.member_status='active' AND (m.member_until IS NULL OR m.member_until>=CURRENT_DATE())";
        elseif($f->status==='inactive')$w[]="((COALESCE(m.member_status,'unknown') NOT IN ('active','unknown','')) OR m.member_until<CURRENT_DATE())";
        elseif($f->status==='unknown')$w[]="COALESCE(m.member_status,'unknown') IN ('unknown','') AND (m.member_until IS NULL OR m.member_until>=CURRENT_DATE())";
        if($f->program!==''){
            $w[]='EXISTS (SELECT 1 FROM subscription_members map JOIN vw_subscription_item_rates i ON i.subscription_id=map.subscription_id WHERE map.member_id=d.member_id AND (map.valid_from IS NULL OR map.valid_from<=CURRENT_DATE()) AND (map.valid_until IS NULL OR map.valid_until>=CURRENT_DATE()) AND i.product_name=?)';$params[]=$f->program;
        }
        if($f->age==='unknown')$w[]='d.age_years IS NULL';
        elseif($f->age!==''){$bracket=$this->brackets->find($f->age);if($bracket===null)throw new InvalidArgumentException('Invalid age bracket.');$w[]='d.age_years>=?';$params[]=$bracket['min'];if($bracket['max']!==null){$w[]='d.age_years<=?';$params[]=$bracket['max'];}}
        if($f->city!==''){$w[]='TRIM(d.city)=?';$params[]=$f->city;}
        if($f->zip!==''){$w[]='UPPER(TRIM(d.postal_code))=?';$params[]=$f->zip;}
        if($f->from!==null){$w[]='m.member_since>=?';$params[]=$f->from;}
        if($f->to!==null){$w[]='m.member_since<=?';$params[]=$f->to;}
        return ' WHERE '.implode(' AND ',$w);
    }
    public function options(): array
    {
        return ['programs'=>$this->db->query('SELECT DISTINCT product_name FROM vw_subscription_item_rates WHERE product_name IS NOT NULL ORDER BY product_name')->fetchAll(PDO::FETCH_COLUMN),
          'cities'=>$this->db->query("SELECT DISTINCT TRIM(city) FROM vw_member_demographics WHERE NULLIF(TRIM(city),'') IS NOT NULL ORDER BY TRIM(city)")->fetchAll(PDO::FETCH_COLUMN),
          'zips'=>$this->db->query("SELECT DISTINCT UPPER(TRIM(postal_code)) FROM vw_member_demographics WHERE NULLIF(TRIM(postal_code),'') IS NOT NULL ORDER BY UPPER(TRIM(postal_code))")->fetchAll(PDO::FETCH_COLUMN)];
    }
    public function overview(DemographicFilters $f): array
    {
        $params=[];$w=$this->where($f,$params);
        $stats=$this->rows("SELECT COUNT(*) total_members,COUNT(d.age_years) known_ages,AVG(d.age_years) average_age,
          COALESCE(SUM(d.age_years<18),0) youth_count,COALESCE(SUM(d.age_years>=18),0) adult_count,
          COUNT(NULLIF(TRIM(d.city),'')) known_cities,COUNT(NULLIF(TRIM(d.postal_code),'')) known_zips,
          COUNT(DISTINCT NULLIF(TRIM(d.city),''),COALESCE(NULLIF(TRIM(d.state),''),'')) city_count,
          COUNT(DISTINCT UPPER(NULLIF(TRIM(d.postal_code),''))) zip_count,COUNT(m.member_since) known_starts
          FROM vw_member_demographics d JOIN vw_member_summary m ON m.member_id=d.member_id".$w,$params)[0];
        $histogram=$this->rows('SELECT d.age_years,COUNT(*) count FROM vw_member_demographics d JOIN vw_member_summary m ON m.member_id=d.member_id'.$w.' GROUP BY d.age_years ORDER BY d.age_years',$params);
        $stats['median_age']=AgeBrackets::median($histogram);
        $age=$this->brackets->distribution($histogram);
        $youth=[['label'=>'Youth (under 18)','count'=>(int)$stats['youth_count']],['label'=>'Adult (18+)','count'=>(int)$stats['adult_count']],['label'=>'Unknown age','count'=>(int)$stats['total_members']-(int)$stats['known_ages']]];
        $cities=$this->rows("SELECT NULLIF(TRIM(d.city),'') city,NULLIF(TRIM(d.state),'') state,COUNT(*) count FROM vw_member_demographics d JOIN vw_member_summary m ON m.member_id=d.member_id".$w." GROUP BY NULLIF(TRIM(d.city),''),NULLIF(TRIM(d.state),'') ORDER BY count DESC,city,state LIMIT 10",$params);
        $cityChart=[];foreach($cities as $row){$cityChart[]=['label'=>($row['city']??'Unknown city').($row['state']!==null?', '.$row['state']:''),'count'=>(int)$row['count']];}
        $zips=$this->rows("SELECT COALESCE(UPPER(NULLIF(TRIM(d.postal_code),'')),'Unknown ZIP') label,COUNT(*) count FROM vw_member_demographics d JOIN vw_member_summary m ON m.member_id=d.member_id".$w.' GROUP BY label ORDER BY count DESC,label LIMIT 10',$params);
        foreach([&$cityChart,&$zips] as &$chart){$other=(int)$stats['total_members']-array_sum(array_column($chart,'count'));if($other>0)$chart[]=['label'=>'Other locations','count'=>$other];}unset($chart);
        $asOf=AppTime::today();
        return ['stats'=>$stats,'age'=>$age,'youth'=>$youth,'cities'=>$cityChart,'zips'=>$zips,'as_of'=>$asOf];
    }
    private function geographySql(DemographicFilters $f,array &$params): string
    {
        return "SELECT NULLIF(TRIM(d.city),'') city,NULLIF(TRIM(d.state),'') state,COUNT(*) member_count,
          CASE WHEN NULLIF(TRIM(d.city),'') IS NOT NULL AND COUNT(fin.currency)=COUNT(*) AND COUNT(DISTINCT fin.currency)=1 AND COUNT(fin.gross_paid_minor)=COUNT(*) AND SUM(fin.financial_attribution_status='exclusive_payer')=COUNT(*) THEN SUM(fin.gross_paid_minor) ELSE NULL END gross_paid_minor,
          CASE WHEN NULLIF(TRIM(d.city),'') IS NOT NULL AND COUNT(fin.currency)=COUNT(*) AND COUNT(DISTINCT fin.currency)=1 AND COUNT(fin.gross_paid_minor)=COUNT(*) AND SUM(fin.financial_attribution_status='exclusive_payer')=COUNT(*) THEN MAX(fin.currency) ELSE NULL END currency
          FROM vw_member_demographics d JOIN vw_member_summary m ON m.member_id=d.member_id LEFT JOIN vw_member_financial_summary fin ON fin.member_id=d.member_id".$this->where($f,$params)." GROUP BY NULLIF(TRIM(d.city),''),NULLIF(TRIM(d.state),'')";
    }
    public function geographyPage(DemographicFilters $f,string $search,int $offset,int $limit,int $column,string $direction): array
    {
        $params=[];$sql=$this->geographySql($f,$params);
        $total=(int)$this->rows('SELECT COUNT(*) count FROM ('.$sql.') g',$params)[0]['count'];
        $search=mb_substr(trim($search),0,100);$searchWhere='';
        if($search!==''){$searchWhere=" WHERE COALESCE(g.city,'Unknown city') LIKE ? ESCAPE '!' OR COALESCE(g.state,'Unknown') LIKE ? ESCAPE '!'";$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';array_push($params,$like,$like);}
        $filtered=(int)$this->rows('SELECT COUNT(*) count FROM ('.$sql.') g'.$searchWhere,$params)[0]['count'];
        $orders=['g.city','g.state','g.member_count','g.member_count'];$sort=$orders[$column]??$orders[2];$direction=strtolower($direction)==='asc'?'ASC':'DESC';$offset=max(0,min($offset,1000000));$limit=max(1,min($limit,100));
        $rows=$this->rows('SELECT g.* FROM ('.$sql.') g'.$searchWhere." ORDER BY $sort $direction,g.city,g.state LIMIT $limit OFFSET $offset",$params);
        // Denominator follows dashboard filters, not the table-only search.
        $memberParams=[];$memberWhere=$this->where($f,$memberParams);$members=(int)$this->rows('SELECT COUNT(*) count FROM vw_member_demographics d JOIN vw_member_summary m ON m.member_id=d.member_id'.$memberWhere,$memberParams)[0]['count'];
        foreach($rows as &$row){$row['percent']=$members>0?100*(int)$row['member_count']/$members:null;}unset($row);
        return compact('total','filtered','rows');
    }
}
