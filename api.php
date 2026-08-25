<?php
declare(strict_types=1);

use PenguLab\Database;
use PenguLab\FeedReader;
use PenguLab\Favicon;
use PenguLab\HttpClient;

try {
    /** @var array $ctx */
    $ctx = require __DIR__ . '/bootstrap.php';
    $db = $ctx['db'];
    $addons = $ctx['addons'];
    $integrations = $ctx['integrations'];
    $auth = $ctx['auth'];
} catch (\Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}

$route = trim((string)($_GET['route'] ?? 'bootstrap'), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!$auth->check()) {
    json_response(['ok'=>false,'error'=>'Authentication required.'], 401);
}

if ($method !== 'GET') {
    $csrf = (string)($_SERVER['HTTP_X_PENGULAB_CSRF'] ?? '');
    if ($csrf === '' || !hash_equals((string)$ctx['csrf'], $csrf)) {
        json_response(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }
}

try {
    switch ($route) {
        case 'bootstrap':
            require_method('GET');
            json_response(['ok' => true] + bootstrap_payload($ctx));

        case 'apps/list':
            require_method('GET');
            json_response(['ok' => true, 'apps' => $db->apps()]);

        case 'apps/save':
            require_method('POST');
            require_admin($auth);
            $input = json_body();
            $app = save_app($db, $input);
            json_response(['ok' => true, 'app' => $app, 'apps' => $db->apps(), 'widgets' => $db->widgets()]);

        case 'apps/favicon':
            require_method('POST');
            require_admin($auth);
            $input = json_body();
            $url = clean_url((string)($input['url'] ?? ''));
            $verifyTls = !array_key_exists('verify_tls', $input) || (bool)$input['verify_tls'];
            $image = Favicon::detect($url, $verifyTls);
            json_response(['ok' => true, 'image' => $image]);

        case 'apps/delete':
            require_method('POST');
            require_admin($auth);
            delete_app($db, (string)(json_body()['id'] ?? ''));
            json_response(['ok' => true, 'apps' => $db->apps(), 'widgets' => $db->widgets()]);

        case 'settings/save':
            require_method('POST');
            require_admin($auth);
            $input = json_body();
            foreach (['theme', 'language', 'dashboard_title'] as $key) {
                if (array_key_exists($key, $input)) {
                    $value = $input[$key];
                    if ($key === 'theme' && !in_array($value, ['system', 'light', 'dark'], true)) continue;
                    if ($key === 'language' && !in_array($value, ['de', 'en'], true)) continue;
                    $db->setSetting($key, $value);
                }
            }
            json_response(['ok' => true, 'settings' => settings_payload($db)]);

        case 'widgets/create':
            require_method('POST');
            require_admin($auth);
            $widget = create_widget($db, $addons, json_body());
            json_response(['ok' => true, 'widget' => $widget, 'widgets' => $db->widgets()]);

        case 'widgets/update':
            require_method('POST');
            require_admin($auth);
            update_widget($db, json_body());
            json_response(['ok' => true, 'widgets' => $db->widgets()]);

        case 'widgets/delete':
            require_method('POST');
            require_admin($auth);
            $id = trim((string)(json_body()['id'] ?? ''));
            $db->pdo()->prepare('DELETE FROM widgets WHERE id = :id')->execute(['id' => $id]);
            json_response(['ok' => true, 'widgets' => $db->widgets()]);

        case 'widgets/layout':
            require_method('POST');
            require_admin($auth);
            save_layout($db, json_body()['widgets'] ?? []);
            json_response(['ok' => true, 'widgets' => $db->widgets()]);

        case 'widgets/data':
            require_method('GET');
            $id = trim((string)($_GET['id'] ?? ''));
            require_widget_access($db, $auth, $id);
            $cachedOnly = !empty($_GET['cached']);
            json_response(['ok' => true, 'data' => widget_data($db, $addons, $integrations, $id, $cachedOnly)]);

        case 'addons/upload':
            require_method('POST');
            require_admin($auth);
            $file = $_FILES['package'] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('No valid PenguHub ZIP package was uploaded.');
            }
            $originalName = (string)($file['name'] ?? 'package.zip');
            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
                throw new RuntimeException('PenguHub packages must be uploaded as ZIP files.');
            }
            $tmpName = (string)($file['tmp_name'] ?? '');
            if ($tmpName === '' || !is_file($tmpName)) throw new RuntimeException('Uploaded package could not be read.');
            $manifest = $addons->uploadPackage($tmpName, $originalName);
            if (is_array($manifest['integration'] ?? null)) {
                $type = (string)($manifest['integration']['type'] ?? '');
                if ($type !== '') $db->pdo()->prepare('UPDATE integrations SET enabled=1 WHERE type=:type')->execute(['type'=>$type]);
            }
            json_response(['ok'=>true,'package'=>$manifest] + bootstrap_payload($ctx));

        case 'addons/delete-uploaded':
            require_method('POST');
            require_admin($auth);
            $id = trim((string)(json_body()['id'] ?? ''));
            $manifest = $addons->manifest($id);
            if (!$manifest) throw new RuntimeException('PenguHub package not found.');
            $type = is_array($manifest['integration'] ?? null) ? (string)($manifest['integration']['type'] ?? '') : '';
            $addons->deleteUploadedPackage($id);
            if ($type !== '') $db->pdo()->prepare('UPDATE integrations SET enabled=0 WHERE type=:type')->execute(['type'=>$type]);
            json_response(['ok'=>true] + bootstrap_payload($ctx));

        case 'addons/install':
            require_method('POST');
            require_admin($auth);
            $id = trim((string)(json_body()['id'] ?? ''));
            $manifest = $addons->manifest($id);
            $addons->install($id);
            if (is_array($manifest['integration'] ?? null)) {
                $type = (string)($manifest['integration']['type'] ?? '');
                if ($type !== '') {
                    $db->pdo()->prepare('UPDATE integrations SET enabled=1 WHERE type=:type')->execute(['type' => $type]);
                }
            }
            json_response(['ok' => true] + bootstrap_payload($ctx));

        case 'addons/uninstall':
            require_method('POST');
            require_admin($auth);
            $id = trim((string)(json_body()['id'] ?? ''));
            $manifest = $addons->manifest($id);
            $addons->uninstall($id);
            if (is_array($manifest['integration'] ?? null)) {
                $type = (string)($manifest['integration']['type'] ?? '');
                if ($type !== '') {
                    $db->pdo()->prepare('UPDATE integrations SET enabled=0 WHERE type=:type')->execute(['type' => $type]);
                }
            }
            json_response(['ok' => true] + bootstrap_payload($ctx));

        case 'integrations/save':
            require_method('POST');
            require_admin($auth);
            $saved = $integrations->save(json_body());
            json_response(['ok' => true, 'integration' => $saved, 'integrations' => $integrations->list()]);

        case 'integrations/delete':
            require_method('POST');
            require_admin($auth);
            $integrations->delete(trim((string)(json_body()['id'] ?? '')));
            json_response(['ok' => true, 'integrations' => $integrations->list()]);

        case 'integrations/test':
            require_method('POST');
            require_admin($auth);
            $id = trim((string)(json_body()['id'] ?? ''));
            $result = $integrations->test($id);
            json_response(['ok' => true, 'data' => $result, 'integrations' => $integrations->list()]);

        case 'integrations/interfaces':
            require_method('POST');
            require_admin($auth);
            $id = trim((string)(json_body()['id'] ?? ''));
            $integration = $integrations->full($id);
            if (!$integration || (string)($integration['type'] ?? '') !== 'opnsense') {
                throw new RuntimeException('OPNsense integration not found.');
            }
            $result = $integrations->execute($id, 'summary');
            json_response(['ok'=>true,'interfaces'=>is_array($result['interfaces'] ?? null) ? $result['interfaces'] : [],'gateways'=>is_array($result['gateways'] ?? null) ? $result['gateways'] : []]);

        case 'homeassistant/entities':
            require_method('POST');
            require_admin($auth);
            $input = json_body();
            $id = trim((string)($input['id'] ?? ''));
            $integration = $integrations->full($id);
            if (!$integration || (string)($integration['type'] ?? '') !== 'homeassistant') {
                throw new RuntimeException('Home Assistant integration not found.');
            }
            $result = $integrations->execute($id, 'entities');
            json_response(['ok'=>true,'entities'=>$result['entities'] ?? []]);

        case 'homeassistant/action':
            require_method('POST');
            $input = json_body();
            $id = trim((string)($input['id'] ?? ''));
            if (!$auth->canIntegration($id)) json_response(['ok'=>false,'error'=>'No permission for this integration.'],403);
            $integration = $integrations->full($id);
            if (!$integration || (string)($integration['type'] ?? '') !== 'homeassistant') {
                throw new RuntimeException('Home Assistant integration not found.');
            }
            $payload = [
                'entity_id' => trim((string)($input['entity_id'] ?? '')),
                'action' => trim((string)($input['action'] ?? '')),
            ];
            if (!homeassistant_entity_is_exposed($db, $id, $payload['entity_id'])) {
                json_response(['ok'=>false,'error'=>'This Home Assistant entity is not exposed by a dashboard widget.'],403);
            }
            if (array_key_exists('position', $input)) $payload['position'] = (int)$input['position'];
            $mode = 'action:' . base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
            $result = $integrations->execute($id, $mode);
            json_response(['ok'=>true,'data'=>$result]);

        case 'integrations/action':
            require_method('POST');
            $input = json_body();
            $id = trim((string)($input['id'] ?? ''));
            $action = trim((string)($input['action'] ?? ''));
            if (!$auth->canIntegration($id)) json_response(['ok'=>false,'error'=>'No permission for this integration.'],403);
            $result = $integrations->action($id, $action);
            usleep(250000);
            $summary = $integrations->execute($id, 'summary');
            $integration = null;
            foreach ($integrations->list() as $entry) if ($entry['id'] === $id) $integration = $entry;
            if (!$integration) throw new RuntimeException('Integration not found after action.');
            $history = persist_integration_widget_data($db, $integration, $summary);
            $expected = $action === 'protection_enable';
            if ((bool)($summary['protection'] ?? !$expected) !== $expected) {
                throw new RuntimeException('The service accepted the request, but the protection state did not change. Check API permissions and the integration log.');
            }
            json_response(['ok'=>true,'data'=>$result,'summary'=>$summary,'history'=>$history,'integration'=>$integration,'integrations'=>visible_integrations($ctx)]);

        case 'users/preference':
            require_method('POST');
            $input=json_body(); $key=(string)($input['key']??'');
            if (!in_array($key,['sidebar_collapsed'],true)) throw new RuntimeException('Unsupported preference.');
            $auth->setPreference($key,(bool)($input['value']??false));
            json_response(['ok'=>true,'user'=>$auth->user()]);

        case 'users/save':
            require_method('POST'); require_admin($auth);
            $auth->saveUser(json_body());
            json_response(['ok'=>true,'users'=>$auth->listUsers()]);

        case 'users/delete':
            require_method('POST'); require_admin($auth);
            $auth->deleteUser(trim((string)(json_body()['id']??'')));
            json_response(['ok'=>true,'users'=>$auth->listUsers()]);

        case 'users/password':
            require_method('POST');
            $input=json_body(); $auth->changeOwnPassword((string)($input['current']??''),(string)($input['password']??''));
            json_response(['ok'=>true]);

        case 'search':
            require_method('GET');
            json_response(['ok' => true, 'items' => global_search($db, $addons, $integrations, $auth, (string)($_GET['q'] ?? ''))]);

        case 'export':
            require_method('GET');
            require_admin($auth);
            json_response(['ok' => true, 'data' => export_data($ctx)]);

        case 'import':
            require_method('POST');
            require_admin($auth);
            import_data($ctx, json_body()['data'] ?? []);
            json_response(['ok' => true] + bootstrap_payload($ctx));

        default:
            if (str_starts_with($route, 'addon/')) {
                $parts = explode('/', $route, 3);
                $addonId = $parts[1] ?? '';
                if ($addonId === 'ipmanager' && !$auth->canIpManager()) json_response(['ok'=>false,'error'=>'No permission for IP Manager.'],403);
                $action = $parts[2] ?? 'get';
                $apiFile = $addons->apiFile($addonId);
                if (!$apiFile) json_response(['ok' => false, 'error' => 'Addon API unavailable.'], 404);
                $addonAction = $action;
                $penguLab = $ctx;
                require $apiFile;
                exit;
            }
            json_response(['ok' => false, 'error' => 'Unknown API route.'], 404);
    }
} catch (\Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function require_method(string $expected): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== $expected) json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}


