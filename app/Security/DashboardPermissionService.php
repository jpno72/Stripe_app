<?php
declare(strict_types=1);
require_once __DIR__.'/Security.php';

/** Authorization depends on canonical app_users records, never login transport. */
final class DashboardPermissionService
{
    public const ROLES = ['admin','analyst','viewer','staff'];
    public const ADMIN_ONLY = ['imports','matches','settings','users'];
    public const KEYS = ['overview','revenue','membership','demographics','members','transactions','waivers','reports','imports','matches','settings','users'];
    public function __construct(private PDO $db) {}

    public function dashboardsForUser(array $user): array
    {
        $q=$this->db->prepare('SELECT id,role,is_active FROM app_users WHERE id=?');
        $q->execute([$user['id']??0]);$canonical=$q->fetch();
        if(!$canonical || !$canonical['is_active']) return [];
        $q=$this->db->prepare('SELECT d.*, COALESCE(r.can_view,0) AS role_access, o.permission AS user_override FROM dashboards d LEFT JOIN role_dashboard_permissions r ON r.dashboard_id=d.id AND r.role=? LEFT JOIN user_dashboard_overrides o ON o.dashboard_id=d.id AND o.user_id=? ORDER BY d.navigation_order,d.id');
        $q->execute([$canonical['role'],$canonical['id']]);$rows=$q->fetchAll();
        foreach($rows as &$row) {
            $allowed=$row['user_override']==='DENY'?false:($row['user_override']==='ALLOW'?true:(bool)$row['role_access']);
            $row['effective_access']=in_array($row['dashboard_key'],self::KEYS,true) && (bool)$row['is_enabled'] && $allowed && (!in_array($row['dashboard_key'],self::ADMIN_ONLY,true) || $canonical['role']==='admin');
        }
        unset($row);return $rows;
    }
    public function canViewDashboard(array $user,string $key): bool
    {
        if(!in_array($key,self::KEYS,true)) return false;
        foreach($this->dashboardsForUser($user) as $row) if($row['dashboard_key']===$key) return $row['effective_access'];
        return false;
    }
    public function requireDashboardAccess(array $user,string $key): void
    {
        if(!$this->canViewDashboard($user,$key)){http_response_code(403);exit('Dashboard access denied.');}
    }
    public function getVisibleDashboards(array $user): array
    {
        return array_values(array_filter($this->dashboardsForUser($user),static fn($d)=>$d['effective_access'] && $d['show_in_navigation']));
    }
    public function rolePermissions(): array
    {
        $result=[];foreach($this->db->query('SELECT role,dashboard_id,can_view FROM role_dashboard_permissions')->fetchAll() as $r)$result[$r['dashboard_id']][$r['role']]=(bool)$r['can_view'];return $result;
    }
    /** Caller holds users locks first, then registry locks, in one transaction. */
    public function assertAdministrationAvailable(): void
    {
        $this->db->query('SELECT id FROM dashboards ORDER BY id FOR UPDATE')->fetchAll();
        foreach($this->db->query("SELECT id FROM app_users WHERE role='admin' AND is_active=1 ORDER BY id")->fetchAll() as $admin) {
            if($this->canViewDashboard($admin,'settings') && $this->canViewDashboard($admin,'users'))return;
        }
        throw new InvalidArgumentException('Keep Settings and User management accessible to at least one active administrator.');
    }
    private function lockAdministrator(int $actor): void
    {
        $this->db->query('SELECT id FROM app_users ORDER BY id FOR UPDATE')->fetchAll();
        $this->db->query('SELECT id FROM dashboards ORDER BY id FOR UPDATE')->fetchAll();
        $q=$this->db->prepare('SELECT id,role,is_active FROM app_users WHERE id=?');$q->execute([$actor]);$u=$q->fetch();
        if(!$u || !$u['is_active'] || $u['role']!=='admin' || !$this->canViewDashboard($u,'settings'))throw new InvalidArgumentException('Authorized administrator required.');
    }
    private function dashboard(string $key): array
    {
        if(!in_array($key,self::KEYS,true))throw new InvalidArgumentException('Unknown dashboard.');
        $q=$this->db->prepare('SELECT * FROM dashboards WHERE dashboard_key=?');$q->execute([$key]);$d=$q->fetch();
        if(!$d)throw new InvalidArgumentException('Unknown dashboard.');return $d;
    }
    private function audit(int $actor,string $key,string $action,array $previous,array $new): void
    {
        Security::audit($this->db,$actor,'dashboard.'.$action,$key,['dashboard_key'=>$key,'previous'=>$previous,'new'=>$new]);
    }
    public function saveDashboard(int $actor,string $key,string $name,bool $enabled,bool $navigation,array $roles): void
    {
        $name=trim($name);if($name==='' || mb_strlen($name)>100 || array_diff(array_keys($roles),self::ROLES) || array_diff(self::ROLES,array_keys($roles)))throw new InvalidArgumentException('Invalid dashboard settings.');
        foreach($roles as $value)if(!is_bool($value))throw new InvalidArgumentException('Invalid role permission.');
        $this->db->beginTransaction();try {
            $this->lockAdministrator($actor);$d=$this->dashboard($key);
            $this->db->prepare('UPDATE dashboards SET display_name=?,is_enabled=?,show_in_navigation=? WHERE id=?')->execute([$name,(int)$enabled,(int)$navigation,$d['id']]);
            if($d['display_name']!==$name)$this->audit($actor,$key,'display_name_changed',['display_name'=>$d['display_name']],['display_name'=>$name]);
            if((bool)$d['is_enabled']!==$enabled)$this->audit($actor,$key,$enabled?'enabled':'disabled',['enabled'=>(bool)$d['is_enabled']],['enabled'=>$enabled]);
            if((bool)$d['show_in_navigation']!==$navigation)$this->audit($actor,$key,'navigation_changed',['show_in_navigation'=>(bool)$d['show_in_navigation']],['show_in_navigation'=>$navigation]);
            $old=$this->rolePermissions()[$d['id']]??[];
            foreach($roles as $role=>$allow){
                $this->db->prepare('INSERT INTO role_dashboard_permissions(role,dashboard_id,can_view) VALUES (?,?,?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view)')->execute([$role,$d['id'],(int)$allow]);
                if(($old[$role]??false)!==$allow)$this->audit($actor,$key,'role_permission_changed',['role'=>$role,'can_view'=>$old[$role]??false],['role'=>$role,'can_view'=>$allow]);
            }
            $this->assertAdministrationAvailable();$this->db->commit();
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
    public function saveOverride(int $actor,int $target,string $key,string $permission): void
    {
        if(!in_array($permission,['INHERIT','ALLOW','DENY'],true))throw new InvalidArgumentException('Invalid override.');
        $this->db->beginTransaction();try {
            $this->lockAdministrator($actor);$d=$this->dashboard($key);
            $q=$this->db->prepare('SELECT id FROM app_users WHERE id=? AND is_active=1');$q->execute([$target]);if(!$q->fetchColumn())throw new InvalidArgumentException('Select an active user.');
            $q=$this->db->prepare('SELECT permission FROM user_dashboard_overrides WHERE user_id=? AND dashboard_id=?');$q->execute([$target,$d['id']]);$previous=$q->fetchColumn()?:'INHERIT';
            if($permission==='INHERIT')$this->db->prepare('DELETE FROM user_dashboard_overrides WHERE user_id=? AND dashboard_id=?')->execute([$target,$d['id']]);
            else $this->db->prepare('INSERT INTO user_dashboard_overrides(user_id,dashboard_id,permission) VALUES (?,?,?) ON DUPLICATE KEY UPDATE permission=VALUES(permission)')->execute([$target,$d['id'],$permission]);
            if($previous!==$permission)$this->audit($actor,$key,'user_override_changed',['user_id'=>$target,'permission'=>$previous],['user_id'=>$target,'permission'=>$permission]);
            $this->assertAdministrationAvailable();$this->db->commit();
        }catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
}
