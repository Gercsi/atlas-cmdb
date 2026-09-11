<?php

// Included by api.php after authentication. Shares the authenticated request context.
if (($parts[0] ?? '') === 'admin' && ($parts[1] ?? '') === 'sso') {
    if ($a->user['role'] !== 'admin') {
        throw new \Cmdb\ApiError(403, 'Adminisztrátori jogosultság szükséges.');
    }
    $sso = new \Cmdb\Sso($a);
    if ($path === 'admin/sso' && $method === 'GET') {
        $reply($sso->adminSettings($ssoCallbackUrl));
        exit;
    }
    if ($path === 'admin/sso' && $method === 'PUT') {
        $reply($sso->save($body, $ssoCallbackUrl));
        exit;
    }
    if ($path === 'admin/sso/test' && $method === 'POST') {
        $reply($sso->testConfiguration());
        exit;
    }
    throw new \Cmdb\ApiError(405, 'Nem támogatott SSO adminisztrációs művelet.');
}
if ($path === 'search' && $method === 'GET') {
    $query = trim($_GET['q'] ?? '');
    $rows = [];
    if (mb_strlen($query) >= 2) {
        foreach (\Cmdb\Schema::TYPES as $type) {
            $list = $a->listing($type, ['q' => $query,'per_page' => 25]);
            foreach (array_slice($list['data'], 0, 5) as $r) {
                $rows[] = ['id' => $r['id'],'entity_type' => $type,'public_id' => $r['public_id'],'name' => $r['name'] ?? $r['interface_type'] ?? 'Név nélkül'];
            }
        }
    }$reply(['data' => $rows]);
    exit;
}
if (($parts[0] ?? '') === 'integrations' && ($parts[2] ?? '') === 'network-connections' && in_array($method, ['POST','DELETE'])) {
    $a->need('edit');
    $id = $parts[1];
    $a->get('integrations', $id);
    $cid = $body['connection_id'] ?? $parts[3] ?? '';
    $a->get('network_connections', $cid);
    $a->tx(function () use ($a, $method, $id, $cid, $body) {
        if ($method === 'POST') {
            $a->run('INSERT INTO integration_connections VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE mapping_notes=VALUES(mapping_notes),verified_by=VALUES(verified_by),verified_at=VALUES(verified_at)', [$id,$cid,$body['mapping_notes'] ?? null,$a->user['id']]);
        } else {
            $a->run('DELETE FROM integration_connections WHERE integration_id=? AND connection_id=?', [$id,$cid]);
        }$a->audit('network_mapping', 'integrations', $id, [], ['connection_id' => $cid,'operation' => $method]);
    });
    $reply(['success' => true]);
    exit;
}
if (($parts[0] ?? '') === 'admin' && ($parts[1] ?? '') === 'boundaries') {
    if ($a->user['role'] !== 'admin') {
        throw new \Cmdb\ApiError(403, 'Adminisztrátori jogosultság szükséges.');
    }if ($method === 'GET') {
        $reply(['data' => $a->all('SELECT * FROM network_boundaries')]);
        exit;
    }
    if ($method === 'POST') {
        if (empty($body['name']) || empty($body['zone_a_id']) || empty($body['zone_b_id']) || $body['zone_a_id'] === $body['zone_b_id'] || !in_array($body['boundary_type'] ?? '', ['firewall','trust','other'])) {
            throw new \Cmdb\ApiError(422, 'Név, két különböző zóna és határtípus szükséges.');
        }$a->tx(function () use ($a, $body) {
            $id = \Cmdb\App::id();
            $a->run('INSERT INTO network_boundaries VALUES (?,?,?,?,?,?)', [$id,$body['name'],$body['zone_a_id'],$body['zone_b_id'],$body['boundary_type'],$body['notes'] ?? null]);
            $a->audit('boundary', 'network_boundaries', $id, [], $body);
        });
        $reply(['success' => true]);
        exit;
    }
}