function require_admin($auth): void
{
    if (!$auth->isAdmin()) json_response(['ok'=>false,'error'=>'Administrator permission required.'],403);
}

function visible_integrations(array $ctx): array
{
    $all=$ctx['integrations']->list(); $auth=$ctx['auth'];
    if($auth->isAdmin()) return $all;
    return array_values(array_filter($all,fn($i)=>$auth->canIntegration((string)$i['id'])));
}

function visible_widgets(array $ctx): array
{
    $widgets=$ctx['db']->widgets(); $auth=$ctx['auth'];
    if($auth->isAdmin()) return $widgets;
    return array_values(array_filter($widgets,function($w)use($auth){
        if(($w['type']??'')==='ipmanager-summary') return $auth->canIpManager();
        if(in_array(($w['type']??''),['integration-summary','homeassistant-entities'],true)) return $auth->canIntegration((string)($w['config']['integration_id']??''));
        return true;
    }));
}

function require_widget_access(Database $db, $auth, string $id): void
{
    foreach($db->widgets() as $w){if($w['id']!==$id)continue;
        if(($w['type']??'')==='ipmanager-summary'&&!$auth->canIpManager())json_response(['ok'=>false,'error'=>'No permission for IP Manager.'],403);
        if(in_array(($w['type']??''),['integration-summary','homeassistant-entities'],true)&&!$auth->canIntegration((string)($w['config']['integration_id']??'')))json_response(['ok'=>false,'error'=>'No permission for this integration.'],403);
        return;
    }
}

