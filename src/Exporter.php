<?php

namespace Cmdb;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class Exporter
{
    public function __construct(private App $a)
    {
    }
    public function create(array $options): array
    {
        $types = $options['types'] ?? Schema::TYPES;
        if (!is_array($types) || !$types || array_diff($types, Schema::TYPES)) {
            throw new ApiError(422, 'Válassz érvényes osztályokat.');
        }$format = $options['format'] ?? 'xlsx';
        if (!in_array($format, ['xlsx','zip'])) {
            throw new ApiError(422, 'XLSX vagy CSV-ZIP formátum szükséges.');
        }$id = App::id();
        $this->a->run('INSERT INTO jobs VALUES (?,?,?,?,NULL,?,NULL,UTC_TIMESTAMP())', [$id,$this->a->user['id'],'queued',$format,json_encode($options)]);
        return ['id' => $id,'status' => 'queued'];
    }
    public function process(string $id): void
    {
        $j = $this->a->one('SELECT * FROM jobs WHERE id=?', [$id]);
        if (!$j || $j['status'] !== 'queued') {
            return;
        }$this->a->user = $this->a->one('SELECT * FROM users WHERE id=? AND active=1', [$j['user_id']]);
        if (!$this->a->can('export_data')) {
            $this->a->run("UPDATE jobs SET status='failed',error=? WHERE id=?", ['Nincs exportjogosultság.',$id]);
            return;
        }$claim=$this->a->db->prepare("UPDATE jobs SET status='running' WHERE id=? AND status='queued'");$claim->execute([$id]);if($claim->rowCount()!==1)return;
        try {
            $tables = $this->snapshot(json_decode($j['options'], true));
            $path = $this->a->config['storage'].'/'.$id.'.'.$j['format'];
            if ($j['format'] === 'xlsx') {
                $this->xlsx($tables, $path);
            } else {
                $this->csv($tables, $path);
            }$this->a->run("UPDATE jobs SET status='completed',path=? WHERE id=?", [$path,$id]);
            $this->a->audit('export', 'jobs', $id, [], ['format' => $j['format'],'tables' => array_keys($tables)]);
        } catch (\Throwable $e) {
            $this->a->run("UPDATE jobs SET status='failed',error=? WHERE id=?", ['Az export nem sikerült. Ellenőrizd a tárhelyet és a formátumot.',$id]);
            throw $e;
        }
    }
    public function snapshot(array $o): array
    {
        return $this->a->tx(function () use ($o) {
            $types = $o['types'] ?? Schema::TYPES;
            $tables = [];
            $public = [];
            $selected = [];
            foreach (Schema::TYPES as $t) {
                foreach ($this->a->all("SELECT id,public_id FROM `$t`") as $r) {
                    $public[$r['id']] = $r['public_id'];
                }
            }foreach ($this->a->all('SELECT id,name FROM network_zones') as $r) {
                $public[$r['id']] = $r['id'];
            }
            foreach ($types as $t) {
                $filter = ($o['scope'] ?? 'all') === 'all' ? [] : ($o['filters'] ?? []);
                if (($o['scope'] ?? 'all') === 'selected') {
                    $filter['ids'] = $o['ids'] ?? [];
                }if (($o['scope'] ?? 'all') === 'selected' && empty($filter['ids'])) {
                    throw new ApiError(422, 'Nincs kijelölt rekord.');
                }$filter['archived'] = $o['archived'] ?? false;
                [$w,$p] = $this->a->query($t, $filter);
                $rows = $this->a->all("SELECT * FROM `$t` WHERE $w ORDER BY public_id", $p);
                $headers = ['public_id',...array_keys(Schema::fields()[$t]),'archived_at','data_quality_status','quality_warnings'];
                if (!empty($o['columns'])) {
                    $headers = array_values(array_unique(['public_id',...array_intersect($headers, $o['columns'])]));
                }$out = [];
                foreach ($rows as $r) {
                    $selected[$t][$r['id']] = true;
                    $clean = $this->a->clean($r, $t);
                    $v = [];
                    foreach ($headers as $h) {
                        $value = $clean[$h] ?? null;
                        if (str_starts_with(Schema::fields()[$t][$h] ?? '', 'ref:') && $value) {
                            $value = $public[$value] ?? $value;
                        }if (is_array($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                        }$v[$h] = $value;
                    }$out[] = $v;
                }$tables[$t] = ['headers' => $headers,'rows' => $out];
            }
            $rels = ['ApplicationContacts' => ['applications_contacts',['applications','contacts'],'target_id','applications'],'ServerContacts' => ['servers_contacts',['servers','contacts'],'target_id','servers'],'DatabaseContacts' => ['databases_contacts',['databases','contacts'],'target_id','databases'],'Addresses' => ['server_addresses',['servers'],'server_id','servers'],'ConnectionEndpoints' => ['connection_endpoints',['network_connections','servers'],'connection_id','network_connections'],'ConnectionServices' => ['connection_services',['network_connections'],'connection_id','network_connections'],'IntegrationConnections' => ['integration_connections',['integrations','network_connections'],'integration_id','integrations'],'ApplicationBusinessAreas' => ['application_business_areas',['applications'],'application_id','applications']];
            foreach ($rels as $name => [$table,$needed,$owner,$ownerType]) {
                if (!array_diff($needed, $types)) {
                    $rows = $this->a->all("SELECT * FROM `$table`");
                    $out = [];
                    foreach ($rows as $r) {
                        if (!isset($selected[$ownerType][$r[$owner]])) {
                            continue;
                        }if (isset($r['contact_id']) && !isset($selected['contacts'][$r['contact_id']])) {
                            continue;
                        }if ($name !== 'Addresses') {
                            unset($r['id']);
                        }foreach ($r as $k => $v) {
                            if (str_ends_with($k, '_id') && isset($public[$v])) {
                                $r[$k] = $public[$v];
                            }
                        }$out[] = $r;
                    }$cols = array_column($this->a->all("SHOW COLUMNS FROM `$table`"), 'Field');
                    $tables[$name] = ['headers' => ($name === 'Addresses' ? $cols : array_values(array_diff($cols, ['id']))),'rows' => $out];
                }
            }
            foreach (['ReferenceData' => 'reference_data','Zones' => 'network_zones','Boundaries' => 'network_boundaries'] as $name => $table) {
                $rows = $this->a->all("SELECT * FROM `$table`");
                $tables[$name] = ['headers' => array_column($this->a->all("SHOW COLUMNS FROM `$table`"), 'Field'),'rows' => $rows];
            }
            $manifest = ['schema_version' => 1,'export_id' => App::id(),'created_at' => gmdate('c'),'timezone' => 'UTC','types' => $types,'scope' => $o['scope'] ?? 'all','filters' => $o['filters'] ?? [],'units' => ['ram_gib' => 'GiB','storage_gb' => 'GB'],'archived' => $o['archived'] ?? false,'missing_classes' => array_values(array_diff(Schema::TYPES, $types)),'csv_escape' => "Prefix apostrophe for text beginning = + - @ or control character. NULL encoded as \\N; literal leading backslash doubled.",'counts' => array_map(fn ($t) => count($t['rows']), $tables)];
            $tables['_Manifest'] = ['headers' => ['json'],'rows' => [['json' => json_encode($manifest, JSON_UNESCAPED_UNICODE)]]];
            return $tables;
        });
    }
    public function xlsx(array $tables, string $path): void
    {
        $wb = new Spreadsheet();
        $wb->removeSheetByIndex(0);
        foreach ($tables as $name => $t) {
            $sh = $wb->createSheet();
            $sh->setTitle($name);
            $sh->fromArray($t['headers'], null, 'A1');
            foreach ($t['rows'] as $i => $r) {
                foreach ($t['headers'] as $col => $key) {
                    $value = $r[$key] ?? null;
                    $cell = $sh->getCell([$col + 1,$i + 2]);
                    $kind = Schema::fields()[$name][$key] ?? 'text';
                    if ($value === null) {
                        $cell->setValueExplicit('\\N', DataType::TYPE_STRING);
                    } elseif (in_array($kind, ['int','decimal'])) {
                        $cell->setValueExplicit((float)$value, DataType::TYPE_NUMERIC);
                    } elseif ($kind === 'date') {
                        $cell->setValueExplicit(\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($value), DataType::TYPE_NUMERIC);
                        $cell->getStyle()->getNumberFormat()->setFormatCode('yyyy-mm-dd');
                    } elseif ($kind === 'bool') {
                        $cell->setValueExplicit((bool)$value, DataType::TYPE_BOOL);
                    } else {
                        $cell->setValueExplicit(is_string($value) && str_starts_with($value, '\\') ? '\\'.$value : (string)$value, DataType::TYPE_STRING);
                    }
                }
            }$end = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($t['headers']));
            $sh->freezePane('A2');
            $sh->setAutoFilter('A1:'.$end.max(1, count($t['rows']) + 1));
            $sh->getStyle('A1:'.$end.'1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sh->getStyle('A1:'.$end.'1')->getFill()->setFillType('solid')->getStartColor()->setRGB('164E48');
            foreach (range(1, count($t['headers'])) as $c) {
                $sh->getColumnDimensionByColumn($c)->setWidth(25);
            }
        }$wb->setActiveSheetIndex(0);
        (new Xlsx($wb))->save($path);
        $wb->disconnectWorksheets();
    }
    public function csv(array $tables, string $path): void
    {
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($tables as $name => $t) {
            $fp = fopen('php://temp', 'w+');
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $t['headers'], ',', '"', '', "\r\n");
            foreach ($t['rows'] as $r) {
                $line = [];
                foreach ($t['headers'] as $key) {
                    $v = $r[$key] ?? null;
                    if ($v === null) {
                        $v = '\\N';
                    } elseif (is_string($v)) {
                        if (str_starts_with($v, '\\')) {
                            $v = '\\'.$v;
                        }if (preg_match('/^[=+\-@\x00-\x20]/u', $v)) {
                            $v = "'".$v;
                        }
                    }$line[] = $v;
                }fputcsv($fp,$line,',','"','',"\r\n");
            }rewind($fp);
            $z->addFromString($name.'.csv',stream_get_contents($fp));
            fclose($fp);
        }$z->close();
    }
}
