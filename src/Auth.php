<?php
declare(strict_types=1);

namespace PenguLab;

use RuntimeException;

final class Auth
{
    private const REMEMBER_COOKIE = 'pengulab_remember';
    private const REMEMBER_DAYS = 90;
    private ?array $user = null;

    public function __construct(private Database $db)
    {
        $this->ensureDefaultAdmin();
        $this->cleanupExpiredTokens();
        $this->loadSessionUser();
        if (!$this->user) $this->loginFromRememberCookie();
    }

    public function user(): ?array { return $this->user; }
    public function check(): bool { return $this->user !== null; }
    public function isAdmin(): bool { return ($this->user['role'] ?? '') === 'admin'; }

    public function login(string $username, string $password, bool $remember = true): bool
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM users WHERE username=:u AND enabled=1 LIMIT 1');
        $stmt->execute(['u'=>trim($username)]); $row=$stmt->fetch();
        if(!is_array($row) || !password_verify($password,(string)$row['password_hash'])) return false;
        if(password_needs_rehash((string)$row['password_hash'],PASSWORD_DEFAULT)){
            $this->db->pdo()->prepare('UPDATE users SET password_hash=:h WHERE id=:id')->execute(['h'=>password_hash($password,PASSWORD_DEFAULT),'id'=>$row['id']]);
        }
        session_regenerate_id(true); $_SESSION['pengulab_user_id']=$row['id'];
        $this->db->pdo()->prepare('UPDATE users SET last_login_at=:t WHERE id=:id')->execute(['t'=>gmdate(DATE_ATOM),'id'=>$row['id']]);
        $this->user=$this->publicUser($this->row((string)$row['id']));
        if($remember) $this->issueRememberToken((string)$row['id']);
        return true;
    }

    public function logout(): void
    {
        $cookie=(string)($_COOKIE[self::REMEMBER_COOKIE]??'');
        if(str_contains($cookie,':')){
            [$selector]=explode(':',$cookie,2);
            $this->db->pdo()->prepare('DELETE FROM remember_tokens WHERE selector=:s')->execute(['s'=>$selector]);
        }
        $this->clearRememberCookie();
        unset($_SESSION['pengulab_user_id']);
        session_regenerate_id(true); $this->user=null;
    }

    public function listUsers(): array
    {
        $rows=$this->db->pdo()->query('SELECT * FROM users ORDER BY role DESC, username COLLATE NOCASE')->fetchAll();
        return array_map(fn(array $r)=>$this->publicUser($r),$rows);
    }

    public function saveUser(array $input): array
    {
        if(!$this->isAdmin()) throw new RuntimeException('Administrator permission required.');
        $id=trim((string)($input['id']??'')) ?: Database::uuid('user');
        $username=mb_substr(trim((string)($input['username']??'')),0,80);
        if(!preg_match('/^[A-Za-z0-9._-]{2,80}$/',$username)) throw new RuntimeException('Username must contain 2-80 letters, numbers, dot, underscore or dash.');
        $role=in_array(($input['role']??''),['admin','user'],true)?(string)$input['role']:'user';
        $permissions=['ipmanager'=>!empty($input['permissions']['ipmanager']),'integrations'=>array_values(array_unique(array_filter(array_map('strval',$input['permissions']['integrations']??[]))))];
        $existing=$this->row($id); $password=(string)($input['password']??'');
        if(!$existing && strlen($password)<8) throw new RuntimeException('New users need a password with at least 8 characters.');
        if($password!=='' && strlen($password)<8) throw new RuntimeException('Password must contain at least 8 characters.');
        $hash=$password!==''?password_hash($password,PASSWORD_DEFAULT):(string)($existing['password_hash']??'');
        $now=gmdate(DATE_ATOM);
        try{
            $stmt=$this->db->pdo()->prepare('INSERT INTO users(id,username,password_hash,role,permissions_json,preferences_json,enabled,created_at,updated_at,last_login_at) VALUES(:id,:u,:h,:r,:p,:pref,1,:c,:up,NULL) ON CONFLICT(id) DO UPDATE SET username=excluded.username,password_hash=excluded.password_hash,role=excluded.role,permissions_json=excluded.permissions_json,enabled=1,updated_at=excluded.updated_at');
            $stmt->execute(['id'=>$id,'u'=>$username,'h'=>$hash,'r'=>$role,'p'=>json_encode($permissions,JSON_UNESCAPED_SLASHES),'pref'=>$existing['preferences_json']??'{}','c'=>$existing['created_at']??$now,'up'=>$now]);
        }catch(\PDOException $e){ if(str_contains($e->getMessage(),'UNIQUE')) throw new RuntimeException('Username already exists.'); throw $e; }
        if($this->user && $this->user['id']===$id) $this->user=$this->publicUser($this->row($id));
        return $this->publicUser($this->row($id));
    }

    public function deleteUser(string $id): void
    {
        if(!$this->isAdmin()) throw new RuntimeException('Administrator permission required.');
        if($this->user && $this->user['id']===$id) throw new RuntimeException('You cannot delete your own account.');
        $row=$this->row($id); if(!$row) return;
        if($row['role']==='admin'){
            $count=(int)$this->db->pdo()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND enabled=1")->fetchColumn();
            if($count<=1) throw new RuntimeException('The last administrator cannot be deleted.');
        }
        $this->db->pdo()->prepare('DELETE FROM users WHERE id=:id')->execute(['id'=>$id]);
    }

    public function changeOwnPassword(string $current, string $new): void
    {
        if(!$this->user) throw new RuntimeException('Not signed in.');
        if(strlen($new)<8) throw new RuntimeException('New password must contain at least 8 characters.');
        $row=$this->row((string)$this->user['id']);
        if(!$row || !password_verify($current,(string)$row['password_hash'])) throw new RuntimeException('Current password is incorrect.');
        $this->db->pdo()->prepare('UPDATE users SET password_hash=:h,updated_at=:t WHERE id=:id')->execute(['h'=>password_hash($new,PASSWORD_DEFAULT),'t'=>gmdate(DATE_ATOM),'id'=>$row['id']]);
        $this->db->pdo()->prepare('DELETE FROM remember_tokens WHERE user_id=:id')->execute(['id'=>$row['id']]);
        $this->issueRememberToken((string)$row['id']);
    }

    public function canIntegration(string $id): bool
    {
        if($this->isAdmin()) return true;
        return in_array($id,$this->user['permissions']['integrations']??[],true);
    }
    public function canIpManager(): bool { return $this->isAdmin() || !empty($this->user['permissions']['ipmanager']); }

    public function preference(string $key,mixed $default=null): mixed
    {
        if(!$this->user) return $default;
        return array_key_exists($key,$this->user['preferences']??[])?$this->user['preferences'][$key]:$default;
    }
    public function setPreference(string $key,mixed $value): void
    {
        if(!$this->user) throw new RuntimeException('Not signed in.');
        $prefs=$this->user['preferences']??[]; $prefs[$key]=$value;
        $this->db->pdo()->prepare('UPDATE users SET preferences_json=:p,updated_at=:t WHERE id=:id')->execute(['p'=>json_encode($prefs,JSON_UNESCAPED_SLASHES),'t'=>gmdate(DATE_ATOM),'id'=>$this->user['id']]);
        $this->user['preferences']=$prefs;
    }

    public function isUsingDefaultAdminPassword(): bool
    {
        if(!$this->user || $this->user['username']!=='admin') return false;
        $row=$this->row((string)$this->user['id']); return $row ? password_verify('admin',(string)$row['password_hash']) : false;
    }

    private function ensureDefaultAdmin(): void
    {
        $count=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn(); if($count>0)return;
        $now=gmdate(DATE_ATOM); $id=Database::uuid('user');
        $stmt=$this->db->pdo()->prepare('INSERT INTO users(id,username,password_hash,role,permissions_json,preferences_json,enabled,created_at,updated_at,last_login_at) VALUES(:id,\'admin\',:h,\'admin\',\'{"ipmanager":true,"integrations":[]}\',\'{}\',1,:c,:u,NULL)');
        $stmt->execute(['id'=>$id,'h'=>password_hash('admin',PASSWORD_DEFAULT),'c'=>$now,'u'=>$now]);
    }
    private function row(string $id): ?array { $s=$this->db->pdo()->prepare('SELECT * FROM users WHERE id=:id');$s->execute(['id'=>$id]);$r=$s->fetch();return is_array($r)?$r:null; }
    private function publicUser(?array $row): array
    {
        if(!$row)return [];
        return ['id'=>(string)$row['id'],'username'=>(string)$row['username'],'role'=>(string)$row['role'],'enabled'=>!empty($row['enabled']),'permissions'=>json_decode((string)$row['permissions_json'],true)?:['ipmanager'=>false,'integrations'=>[]],'preferences'=>json_decode((string)$row['preferences_json'],true)?:[],'last_login_at'=>$row['last_login_at']??null];
    }
    private function loadSessionUser(): void
    {
        $id=(string)($_SESSION['pengulab_user_id']??''); if($id==='')return; $row=$this->row($id);
        if(!$row || empty($row['enabled'])){unset($_SESSION['pengulab_user_id']);return;} $this->user=$this->publicUser($row);
    }
    private function issueRememberToken(string $userId): void
    {
        $selector=bin2hex(random_bytes(9));$validator=bin2hex(random_bytes(32));$expires=time()+self::REMEMBER_DAYS*86400;
        $this->db->pdo()->prepare('INSERT INTO remember_tokens(selector,user_id,token_hash,expires_at,created_at) VALUES(:s,:u,:h,:e,:c)')->execute(['s'=>$selector,'u'=>$userId,'h'=>hash('sha256',$validator),'e'=>$expires,'c'=>time()]);
        setcookie(self::REMEMBER_COOKIE,$selector.':'.$validator,['expires'=>$expires,'path'=>'/','secure'=>$this->secureCookie(),'httponly'=>true,'samesite'=>'Lax']);
        $_COOKIE[self::REMEMBER_COOKIE]=$selector.':'.$validator;
    }
    private function loginFromRememberCookie(): void
    {
        $cookie=(string)($_COOKIE[self::REMEMBER_COOKIE]??''); if(!str_contains($cookie,':'))return;[$selector,$validator]=explode(':',$cookie,2);
        $s=$this->db->pdo()->prepare('SELECT * FROM remember_tokens WHERE selector=:s AND expires_at>:n');$s->execute(['s'=>$selector,'n'=>time()]);$token=$s->fetch();
        if(!is_array($token)||!hash_equals((string)$token['token_hash'],hash('sha256',$validator))){$this->clearRememberCookie();return;}
        $row=$this->row((string)$token['user_id']);if(!$row||empty($row['enabled'])){$this->clearRememberCookie();return;}
        session_regenerate_id(true);$_SESSION['pengulab_user_id']=$row['id'];$this->user=$this->publicUser($row);
        // Rotate on successful remember-login.
        $this->db->pdo()->prepare('DELETE FROM remember_tokens WHERE selector=:s')->execute(['s'=>$selector]);$this->issueRememberToken((string)$row['id']);
    }
    private function cleanupExpiredTokens(): void { $this->db->pdo()->prepare('DELETE FROM remember_tokens WHERE expires_at<=:n')->execute(['n'=>time()]); }
    private function clearRememberCookie(): void { setcookie(self::REMEMBER_COOKIE,'',['expires'=>time()-3600,'path'=>'/','secure'=>$this->secureCookie(),'httponly'=>true,'samesite'=>'Lax']);unset($_COOKIE[self::REMEMBER_COOKIE]); }
    private function secureCookie(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'; }
}
