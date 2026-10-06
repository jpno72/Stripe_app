<?php
declare(strict_types=1);
require_once __DIR__.'/Security.php';require_once __DIR__.'/Passwords.php';require_once __DIR__.'/DashboardPermissionService.php';
final class UserManagement
{
    public function __construct(private PDO $db){}
    public function create(string $email,string $name,string $role,#[SensitiveParameter] string $password): int
    {
        $email=mb_strtolower(trim($email),'UTF-8');$name=trim($name);if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||$name===''||mb_strlen($name)>255||!in_array($role,['admin','viewer'],true))throw new InvalidArgumentException('Invalid account details.');
        $hash=Passwords::hash($password);$this->db->beginTransaction();try{$this->db->prepare('INSERT INTO app_users(email,email_normalized,display_name,password_hash,role) VALUES (?,?,?,?,?)')->execute([$email,$email,$name,$hash,$role]);$id=(int)$this->db->lastInsertId();Security::audit($this->db,null,'cli_user_created',(string)$id,['role'=>$role]);$this->db->commit();return $id;}catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
    public function reset(string $email,#[SensitiveParameter] string $password): void
    {
        $hash=Passwords::hash($password);$this->db->beginTransaction();try{$q=$this->db->prepare('SELECT id FROM app_users WHERE email_normalized=? FOR UPDATE');$q->execute([mb_strtolower(trim($email),'UTF-8')]);$id=$q->fetchColumn();if(!$id)throw new InvalidArgumentException('Account not found.');$this->db->prepare('UPDATE app_users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([$hash,$id]);Security::audit($this->db,null,'cli_password_reset',(string)$id);$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
    public function update(int $actor,int $target,string $role,bool $active): void
    {
        if(!in_array($role,['admin','viewer'],true))throw new InvalidArgumentException('Choose admin or viewer.');$this->db->beginTransaction();
        try{
            // A stable lock order serializes role changes and last-admin checks.
            $users=$this->db->query('SELECT id,role,is_active,auth_version FROM app_users ORDER BY id FOR UPDATE')->fetchAll();$byId=array_column($users,null,'id');$acting=$byId[$actor]??null;$old=$byId[$target]??null;
            if(!$acting||$acting['role']!=='admin'||!$acting['is_active'])throw new RuntimeException('Administrator required.');if(!(new DashboardPermissionService($this->db))->canViewDashboard($acting,'users'))throw new RuntimeException('User management access required.');if(!$old)throw new InvalidArgumentException('Account not found.');
            $otherAdmins=array_filter($users,static fn($u)=>(int)$u['id']!==$target&&$u['role']==='admin'&&$u['is_active']);
            if($old['role']==='admin'&&$old['is_active']&&($role!=='admin'||!$active)&&!$otherAdmins)throw new InvalidArgumentException('Keep at least one active administrator.');
            if($old['role']!==$role||(bool)$old['is_active']!==$active){$this->db->prepare('UPDATE app_users SET role=?,is_active=?,auth_version=auth_version+1 WHERE id=?')->execute([$role,(int)$active,$target]);Security::audit($this->db,$actor,'user_access_changed',(string)$target,['previous'=>['role'=>$old['role'],'active'=>(bool)$old['is_active']],'new'=>['role'=>$role,'active'=>$active]]);}(new DashboardPermissionService($this->db))->assertAdministrationAvailable();$this->db->commit();
        }catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
    public function changePassword(int $id,#[SensitiveParameter] string $current,#[SensitiveParameter] string $password): void
    {
        $hash=Passwords::hash($password);$this->db->beginTransaction();try{$q=$this->db->prepare('SELECT password_hash,is_active FROM app_users WHERE id=? FOR UPDATE');$q->execute([$id]);$user=$q->fetch();if(!$user||!$user['is_active']||strlen($current)>72||!password_verify($current,$user['password_hash']))throw new InvalidArgumentException('Current password is incorrect.');$this->db->prepare('UPDATE app_users SET password_hash=?,auth_version=auth_version+1 WHERE id=?')->execute([$hash,$id]);Security::audit($this->db,$id,'password_changed',(string)$id);$this->db->commit();}catch(Throwable $error){$this->db->rollBack();throw $error;}
    }
}
