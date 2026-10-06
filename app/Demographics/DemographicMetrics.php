<?php
declare(strict_types=1);
final class DemographicMetrics
{
    public static function cards(array $s): array
    {
        $total=(int)$s['total_members'];$ages=(int)$s['known_ages'];$ageDetail=$ages.' known ages; '.($total-$ages).' unknown';
        return [
          ['Average age',$s['average_age']===null?'Unavailable':number_format((float)$s['average_age'],1).' years',$ageDetail],
          ['Median age',$s['median_age']===null?'Unavailable':number_format((float)$s['median_age'],1).' years',$ageDetail],
          ['Youth members (known age)',$ages===0?'Unavailable':number_format((int)$s['youth_count']),'Under 18 - '.$ageDetail],
          ['Adult members (known age)',$ages===0?'Unavailable':number_format((int)$s['adult_count']),'18 and over - '.$ageDetail],
          ['Cities represented',(int)$s['known_cities']===0?'Unavailable':number_format((int)$s['city_count']),$s['known_cities'].' known member cities; '.($total-(int)$s['known_cities']).' unknown'],
          ['ZIP / postal codes represented',(int)$s['known_zips']===0?'Unavailable':number_format((int)$s['zip_count']),$s['known_zips'].' known member postal codes; '.($total-(int)$s['known_zips']).' unknown'],
        ];
    }
}
