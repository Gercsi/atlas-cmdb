<?php

namespace Cmdb;

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

final class Importer
{
    public function __construct(private App $app)
    {
    }
    public function preview(string $path, string $ram = 'preserve'): array
    {
        if (!in_array($ram, ['preserve','binary'])) {
            throw new ApiError(422, 'Válassz memóriaegység-profilt.');
        }
        if (!is_file($path) || filesize($path) > 20 * 1024 * 1024) {
            throw new ApiError(422, 'Hiányzó vagy 20 MB-nál nagyobb fájl.');
        }$zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ApiError(422, 'Érvényes XLSX fájl szükséges.');
        }$size = 0;
        for ($i = 0;$i < $zip->numFiles;$i++) {
            $s = $zip->statIndex($i);
            $size += $s['size'];
            if (str_contains($s['name'], 'vbaProject') || str_starts_with($s['name'], 'xl/externalLinks/')) {
                throw new ApiError(422, 'Makró vagy külső munkafüzet-hivatkozás nem megengedett.');
            }
        }if ($size > 80 * 1024 * 1024 || $size / max(1, filesize($path)) > 200) {
            throw new ApiError(422, 'A kitömörítési korlát túllépve.');
        }$zip->close();
        $reader = new Xlsx();
        $reader->setReadDataOnly(false);
        $wb = $reader->load($path);
        if ($wb->getSheetByName('_Manifest')) {
            return (new OwnImporter($this->app))->preview($wb, $path);
        }$rows = [];
        $references = [];
        $counts = [];
        $warnings = [];
        $maps = [
        '01_Applications' => ['applications',['public_id','server_id','name','description','legacy_business_areas_text','legacy_business_owner_text','legacy_technical_owner_text','vendor','version','environment','criticality','sla','lifecycle_status','go_live_date','end_of_support_date','notes']],
        '02_Servers' => ['servers',['public_id','name','dns_name','hostname','ip_raw','environment','hosting_type','virtualization_platform','os','os_version','cpu','ram_raw','storage_gb','datacenter','legacy_owner_text','notes']],
        '03_Databases' => ['databases',['public_id','name','engine','version','server_id','legacy_owner_text','backup_enabled','replication_enabled','notes']],
        '04_Integrations' => ['integrations',['public_id','source_application_id','target_application_id','interface_type','protocol','authentication','frequency','middleware','legacy_data_owner_text','criticality','status','notes']],
        '05_Contacts' => ['contacts',['public_id','name','unused_application','role_raw','email','phone','related_raw','notes']]];
        foreach ($maps as $sheetName => [$type,$fields]) {
            $sheet = $wb->getSheetByName($sheetName);
            if (!$sheet) {
                throw new ApiError(422, "Hiányzó munkalap: $sheetName");
            }if ($sheet->getHighestDataRow() > 20000 || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 100) {
                throw new ApiError(422, 'A munkalap túl nagy.');
            }$expectedFirst = ['applications' => 'Application ID','servers' => 'Server ID','databases' => 'Database ID','integrations' => 'Integration ID','contacts' => 'Contact ID'][$type];
            if (trim((string)$sheet->getCell('A1')->getValue()) !== $expectedFirst) {
                throw new ApiError(422, 'Hibás importfejléc: '.$sheetName);
            }$counts[$type] = 0;
            for ($r = 2;$r <= $sheet->getHighestDataRow();$r++) {
                $raw = [];
                $data = [];
                $formula = [];
                $warn = [];
                foreach ($fields as $col => $field) {
                    $cell = $sheet->getCell([$col + 1,$r]);
                    $v = $cell->getValue();
                    if ($cell->getDataType() === 'f') {
                        $formula[$field] = $v;
                        $v = $cell->getOldCalculatedValue();
                    } $raw[$field] = $v;
                    $data[$field] = is_string($v) ? trim(str_replace("\xc2\xa0", ' ', $v)) : $v;
                    if ($data[$field] === '') {
                        $data[$field] = null;
                    }
                }
                if (empty($data['public_id'])) {
                    continue;
                }if (!preg_match('/^(APP|SRV|DB|INT|CON)-.+$|^(INT|CON)-\d+$/', $data['public_id'])) {
                    throw new ApiError(422, "Hiányzó cached ID: $sheetName/$r. Mentsd a forrást Excelben; képletet nem futtatunk.");
                }
                foreach (['name','hostname','dns_name','os','cpu','storage_gb','ram_raw'] as $f) {
                    if (isset($data[$f]) && (in_array($data[$f], ['?','-'], true) || ($f === 'hostname' && (string)$data[$f] === '0'))) {
                        $data[$f] = null;
                        $warn[] = "$f: forrás-helyőrző";
                    }
                }
                if (in_array($type, ['applications','servers']) && empty($data['environment'])) {
                    $data['environment'] = 'UNKNOWN';
                    $warn[] = 'Hiányzó környezet';
                }
                if ($type !== 'integrations' && empty($data['name'])) {
                    $warn[] = 'Hiányzó név; az eredeti ID jelenik meg';
                }
                if (str_contains($data['public_id'], '?')) {
                    $warn[] = 'Legacy azonosító ismeretlen környezettel';
                }
                if ($type === 'servers') {
                    $v = $data['ram_raw'];
                    if ($v !== null) {
                        $v = str_replace(' ', '', (string)$v);
                        if (is_numeric($v)) {
                            $data['ram_gib'] = $ram === 'binary' ? (float)$v / 1024 : null;
                            $warn[] = $ram === 'binary' ? 'RAM: bináris /1024 profil, nyers érték megőrizve' : 'RAM: eredeti MB megőrizve, GiB-konverzió jóváhagyásra vár';
                            if ((float)$v === 32765.0) {
                                $warn[] = 'Szokatlan RAM: 32765, nincs kerekítve';
                            }
                        } else {
                            $warn[] = 'Nem numerikus RAM';
                        }
                    }$data['hosting_type'] = match(strtolower((string)$data['hosting_type'])) {
                        'on-premise' => 'on_premise','cloud' => 'cloud','hosted' => 'hosted',default => null
                    };
                    if (!empty($data['ip_raw']) && !filter_var($data['ip_raw'], FILTER_VALIDATE_IP)) {
                        $warn[] = 'Az IP mező nem érvényes cím; nyers szöveg megőrizve';
                    }
                }
                if ($type === 'databases') {
                    foreach (['backup_enabled','replication_enabled'] as $f) {
                        $v = strtolower((string)$data[$f]);
                        $data[$f] = $v === 'true' ? true : ($v === 'false' ? false : null);
                    }
                }
                foreach (['legacy_business_owner_text','legacy_technical_owner_text','legacy_owner_text','legacy_data_owner_text'] as $f) {
                    if (!empty($data[$f])) {
                        $warn[] = "$f: nem feloldott tulajdonos";
                    }
                }
                if ($type === 'integrations' && !empty($data['notes'])) {
                    $warn[] = 'Az explicit forrás- és célkapcsolat az irányadó; a szabad szöveg nem írja felül a végpontokat.';
                }
                $disposition = $type === 'contacts' && empty($data['name']) && empty($data['notes']) && empty($data['email']) && empty($data['phone']) ? 'staging' : 'import';
                $existing = $this->app->one("SELECT * FROM `$type` WHERE public_id=?", [$data['public_id']]);
                $rows[] = ['entity' => $type,'sheet' => $sheetName,'row_number' => $r,'public_id' => $data['public_id'],'data' => $data,'raw' => $raw,'formulas' => $formula,'warnings' => $warn,'disposition' => $disposition,'operation' => $existing ? 'update' : 'create'];
                if ($disposition === 'import') {
                    $counts[$type]++;
                }
            }
        }
        $known = [];
        foreach ($rows as $r) {
            $known[$r['public_id']] = $r;
        }
        $links = ['application_server' => 0,'integration' => 0,'application_contact' => 0];
        foreach ($rows as &$row) {
            $d = &$row['data'];
            foreach (['server_id','source_application_id','target_application_id'] as $field) {
                if (!empty($d[$field])) {
                    $id = $this->parseId($d[$field]);
                    if (!$id || !isset($known[$id])) {
                        $row['warnings'][] = "Feloldhatatlan hivatkozás: $field";
                        if (in_array($field, ['source_application_id','target_application_id'])) {
                            $row['disposition'] = 'staging';
                        }$d[$field] = null;
                    } else {
                        $d[$field] = $id;
                        if ($field === 'server_id' && $row['entity'] === 'applications') {
                            $links['application_server']++;
                            if (($d['environment'] ?? null) !== ($known[$id]['data']['environment'] ?? null)) {
                                $row['warnings'][] = 'Alkalmazás és szerver környezete eltér';
                            }
                        }
                    }
                }
            }
            if ($row['entity'] === 'integrations' && $row['disposition'] === 'import') {
                $links['integration']++;
            }
            if ($row['entity'] === 'contacts' && !empty($d['related_raw'])) {
                preg_match_all('/APP-(?:[A-Z]+|\?)-\d+/', $d['related_raw'], $m);
                $d['related_ids'] = array_values(array_filter(array_unique($m[0]), fn ($id) => isset($known[$id])));
                $links['application_contact'] += count($d['related_ids']);
            }
            if ($row['entity'] === 'servers' && !empty($d['name'])) {
                $duplicates = array_filter($known, fn ($k) => $k['entity'] === 'servers' && ($k['data']['name'] ?? null) === $d['name']);
                if (count($duplicates) > 1) {
                    $row['warnings'][] = 'Duplikációgyanús szervernév; nem összevonva';
                }
            }
            $old = $this->app->one("SELECT * FROM `".$row['entity']."` WHERE public_id=?", [$row['public_id']]);
            $row['diff'] = [];
            if ($old) {
                foreach (Schema::fields()[$row['entity']] as $f => $kind) {
                    $v = $d[$f] ?? null;
                    if ($v === null) {
                        continue;
                    }$prior = $old[$f] ?? null;
                    if (str_starts_with($kind, 'ref:') && $prior) {
                        $target = substr($kind, 4);
                        if (in_array($target, Schema::TYPES)) {
                            $prior = $this->app->one("SELECT public_id FROM `$target` WHERE id=?", [$prior])['public_id'] ?? null;
                        }
                    }if ((string)$v !== (string)$prior) {
                        $row['diff'][$f] = ['before' => $prior,'after' => $v];
                    }
                }$row['operation'] = $row['diff'] ? 'update' : 'unchanged';
            }$d['quality_warnings'] = $row['warnings'];
            $d['data_quality_status'] = $row['warnings'] ? 'review' : 'complete';
            $d['raw_source'] = ['values' => $row['raw'],'formulas' => $row['formulas'],'sheet' => $row['sheet'],'row' => $row['row_number'],'ram_profile' => $ram];
        }unset($row,$d);
        if ($sh = $wb->getSheetByName('06_ReferenceData')) {
            for ($r = 2;$r <= $sh->getHighestDataRow();$r++) {
                $category = (string)$sh->getCell([1,$r])->getValue();
                $value = $sh->getCell([2,$r])->getValue();
                if ($category && $value !== null) {
                    $references[] = ['category' => match(strtolower($category)) {
                        'lifecycle' => 'lifecycle','environment' => 'environment','criticality' => 'criticality','os' => 'os',default => strtolower($category)
                    },'value' => $value];
                }
            }
        }
        $hash = hash_file('sha256', $path);
        $profile = 'legacy-v1:'.$ram;
        $same = $this->app->one("SELECT id FROM imports WHERE file_hash=? AND profile=? AND status='committed'", [$hash,$profile]);
        $result = ['id' => App::id(),'file_hash' => $hash,'profile' => $profile,'ram_profile' => $ram,'revision' => $this->app->revision(),'counts' => $counts,'business_total' => array_sum($counts),'source_total' => count($rows),'staging_total' => count(array_filter($rows, fn ($r) => $r['disposition'] === 'staging')),'links' => $links,'noop' => (bool)$same,'rows' => $rows,'references' => $references];
        copy($path, $this->app->config['storage'].'/'.$result['id'].'.xlsx');
        $this->app->run('INSERT INTO imports VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())', [$result['id'],$hash,$profile,'preview',$result['revision'],json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return $result;
    }
    private function parseId(string $v): ?string
    {
        return preg_match('/^((?:APP|SRV)-(?:[A-Z]+|\?)-\d+)(?:\(|$)/', trim($v), $m) ? $m[1] : null;
    }
    public function commit(string $id): array
    {
        return $this->app->tx(function () use ($id) {
            $batch = $this->app->one('SELECT * FROM imports WHERE id=? FOR UPDATE', [$id]);
            if (!$batch) {
                throw new ApiError(404, 'Import nem található.');
            }$p = json_decode($batch['preview'], true);
            if ($batch['status'] === 'committed' || $p['noop']) {
                return ['noop' => true];
            }$this->app->one('SELECT value FROM revision WHERE id=1 FOR UPDATE');
            if ($this->app->revision() !== (int)$batch['revision']) {
                throw new ApiError(409, 'Az adatbázis változott, új előnézet szükséges.');
            }$path = $this->app->config['storage'].'/'.$id.'.xlsx';
            if (!is_file($path) || hash_file('sha256', $path) !== $batch['file_hash']) {
                throw new ApiError(409, 'A forrásfájl módosult.');
            }
            if ($batch['profile'] === 'atlas-v1') {
                return (new OwnImporter($this->app))->commitInside($p);
            }$ids = [];
            foreach (Schema::TYPES as $t) {
                foreach ($this->app->all("SELECT id,public_id FROM `$t`") as $r) {
                    $ids[$r['public_id']] = $r['id'];
                }
            }
            foreach ($p['references'] as $ref) {
                if ($ref['value'] !== '?') {
                    $this->app->run('INSERT IGNORE INTO reference_data VALUES (?,?,?,?,1)', [App::id(),$ref['category'],$ref['value'],$ref['value']]);
                }
            }
            $zone = $this->app->one("SELECT id FROM network_zones WHERE scope='unknown' LIMIT 1")['id'];
            foreach (['servers','applications','databases','contacts','integrations'] as $type) {
                foreach ($p['rows'] as $row) {
                    if ($row['entity'] === $type) {
                        $this->app->run('INSERT INTO import_rows VALUES (?,?,?,?,?,?,?,?)', [App::id(),$id,$type,$row['public_id'],$row['sheet'],$row['row_number'],$row['disposition'],json_encode(['values' => $row['raw'],'formulas' => $row['formulas']], JSON_UNESCAPED_UNICODE)]);
                        if (preg_match('/-(\d+)$/', $row['public_id'], $m)) {
                            $this->app->run('UPDATE sequences SET next_value=GREATEST(next_value,?) WHERE entity=?', [(int)$m[1] + 1,$type]);
                        }if ($row['disposition'] === 'staging') {
                            continue;
                        }$d = $row['data'];
                        foreach (['server_id','source_application_id','target_application_id'] as $fk) {
                            if (!empty($d[$fk])) {
                                $d[$fk] = $ids[$d[$fk]] ?? null;
                            }
                        }
                        if ($type === 'servers' && !empty($d['ip_raw']) && filter_var($d['ip_raw'], FILTER_VALIDATE_IP)) {
                            $d['addresses'] = [['address' => $d['ip_raw'],'zone_id' => $zone,'is_primary' => 1]];
                        }
                        $existing = $ids[$row['public_id']] ?? null;
                        if ($existing) {
                            $d = array_filter($d, fn ($v) => $v !== null);
                        }$saved = $this->app->saveInside($type, $d, $existing, true);
                        $ids[$row['public_id']] = $saved['id'];
                        if ($type === 'applications' && !empty($d['legacy_business_areas_text'])) {
                            foreach (preg_split('/\R/', $d['legacy_business_areas_text']) as $area) {
                                $area = trim($area);
                                if (!$area) {
                                    continue;
                                }$this->app->run('INSERT IGNORE INTO reference_data VALUES (?,?,?,?,1)', [App::id(),'business_area',$area,$area]);
                                $rid = $this->app->one('SELECT id FROM reference_data WHERE category=? AND code=?', ['business_area',$area])['id'];
                                $this->app->run('INSERT IGNORE INTO application_business_areas VALUES (?,?)', [$saved['id'],$rid]);
                            }
                        }
                    }
                }
            }
            foreach ($p['rows'] as $r) {
                if ($r['entity'] === 'contacts' && $r['disposition'] === 'import') {
                    foreach ($r['data']['related_ids'] ?? [] as $appId) {
                        $this->app->run('INSERT IGNORE INTO applications_contacts(id,target_id,contact_id,role_code,role_description) VALUES (?,?,?,?,?)', [App::id(),$ids[$appId],$ids[$r['public_id']],'unspecified',$r['data']['role_raw'] ?? null]);
                    }
                }
            }
            // Imported IDs reserve their actual maxima, independent of processing order.
            foreach (Schema::TYPES as $type) {
                $max = 0;
                foreach ($p['rows'] as $r) {
                    if ($r['entity'] === $type && preg_match('/-(\d+)$/', $r['public_id'], $m)) {
                        $max = max($max, (int)$m[1]);
                    }
                }$this->app->run('UPDATE sequences SET next_value=? WHERE entity=?', [max($max + 1, (int)($this->app->one('SELECT next_value FROM sequences WHERE entity=?', [$type])['next_value'])),$type]);
            }
            $this->app->run("UPDATE imports SET status='committed' WHERE id=?", [$id]);
            $this->app->audit('import','imports',$id,[],['counts' => $p['counts'],'links' => $p['links'],'file_hash' => $p['file_hash'],'ram_profile' => $p['ram_profile']]);
            return ['counts' => $p['counts'],'links' => $p['links'],'staging' => $p['staging_total']];
        });
    }
}