function settings_payload(Database $db): array
{
    return [
        'theme' => $db->setting('theme', 'system'),
        'language' => $db->setting('language', 'de'),
        'dashboard_title' => $db->setting('dashboard_title', 'My Homelab'),
    ];
}

function bootstrap_payload(array $ctx): array
{
    return [
        'version' => (string)($ctx['version'] ?? 'dev'),
        'csrf' => $ctx['csrf'],
        'settings' => settings_payload($ctx['db']),
        'user' => $ctx['auth']->user(),
        'defaultPassword' => $ctx['auth']->isUsingDefaultAdminPassword(),
        'users' => $ctx['auth']->isAdmin() ? $ctx['auth']->listUsers() : [],
        'apps' => $ctx['db']->apps(),
        'widgets' => visible_widgets($ctx),
        'addons' => $ctx['auth']->isAdmin() ? $ctx['addons']->all() : [],
        'widgetCatalog' => $ctx['auth']->isAdmin() ? $ctx['addons']->widgetCatalog() : [],
        'integrationTypes' => $ctx['auth']->isAdmin() ? $ctx['addons']->integrationTypes() : [],
        'integrations' => visible_integrations($ctx),
    ];
}

function homeassistant_entity_is_exposed(Database $db, string $integrationId, string $entityId): bool
{
    if ($integrationId === '' || $entityId === '') return false;
    foreach ($db->widgets() as $widget) {
        if (($widget['type'] ?? '') !== 'homeassistant-entities') continue;
        $config = is_array($widget['config'] ?? null) ? $widget['config'] : [];
        if ((string)($config['integration_id'] ?? '') !== $integrationId) continue;
        $ids = is_array($config['entity_ids'] ?? null) ? array_map('strval', $config['entity_ids']) : [];
        if (in_array($entityId, $ids, true)) return true;
    }
    return false;
}

function clean_url(string $url): string
{
    $url = trim($url);
    if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('Please enter a valid HTTP or HTTPS URL.');
    }
    return $url;
}

