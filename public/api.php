<?php

declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use Cmdb\{App,ApiError,Schema,Importer,Graph,Exporter,Config,Installer,Sso};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
$requestId = bin2hex(random_bytes(8));
try {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'])) {
        throw new ApiError(403, 'Ez a helyi példány csak a webszerver gépéről érhető el.');
    }
    $requestHost = strtolower((string)preg_replace('/:\d+$/D', '', $_SERVER['HTTP_HOST'] ?? ''));
    $allowedHosts = ['localhost','127.0.0.1','[::1]'];
    foreach (explode(',', getenv('CMDB_ALLOWED_HOSTS') ?: '') as $allowedHost) {
        $allowedHost = strtolower(trim($allowedHost));
        if ($allowedHost !== '' && preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\[[0-9a-f:]+\])$/D', $allowedHost)) {
            $allowedHosts[] = $allowedHost;
        }
    }
    if (!in_array($requestHost, array_unique($allowedHosts), true)) {
        throw new ApiError(403, 'A kért állomásnév nincs engedélyezve a CMDB_ALLOWED_HOSTS beállításban.');
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 22 * 1024 * 1024) {
        throw new ApiError(413, 'Túl nagy kérés.');
    }
    $config = Config::read();
    $storage = $config['storage'] ?? Config::directory();
    Config::ensurePrivateDirectory($storage);
    if (!is_dir($storage.'/sessions')) {
        mkdir($storage.'/sessions', 0700, true);
    }session_save_path($storage.'/sessions');
    session_name('CMDBSESSID');
    // OIDC returns through a cross-site top-level GET, which requires Lax.
    // State, nonce and CSRF tokens still protect login and every mutation.
    session_set_cookie_params(['httponly' => true,'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite' => 'Lax','path' => '/']);
    ini_set('session.use_strict_mode', '1');
    session_start();
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > 3600) {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $method = $_SERVER['REQUEST_METHOD'];
    $path = trim($_GET['r'] ?? preg_replace('#^/api/v1/?#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
    $parts = explode('/', $path);
    $body = [];
    if (!in_array($method, ['GET','HEAD']) && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $rawBody = file_get_contents('php://input');
        $body = $rawBody === '' ? [] : (json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR) ?? []);
        if (!is_array($body)) {
            throw new ApiError(422, 'JSON objektum szükséges.');
        }
    }
    if (!in_array($method, ['GET','HEAD']) && !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        throw new ApiError(419, 'Lejárt munkamenet. Frissítsd az oldalt.');
    }
    $reply = function ($data, int $status = 200) {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    };
    // CSRF still applies, but ending a session must not require a logged-in
    // user (or even an available database). Repeated logout is safe.
    if ($path === 'logout' && $method === 'POST') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $reply(['success' => true]);
        exit;
    }
    if ($path === 'install' && $method === 'POST') {
        $reply(Installer::install($body), 201);
        // The same CSRF-protected session continues to first-admin creation.
        unset($_SESSION['user_id']);
        exit;
    }
    if ($config === null) {
        if ($path === 'session' && $method === 'GET') {
            $reply(['user' => null, 'csrf' => $_SESSION['csrf'], 'installation_required' => true, 'setup_required' => false, 'installation' => Installer::status()]);
            exit;
        }
        throw new ApiError(503, 'Először fejezd be az adatbázis telepítését.');
    }
    try {
        $a = new App();
    } catch (PDOException $e) {
        error_log('CMDB configured database unavailable: '.$e->getCode());
        throw new ApiError(503, 'A beállított adatbázis nem érhető el. Ellenőrizd az SQL-szolgáltatást és a privát konfigurációt. A telepítést nem indítjuk újra.');
    }
    if (isset($_SESSION['user_id'])) {
        $a->user = $a->one('SELECT * FROM users WHERE id=? AND active=1', [$_SESSION['user_id']]);
    }
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDirectory = $scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/');
    $origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
    $ssoCallbackUrl = $origin.$scriptDirectory.'/api.php?r=sso/callback';
    $frontendUrl = $origin.$scriptDirectory.'/';
    if (in_array($path, ['sso/login','sso/callback'], true) && $method === 'GET') {
        try {
            $sso = new Sso($a);
            if ($path === 'sso/login') {
                if ((int)$a->db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
                    throw new ApiError(403, 'Előbb hozd létre az első helyi adminisztrátort.');
                }
                $destination = $sso->begin($ssoCallbackUrl);
                session_write_close();
                header('Location: '.$destination, true, 302);
                exit;
            }
            $u = $sso->complete($_GET, $ssoCallbackUrl);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            unset($_SESSION['sso_error']);
            session_write_close();
            header('Location: '.$frontendUrl.'#/dashboard', true, 302);
            exit;
        } catch (ApiError $e) {
            unset($_SESSION['sso_flow']);
            $_SESSION['sso_error'] = $e->getMessage();
            session_write_close();
            header('Location: '.$frontendUrl.'#/login?reason=sso-error', true, 302);
            exit;
        }
    }
    if ($path === 'session' && $method === 'GET') {
        $u = $a->user;
        if ($u) {
            unset($u['password_hash']);
        }
        $ssoError = $_SESSION['sso_error'] ?? null;
        unset($_SESSION['sso_error']);
        $reply(['user' => $u,'csrf' => $_SESSION['csrf'],'installation_required' => false,'setup_required' => (int)$a->db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0,'sso' => (new Sso($a))->publicStatus(),'sso_error' => $ssoError]);
        exit;
    }
    if ($path === 'setup' && $method === 'POST') {
        if ((int)$a->db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            throw new ApiError(403, 'Az első admin már létrejött.');
        }if (!is_string($body['password'] ?? null) || strlen($body['password']) < 12 || strlen($body['password']) > 72 || !is_string($body['username'] ?? null) || !preg_match('/^[A-Za-z0-9._-]{3,100}$/D', $body['username'])) {
            throw new ApiError(422, 'Legalább 3 karakteres felhasználónév és 12 karakteres jelszó szükséges.');
        }$a->tx(function () use ($a, $body) {
            $a->one('SELECT value FROM revision WHERE id=1 FOR UPDATE');
            if ((int)$a->db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                throw new ApiError(409, 'Az első admin időközben létrejött.');
            }$a->run('INSERT INTO users VALUES (?,?,?,?,?,1)', [App::id(),$body['username'],password_hash($body['password'], PASSWORD_DEFAULT),'admin','[]']);
        });
        $reply(['success' => true]);
        exit;
    }
    if ($path === 'login' && $method === 'POST') {
        $bucket = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '').'|'.strtolower($body['username'] ?? ''));
        $attempt = $a->one('SELECT * FROM login_attempts WHERE bucket=?', [$bucket]);
        if ($attempt && (int)$attempt['reset_at'] > time() && (int)$attempt['attempts'] >= 8) {
            throw new ApiError(429, 'Túl sok próbálkozás. Várj 15 percet.');
        }$u = $a->one('SELECT * FROM users WHERE username=? AND active=1', [$body['username'] ?? '']);
        if (!$u || !password_verify($body['password'] ?? '', $u['password_hash'])) {
            $a->run('INSERT INTO login_attempts VALUES (?,1,?) ON DUPLICATE KEY UPDATE attempts=IF(reset_at<UNIX_TIMESTAMP(),1,attempts+1),reset_at=IF(reset_at<UNIX_TIMESTAMP(),VALUES(reset_at),reset_at)', [$bucket,time() + 900]);
            throw new ApiError(401, 'Hibás bejelentkezési adatok.');
        }session_regenerate_id(true);
        $_SESSION['user_id'] = $u['id'];
        $a->run('DELETE FROM login_attempts WHERE bucket=?', [$bucket]);
        unset($u['password_hash']);
        $reply(['user' => $u,'csrf' => $_SESSION['csrf']]);
        exit;
    }
    if (!$a->user) {
        throw new ApiError(401, 'Bejelentkezés szükséges.');
    }
    require __DIR__.'/extra-routes.php';
    if ($path === 'health') {
        $reply(['status' => 'ok','database' => true,'revision' => $a->revision()]);
        exit;
    }
    if ($path === 'metadata') {
        $reply(['fields' => Schema::fields(),'references' => $a->all('SELECT * FROM reference_data ORDER BY category,code'),'zones' => $a->all('SELECT * FROM network_zones'),'capabilities' => array_values(array_filter(['edit','import_data','export_data','export_diagram','view_contact_details'], fn ($c) => $a->can($c)))]);
        exit;
    }
    if ($path === 'dashboard') {
        $counts = [];
        $quality = [];
        foreach (Schema::TYPES as $t) {
            $counts[$t] = (int)$a->one("SELECT COUNT(*) n FROM `$t` WHERE archived_at IS NULL")['n'];
            $quality[$t] = (int)$a->one("SELECT COUNT(*) n FROM `$t` WHERE data_quality_status='review' AND archived_at IS NULL")['n'];
        }$reply(['counts' => $counts,'quality' => $quality,'staging' => (int)$a->one("SELECT COUNT(*) n FROM import_rows WHERE disposition='staging'")['n'],'recent' => $a->all('SELECT action,entity,entity_id,created_at FROM audit_log ORDER BY created_at DESC LIMIT 8'),'environments' => $a->all('SELECT environment,COUNT(*) count FROM applications WHERE archived_at IS NULL GROUP BY environment')]);
        exit;
    }
    if ($parts[0] === 'lookups' && $method === 'GET') {
        $t = $a->type($parts[1] ?? '');
        $list = $a->listing($t, [...$_GET,'per_page' => 25]);
        $list['data'] = array_map(fn ($r) => ['id' => $r['id'],'label' => implode(' · ', array_filter([$r['public_id'],$r['name'] ?? 'Név nélkül',$r['dns_name'] ?? null,$r['environment'] ?? null]))], $list['data']);
        $reply($list);
        exit;
    }
    if ($path === 'graphs/query' && $method === 'POST') {
        $reply((new Graph($a))->query($body));
        exit;
    }
    if ($parts[0] === 'diagram-views') {
        if ($method === 'GET') {
            $rows = $a->all("SELECT * FROM diagram_views WHERE owner_id=? OR visibility='shared'", [$a->user['id']]);
            foreach ($rows as &$r) {
                $r['config'] = json_decode($r['config'], true);
            }$reply(['data' => $rows]);
            exit;
        }
        if (!in_array($method, ['POST','PATCH'])) {
            throw new ApiError(405, 'Nem támogatott művelet.');
        }$id = $parts[1] ?? App::id();
        $old = isset($parts[1]) ? $a->one('SELECT * FROM diagram_views WHERE id=?', [$id]) : null;
        if ($old && $old['owner_id'] !== $a->user['id'] && $a->user['role'] !== 'admin') {
            throw new ApiError(403, 'Más privát nézetét nem szerkesztheted.');
        }if (empty($body['name']) || strlen(json_encode($body['config'] ?? [])) > 1000000) {
            throw new ApiError(422, 'Név és legfeljebb 1 MB nézet szükséges.');
        }$config = json_encode($body['config']);
        if ($old) {
            $st = $a->db->prepare('UPDATE diagram_views SET name=?,visibility=?,config=?,lock_version=lock_version+1 WHERE id=? AND lock_version=?');
            $st->execute([$body['name'],($body['visibility'] ?? 'private') === 'shared' ? 'shared' : 'private',$config,$id,$body['lock_version'] ?? 0]);
            if (!$st->rowCount()) {
                throw new ApiError(409, 'A nézet módosult.');
            }
        } else {
            $a->run('INSERT INTO diagram_views VALUES (?,?,?,?,?,1)', [$id,$a->user['id'],$body['name'],($body['visibility'] ?? 'private') === 'shared' ? 'shared' : 'private',$config]);
        }$reply(['id' => $id]);
        exit;
    }
    if ($parts[0] === 'imports') {
        $a->need('import_data');
        if ($path === 'imports/preview' && $method === 'POST') {
            $file = $_FILES['file']['tmp_name'] ?? $a->config['source'] ?? null;
            if (!is_string($file) || !is_file($file)) {
                throw new ApiError(422, 'Válassz importálandó XLSX fájlt. Az üres telepítéshez nincs automatikus forrásfájl.');
            }
            $ram = $_POST['ram_profile'] ?? $body['ram_profile'] ?? 'preserve';
            $reply((new Importer($a))->preview($file, $ram));
            exit;
        }if (($parts[2] ?? '') === 'commit' && $method === 'POST') {
            $reply((new Importer($a))->commit($parts[1]));
            exit;
        }if ($method === 'GET') {
            $reply(['data' => $a->all('SELECT id,file_hash,profile,status,created_at FROM imports ORDER BY created_at DESC'),'staging' => $a->all("SELECT public_id,sheet,`row_number`,raw_values FROM import_rows WHERE disposition='staging'")]);
            exit;
        }
    }
    if ($path === 'exports' && $method === 'POST') {
        $a->need('export_data');
        $reply((new Exporter($a))->create($body), 202);
        exit;
    }
    if ($parts[0] === 'jobs' && $method === 'GET') {
        $a->need('export_data');
        $j = $a->one('SELECT id,status,format,error,created_at,user_id FROM jobs WHERE id=?', [$parts[1] ?? '']);
        if (!$j || $j['user_id'] !== $a->user['id']) {
            throw new ApiError(404, 'Feladat nem található.');
        }$reply($j);
        exit;
    }
    if ($parts[0] === 'jobs' && ($parts[2] ?? '') === 'cancel' && $method === 'POST') {
        $a->need('export_data');
        $a->run("UPDATE jobs SET status='cancelled' WHERE id=? AND user_id=? AND status='queued'", [$parts[1],$a->user['id']]);
        $reply(['success' => true]);
        exit;
    }
    if ($parts[0] === 'exports' && ($parts[2] ?? '') === 'download' && $method === 'GET') {
        $a->need('export_data');
        $j = $a->one("SELECT * FROM jobs WHERE id=? AND user_id=? AND status='completed' AND created_at>UTC_TIMESTAMP()-INTERVAL 24 HOUR", [$parts[1],$a->user['id']]);
        if (!$j || !is_file($j['path'])) {
            throw new ApiError(404, 'Az export lejárt vagy nem található.');
        }header('Content-Type: '.($j['format'] === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/zip'));
        header('Content-Disposition: attachment; filename="CMDB-'.$j['id'].'.'.$j['format'].'"');
        readfile($j['path']);
        exit;
    }
    if ($parts[0] === 'admin') {
        if ($a->user['role'] !== 'admin') {
            throw new ApiError(403, 'Adminisztrátori jogosultság szükséges.');
        }$resource = $parts[1] ?? '';
        if ($resource === 'users') {
            if ($method === 'GET') {
                $reply(['data' => $a->all('SELECT u.id,u.username,u.role,u.capabilities,u.active,EXISTS(SELECT 1 FROM user_identities i WHERE i.user_id=u.id) AS sso_identity FROM users u')]);
                exit;
            }if (strlen($body['password'] ?? '') < 12 || !in_array($body['role'] ?? '', ['viewer','editor','admin'])) {
                throw new ApiError(422, 'Érvényes szerep és legalább 12 karakteres jelszó szükséges.');
            }$a->run('INSERT INTO users VALUES (?,?,?,?,?,1)', [App::id(),$body['username'],password_hash($body['password'], PASSWORD_DEFAULT),$body['role'],json_encode(array_values(array_intersect($body['capabilities'] ?? [], ['import_data','export_data','export_diagram','view_contact_details'])))]);
            $reply(['success' => true]);
            exit;
        }
        if ($resource === 'references') {
            if ($method === 'GET') {
                $reply(['data' => $a->all('SELECT * FROM reference_data')]);
                exit;
            }if (empty($body['category']) || empty($body['code']) || empty($body['label'])) {
                throw new ApiError(422, 'Kategória, kód és címke szükséges.');
            }$a->run('INSERT INTO reference_data VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),active=VALUES(active)', [App::id(),$body['category'],$body['code'],$body['label'],(int)($body['active'] ?? 1)]);
            $a->audit('dictionary', 'reference_data', null, [], $body);
            $reply(['success' => true]);
            exit;
        }
        if ($resource === 'zones') {
            if ($method === 'GET') {
                $reply(['data' => $a->all('SELECT * FROM network_zones')]);
                exit;
            }if (empty($body['name']) || !in_array($body['scope'] ?? '', ['internal','dmz','partner','external','unknown'])) {
                throw new ApiError(422, 'Név és érvényes zónatípus szükséges.');
            }$a->run('INSERT INTO network_zones VALUES (?,?,?,?,?,?)', [App::id(),$body['name'],$body['scope'],$body['parent_zone_id'] ?? null,$body['color'] ?? '#94a3b8',$body['notes'] ?? null]);
            $a->audit('zone', 'network_zones', null, [], $body);
            $reply(['success' => true]);
            exit;
        }
    }
    $t = str_replace('-', '_', $parts[0]);
    $a->type($t);
    $id = $parts[1] ?? null;
    $action = $parts[2] ?? null;
    if (!$id) {
        if ($method === 'GET') {
            $reply($a->listing($t, $_GET));
            exit;
        }if ($method === 'POST') {
            $a->need('edit');
            $reply($a->save($t, $body), 201);
            exit;
        }
    }
    if ($action === 'relationships' && $method === 'GET') {
        $reply($a->relationships($t, $id));
        exit;
    }
    if ($action === 'contacts' && in_array($t, ['applications','servers','databases'])) {
        $a->need('edit');
        $result = $a->tx(function () use ($a, $t, $id, $method, $parts, $body) {
            $a->one("SELECT id FROM `$t` WHERE id=? FOR UPDATE", [$id]);
            if ($method === 'DELETE') {
                $a->run("DELETE FROM {$t}_contacts WHERE id=? AND target_id=?", [$parts[3] ?? '',$id]);
            } elseif ($method === 'POST') {
                if (!$a->one("SELECT id FROM reference_data WHERE category='role' AND code=?", [$body['role_code'] ?? ''])) {
                    throw new ApiError(422, 'Érvényes szerep szükséges.');
                }if (!empty($body['is_primary']) && $a->one("SELECT id FROM {$t}_contacts WHERE target_id=? AND role_code=? AND is_primary=1", [$id,$body['role_code']])) {
                    throw new ApiError(422, 'Ebben a szerepben már van elsődleges kontakt.');
                }$a->run("INSERT INTO {$t}_contacts VALUES (?,?,?,?,?,?,?)", [App::id(),$id,$body['contact_id'],$body['role_code'],$body['role_description'] ?? null,(int)($body['is_primary'] ?? 0),$body['notes'] ?? null]);
            } else {
                throw new ApiError(405, 'Nem támogatott művelet.');
            }$a->audit('contacts', $t, $id, [], $body);
            return ['success' => true];
        });
        $reply($result);
        exit;
    }
    if (in_array($action, ['archive','restore']) && $method === 'POST') {
        $a->need('edit');
        $a->tx(function () use ($a, $t, $id, $action, $body) {
            $before = $a->get($t, $id, true);
            if ((int)($body['lock_version'] ?? 0) !== (int)$before['lock_version']) {
                throw new ApiError(409, 'A rekord módosult.');
            }$st = $a->db->prepare("UPDATE `$t` SET archived_at=".($action === 'archive' ? 'UTC_TIMESTAMP(6)' : 'NULL').',lock_version=lock_version+1 WHERE id=? AND lock_version=?');
            $st->execute([$id,$body['lock_version']]);
            if (!$st->rowCount()) {
                throw new ApiError(409, 'A rekord módosult.');
            }$a->audit($action, $t, $id, $before, $a->get($t, $id, true));
        });
        $reply(['success' => true]);
        exit;
    }
    if ($id && !$action) {
        if ($method === 'GET') {
            $reply($a->get($t, $id));
            exit;
        }if ($method === 'PATCH') {
            $a->need('edit');
            $reply($a->save($t, $body, $id));
            exit;
        }
    }
    throw new ApiError(404, 'Végpont nem található.');
} catch (ApiError $e) {
    http_response_code($e->status);
    echo json_encode(['code' => 'CMDB_'.$e->status,'message' => $e->getMessage(),'field_errors' => $e->fields,'request_id' => $requestId], JSON_UNESCAPED_UNICODE);
} catch (\PDOException $e) {
    http_response_code(422);
    echo json_encode(['code' => 'CONSTRAINT','message' => 'Az adatkapcsolat vagy az egyediség sérülne. Ellenőrizd a hivatkozásokat.','request_id' => $requestId], JSON_UNESCAPED_UNICODE);
    error_log($requestId.' database error '.$e->getCode());
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['code' => 'INTERNAL','message' => 'Belső hiba történt. Az adatok nem lettek részlegesen mentve.','request_id' => $requestId], JSON_UNESCAPED_UNICODE);
    error_log($requestId.' '.$e->getMessage());
}
