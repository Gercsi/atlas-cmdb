<?php

namespace Cmdb;

use PDO;
use Symfony\Component\Uid\Ulid;

final class App
{
    public PDO $db;
    public ?array $user = null;
    public array $config;
    public function __construct()
    {
        $this->config = Config::read() ?? throw new ApiError(503, 'Az adatbázis még nincs telepítve. Nyisd meg az alkalmazást a telepítéshez.');
        $this->db = new PDO($this->config['dsn'], $this->config['db_user'], $this->config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES => false]);
        $this->db->exec("SET time_zone='+00:00'");
    }
    public static function id(): string
    {
        return (string)new Ulid();
    }
    public function all(string $sql, array $params = []): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }
    public function one(string $sql, array $params = []): ?array
    {
        return $this->all($sql, $params)[0] ?? null;
    }
    public function run(string $sql, array $params = []): void
    {
        $this->db->prepare($sql)->execute($params);
    }
    public function tx(callable $f): mixed
    {
        $this->db->beginTransaction();
        try {
            $r = $f();
            $this->db->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    public function revision(): int
    {
        return (int)$this->db->query('SELECT value FROM revision WHERE id=1')->fetchColumn();
    }
    public function audit(string $action, string $type, ?string $id, array $before = [], array $after = []): void
    {
        $diff = [];
        foreach (array_unique([...array_keys($before),...array_keys($after)]) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $diff[$key] = ['before' => $before[$key] ?? null,'after' => $after[$key] ?? null];
            }
        }$this->run('INSERT INTO audit_log VALUES (?,?,UTC_TIMESTAMP(6),?,?,?,?)', [self::id(),$this->user['id'] ?? null,$action,$type,$id,json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $this->run('UPDATE revision SET value=value+1 WHERE id=1');
    }
    public function can(string $cap): bool
    {
        if (!$this->user) {
            return false;
        }if ($this->user['role'] === 'admin') {
            return true;
        }if ($cap === 'edit') {
            return $this->user['role'] === 'editor';
        }return in_array($cap, json_decode($this->user['capabilities'], true));
    }
    public function need(string $cap): void
    {
        if (!$this->can($cap)) {
            throw new ApiError(403, 'Ehhez a művelethez nincs jogosultságod.');
        }
    }
    public function type(string $type): string
    {
        if (!in_array($type, Schema::TYPES)) {
            throw new ApiError(404, 'Ismeretlen nyilvántartás.');
        }return $type;
    }
    public function clean(array $r, string $type): array
    {
        unset($r['raw_source']);
        foreach (['quality_warnings'] as $f) {
            if (isset($r[$f]) && is_string($r[$f])) {
                $r[$f] = json_decode($r[$f], true);
            }
        }if (!$this->can('view_contact_details')) {
            foreach (['email','phone','legacy_owner_text','legacy_business_owner_text','legacy_technical_owner_text'] as $f) {
                unset($r[$f]);
            }if ($type === 'contacts') {
                foreach (['notes','job_title'] as $f) {
                    unset($r[$f]);
                }
            }
        }return $r;
    }
    public function get(string $type, string $id, bool $raw = false): array
    {
        $this->type($type);
        $r = $this->one("SELECT * FROM `$type` WHERE id=?", [$id]);
        if (!$r) {
            throw new ApiError(404, 'A rekord nem található.');
        }
        if ($raw) {
            return $r;
        }
        return $this->decorateReferences($type, [$this->clean($r, $type)])[0];
    }
    private function listFields(string $type): array
    {
        return ['public_id',...array_keys(Schema::fields()[$type]),'data_quality_status'];
    }
    private function referenceTarget(string $type, string $field): ?string
    {
        $kind = Schema::fields()[$type][$field] ?? '';
        return str_starts_with($kind, 'ref:') ? substr($kind, 4) : null;
    }
    private function columnFilters(string $type, array $p): array
    {
        $raw = $p['column_filters'] ?? [];
        if (is_string($raw)) {
            if (strlen($raw) > 50000) {
                throw new ApiError(422, 'Túl sok oszlopszűrő érkezett.');
            }
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $allowed = array_fill_keys($this->listFields($type), true);
        $filters = [];
        foreach ($raw as $field => $filter) {
            if (!is_string($field) || !isset($allowed[$field]) || !is_array($filter)) {
                continue;
            }
            $text = is_scalar($filter['text'] ?? null) ? trim((string)$filter['text']) : '';
            $values = is_array($filter['values'] ?? null) ? $filter['values'] : [];
            $values = array_slice(array_values(array_unique(array_map(
                fn ($value) => is_scalar($value) ? mb_substr((string)$value, 0, 255) : '',
                $values
            ))), 0, 100);
            $values = array_values(array_filter($values, fn ($value) => $value !== ''));
            if ($text !== '' || $values) {
                $filters[$field] = ['text' => mb_substr($text, 0, 255),'values' => $values];
            }
        }
        return $filters;
    }
    private function decorateReferences(string $type, array $rows): array
    {
        if (!$rows) {
            return $rows;
        }
        $requests = [];
        foreach (Schema::fields()[$type] as $field => $kind) {
            if (!str_starts_with($kind, 'ref:')) {
                continue;
            }
            $target = substr($kind, 4);
            foreach ($rows as $row) {
                if (!empty($row[$field])) {
                    $requests[$target][$row[$field]] = true;
                }
            }
        }
        $labels = [];
        foreach ($requests as $target => $ids) {
            $idList = array_keys($ids);
            $public = in_array($target, Schema::TYPES, true);
            $select = $public ? 'id,name,public_id' : 'id,name';
            $found = $this->all("SELECT $select FROM `$target` WHERE id IN (".implode(',', array_fill(0, count($idList), '?')).')', $idList);
            foreach ($found as $record) {
                $labels[$target][$record['id']] = trim((string)($record['name'] ?? '')) ?: ($record['public_id'] ?? $record['id']);
            }
        }
        foreach ($rows as &$row) {
            foreach (Schema::fields()[$type] as $field => $kind) {
                if (!str_starts_with($kind, 'ref:') || empty($row[$field])) {
                    continue;
                }
                $target = substr($kind, 4);
                $row[$field.'_display'] = $labels[$target][$row[$field]] ?? $row[$field];
            }
        }
        unset($row);
        return $rows;
    }
    public function query(string $type, array $p): array
    {
        $this->type($type);
        $where = ['1=1'];
        $args = [];
        if (empty($p['archived'])) {
            $where[] = 'archived_at IS NULL';
        }if (!empty($p['q'])) {
            $fields = array_intersect(['public_id','name','dns_name','hostname'], ['public_id',...array_keys(Schema::fields()[$type])]);
            $parts = [];
            foreach ($fields as $f) {
                $parts[] = "`$f` LIKE ?";
                $args[] = '%'.$p['q'].'%';
            }if ($type === 'servers') {
                $parts[] = 'id IN (SELECT server_id FROM server_addresses WHERE address LIKE ?)';
                $args[] = '%'.$p['q'].'%';
            }$where[] = '('.implode(' OR ', $parts).')';
        }foreach (['environment','criticality','status','data_quality_status'] as $f) {
            if (isset($p[$f]) && $p[$f] !== '' && ($f === 'data_quality_status' || isset(Schema::fields()[$type][$f]))) {
                $where[] = "`$f`=?";
                $args[] = $p[$f];
            }
        }if (!empty($p['ids'])) {
            $ids = is_array($p['ids']) ? $p['ids'] : explode(',', $p['ids']);
            $where[] = 'id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            array_push($args, ...$ids);
        }
        foreach ($this->columnFilters($type, $p) as $field => $filter) {
            $column = "`$field`";
            if ($filter['text'] !== '') {
                $pattern = '%'.$filter['text'].'%';
                $target = $this->referenceTarget($type, $field);
                $kind = Schema::fields()[$type][$field] ?? '';
                if ($target) {
                    $lookup = '`name` LIKE ?';
                    $lookupArgs = [$pattern];
                    if (in_array($target, Schema::TYPES, true)) {
                        $lookup .= ' OR `public_id` LIKE ?';
                        $lookupArgs[] = $pattern;
                    }
                    $where[] = "($column LIKE ? OR $column IN (SELECT id FROM `$target` WHERE $lookup))";
                    $args[] = $pattern;
                    array_push($args, ...$lookupArgs);
                } elseif (str_starts_with($kind, 'enum:')) {
                    $where[] = "($column LIKE ? OR $column IN (SELECT code FROM reference_data WHERE category=? AND label LIKE ?))";
                    array_push($args, $pattern, substr($kind, 5), $pattern);
                } else {
                    $where[] = "$column LIKE ?";
                    $args[] = $pattern;
                }
            }
            if ($filter['values']) {
                $includeNull = in_array('__EMPTY__', $filter['values'], true);
                $values = array_values(array_filter($filter['values'], fn ($value) => $value !== '__EMPTY__'));
                $choices = [];
                if ($values) {
                    $choices[] = $column.' IN ('.implode(',', array_fill(0, count($values), '?')).')';
                    array_push($args, ...$values);
                }
                if ($includeNull) {
                    $choices[] = "$column IS NULL";
                }
                if ($choices) {
                    $where[] = '('.implode(' OR ', $choices).')';
                }
            }
        }
        return [implode(' AND ', $where),$args];
    }
    public function listing(string $type, array $p): array
    {
        [$w,$a] = $this->query($type, $p);
        $n = (int)($this->one("SELECT COUNT(*) n FROM `$type` WHERE $w", $a)['n']);
        $page = max(1, (int)($p['page'] ?? 1));
        $per = in_array((int)($p['per_page'] ?? 25), [25,50,100]) ? (int)($p['per_page'] ?? 25) : 25;
        $sort = in_array($p['sort'] ?? '', $this->listFields($type), true) ? $p['sort'] : 'public_id';
        $dir = ($p['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $offset = ($page - 1) * $per;
        $target = $this->referenceTarget($type, $sort);
        $order = $target ? "COALESCE((SELECT NULLIF(name,'') FROM `$target` WHERE `$target`.id=`$type`.`$sort`),`$sort`)" : "`$sort`";
        $rows = array_map(fn ($r) => $this->clean($r, $type), $this->all("SELECT * FROM `$type` WHERE $w ORDER BY $order $dir,id LIMIT $per OFFSET $offset", $a));
        $facets = [];
        $truncated = [];
        $facetFields = is_array($p['facet_fields'] ?? null) ? $p['facet_fields'] : explode(',', (string)($p['facet_fields'] ?? ''));
        $facetFields = array_slice(array_values(array_intersect($this->listFields($type), $facetFields)), 0, 12);
        foreach ($facetFields as $field) {
            $kind = Schema::fields()[$type][$field] ?? '';
            if ($kind === 'long') {
                $facets[$field] = [];
                $truncated[$field] = false;
                continue;
            }
            $withoutCurrent = $this->columnFilters($type, $p);
            unset($withoutCurrent[$field]);
            $facetParams = [...$p,'column_filters' => $withoutCurrent];
            [$facetWhere,$facetArgs] = $this->query($type, $facetParams);
            $values = $this->all("SELECT `$field` value,COUNT(*) count FROM `$type` WHERE $facetWhere GROUP BY `$field` ORDER BY count DESC LIMIT 101", $facetArgs);
            $truncated[$field] = count($values) > 100;
            $values = array_slice($values, 0, 100);
            $target = $this->referenceTarget($type, $field);
            if ($target) {
                $fake = array_map(fn ($value) => [$field => $value['value']], $values);
                $fake = $this->decorateReferences($type, $fake);
                foreach ($values as $index => &$value) {
                    $value['label'] = $fake[$index][$field.'_display'] ?? $value['value'];
                }
                unset($value);
            } elseif (str_starts_with($kind, 'enum:')) {
                $dictionary = array_column($this->all('SELECT label,code FROM reference_data WHERE category=?', [substr($kind, 5)]), 'label', 'code');
                foreach ($values as &$value) {
                    $value['label'] = $dictionary[$value['value']] ?? $value['value'];
                }
                unset($value);
            }
            foreach ($values as &$value) {
                $value['value'] = $value['value'] === null ? '__EMPTY__' : (string)$value['value'];
                $value['label'] = trim((string)($value['label'] ?? '')) ?: 'Nincs megadva';
                $value['count'] = (int)$value['count'];
            }
            unset($value);
            usort($values, fn ($left, $right) => strnatcasecmp($left['label'], $right['label']));
            $facets[$field] = $values;
        }
        return ['data' => $this->decorateReferences($type, $rows),'meta' => ['total' => $n,'page' => $page,'per_page' => $per,'facets' => $facets,'facet_truncated' => $truncated],'links' => ['next' => $offset + $per < $n ? $page + 1 : null]];
    }
    public function validate(string $type, array $data, bool $legacy = false): array
    {
        $out = [];
        $errors = [];
        foreach (Schema::fields()[$type] as $f => $kind) {
            if (!array_key_exists($f, $data)) {
                continue;
            }$v = $data[$f];
            if ($v === '') {
                $v = null;
            }if ($v !== null) {
                if (is_array($v) || is_object($v)) {
                    $errors[$f] = 'Egyszerű érték szükséges.';
                    continue;
                }if ($kind === 'int' && (!is_numeric($v) || floor((float)$v) != (float)$v || (float)$v < 0)) {
                    $errors[$f] = 'Nem negatív egész szám szükséges.';
                }if ($f === 'cpu' && (float)$v <= 0) {
                    $errors[$f] = 'Pozitív egész szám szükséges.';
                }if ($kind === 'decimal' && (!is_numeric($v) || (float)$v < 0)) {
                    $errors[$f] = 'Nem negatív szám szükséges.';
                }if ($kind === 'bool') {
                    if (!in_array($v, [true,false,0,1,'0','1'], true)) {
                        $errors[$f] = 'Igen, nem vagy ismeretlen.';
                    } else {
                        $v = (int)$v;
                    }
                }if ($kind === 'date') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) || date('Y-m-d', strtotime($v)) !== $v) {
                        $errors[$f] = 'Érvényes ISO dátum szükséges.';
                    }
                }if (str_starts_with($kind, 'select:') && !in_array($v, explode(',', substr($kind, 7)))) {
                    $errors[$f] = 'Ismeretlen érték.';
                }if (str_starts_with($kind, 'enum:') && !$this->one('SELECT id FROM reference_data WHERE category=? AND code=? AND active=1', [substr($kind, 5),$v])) {
                    $errors[$f] = 'Ismeretlen szótárérték.';
                }if (str_starts_with($kind, 'ref:') && !$this->one('SELECT id FROM `'.substr($kind, 4).'` WHERE id=?', [$v])) {
                    $errors[$f] = 'A hivatkozás nem található.';
                }if (in_array($kind, ['text','long']) && mb_strlen((string)$v) > ($kind === 'long' ? 20000 : 255)) {
                    $errors[$f] = 'Túl hosszú szöveg.';
                }
            }$out[$f] = $v;
        }
        if (!$legacy) {
            $required = match($type) {
                'applications','servers' => ['name','environment'],'databases' => ['name','engine'],'integrations' => ['source_application_id','target_application_id'],'network_connections' => ['name','connection_type','action','status','transport_protocol'],default => ['name']
            };
            foreach ($required as $f) {
                if (empty($out[$f]) || $out[$f] === '-') {
                    $errors[$f] = 'Kötelező mező.';
                }
            }if (($out['environment'] ?? '') === 'UNKNOWN') {
                $errors['environment'] = 'Új rekordhoz valódi környezet szükséges.';
            }
        }
        if ($type === 'contacts' && !empty($out['email']) && !filter_var($out['email'], FILTER_VALIDATE_EMAIL) && !$legacy) {
            $errors['email'] = 'Érvényes email cím szükséges.';
        }
        if ($type === 'integrations' && !empty($out['source_application_id']) && $out['source_application_id'] === ($out['target_application_id'] ?? null)) {
            $errors['target_application_id'] = 'A forrás és cél nem lehet azonos.';
        }
        if ($errors) {
            throw new ApiError(422, 'Ellenőrizd a megjelölt mezőket.', $errors);
        }return $out;
    }
    public function save(string $type, array $input, ?string $id = null, bool $legacy = false): array
    {
        $this->type($type);
        return $this->tx(function () use ($type, $input, $id, $legacy) {
            return $this->saveInside($type, $input, $id, $legacy);
        });
    }
    public function saveInside(string $type, array $input, ?string $id = null, bool $legacy = false): array
    {
        $before = $id ? $this->get($type, $id, true) : [];
        if ($id && !$legacy && (!isset($input['lock_version']) || (int)$input['lock_version'] !== (int)$before['lock_version'])) {
            throw new ApiError(409, 'A rekord időközben módosult. Nyisd meg újra az összehasonlításhoz.');
        }
        $data = $this->validate($type, array_merge($before, $input), $legacy);
        if ($type === 'databases' && !empty($data['application_id'])) {
            $taken = $this->one('SELECT id FROM `databases` WHERE application_id=? AND id<>?', [$data['application_id'],$id ?? '']);
            if ($taken) {
                throw new ApiError(422, 'Az alkalmazáshoz már tartozik adatbázis.', ['application_id' => 'Egy alkalmazáshoz legfeljebb egy adatbázis rendelhető.']);
            }
        }
        if ($type === 'network_connections') {
            $this->validateNetwork($data, $input, $id);
        }
        if (!$id) {
            $id = self::id();
            $seq = $this->one('SELECT next_value FROM sequences WHERE entity=? FOR UPDATE', [$type]);
            $n = (int)$seq['next_value'];
            if (!$legacy) {
                $this->run('UPDATE sequences SET next_value=next_value+1 WHERE entity=?', [$type]);
            }$prefix = Schema::PREFIX[$type];
            if (in_array($type, ['applications','servers'])) {
                $prefix .= '-'.($data['environment'] ?? 'UNKNOWN');
            }if ($type === 'databases') {
                $prefix .= '-'.(preg_replace('/[^A-Z0-9]+/', '-', strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT', $data['name'] ?? 'DB'))) ?: 'DB');
            }$public = $legacy && isset($input['public_id']) ? $input['public_id'] : $prefix.'-'.str_pad((string)$n, 5, '0', STR_PAD_LEFT);
            if ($legacy && preg_match('/-(\d+)$/', $public, $m)) {
                $this->run('UPDATE sequences SET next_value=GREATEST(next_value,?) WHERE entity=?', [(int)$m[1] + 1,$type]);
            }$this->run("INSERT INTO `$type`(id,public_id,created_at,updated_at,created_by,updated_by) VALUES (?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,?)", [$id,$public,$this->user['id'] ?? null,$this->user['id'] ?? null]);
        }
        if ($legacy) {
            foreach (['data_quality_status','quality_warnings','raw_source'] as $k) {
                if (isset($input[$k])) {
                    $data[$k] = is_array($input[$k]) ? json_encode($input[$k], JSON_UNESCAPED_UNICODE) : $input[$k];
                }
            }
        }
        $set = [];
        $args = [];
        foreach ($data as $k => $v) {
            $set[] = "`$k`=?";
            $args[] = $v;
        }$set[] = 'updated_at=UTC_TIMESTAMP(6)';
        $set[] = 'updated_by=?';
        $args[] = $this->user['id'] ?? null;
        if ($before) {
            $set[] = 'lock_version=lock_version+1';
        }$args[] = $id;
        $sql = "UPDATE `$type` SET ".implode(',', $set).' WHERE id=?';
        if ($before && !$legacy) {
            $sql .= ' AND lock_version=?';
            $args[] = $input['lock_version'];
        }$st = $this->db->prepare($sql);
        $st->execute($args);
        if ($before && !$legacy && $st->rowCount() !== 1) {
            throw new ApiError(409, 'Párhuzamos módosítás történt.');
        }
        $this->children($type, $id, $input);
        $after = $this->get($type, $id, true);
        $this->audit($before ? 'update' : 'create', $type, $id, $before, $after);
        return $this->clean($after, $type);
    }
    public function validateNetwork(array $d, array $in, ?string $id): void
    {
        $ep = $in['endpoints'] ?? ($id ? $this->all('SELECT * FROM connection_endpoints WHERE connection_id=?', [$id]) : []);
        $sv = $in['services'] ?? ($id ? $this->all('SELECT * FROM connection_services WHERE connection_id=?', [$id]) : []);
        $sides = [];
        foreach ($ep as $e) {
            if (!in_array($e['side'] ?? '', ['source','target'])) {
                throw new ApiError(422, 'Hibás végpontirány.');
            }$sides[] = $e['side'];
            $kind = $e['endpoint_kind'] ?? '';
            $v = $e['value'] ?? '';
            $valid = match($kind) {
                'server' => !empty($e['server_id']),'server_address' => !empty($e['server_address_id']),'zone' => !empty($e['zone_id']),'ip' => (bool)filter_var($v, FILTER_VALIDATE_IP),'cidr' => $this->cidr($v),'fqdn' => (bool)filter_var($v, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME),'any' => true,default => false
            };
            if (!$valid) {
                throw new ApiError(422, 'Hibás hálózati végpont.');
            }
        }
        foreach ($sv as $s) {
            if (!in_array($s['side'] ?? '', ['source','destination'])) {
                throw new ApiError(422, 'Hibás portirány.');
            }if (($d['transport_protocol'] ?? '') === 'icmp' && (!empty($s['port_from']) || !empty($s['port_to']))) {
                throw new ApiError(422, 'ICMP esetén port nem adható meg.');
            }if (in_array($d['transport_protocol'] ?? '', ['tcp','udp']) && ((int)($s['port_from'] ?? 0) < 1 || (int)($s['port_to'] ?? 0) > 65535 || (int)($s['port_to'] ?? 0) < (int)($s['port_from'] ?? 0))) {
                throw new ApiError(422, 'Porttartomány: 1–65535, növekvő sorrendben.');
            }
        }
        if (($d['status'] ?? '') === 'active') {
            if (!in_array('source', $sides) || !in_array('target', $sides)) {
                throw new ApiError(422, 'Aktív szabályhoz forrás- és célvégpont szükséges.');
            }foreach (['source','destination'] as $side) {
                if (($d[$side.'_ports_mode'] ?? 'unknown') === 'specified' && !array_filter($sv, fn ($s) => $s['side'] === $side)) {
                    throw new ApiError(422, 'A megadott portmódhoz portlista szükséges.');
                }
            }
        }
    }
    private function cidr(string $v): bool
    {
        $p = explode('/', $v);
        return count($p) === 2 && filter_var($p[0], FILTER_VALIDATE_IP) && ctype_digit($p[1]) && (int)$p[1] <= (str_contains($p[0], ':') ? 128 : 32);
    }
    public function children(string $type, string $id, array $in): void
    {
        $maps = $type === 'servers' ? ['addresses' => ['server_addresses','server_id',['address','zone_id','is_primary']]] : ($type === 'network_connections' ? ['endpoints' => ['connection_endpoints','connection_id',['side','endpoint_kind','server_id','server_address_id','zone_id','value']],'services' => ['connection_services','connection_id',['side','port_from','port_to','icmp_type','icmp_code']]] : []);
        foreach ($maps as $key => [$table,$fk,$fields]) {
            if (array_key_exists($key, $in)) {
                if (!is_array($in[$key]) || count($in[$key]) > 100) {
                    throw new ApiError(422, 'Legfeljebb 100 kapcsolati sor engedélyezett.');
                }$this->run("DELETE FROM `$table` WHERE `$fk`=?", [$id]);
                $primary = 0;
                foreach ($in[$key] as $r) {
                    if ($key === 'addresses') {
                        if (!filter_var($r['address'] ?? '', FILTER_VALIDATE_IP)) {
                            throw new ApiError(422, 'Érvénytelen IP-cím.');
                        }$r['is_primary']=(int)($r['is_primary']??0);if (!empty($r['is_primary']) && ++$primary > 1) {
                            throw new ApiError(422, 'Egy elsődleges cím lehet.');
                        }
                    }$values = [self::id(),$id];
                    foreach ($fields as $f) {
                        $values[] = $r[$f] ?? null;
                    }$this->run("INSERT INTO `$table` (id,`$fk`,".implode(',', $fields).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')', $values);
                }$this->audit('relations', $type, $id, [], [$key => $in[$key]]);
            }
        }
    }
    public function relationships(string $type, string $id): array
    {
        $this->get($type, $id);
        $out = [];
        foreach (Schema::fields()[$type] as $f => $kind) {
            if (str_starts_with($kind, 'ref:')) {
                $r = $this->get($type, $id);
                if (!empty($r[$f])) {
                    $t = substr($kind, 4);
                    $out[$f] = $t === 'network_zones' ? $this->one('SELECT * FROM network_zones WHERE id=?', [$r[$f]]) : $this->get($t, $r[$f]);
                }
            }
        }
        foreach (['applications' => ['server_id' => 'servers'],'databases' => ['server_id' => 'servers','application_id' => 'applications'],'integrations' => ['source_application_id' => 'applications','target_application_id' => 'applications','data_owner_application_id' => 'applications']] as $t => $refs) {
            $f = array_keys(array_filter($refs, fn ($v) => $v === $type));
            if ($f) {
                $rows = $this->all("SELECT * FROM `$t` WHERE ".implode(' OR ', array_map(fn ($k) => "`$k`=?", $f)), array_fill(0, count($f), $id));
                $out[$t] = array_map(fn ($r) => $this->clean($r, $t), $rows);
            }
        }
        if (in_array($type, ['applications','servers','databases'])) {
            $out['contacts'] = array_map(fn ($r) => $this->clean($r, 'contacts'), $this->all("SELECT c.*,a.id assignment_id,a.role_code,a.role_description,a.is_primary FROM {$type}_contacts a JOIN contacts c ON c.id=a.contact_id WHERE a.target_id=?", [$id]));
        }
        if ($type === 'contacts') {
            foreach (['applications','servers','databases'] as $t) {
                $out[$t] = $this->all("SELECT t.id,t.public_id,t.name,a.role_code,a.role_description,a.id assignment_id FROM {$t}_contacts a JOIN `$t` t ON t.id=a.target_id WHERE a.contact_id=?", [$id]);
            }
        }
        if ($type === 'servers') {
            $out['addresses'] = $this->all('SELECT * FROM server_addresses WHERE server_id=?',[$id]);
        }if ($type === 'network_connections') {
            $out['endpoints'] = $this->all('SELECT * FROM connection_endpoints WHERE connection_id=?',[$id]);
            $out['services'] = $this->all('SELECT * FROM connection_services WHERE connection_id=?',[$id]);
        }
        if ($type === 'integrations') {
            $out['network_connections'] = $this->all('SELECT n.*,m.mapping_notes FROM integration_connections m JOIN network_connections n ON n.id=m.connection_id WHERE m.integration_id=?',[$id]);
        }if ($type === 'servers') {
            $out['network_connections'] = $this->all('SELECT DISTINCT n.* FROM network_connections n JOIN connection_endpoints e ON e.connection_id=n.id WHERE e.server_id=?',[$id]);
        }if ($type === 'network_connections') {
            $out['integrations'] = $this->all('SELECT i.* FROM integrations i JOIN integration_connections m ON m.integration_id=i.id WHERE m.connection_id=?',[$id]);
        }$out['audit'] = $this->can('view_contact_details') ? $this->all('SELECT id,action,created_at,diff FROM audit_log WHERE entity=? AND entity_id=? ORDER BY created_at DESC LIMIT 30',[$type,$id]) : [];
        return $out;
    }
}