function clean_image(string $image): string
{
    $image = trim($image);
    if ($image === '') return '';
    if (preg_match('~^data:image/(png|jpe?g|webp|gif|svg\+xml);base64,~i', $image)) {
        if (strlen($image) > 2_500_000) throw new RuntimeException('Image is too large.');
        return $image;
    }
    $parts = parse_url($image);
    if ($parts && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return $image;
    return '';
}

function save_app(Database $db, array $input): array
{
    $id = trim((string)($input['id'] ?? '')) ?: Database::uuid('app');
    $name = mb_substr(trim(strip_tags((string)($input['name'] ?? ''))), 0, 100);
    if ($name === '') throw new RuntimeException('App name is required.');
    $url = clean_url((string)($input['url'] ?? ''));
    $description = mb_substr(trim(strip_tags((string)($input['description'] ?? ''))), 0, 300);
    $category = mb_substr(trim(strip_tags((string)($input['category'] ?? ''))), 0, 80);
    $image = clean_image((string)($input['image'] ?? ''));
    $now = gmdate(DATE_ATOM);
    $existingStmt = $db->pdo()->prepare('SELECT id,position,created_at,image,url FROM apps WHERE id=:id');
    $existingStmt->execute(['id' => $id]);
    $existing = $existingStmt->fetch();
    $position = $existing ? (int)$existing['position'] : (int)$db->pdo()->query('SELECT COALESCE(MAX(position),-1)+1 FROM apps')->fetchColumn();
    $created = $existing['created_at'] ?? $now;

    $refreshFavicon = !empty($input['refresh_favicon']);
    if ($image === '' && (!$existing || empty($existing['image']) || $refreshFavicon)) {
        try {
            $detected = Favicon::detect($url, !array_key_exists('favicon_verify_tls', $input) || (bool)$input['favicon_verify_tls']);
            $image = $detected !== '' ? $detected : (string)($existing['image'] ?? '');
        } catch (Throwable) {
            // Favicon lookup is best-effort and must never prevent saving an app.
            $image = (string)($existing['image'] ?? '');
        }
    } elseif ($image === '' && $existing) {
        $image = (string)($existing['image'] ?? '');
    }

    $stmt = $db->pdo()->prepare(
        'INSERT INTO apps(id,name,url,description,category,image,position,created_at,updated_at) VALUES(:id,:name,:url,:description,:category,:image,:position,:created,:updated) '
        . 'ON CONFLICT(id) DO UPDATE SET name=excluded.name,url=excluded.url,description=excluded.description,category=excluded.category,image=excluded.image,updated_at=excluded.updated_at'
    );
    $stmt->execute(compact('id','name','url','description','category','image','position','created') + ['updated' => $now]);

    if (!$existing && !empty($input['add_to_dashboard'])) {
        create_widget($db, null, ['type' => 'app', 'config' => ['app_id' => $id, 'layout' => 'vertical'], 'w' => 1, 'h' => 4]);
    }

    $stmt = $db->pdo()->prepare('SELECT * FROM apps WHERE id=:id');
    $stmt->execute(['id' => $id]);
    return $stmt->fetch() ?: [];
}

function delete_app(Database $db, string $id): void
{
    if ($id === '') return;
    $db->transaction(function($pdo) use ($id): void {
        $pdo->prepare('DELETE FROM apps WHERE id=:id')->execute(['id' => $id]);
        $rows = $pdo->query("SELECT id, config_json FROM widgets WHERE type='app'")->fetchAll();
        $del = $pdo->prepare('DELETE FROM widgets WHERE id=:id');
        foreach ($rows as $row) {
            $config = json_decode((string)$row['config_json'], true) ?: [];
            if (($config['app_id'] ?? '') === $id) $del->execute(['id' => $row['id']]);
        }
    });
}

function create_widget(Database $db, $addons, array $input): array
{
    $type = trim((string)($input['type'] ?? ''));
    $allowed = ['app', 'clock', 'note'];
    if ($addons) {
        foreach ($addons->widgetCatalog() as $item) $allowed[] = (string)($item['type'] ?? '');
    }
    if ($type === '' || !in_array($type, array_unique($allowed), true)) throw new RuntimeException('Unknown widget type.');
    $id = Database::uuid('widget');
    $dashboard = $db->defaultDashboardId();
    $config = is_array($input['config'] ?? null) ? $input['config'] : [];
    $title = mb_substr(trim(strip_tags((string)($input['title'] ?? ''))), 0, 100);
    $minW = in_array($type, ['app','homeassistant-entities'], true) ? 1 : 2;
    $w = max($minW, min(12, (int)($input['w'] ?? 3)));
    $h = max(4, min(32, (int)($input['h'] ?? 8)));
    $x = max(0, min(11, (int)($input['x'] ?? 0)));
    $maxY = (int)$db->pdo()->query('SELECT COALESCE(MAX(y+h),0) FROM widgets')->fetchColumn();
    $y = max(0, (int)($input['y'] ?? $maxY));
    $now = gmdate(DATE_ATOM);
    $stmt = $db->pdo()->prepare('INSERT INTO widgets(id,dashboard_id,type,title,x,y,w,h,config_json,created_at,updated_at) VALUES(:id,:dashboard,:type,:title,:x,:y,:w,:h,:config,:created,:updated)');
    $stmt->execute([
        'id'=>$id,'dashboard'=>$dashboard,'type'=>$type,'title'=>$title,'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,
        'config'=>json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),'created'=>$now,'updated'=>$now,
    ]);
    foreach ($db->widgets() as $widget) if ($widget['id'] === $id) return $widget;
    return [];
}

