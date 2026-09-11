<?php

namespace Cmdb;

final class Graph
{
    public function __construct(private App $a)
    {
    }

    public function query(array $c): array
    {
        $view = $c['view'] ?? 'infrastructure';
        if (!in_array($view, ['infrastructure','applications','servers','network','datacenters'], true)) {
            throw new ApiError(422, 'Ismeretlen diagramnézet.');
        }
        $env = $c['environment'] ?? '';
        $tables = [];
        foreach (['servers','applications','databases','integrations','network_connections'] as $t) {
            $rows = $this->a->all("SELECT * FROM `$t`".(empty($c['archived']) ? ' WHERE archived_at IS NULL' : ''));
            $tables[$t] = array_column($rows, null, 'id');
        }
        $servers = $tables['servers'];
        $apps = $tables['applications'];
        $dbs = $tables['databases'];
        $nodes = [];
        $edges = [];
        $warnings = [];
        $unprojected = [];
        $omitted = 0;
        $allowEnv = fn ($r) => !$env || ($r['environment'] ?? null) === $env;
        $base = [];
        foreach ($servers as $id => $s) {
            if ($allowEnv($s)) {
                $base[$id] = true;
            }
        }
        $selected = array_values(array_filter($c['server_ids'] ?? [], fn ($id) => isset($base[$id])));
        $allowed = $selected ? array_fill_keys($selected, true) : $base;
        $ints = array_filter($tables['integrations'], fn ($i) => (empty($c['criticality']) || $i['criticality'] === $c['criticality']) && (empty($c['status']) || $i['status'] === $c['status'] || (!empty($c['include_unknown']) && $i['status'] === null)));
        if ($selected && ($c['mode'] ?? 'selected') === 'neighbors') {
            for ($step = 0; $step < min(2, max(1, (int)($c['hops'] ?? 1))); $step++) {
                $next = $allowed;
                foreach ($ints as $i) {
                    $s = $apps[$i['source_application_id']]['server_id'] ?? null;
                    $t = $apps[$i['target_application_id']]['server_id'] ?? null;
                    if (isset($allowed[$s]) && isset($base[$t])) {
                        $next[$t] = true;
                    }
                    if (isset($allowed[$t]) && isset($base[$s])) {
                        $next[$s] = true;
                    }
                }
                $allowed = $next;
            }
        }

        $add = function (string $id, string $type, string $label, ?string $parent = null, array $extra = []) use (&$nodes) {
            $nodes[$id] = ['id' => $id,'entity_type' => $type,'entity_id' => $id,'label' => $label,'parent' => $parent,...$extra];
        };
        $zones = $this->a->all('SELECT * FROM network_zones');
        $zonesById = array_column($zones, null, 'id');
        $unknown = null;
        foreach ($zones as $z) {
            if ($z['scope'] === 'unknown') {
                $unknown = $z['id'];
            }
        }
        $ensureZone = function (?string $id) use ($zonesById, $unknown, $add) {
            $id = $id ?: $unknown;
            $z = $zonesById[$id] ?? null;
            if ($z) {
                $add('zone-'.$id, 'zone', $z['name'].' · '.$z['scope'], null, ['entity_id' => $id,'color' => $z['color']]);
            }
            return 'zone-'.$id;
        };
        $serverFrame = function (array $s, ?string $parent = null) use ($add, $selected) {
            $id = $s['id'];
            $add('group-'.$id, 'server_group', $s['name'] ?: $s['public_id'], $parent, [
                'entity_id' => $id,
                'record_type' => 'servers',
                'public_id' => $s['public_id'],
                'environment' => $s['environment'],
                'data_quality' => $s['data_quality_status'],
                'hosting_type' => $s['hosting_type'],
                'datacenter' => $s['datacenter'],
                'neighbor' => $selected && !in_array($id, $selected, true),
            ]);
            return 'group-'.$id;
        };

        $groups = ($c['groups'] ?? true) && $view === 'infrastructure';
        if ($view !== 'datacenters') {
            foreach ($servers as $id => $s) {
                if (!isset($allowed[$id]) || $view === 'applications') {
                    continue;
                }
                if ($groups) {
                    $serverFrame($s);
                    continue;
                }
                $parent = $view === 'network' ? $ensureZone($s['primary_network_zone_id']) : null;
                $add($id, 'servers', $s['name'] ?: $s['public_id'], $parent, [
                    'public_id' => $s['public_id'],
                    'environment' => $s['environment'],
                    'data_quality' => $s['data_quality_status'],
                    'hosting_type' => $s['hosting_type'],
                    'datacenter' => $s['datacenter'],
                    'neighbor' => $selected && !in_array($id, $selected, true),
                ]);
            }
        }

        $locationDefinitions = [];
        $serverLocation = [];
        $locationId = fn (string $key) => 'location-'.substr(hash('sha256', mb_strtolower($key)), 0, 16);
        $defineLocation = function (string $key, string $label, string $kind, string $scope) use (&$locationDefinitions, $locationId) {
            if (!isset($locationDefinitions[$key])) {
                $locationDefinitions[$key] = ['id' => $locationId($key),'key' => $key,'label' => $label,'kind' => $kind,'scope' => $scope,'member_count' => 0];
            }
            return $locationDefinitions[$key]['id'];
        };
        $serverLocationKey = function (array $s) use ($zonesById): array {
            $hosting = $s['hosting_type'] ?: 'other';
            $dc = trim((string)($s['datacenter'] ?? ''));
            $zoneScope = $zonesById[$s['primary_network_zone_id']]['scope'] ?? 'unknown';
            if ($hosting === 'cloud') {
                return ['cloud', 'Felhő · külső hosztolás', 'cloud', 'external'];
            }
            if ($hosting === 'hosted') {
                return ['hosted', 'Külső szolgáltatónál hosztolva', 'hosted', 'external'];
            }
            if ($zoneScope === 'external') {
                return ['internet', 'Internet / külső hálózat', 'internet', 'external'];
            }
            if ($dc !== '') {
                return ['dc:'.mb_strtolower($dc), 'Adatközpont · '.$dc, 'datacenter', 'internal'];
            }
            return ['internal-unspecified', 'Belső elhelyezés · nincs adatközpont megadva', 'internal_unknown', 'unknown'];
        };

        if ($view === 'datacenters') {
            foreach ($servers as $id => $s) {
                if (!isset($allowed[$id])) {
                    continue;
                }
                [$key,$label,$kind,$scopeName] = $serverLocationKey($s);
                $location = $defineLocation($key, $label, $kind, $scopeName);
                $serverLocation[$id] = $key;
                $locationDefinitions[$key]['member_count']++;
                $serverFrame($s, $location);
            }
        }

        if (in_array($view, ['infrastructure','applications','datacenters'], true)) {
            foreach ($apps as $id => $app) {
                if (!$allowEnv($app) || ($selected && !isset($allowed[$app['server_id']]))) {
                    continue;
                }
                $parent = null;
                if ($view === 'datacenters') {
                    if ($app['server_id'] && isset($serverLocation[$app['server_id']])) {
                        $parent = 'group-'.$app['server_id'];
                    } else {
                        $classification = $app['hosting_classification'] ?: 'unknown';
                        if ($classification === 'saas') {
                            $key = 'cloud';
                            $parent = $defineLocation($key, 'Felhő · külső hosztolás', 'cloud', 'external');
                        } elseif ($classification === 'external') {
                            $key = 'internet';
                            $parent = $defineLocation($key, 'Internet / külső hálózat', 'internet', 'external');
                        } else {
                            $key = 'application-unassigned';
                            $parent = $defineLocation($key, 'Nincs hosztolási hely hozzárendelve', 'unknown', 'unknown');
                        }
                        $locationDefinitions[$key]['member_count']++;
                    }
                } elseif ($groups) {
                    if ($app['server_id'] && isset($allowed[$app['server_id']])) {
                        $parent = 'group-'.$app['server_id'];
                    } else {
                        $parent = 'unassigned';
                        $add($parent, 'group', 'Nincs szerver hozzárendelve');
                    }
                }
                $add($id, 'applications', $app['name'] ?: $app['public_id'], $parent, ['public_id' => $app['public_id'],'environment' => $app['environment'],'data_quality' => $app['data_quality_status'],'hosting_classification' => $app['hosting_classification']]);
            }
            if (in_array($view, ['infrastructure','datacenters'], true) && ($c['databases'] ?? true)) {
                foreach ($dbs as $id => $d) {
                    if ($selected && !isset($allowed[$d['server_id']])) {
                        continue;
                    }
                    $parent = null;
                    if (($groups || $view === 'datacenters') && $d['server_id'] && isset($allowed[$d['server_id']])) {
                        $parent = 'group-'.$d['server_id'];
                    } elseif ($groups) {
                        $parent = 'unassigned';
                        $add('unassigned', 'group', 'Nincs szerver hozzárendelve');
                    } elseif ($view === 'datacenters') {
                        $key = 'database-unassigned';
                        $parent = $defineLocation($key, 'Nincs hosztolási hely hozzárendelve', 'unknown', 'unknown');
                        $locationDefinitions[$key]['member_count']++;
                    }
                    $add($id, 'databases', $d['name'] ?: $d['public_id'], $parent, ['public_id' => $d['public_id'],'data_quality' => $d['data_quality_status']]);
                    if ($d['application_id'] && isset($nodes[$d['application_id']])) {
                        $edges[] = ['id' => 'db-'.$id,'source' => $d['application_id'],'target' => $id,'type' => 'database','entity_ids' => [$id],'count' => 1,'label' => 'Adatbázis-használat'];
                    }
                }
            }
        }

        if ($view !== 'network' && ($c['integrations'] ?? true)) {
            foreach ($ints as $i) {
                $s = $i['source_application_id'];
                $t = $i['target_application_id'];
                if ($view === 'servers') {
                    $s = $apps[$s]['server_id'] ?? null;
                    $t = $apps[$t]['server_id'] ?? null;
                    if (!$s || !$t) {
                        $unprojected[] = $i['public_id'];
                        continue;
                    }
                }
                if (!isset($nodes[$s], $nodes[$t])) {
                    $omitted++;
                    continue;
                }
                $key = $view === 'servers' && ($c['aggregate'] ?? true) ? 'int-'.$s.'-'.$t : 'int-'.$i['id'];
                if (isset($edges[$key])) {
                    $edges[$key]['entity_ids'][] = $i['id'];
                    $edges[$key]['count']++;
                    $edges[$key]['label'] = $edges[$key]['count'].' integráció';
                } else {
                    $edges[$key] = ['id' => $key,'source' => $s,'target' => $t,'type' => 'integration','entity_ids' => [$i['id']],'count' => 1,'status' => $i['status'],'label' => $i['interface_type'] ?: 'Integráció'];
                }
            }
        }

        $eps = $this->a->all('SELECT * FROM connection_endpoints');
        if ($view === 'network') {
            foreach ($tables['network_connections'] as $id => $r) {
                $sides = ['source' => [],'target' => []];
                foreach ($eps as $e) {
                    if ($e['connection_id'] !== $id) {
                        continue;
                    }
                    $nid = $e['server_id'];
                    if ($nid) {
                        if (!isset($nodes[$nid])) {
                            continue;
                        }
                    } else {
                        $nid = 'ep-'.$e['id'];
                        $add($nid, 'endpoint', $e['value'] ?: ($e['endpoint_kind'] === 'any' ? 'Bármely (explicit)' : $e['endpoint_kind']), $ensureZone($e['zone_id']));
                    }
                    $sides[$e['side']][] = $nid;
                }
                foreach ($sides['source'] as $s) {
                    foreach ($sides['target'] as $t) {
                        $edges[] = ['id' => 'net-'.$id.'-'.$s.'-'.$t,'source' => $s,'target' => $t,'type' => 'network','entity_ids' => [$id],'count' => 1,'action' => $r['action'],'status' => $r['status'],'label' => ($r['action'] === 'allow' ? 'Rögzített engedély' : ($r['action'] === 'deny' ? 'Rögzített tiltás' : 'Nem eldönthető')).' · '.$r['status']];
                    }
                }
            }
            if (!$tables['network_connections']) {
                $warnings[] = 'Nincs rögzített hálózati szabály. Ez nem jelent tiltott vagy elérhetetlen hálózatot.';
            }
        }

        $locationsMeta = [];
        $locationPath = [];
        if ($view === 'datacenters') {
            $addresses = array_column($this->a->all('SELECT * FROM server_addresses'), null, 'id');
            $zoneLocations = [];
            foreach ($servers as $serverId => $server) {
                if (!isset($serverLocation[$serverId])) {
                    continue;
                }
                if ($server['primary_network_zone_id']) {
                    $zoneLocations[$server['primary_network_zone_id']][$serverLocation[$serverId]] = true;
                }
            }
            foreach ($addresses as $address) {
                if (isset($serverLocation[$address['server_id']]) && $address['zone_id']) {
                    $zoneLocations[$address['zone_id']][$serverLocation[$address['server_id']]] = true;
                }
            }
            for ($round = 0; $round < count($zones); $round++) {
                foreach ($zones as $zone) {
                    if ($zone['parent_zone_id'] && !empty($zoneLocations[$zone['id']])) {
                        foreach ($zoneLocations[$zone['id']] as $key => $_) {
                            $zoneLocations[$zone['parent_zone_id']][$key] = true;
                        }
                    }
                }
            }
            $externalLocation = function (string $scope) use ($defineLocation): void {
                if ($scope === 'external') {
                    $defineLocation('internet', 'Internet / külső hálózat', 'internet', 'external');
                } elseif ($scope === 'partner') {
                    $defineLocation('partner', 'Partnerhálózat · külső', 'partner', 'external');
                }
            };
            $endpointLocations = function (array $e) use (&$serverLocation, $addresses, &$zoneLocations, $zonesById, $externalLocation, $defineLocation): array {
                if ($e['server_id'] && isset($serverLocation[$e['server_id']])) {
                    return [$serverLocation[$e['server_id']]];
                }
                if ($e['server_address_id'] && isset($addresses[$e['server_address_id']])) {
                    $address = $addresses[$e['server_address_id']];
                    if (isset($serverLocation[$address['server_id']])) {
                        return [$serverLocation[$address['server_id']]];
                    }
                }
                $zoneId = $e['zone_id'] ?: ($addresses[$e['server_address_id']]['zone_id'] ?? null);
                if ($zoneId && !empty($zoneLocations[$zoneId])) {
                    return array_keys($zoneLocations[$zoneId]);
                }
                if ($zoneId && isset($zonesById[$zoneId])) {
                    $externalLocation($zonesById[$zoneId]['scope']);
                    if (!in_array($zonesById[$zoneId]['scope'], ['external','partner'], true)) {
                        $defineLocation('network-unspecified', 'Besorolatlan hálózati végpont', 'network_unknown', 'unknown');
                    }
                    return match ($zonesById[$zoneId]['scope']) {
                        'external' => ['internet'],
                        'partner' => ['partner'],
                        default => ['network-unspecified'],
                    };
                }
                $defineLocation('network-unspecified', 'Besorolatlan hálózati végpont', 'network_unknown', 'unknown');
                return ['network-unspecified'];
            };
            $locationEdges = [];
            foreach ($tables['network_connections'] as $id => $connection) {
                $sides = ['source' => [],'target' => []];
                foreach ($eps as $e) {
                    if ($e['connection_id'] === $id) {
                        foreach ($endpointLocations($e) as $key) {
                            $sides[$e['side']][$key] = true;
                        }
                    }
                }
                foreach (array_keys($sides['source']) as $sourceKey) {
                    foreach (array_keys($sides['target']) as $targetKey) {
                        if ($sourceKey === $targetKey || !isset($locationDefinitions[$sourceKey], $locationDefinitions[$targetKey])) {
                            continue;
                        }
                        $edgeKey = implode('|', [$sourceKey,$targetKey,$connection['action'],$connection['status']]);
                        if (!isset($locationEdges[$edgeKey])) {
                            $locationEdges[$edgeKey] = [
                                'id' => 'loc-net-'.substr(hash('sha256', $edgeKey), 0, 20),
                                'source_key' => $sourceKey,
                                'target_key' => $targetKey,
                                'type' => 'network',
                                'entity_ids' => [],
                                'count' => 0,
                                'action' => $connection['action'],
                                'status' => $connection['status'],
                                'route_eligible' => $connection['action'] === 'allow' && $connection['status'] === 'active',
                                'label' => '',
                            ];
                        }
                        $locationEdges[$edgeKey]['entity_ids'][] = $id;
                        $locationEdges[$edgeKey]['count']++;
                        $locationEdges[$edgeKey]['label'] = $locationEdges[$edgeKey]['count'] === 1
                            ? (($connection['name'] ?: 'Hálózati kapcsolat').' · '.$connection['status'])
                            : $locationEdges[$edgeKey]['count'].' hálózati kapcsolat';
                    }
                }
            }
            foreach ($this->a->all('SELECT * FROM network_boundaries') as $boundary) {
                $aKeys = array_keys($zoneLocations[$boundary['zone_a_id']] ?? []);
                $bKeys = array_keys($zoneLocations[$boundary['zone_b_id']] ?? []);
                if (!$aKeys) {
                    $scopeName = $zonesById[$boundary['zone_a_id']]['scope'] ?? '';
                    $externalLocation($scopeName);
                    $aKeys = $scopeName === 'external' ? ['internet'] : ($scopeName === 'partner' ? ['partner'] : []);
                }
                if (!$bKeys) {
                    $scopeName = $zonesById[$boundary['zone_b_id']]['scope'] ?? '';
                    $externalLocation($scopeName);
                    $bKeys = $scopeName === 'external' ? ['internet'] : ($scopeName === 'partner' ? ['partner'] : []);
                }
                foreach ($aKeys as $sourceKey) {
                    foreach ($bKeys as $targetKey) {
                        if ($sourceKey === $targetKey || !isset($locationDefinitions[$sourceKey], $locationDefinitions[$targetKey])) {
                            continue;
                        }
                        $edgeKey = 'boundary|'.implode('|', [$sourceKey,$targetKey,$boundary['boundary_type']]);
                        if (!isset($locationEdges[$edgeKey])) {
                            $locationEdges[$edgeKey] = [
                                'id' => 'loc-boundary-'.substr(hash('sha256', $edgeKey), 0, 20),
                                'source_key' => $sourceKey,
                                'target_key' => $targetKey,
                                'type' => 'boundary',
                                'entity_ids' => [],
                                'count' => 0,
                                'status' => 'documented',
                                'route_eligible' => true,
                                'label' => '',
                            ];
                        }
                        $locationEdges[$edgeKey]['count']++;
                        $locationEdges[$edgeKey]['label'] = $locationEdges[$edgeKey]['count'] === 1
                            ? (($boundary['name'] ?: 'Hálózati határ').' · '.$boundary['boundary_type'])
                            : $locationEdges[$edgeKey]['count'].' hálózati határ';
                    }
                }
            }

            $adjacency = [];
            foreach ($locationDefinitions as $key => $_) {
                $adjacency[$key] = [];
            }
            foreach ($locationEdges as $edge) {
                if (empty($edge['route_eligible'])) {
                    continue;
                }
                $adjacency[$edge['source_key']][$edge['target_key']] = true;
                $adjacency[$edge['target_key']][$edge['source_key']] = true;
            }
            $pathFrom = (string)($c['path_from'] ?? '');
            $pathTo = (string)($c['path_to'] ?? '');
            if ($pathFrom && $pathTo && isset($adjacency[$pathFrom], $adjacency[$pathTo])) {
                $previous = [$pathFrom => null];
                $queue = [$pathFrom];
                for ($at = 0; $at < count($queue) && !array_key_exists($pathTo, $previous); $at++) {
                    foreach (array_keys($adjacency[$queue[$at]]) as $next) {
                        if (!array_key_exists($next, $previous)) {
                            $previous[$next] = $queue[$at];
                            $queue[] = $next;
                        }
                    }
                }
                if (array_key_exists($pathTo, $previous)) {
                    for ($at = $pathTo; $at !== null; $at = $previous[$at]) {
                        array_unshift($locationPath, $at);
                    }
                } else {
                    $warnings[] = 'A kiválasztott helyek között nincs dokumentált hálózati útvonal. Ez nem élő elérhetőségi mérés.';
                }
            }

            foreach ($locationDefinitions as $key => $location) {
                $pathState = in_array($key, $locationPath, true) ? ($key === $pathFrom || $key === $pathTo ? 'endpoint' : 'path') : '';
                $add($location['id'], 'location_group', $location['label'], null, [
                    'entity_id' => $key,
                    'location_key' => $key,
                    'location_kind' => $location['kind'],
                    'scope' => $location['scope'],
                    'member_count' => $location['member_count'],
                    'path_state' => $pathState,
                ]);
                $locationsMeta[] = $location;
            }
            foreach ($locationEdges as $edge) {
                $sourceLocation = $locationDefinitions[$edge['source_key']];
                $targetLocation = $locationDefinitions[$edge['target_key']];
                $sourceGateway = 'gateway-'.$sourceLocation['id'];
                $targetGateway = 'gateway-'.$targetLocation['id'];
                $onPath = false;
                for ($i = 1; $i < count($locationPath); $i++) {
                    if (!empty($edge['route_eligible']) && (($locationPath[$i - 1] === $edge['source_key'] && $locationPath[$i] === $edge['target_key']) || ($locationPath[$i - 1] === $edge['target_key'] && $locationPath[$i] === $edge['source_key']))) {
                        $onPath = true;
                        break;
                    }
                }
                $add($sourceGateway, 'location_gateway', 'Hálózati kijárat', $sourceLocation['id'], ['location_key' => $edge['source_key'],'path_state' => $onPath ? 'path' : '']);
                $add($targetGateway, 'location_gateway', 'Hálózati kijárat', $targetLocation['id'], ['location_key' => $edge['target_key'],'path_state' => $onPath ? 'path' : '']);
                unset($edge['source_key'], $edge['target_key']);
                unset($edge['route_eligible']);
                $edge['source'] = $sourceGateway;
                $edge['target'] = $targetGateway;
                $edge['on_path'] = $onPath;
                $edges[] = $edge;
            }
            usort($locationsMeta, fn ($a, $b) => $a['label'] <=> $b['label']);
            if (!$locationEdges) {
                $warnings[] = 'Nincs adatközpontok közötti dokumentált hálózati kapcsolat vagy zónahatár. Ez nem jelent elérhetetlenséget.';
            }
        }

        if (!($c['isolated'] ?? true)) {
            $used = [];
            foreach ($edges as $e) {
                $used[$e['source']] = true;
                $used[$e['target']] = true;
            }
            foreach ($nodes as $id => $n) {
                if (!in_array($n['entity_type'], ['zone','group','server_group','location_group'], true) && !isset($used[$id])) {
                    unset($nodes[$id]);
                }
            }
        }
        $parentTypes = ['zone','group','location_group'];
        do {
            $changed = false;
            foreach ($nodes as $id => $n) {
                if (in_array($n['entity_type'], $parentTypes, true) && !array_filter($nodes, fn ($x) => ($x['parent'] ?? null) === $id)) {
                    unset($nodes[$id]);
                    $changed = true;
                }
            }
        } while ($changed);
        $businessTypes = ['applications','servers','databases','server_group'];
        $business = count(array_filter($nodes, fn ($n) => in_array($n['entity_type'], $businessTypes, true)));
        $edgeCount = count($edges);
        if ($business > 500 || $edgeCount > 2000) {
            throw new ApiError(422, "A gráf túl nagy ($business objektum, $edgeCount él). Szűkítsd a szűrőket.");
        }
        if ($business > 200 || $edgeCount > 500) {
            $warnings[] = 'Nagy gráf: szűkítés vagy összevonás ajánlott.';
        }
        $pathLabels = array_map(fn ($key) => $locationDefinitions[$key]['label'] ?? $key, $locationPath);
        return [
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'meta' => [
                'revision' => $this->a->revision(),
                'counts' => ['nodes' => $business,'edges' => $edgeCount],
                'truncated' => false,
                'omitted' => $omitted,
                'unprojected' => $unprojected,
                'warnings' => $warnings,
                'locations' => $locationsMeta,
                'location_path' => $pathLabels,
                'notice' => 'Nyilvántartott kapcsolatok; nem élő elérhetőségi mérés',
            ],
        ];
    }
}
