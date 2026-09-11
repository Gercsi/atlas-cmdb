<?php

namespace Cmdb;

final class OwnImporter
{
    public function __construct(private App $app)
    {
    }
    public function preview(\PhpOffice\PhpSpreadsheet\Spreadsheet $wb, string $path): array
    {
        $manifest = json_decode((string)$wb->getSheetByName('_Manifest')->getCell('A2')->getValue(), true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 1 || array_diff(Schema::TYPES, $manifest['types'] ?? []) || ($manifest['scope'] ?? '') !== 'all') {
            throw new ApiError(422, 'A saját visszaimporthoz teljes, 1-es sémájú Atlas-export szükséges.');
        }
        foreach (Schema::TYPES as $t) {
            if ($this->app->one("SELECT COUNT(*) n FROM `$t`")['n']) {
                throw new ApiError(422, 'A saját export visszaállítása üres üzleti adatbázisba engedélyezett; meglévő rekordokat nem ír felül.');
            }
        }
        $allowed = [...Schema::TYPES,'ApplicationContacts','ServerContacts','DatabaseContacts','ApplicationBusinessAreas','ConnectionEndpoints','ConnectionServices','IntegrationConnections','Addresses','Zones','Boundaries','ReferenceData'];
        $tables = [];
        $rows = [];
        $counts = [];
        foreach ($allowed as $name) {
            $sh = $wb->getSheetByName($name);
            if (!$sh) {
                continue;
            }if ($sh->getHighestDataRow() > 20001 || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sh->getHighestDataColumn()) > 100) {
                throw new ApiError(422, 'Túl nagy visszaimport.');
            }$arr = $sh->toArray(null, false, false);
            $headers = array_shift($arr);
            $table = [];
            foreach ($arr as $i => $values) {
                $r = [];
                foreach ($headers as $c => $h) {
                    $v = $values[$c] ?? null;
                    if ($v === '\\N') {
                        $v = null;
                    } elseif (is_string($v) && str_starts_with($v, '\\\\')) {
                        $v = substr($v, 1);
                    }if ((Schema::fields()[$name][$h] ?? '') === 'date' && is_numeric($v)) {
                        $v = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d');
                    }$r[$h] = $v;
                }$table[] = $r;
                if (in_array($name, Schema::TYPES)) {
                    $rows[] = ['entity' => $name,'sheet' => $name,'row_number' => $i + 2,'public_id' => $r['public_id'],'data' => $r,'raw' => $r,'formulas' => [],'warnings' => [],'disposition' => 'import','operation' => 'create'];
                }
            }$tables[$name] = $table;
            if (in_array($name, Schema::TYPES)) {
                $counts[$name] = count($table);
            }
        }
        foreach (Schema::TYPES as $t) {
            if (!array_key_exists($t, $tables)) {
                throw new ApiError(422, "Hiányzó osztálylap: $t");
            }
        }
        $p = ['id' => App::id(),'file_hash' => hash_file('sha256', $path),'profile' => 'atlas-v1','ram_profile' => 'exportban rögzített GiB','revision' => $this->app->revision(),'counts' => $counts,'business_total' => array_sum($counts),'source_total' => array_sum($counts),'staging_total' => 0,'links' => ['application_server' => count(array_filter($tables['applications'], fn ($r) => !empty($r['server_id']))),'integration' => count($tables['integrations']),'application_contact' => count($tables['ApplicationContacts'] ?? [])],'noop' => false,'rows' => $rows,'tables' => $tables];
        copy($path, $this->app->config['storage'].'/'.$p['id'].'.xlsx');
        $this->app->run('INSERT INTO imports VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())', [$p['id'],$p['file_hash'],'atlas-v1','preview',$p['revision'],json_encode($p, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $p;
    }
    public function commitInside(array $p): array
    {
        foreach (Schema::TYPES as $t) {
            if ($this->app->one("SELECT COUNT(*) n FROM `$t`")['n']) {
                throw new ApiError(409, 'A céladatbázis már nem üres.');
            }
        }$tables = $p['tables'];
        $mapping = [];
        foreach ($tables['ReferenceData'] ?? [] as $r) {
            $old = $this->app->one('SELECT id FROM reference_data WHERE category=? AND code=?', [$r['category'],$r['code']]);
            if ($old) {
                $mapping[$r['id']] = $old['id'];
            } else {
                $mapping[$r['id']] = $r['id'];
                $this->app->run('INSERT INTO reference_data VALUES (?,?,?,?,?)', [$r['id'],$r['category'],$r['code'],$r['label'],$r['active']]);
            }
        }
        $pending = $tables['Zones'] ?? [];
        for ($pass = 0;$pending && $pass < 100;$pass++) {
            foreach ($pending as $k => $r) {
                if ($r['parent_zone_id'] && !isset($mapping[$r['parent_zone_id']])) {
                    continue;
                }$old = $r['scope'] === 'unknown' ? $this->app->one("SELECT id FROM network_zones WHERE scope='unknown' LIMIT 1") : null;
                $mapping[$r['id']] = $old ? $old['id'] : App::id();
                if (!$old) {
                    $this->app->run('INSERT INTO network_zones VALUES (?,?,?,?,?,?)', [$mapping[$r['id']],$r['name'],$r['scope'],$r['parent_zone_id'] ? $mapping[$r['parent_zone_id']] : null,$r['color'],$r['notes']]);
                }unset($pending[$k]);
            }
        }if ($pending) {
            throw new ApiError(422, 'Ciklikus vagy hiányos zónahierarchia.');
        }
        foreach (['contacts','servers','applications','databases','integrations','network_connections'] as $type) {
            foreach ($tables[$type] as $r) {
                $data = $r;
                foreach (Schema::fields()[$type] as $f => $kind) {
                    if (str_starts_with($kind, 'ref:') && !empty($data[$f])) {
                        if (!isset($mapping[$data[$f]])) {
                            throw new ApiError(422, "Hiányzó exporthivatkozás: $f");
                        }$data[$f] = $mapping[$data[$f]];
                    }
                }
                if ($type === 'network_connections') {
                    foreach (['ConnectionEndpoints' => 'endpoints','ConnectionServices' => 'services'] as $sheet => $key) {
                        $data[$key] = [];
                        foreach ($tables[$sheet] ?? [] as $child) {
                            if ($child['connection_id'] === $r['public_id']) {
                                foreach (['server_id','zone_id','server_address_id'] as $fk) {
                                    if (!empty($child[$fk])) {
                                        $child[$fk] = $mapping[$child[$fk]] ?? $child[$fk];
                                    }
                                }$data[$key][] = $child;
                            }
                        }
                    }
                }
                $saved = $this->app->saveInside($type, $data, null, true);
                $mapping[$r['public_id']] = $saved['id'];
                if ($r['archived_at']) {
                    $this->app->run("UPDATE `$type` SET archived_at=? WHERE id=?", [$r['archived_at'],$saved['id']]);
                }
                if ($type === 'servers') {
                    foreach ($tables['Addresses'] ?? [] as $addr) {
                        if ($addr['server_id'] === $r['public_id']) {
                            $new = App::id();
                            if (isset($addr['id'])) {
                                $mapping[$addr['id']] = $new;
                            }$this->app->run('INSERT INTO server_addresses VALUES (?,?,?,?,?)', [$new,$saved['id'],$addr['address'],$mapping[$addr['zone_id']],$addr['is_primary']]);
                        }
                    }
                }
                $this->app->run('INSERT INTO import_rows VALUES (?,?,?,?,?,?,?,?)', [App::id(),$p['id'],$type,$r['public_id'],$type,0,'import',json_encode($r, JSON_UNESCAPED_UNICODE)]);
            }
        }
        foreach (['ApplicationContacts' => ['applications_contacts',['target_id','contact_id','role_code','role_description','is_primary','notes']],'ServerContacts' => ['servers_contacts',['target_id','contact_id','role_code','role_description','is_primary','notes']],'DatabaseContacts' => ['databases_contacts',['target_id','contact_id','role_code','role_description','is_primary','notes']],'ApplicationBusinessAreas' => ['application_business_areas',['application_id','reference_id']],'IntegrationConnections' => ['integration_connections',['integration_id','connection_id','mapping_notes']],'Boundaries' => ['network_boundaries',['name','zone_a_id','zone_b_id','boundary_type','notes']]] as $sheet => [$table,$fields]) {
            foreach ($tables[$sheet] ?? [] as $r) {
                $cols = $fields;
                $values = [];
                $hasId = in_array($sheet, ['ApplicationContacts','ServerContacts','DatabaseContacts','Boundaries']);
                if ($hasId) {
                    array_unshift($cols, 'id');
                    $values[] = App::id();
                }foreach ($fields as $f) {
                    $v = $r[$f] ?? null;
                    if ($v !== null && str_ends_with($f, '_id')) {
                        $v = $mapping[$v] ?? throw new ApiError(422, 'Hiányzó kapcsolati hivatkozás.');
                    }$values[] = $v;
                }$this->app->run("INSERT INTO `$table` (".implode(',', $cols).') VALUES ('.implode(',', array_fill(0, count($cols), '?')).')', $values);
            }
        }
        $this->app->run("UPDATE imports SET status='committed' WHERE id=?", [$p['id']]);
        $this->app->audit('import', 'imports', $p['id'], [], ['profile' => 'atlas-v1','counts' => $p['counts']]);
        return ['counts' => $p['counts'],'links' => $p['links'],'staging' => 0];
    }
}