function update_widget(Database $db, array $input): void
{
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '') throw new RuntimeException('Widget ID missing.');
    $existing = null;
    foreach ($db->widgets() as $widget) if ($widget['id'] === $id) $existing = $widget;
    if (!$existing) throw new RuntimeException('Widget not found.');
    $title = array_key_exists('title', $input) ? mb_substr(trim(strip_tags((string)$input['title'])), 0, 100) : $existing['title'];
    $config = is_array($input['config'] ?? null) ? $input['config'] : $existing['config'];
    $stmt = $db->pdo()->prepare('UPDATE widgets SET title=:title, config_json=:config, updated_at=:updated WHERE id=:id');
    $stmt->execute(['title'=>$title,'config'=>json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated'=>gmdate(DATE_ATOM),'id'=>$id]);
    $db->pdo()->prepare('DELETE FROM widget_data_cache WHERE widget_id=:id')->execute(['id'=>$id]);
}

function save_layout(Database $db, mixed $items): void
{
    if (!is_array($items)) throw new RuntimeException('Invalid dashboard layout.');

    $existing = $db->widgets();
    $byId = [];
    foreach ($existing as $widget) $byId[(string)$widget['id']] = $widget;

    // Apply the submitted draft to a complete in-memory layout first. This makes the
    // save atomic and allows us to reject overlaps instead of silently moving widgets.
    $layout = [];
    foreach ($existing as $widget) {
        $layout[(string)$widget['id']] = [
            'id'=>(string)$widget['id'],
            'type'=>(string)$widget['type'],
            'x'=>(int)$widget['x'], 'y'=>(int)$widget['y'],
            'w'=>(int)$widget['w'], 'h'=>(int)$widget['h'],
        ];
    }

    $seen = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') continue;
        if (!isset($layout[$id])) throw new RuntimeException('Dashboard contains an unknown widget.');
        if (isset($seen[$id])) throw new RuntimeException('Dashboard contains a duplicate widget.');
        $seen[$id] = true;

        $minW = in_array(($layout[$id]['type'] ?? ''), ['app','homeassistant-entities'], true) ? 1 : 2;
        $w = max($minW, min(12, (int)($item['w'] ?? $layout[$id]['w'])));
        $h = max(4, min(32, (int)($item['h'] ?? $layout[$id]['h'])));
        $x = max(0, min(12-$w, (int)($item['x'] ?? $layout[$id]['x'])));
        $y = max(0, (int)($item['y'] ?? $layout[$id]['y']));
        $layout[$id] = array_merge($layout[$id], ['x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h]);
    }

    $rows = array_values($layout);
    for ($i=0, $count=count($rows); $i<$count; $i++) {
        $a = $rows[$i];
        for ($j=$i+1; $j<$count; $j++) {
            $b = $rows[$j];
            $overlap = $a['x'] < $b['x']+$b['w'] && $a['x']+$a['w'] > $b['x']
                && $a['y'] < $b['y']+$b['h'] && $a['y']+$a['h'] > $b['y'];
            if ($overlap) throw new RuntimeException('Dashboard layout contains overlapping widgets. Move the widgets apart and save again.');
        }
    }

    // Preserve optional per-widget responsive editor settings (for example
    // smartphone order/size) together with the atomic layout snapshot.
    $submittedConfig = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = trim((string)($item['id'] ?? ''));
        if ($id !== '' && isset($byId[$id]) && is_array($item['config'] ?? null)) {
            $submittedConfig[$id] = $item['config'];
        }
    }

    $stmt = $db->pdo()->prepare('UPDATE widgets SET x=:x,y=:y,w=:w,h=:h,config_json=:config,updated_at=:updated WHERE id=:id');
    $now = gmdate(DATE_ATOM);
    $db->transaction(function() use ($layout,$stmt,$now,$submittedConfig,$byId): void {
        foreach ($layout as $item) {
            $id=(string)$item['id'];
            $config=$submittedConfig[$id] ?? ($byId[$id]['config'] ?? []);
            $stmt->execute([
                'x'=>$item['x'],'y'=>$item['y'],'w'=>$item['w'],'h'=>$item['h'],
                'config'=>json_encode($config, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'updated'=>$now,'id'=>$id
            ]);
        }
    });
}

function integration_cache_payload(Database $db, string $integrationId, string $type): array
{
    $stmt = $db->pdo()->prepare('SELECT summary_json,fetched_at FROM integration_widget_cache WHERE integration_id=:id');
    $stmt->execute(['id'=>$integrationId]);
    $row = $stmt->fetch();
    $summary = [];
    $fetchedAt = 0;
    if (is_array($row)) {
        $decoded = json_decode((string)$row['summary_json'], true);
        if (is_array($decoded)) $summary = $decoded;
        $fetchedAt = (int)$row['fetched_at'];
    }
    return [
        'summary'=>$summary,
        'history'=>integration_metric_history($db, $integrationId, $type),
        'cached'=>$summary !== [],
        'fetched_at'=>$fetchedAt,
        'cache_age'=>$fetchedAt > 0 ? max(0, time()-$fetchedAt) : null,
    ];
}

function integration_metric_history(Database $db, string $integrationId, string $type): array
{
    $metric = in_array($type, ['pihole','adguardhome'], true) ? 'dns' : ($type === 'opnsense' ? 'traffic' : '');
    if ($metric === '') return ['metric'=>'','a'=>[],'b'=>[],'timestamps'=>[]];
    $stmt = $db->pdo()->prepare('SELECT sampled_at,value_a,value_b FROM integration_metric_samples WHERE integration_id=:id AND metric=:metric ORDER BY sampled_at DESC LIMIT 41');
    $stmt->execute(['id'=>$integrationId,'metric'=>$metric]);
    $rows = array_reverse($stmt->fetchAll());
    $a=[]; $b=[]; $timestamps=[]; $previous=null;
    foreach ($rows as $row) {
        $current=['t'=>(int)$row['sampled_at'],'a'=>(float)$row['value_a'],'b'=>(float)$row['value_b']];
        if ($previous !== null) {
            $seconds = max(1, $current['t']-$previous['t']);
            $deltaA = $current['a']-$previous['a'];
            $deltaB = $current['b']-$previous['b'];
            if ($deltaA >= 0 && $deltaB >= 0) {
                $factor = $metric === 'dns' ? (60/$seconds) : (1/$seconds);
                $a[] = round($deltaA*$factor, 3);
                $b[] = round($deltaB*$factor, 3);
                $timestamps[] = $current['t'];
            }
        }
        $previous=$current;
    }
    if (count($a)>30) { $a=array_slice($a,-30); $b=array_slice($b,-30); $timestamps=array_slice($timestamps,-30); }
    return ['metric'=>$metric,'a'=>$a,'b'=>$b,'timestamps'=>$timestamps];
}

function persist_integration_widget_data(Database $db, array $integration, array $summary): array
{
    $integrationId=(string)($integration['id']??'');
    $type=(string)($integration['type']??'');
    if ($integrationId==='') return ['metric'=>'','a'=>[],'b'=>[],'timestamps'=>[]];
    $now=time();
    $stmt=$db->pdo()->prepare('INSERT INTO integration_widget_cache(integration_id,summary_json,fetched_at) VALUES(:id,:summary,:fetched) ON CONFLICT(integration_id) DO UPDATE SET summary_json=excluded.summary_json,fetched_at=excluded.fetched_at');
    $stmt->execute(['id'=>$integrationId,'summary'=>json_encode($summary,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'fetched'=>$now]);

    $metric=''; $a=null; $b=null;
    if (in_array($type,['pihole','adguardhome'],true)) {
        $metric='dns'; $a=(float)($summary['queries']??0); $b=(float)($summary['blocked']??0);
    } elseif ($type==='opnsense' && is_array($summary['traffic']??null)) {
        $rawA=$summary['traffic']['rx_bytes']??null; $rawB=$summary['traffic']['tx_bytes']??null;
        if ($rawA!==null && $rawB!==null && is_numeric($rawA) && is_numeric($rawB)) {
            $metric='traffic'; $a=(float)$rawA; $b=(float)$rawB;
        }
    }
    if ($metric!=='' && $a!==null && $b!==null) {
        // 2.0 could persist a false 0/0 OPNsense sample when the counter field names were
        // not recognized. Drop that legacy series as soon as real counters arrive.
        if ($metric==='traffic' && ($a>0 || $b>0)) {
            $legacy=$db->pdo()->prepare('SELECT value_a,value_b FROM integration_metric_samples WHERE integration_id=:id AND metric=:metric ORDER BY sampled_at DESC LIMIT 1');
            $legacy->execute(['id'=>$integrationId,'metric'=>$metric]);
            $legacyRow=$legacy->fetch();
            if (is_array($legacyRow) && (float)$legacyRow['value_a']===0.0 && (float)$legacyRow['value_b']===0.0) {
                $db->pdo()->prepare('DELETE FROM integration_metric_samples WHERE integration_id=:id AND metric=:metric')->execute(['id'=>$integrationId,'metric'=>$metric]);
            }
        }
        $last=$db->pdo()->prepare('SELECT sampled_at FROM integration_metric_samples WHERE integration_id=:id AND metric=:metric ORDER BY sampled_at DESC LIMIT 1');
        $last->execute(['id'=>$integrationId,'metric'=>$metric]);
        $lastAt=(int)($last->fetchColumn()?:0);
        if ($lastAt===0 || $now-$lastAt>=3) {
            $insert=$db->pdo()->prepare('INSERT INTO integration_metric_samples(integration_id,metric,sampled_at,value_a,value_b) VALUES(:id,:metric,:sampled,:a,:b)');
            $insert->execute(['id'=>$integrationId,'metric'=>$metric,'sampled'=>$now,'a'=>$a,'b'=>$b]);
            $prune=$db->pdo()->prepare('DELETE FROM integration_metric_samples WHERE integration_id=:id AND metric=:metric AND id NOT IN (SELECT id FROM integration_metric_samples WHERE integration_id=:id2 AND metric=:metric2 ORDER BY sampled_at DESC LIMIT 121)');
            $prune->execute(['id'=>$integrationId,'metric'=>$metric,'id2'=>$integrationId,'metric2'=>$metric]);
        }
    }
    return integration_metric_history($db,$integrationId,$type);
}

function widget_cache_read(Database $db, string $widgetId): array
{
    $stmt=$db->pdo()->prepare('SELECT data_json,fetched_at FROM widget_data_cache WHERE widget_id=:id');
    $stmt->execute(['id'=>$widgetId]);
    $row=$stmt->fetch();
    if(!is_array($row)) return ['cached'=>false,'fetched_at'=>0,'cache_age'=>null];
    $data=json_decode((string)$row['data_json'],true);
    if(!is_array($data)) $data=[];
    $fetched=(int)$row['fetched_at'];
    return $data + ['cached'=>true,'fetched_at'=>$fetched,'cache_age'=>$fetched>0?max(0,time()-$fetched):null];
}

function widget_cache_write(Database $db, string $widgetId, array $data): array
{
    $now=time();
    $stmt=$db->pdo()->prepare('INSERT INTO widget_data_cache(widget_id,data_json,fetched_at) VALUES(:id,:data,:fetched) ON CONFLICT(widget_id) DO UPDATE SET data_json=excluded.data_json,fetched_at=excluded.fetched_at');
    $stmt->execute(['id'=>$widgetId,'data'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'fetched'=>$now]);
    return $data + ['cached'=>false,'fetched_at'=>$now,'cache_age'=>0];
}

function widget_data(Database $db, $addons, $integrations, string $id, bool $cachedOnly=false): array
{
    $widget = null;
    foreach ($db->widgets() as $item) if ($item['id'] === $id) $widget = $item;
    if (!$widget) throw new RuntimeException('Widget not found.');
    $config = $widget['config'];
    switch ($widget['type']) {
        case 'app':
            $stmt = $db->pdo()->prepare('SELECT * FROM apps WHERE id=:id');
            $stmt->execute(['id' => (string)($config['app_id'] ?? '')]);
            return ['kind'=>'app','app'=>$stmt->fetch() ?: null];
        case 'clock':
            return ['kind'=>'clock','server_time'=>gmdate(DATE_ATOM)];
        case 'note':
            return ['kind'=>'note','text'=>(string)($config['text'] ?? '')];
        case 'integration-summary':
            $integrationId = (string)($config['integration_id'] ?? '');
            if ($integrationId === '') throw new RuntimeException('No integration selected.');
            $integration = null;
            foreach ($integrations->list() as $entry) if ($entry['id'] === $integrationId) $integration = $entry;
            if (!$integration) throw new RuntimeException('Integration not found.');
            if ($cachedOnly) return ['kind'=>'integration','integration'=>$integration] + integration_cache_payload($db,$integrationId,(string)$integration['type']);
            $summary=$integrations->execute($integrationId,'summary');
            $history=persist_integration_widget_data($db,$integration,$summary);
            return ['kind'=>'integration','integration'=>$integration,'summary'=>$summary,'history'=>$history,'cached'=>false,'fetched_at'=>time(),'cache_age'=>0];
        case 'homeassistant-entities':
            $integrationId = trim((string)($config['integration_id'] ?? ''));
            if ($integrationId === '') throw new RuntimeException('No Home Assistant integration selected.');
            $integration = $integrations->full($integrationId);
            if (!$integration || (string)($integration['type'] ?? '') !== 'homeassistant') throw new RuntimeException('Home Assistant integration not found.');
            if ($cachedOnly) return widget_cache_read($db, $id);
            $entityIds = is_array($config['entity_ids'] ?? null) ? array_values(array_slice(array_unique(array_filter(array_map('strval',$config['entity_ids']))),0,8)) : [];
            if ($entityIds === []) throw new RuntimeException('No Home Assistant entities selected.');
            $mode='states:' . base64_encode(json_encode($entityIds,JSON_UNESCAPED_SLASHES));
            $result=$integrations->execute($integrationId,$mode);
            $payload=[
                'kind'=>'homeassistant',
                'integration'=>array_diff_key($integration,['secret_enc'=>true,'_secrets'=>true,'config_json'=>true]),
                'entities'=>$result['entities'] ?? [],
                'display'=>(string)($config['display'] ?? 'tiles'),
                'show_icons'=>!array_key_exists('show_icons',$config) || (bool)$config['show_icons'],
                'show_controls'=>!array_key_exists('show_controls',$config) || (bool)$config['show_controls'],
            ];
            return widget_cache_write($db,$id,$payload);
        case 'rss':
            if (!$addons->enabled('rss')) throw new RuntimeException('RSS package is not installed.');
            $url = clean_url((string)($config['feed_url'] ?? ''));
            $reader = new FeedReader();
            return ['kind'=>'rss'] + $reader->read($url, max(1,min(15,(int)($config['limit'] ?? 6))), (bool)($config['verify_tls'] ?? true));
        case 'ipmanager-summary':
            if (!$addons->enabled('ipmanager')) throw new RuntimeException('IP Manager is not installed.');
            $networks = (int)$db->pdo()->query('SELECT COUNT(*) FROM ipm_networks')->fetchColumn();
            $devices = (int)$db->pdo()->query('SELECT COUNT(*) FROM ipm_devices')->fetchColumn();
            return ['kind'=>'ipmanager','networks'=>$networks,'devices'=>$devices];
        default:
            throw new RuntimeException('Widget data provider is not available.');
    }
}

function global_search(Database $db, $addons, $integrations, $auth, string $query): array
{
    $q = trim($query);
    if ($q === '') return [];
    $needle = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $items = [];
    $stmt = $db->pdo()->prepare("SELECT id,name,url,category,description FROM apps WHERE name LIKE :q ESCAPE '\\' OR url LIKE :q ESCAPE '\\' OR category LIKE :q ESCAPE '\\' OR description LIKE :q ESCAPE '\\' LIMIT 12");
    $stmt->execute(['q'=>$needle]);
    foreach ($stmt->fetchAll() as $row) $items[] = ['type'=>'app','title'=>$row['name'],'subtitle'=>$row['category'] ?: $row['url'],'url'=>$row['url'],'id'=>$row['id']];
    foreach ($integrations->list() as $row) {
        if (!$auth->canIntegration((string)$row['id'])) continue;
        if (stripos($row['name'].' '.$row['type'].' '.$row['base_url'], $q) !== false) $items[] = ['type'=>'integration','title'=>$row['name'],'subtitle'=>$row['type'],'id'=>$row['id']];
    }
    if ($addons->enabled('ipmanager') && $auth->canIpManager()) {
        $stmt = $db->pdo()->prepare("SELECT id,name,cidr,vlan FROM ipm_networks WHERE name LIKE :q ESCAPE '\\' OR cidr LIKE :q ESCAPE '\\' OR vlan LIKE :q ESCAPE '\\' LIMIT 8");
        $stmt->execute(['q'=>$needle]);
        foreach ($stmt->fetchAll() as $row) $items[] = ['type'=>'network','title'=>$row['name'],'subtitle'=>$row['cidr'] . ($row['vlan']!==''?' · VLAN '.$row['vlan']:''),'id'=>$row['id'],'addon'=>'ipmanager'];
        $stmt = $db->pdo()->prepare("SELECT id,hostname,ip,mac FROM ipm_devices WHERE hostname LIKE :q ESCAPE '\\' OR ip LIKE :q ESCAPE '\\' OR mac LIKE :q ESCAPE '\\' LIMIT 12");
        $stmt->execute(['q'=>$needle]);
        foreach ($stmt->fetchAll() as $row) $items[] = ['type'=>'device','title'=>$row['hostname'] ?: $row['ip'],'subtitle'=>$row['ip'] . ($row['mac']!==''?' · '.$row['mac']:''),'id'=>$row['id'],'addon'=>'ipmanager'];
    }
    return array_slice($items,0,25);
}

function export_data(array $ctx): array
{
    $db = $ctx['db'];
    $integrations = [];
    foreach ($ctx['integrations']->list() as $row) {
        unset($row['has_secrets']);
        $integrations[] = $row;
    }

    $enabledAddons = [];
    foreach ($ctx['addons']->all() as $addon) {
        if (!empty($addon['enabled'])) $enabledAddons[] = (string)$addon['id'];
    }

    $addonData = [];
    if ($ctx['addons']->enabled('ipmanager') && sqlite_table_exists($db, 'ipm_networks')) {
        $networks = $db->pdo()->query('SELECT * FROM ipm_networks ORDER BY name COLLATE NOCASE')->fetchAll();
        $devices = $db->pdo()->query('SELECT * FROM ipm_devices ORDER BY network_id, ip')->fetchAll();
        $addonData['ipmanager'] = ['networks' => $networks, 'devices' => $devices];
    }

    return [
        'format'=>'pengulab-2',
        'version'=>(string)($ctx['version'] ?? 'dev'),
        'exported_at'=>gmdate(DATE_ATOM),
        'settings'=>settings_payload($db),
        'apps'=>$db->apps(),
        'widgets'=>$db->widgets(),
        'enabled_addons'=>$enabledAddons,
        'addon_data'=>$addonData,
        'integrations'=>$integrations,
        'note'=>'Credentials are intentionally excluded. Back up the complete data directory for a full restore.',
    ];
}

function import_data(array $ctx, mixed $data): void
{
    if (!is_array($data)) throw new RuntimeException('Invalid import file.');
    if (($data['format'] ?? '') !== 'pengulab-2') throw new RuntimeException('Unsupported import format.');

    $db = $ctx['db'];
    $addons = $ctx['addons'];
    $importVersion = trim((string)($data['version'] ?? ''));
    $importGridScale = ($importVersion !== '' && version_compare(preg_replace('/[^0-9.].*$/', '', $importVersion) ?: '0.0.0', '2.6.0', '>=')) ? 1 : 4;

    foreach (($data['enabled_addons'] ?? []) as $addonId) {
        $addonId = trim((string)$addonId);
        if ($addonId !== '' && $addons->manifest($addonId)) {
            $addons->install($addonId);
        }
    }
    if (is_array($data['addon_data']['ipmanager'] ?? null) && $addons->manifest('ipmanager')) {
        $addons->install('ipmanager');
    }

    $db->transaction(function($pdo) use ($data,$db,$importGridScale): void {
        if (is_array($data['settings'] ?? null)) {
            foreach (['theme','language','sidebar_compact','dashboard_title'] as $key) {
                if (array_key_exists($key, $data['settings'])) $db->setSetting($key, $data['settings'][$key]);
            }
        }

        if (is_array($data['apps'] ?? null)) {
            $pdo->exec('DELETE FROM apps');
            foreach ($data['apps'] as $app) if (is_array($app)) save_app($db,$app);
        }

        if (is_array($data['widgets'] ?? null)) {
            $pdo->exec('DELETE FROM widgets');
            foreach ($data['widgets'] as $widget) {
                if (!is_array($widget)) continue;
                $id = trim((string)($widget['id'] ?? '')) ?: Database::uuid('widget');
                $now=gmdate(DATE_ATOM);
                $type=(string)($widget['type']??'');
                $minW=in_array($type,['app','homeassistant-entities'],true)?1:2;
                $w=max($minW,min(12,(int)($widget['w']??3)));
                $rawH=(int)($widget['h']??($importGridScale===4?2:8));
                $rawY=(int)($widget['y']??0);
                $h=max(4,min(32,$rawH*$importGridScale));
                $x=max(0,min(12-$w,(int)($widget['x']??0)));
                $y=max(0,$rawY*$importGridScale);
                $title=mb_substr(trim(strip_tags((string)($widget['title']??''))),0,100);
                $config=is_array($widget['config']??null)?$widget['config']:[];
                $stmt=$pdo->prepare('INSERT INTO widgets(id,dashboard_id,type,title,x,y,w,h,config_json,created_at,updated_at) VALUES(:id,:dashboard,:type,:title,:x,:y,:w,:h,:config,:created,:updated)');
                $stmt->execute(['id'=>$id,'dashboard'=>$db->defaultDashboardId(),'type'=>(string)($widget['type']??'note'),'title'=>$title,'x'=>$x,'y'=>$y,'w'=>$w,'h'=>$h,'config'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created'=>$now,'updated'=>$now]);
            }
        }

        if (is_array($data['integrations'] ?? null)) {
            $pdo->exec('DELETE FROM integrations');
            $stmt=$pdo->prepare('INSERT INTO integrations(id,type,name,base_url,username,secret_enc,verify_tls,config_json,enabled,last_status,last_error,last_checked_at,created_at,updated_at) VALUES(:id,:type,:name,:base_url,:username,\'\',:verify_tls,:config_json,1,\'unknown\',\'Credentials must be re-entered after portable import.\',NULL,:created,:updated)');
            foreach ($data['integrations'] as $integration) {
                if (!is_array($integration)) continue;
                $id=trim((string)($integration['id']??'')) ?: Database::uuid('integration');
                $type=mb_substr(trim((string)($integration['type']??'')),0,80);
                $name=mb_substr(trim(strip_tags((string)($integration['name']??''))),0,100);
                if ($type==='' || $name==='') continue;
                $baseUrl=trim((string)($integration['base_url']??''));
                if ($baseUrl!=='' && !preg_match('~^https?://~i',$baseUrl)) continue;
                $username=mb_substr(trim((string)($integration['username']??'')),0,180);
                $config=is_array($integration['config']??null)?$integration['config']:[];
                $now=gmdate(DATE_ATOM);
                $stmt->execute(['id'=>$id,'type'=>$type,'name'=>$name,'base_url'=>$baseUrl,'username'=>$username,'verify_tls'=>!empty($integration['verify_tls'])?1:0,'config_json'=>json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'created'=>$now,'updated'=>$now]);
            }
        }

        $ipm = $data['addon_data']['ipmanager'] ?? null;
        if (is_array($ipm) && sqlite_table_exists($db, 'ipm_networks')) {
            $pdo->exec('DELETE FROM ipm_devices');
            $pdo->exec('DELETE FROM ipm_networks');
            $netStmt=$pdo->prepare('INSERT INTO ipm_networks(id,name,cidr,vlan,gateway,dhcp_start,dhcp_end,dns_json,description,source,created_at,updated_at) VALUES(:id,:name,:cidr,:vlan,:gateway,:dhcp_start,:dhcp_end,:dns_json,:description,:source,:created,:updated)');
            foreach (($ipm['networks'] ?? []) as $network) {
                if (!is_array($network)) continue;
                $id=trim((string)($network['id']??'')) ?: Database::uuid('net');
                $cidr=trim((string)($network['cidr']??''));
                if ($cidr==='' || trim((string)($network['name']??''))==='') continue;
                $now=gmdate(DATE_ATOM);
                $netStmt->execute([
                    'id'=>$id,'name'=>mb_substr(trim(strip_tags((string)$network['name'])),0,100),'cidr'=>$cidr,
                    'vlan'=>mb_substr((string)($network['vlan']??''),0,20),'gateway'=>(string)($network['gateway']??''),
                    'dhcp_start'=>(string)($network['dhcp_start']??''),'dhcp_end'=>(string)($network['dhcp_end']??''),
                    'dns_json'=>is_string($network['dns_json']??null)?$network['dns_json']:json_encode($network['dns']??[],JSON_UNESCAPED_SLASHES),
                    'description'=>mb_substr(trim(strip_tags((string)($network['description']??''))),0,500),'source'=>(string)($network['source']??'import'),
                    'created'=>(string)($network['created_at']??$now),'updated'=>$now,
                ]);
            }
            $devStmt=$pdo->prepare('INSERT INTO ipm_devices(id,network_id,hostname,ip,mac,type,description,source,created_at,updated_at) VALUES(:id,:network_id,:hostname,:ip,:mac,:type,:description,:source,:created,:updated)');
            foreach (($ipm['devices'] ?? []) as $device) {
                if (!is_array($device)) continue;
                $networkId=trim((string)($device['network_id']??''));
                $ip=trim((string)($device['ip']??''));
                if ($networkId==='' || !filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) continue;
                $now=gmdate(DATE_ATOM);
                try {
                    $devStmt->execute([
                        'id'=>trim((string)($device['id']??'')) ?: Database::uuid('device'),'network_id'=>$networkId,
                        'hostname'=>mb_substr(trim(strip_tags((string)($device['hostname']??''))),0,120),'ip'=>$ip,
                        'mac'=>mb_substr(trim((string)($device['mac']??'')),0,32),'type'=>(string)($device['type']??'static'),
                        'description'=>mb_substr(trim(strip_tags((string)($device['description']??''))),0,500),'source'=>(string)($device['source']??'import'),
                        'created'=>(string)($device['created_at']??$now),'updated'=>$now,
                    ]);
                } catch (\PDOException) {
                    // Ignore orphaned/duplicate imported device records instead of aborting the complete import.
                }
            }
        }
    });
}

function sqlite_table_exists(Database $db, string $table): bool
{
    $stmt=$db->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
    $stmt->execute(['name'=>$table]);
    return $stmt->fetchColumn() !== false;
}

