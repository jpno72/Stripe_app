<?php
declare(strict_types=1);
require_once __DIR__ . '/RevenueFilters.php';

final class RevenueRepository
{
    public function __construct(private PDO $db) {}

    public function latestMonth(): ?string
    {
        $value = $this->db->query('SELECT MAX(month_start_utc) FROM vw_monthly_revenue')->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    public function options(): array
    {
        return [
            'currencies' => $this->db->query('SELECT DISTINCT currency FROM vw_transaction_summary WHERE currency IS NOT NULL ORDER BY currency')->fetchAll(PDO::FETCH_COLUMN),
            'statuses' => $this->db->query('SELECT DISTINCT status FROM vw_transaction_summary WHERE status IS NOT NULL ORDER BY status')->fetchAll(PDO::FETCH_COLUMN),
            'programs' => $this->db->query('SELECT DISTINCT program_name FROM vw_revenue_by_program ORDER BY program_name')->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    private function base(): string
    {
        // Each join has at most one row per transaction or subscription.
        return ' FROM vw_transaction_summary t
          LEFT JOIN people payer ON payer.id=t.payer_person_id
          LEFT JOIN vw_subscription_summary s ON s.subscription_id=t.subscription_id
          LEFT JOIN (SELECT subscription_id, COUNT(*) AS mapping_count, MAX(member_id) AS member_id,
                            MAX(valid_from) AS valid_from, MAX(valid_until) AS valid_until
                     FROM subscription_members GROUP BY subscription_id) sm ON sm.subscription_id=t.subscription_id
          LEFT JOIN members m ON m.id=sm.member_id AND sm.mapping_count=1
            AND (sm.valid_from IS NULL OR DATE(t.stripe_created_at)>=sm.valid_from)
            AND (sm.valid_until IS NULL OR DATE(t.stripe_created_at)<=sm.valid_until)
          LEFT JOIN people member_person ON member_person.id=m.person_id';
    }

    private function where(RevenueFilters $filters, array &$params, string $search = ''): string
    {
        $where = ['t.stripe_created_at IS NOT NULL'];
        if ($filters->from !== null) { $where[] = 't.stripe_created_at >= ?'; $params[] = $filters->from . ' 00:00:00'; }
        if ($filters->to !== null) {
            $where[] = 't.stripe_created_at < ?';
            $params[] = (new DateTimeImmutable($filters->to, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if ($filters->currency !== '') { $where[] = 't.currency = ?'; $params[] = $filters->currency; }
        if ($filters->program !== '') { $where[] = 's.item_count=1 AND s.single_product_name = ?'; $params[] = $filters->program; }
        if ($filters->status !== '') { $where[] = 't.status = ?'; $params[] = $filters->status; }
        if ($filters->person !== '') {
            $where[] = "(payer.bma_person_id = ? OR member_person.bma_person_id = ? OR CONCAT_WS(' ',payer.first_name,payer.last_name) LIKE ? ESCAPE '!' OR CONCAT_WS(' ',member_person.first_name,member_person.last_name) LIKE ? ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters->person) . '%';
            array_push($params, $filters->person, $filters->person, $like, $like);
        }
        if ($search !== '') {
            $where[] = "(t.stripe_charge_id LIKE ? ESCAPE '!' OR t.stripe_customer_id LIKE ? ESCAPE '!' OR t.status LIKE ? ESCAPE '!' OR s.single_product_name LIKE ? ESCAPE '!' OR CONCAT_WS(' ',payer.first_name,payer.last_name) LIKE ? ESCAPE '!' OR CONCAT_WS(' ',member_person.first_name,member_person.last_name) LIKE ? ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        return ' WHERE ' . implode(' AND ', $where);
    }

    private function fetchAll(string $sql, array $params): array
    {
        $q = $this->db->prepare($sql);
        $q->execute($params);
        return $q->fetchAll();
    }

    public function totalsByCurrency(RevenueFilters $filters): array
    {
        $params = [];
        $sql = 'SELECT t.currency, COUNT(*) AS paid_count, SUM(t.gross_paid_minor) AS gross_paid_minor,
                SUM(t.refund_known) AS refund_known_count,
                CASE WHEN SUM(t.refund_known)=COUNT(*) THEN SUM(t.refunds_minor) ELSE NULL END AS refunds_minor,
                SUM(t.fee_known) AS fee_known_count,
                CASE WHEN SUM(t.fee_known)=COUNT(*) THEN SUM(t.stripe_fees_minor) ELSE NULL END AS stripe_fees_minor,
                CASE WHEN SUM(t.refund_known)=COUNT(*) AND SUM(t.fee_known)=COUNT(*) THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END AS net_after_recorded_costs_minor'
             . $this->base() . $this->where($filters, $params) . ' AND t.is_paid=1 GROUP BY t.currency ORDER BY t.currency';
        return $this->fetchAll($sql, $params);
    }

    public function monthlyTrend(RevenueFilters $filters): array
    {
        $params = [];
        $sql = 'SELECT t.month_start_utc, t.currency, COUNT(*) paid_count, SUM(t.gross_paid_minor) gross_paid_minor,
                 CASE WHEN SUM(t.refund_known)=COUNT(*) THEN SUM(t.refunds_minor) ELSE NULL END refunds_minor,
                 CASE WHEN SUM(t.fee_known)=COUNT(*) THEN SUM(t.stripe_fees_minor) ELSE NULL END stripe_fees_minor,
                 CASE WHEN SUM(t.refund_known)=COUNT(*) AND SUM(t.fee_known)=COUNT(*) THEN SUM(t.net_after_recorded_costs_minor) ELSE NULL END net_after_recorded_costs_minor'
              . $this->base() . $this->where($filters, $params) . ' AND t.is_paid=1 GROUP BY t.month_start_utc,t.currency ORDER BY t.month_start_utc,t.currency';
        return $this->fetchAll($sql, $params);
    }

    public function programBreakdown(RevenueFilters $filters): array
    {
        $params = [];
        $sql = 'SELECT s.single_product_name program_name,t.currency,COUNT(*) transaction_count,SUM(t.gross_paid_minor) gross_paid_minor'
             . $this->base() . $this->where($filters, $params)
             . ' AND t.is_paid=1 AND s.item_count=1 AND s.single_product_name IS NOT NULL GROUP BY s.single_product_name,t.currency ORDER BY gross_paid_minor DESC LIMIT 12';
        return $this->fetchAll($sql, $params);
    }

    public function currentMrr(?string $currency): array
    {
        $rows = $this->db->query("SELECT currency,COUNT(*) subscription_count,COUNT(active_mrr_minor) known_count,SUM(active_mrr_minor) amount_minor FROM vw_subscription_summary WHERE subscription_status='active' GROUP BY currency")->fetchAll();
        $unknown = false; $selected = [];
        foreach ($rows as $row) {
            if ($row['currency'] === null || (int)$row['known_count'] !== (int)$row['subscription_count']) { $unknown = true; }
            if ($currency === null || $row['currency'] === $currency) { $selected[] = $row; }
        }
        if ($unknown || count($selected) !== 1) {
            return ['amount_minor'=>null,'currency'=>$currency,'reason'=>$unknown ? 'An active rate or currency is unknown' : 'No single known currency and active rate'];
        }
        return ['amount_minor'=>$selected[0]['amount_minor'],'currency'=>$selected[0]['currency'],'reason'=>'Current subscription snapshot; independent of payment date'];
    }

    public function transactionPage(RevenueFilters $filters, string $search, int $offset, int $limit, int $orderColumn, string $direction): array
    {
        $search = mb_substr(trim($search), 0, 100);
        $offset = max(0, min($offset, 1000000)); $limit = max(1, min($limit, 100));
        $orders = ['t.stripe_created_at','COALESCE(member_person.last_name,payer.last_name)','s.single_product_name','t.gross_paid_minor','t.refunds_minor','t.stripe_fees_minor','t.net_after_recorded_costs_minor','t.status','t.stripe_charge_id'];
        $order = $orders[$orderColumn] ?? $orders[0];
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $base = $this->base(); $params = [];
        $where = $this->where($filters, $params);
        $total = (int)$this->fetchAll('SELECT COUNT(*) n' . $base . $where, $params)[0]['n'];
        $searchParams = []; $searchWhere = $this->where($filters, $searchParams, $search);
        $filtered = $search === '' ? $total : (int)$this->fetchAll('SELECT COUNT(*) n' . $base . $searchWhere, $searchParams)[0]['n'];
        $sql = "SELECT t.transaction_id,t.stripe_created_at,t.stripe_charge_id,t.status,t.is_paid,t.currency,t.gross_paid_minor,t.refunds_minor,t.stripe_fees_minor,t.net_after_recorded_costs_minor,
                       payer.bma_person_id payer_bma,TRIM(CONCAT_WS(' ',payer.first_name,payer.last_name)) payer_name,
                       member_person.bma_person_id member_bma,TRIM(CONCAT_WS(' ',member_person.first_name,member_person.last_name)) member_name,
                       CASE WHEN s.item_count=1 THEN s.single_product_name ELSE NULL END program_name"
             . $base . $searchWhere . " ORDER BY $order $direction,t.transaction_id DESC LIMIT $limit OFFSET $offset";
        return ['total'=>$total,'filtered'=>$filtered,'rows'=>$this->fetchAll($sql,$searchParams)];
    }
}
