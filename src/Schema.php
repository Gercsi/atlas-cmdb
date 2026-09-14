<?php

namespace Cmdb;

final class Schema
{
    public const TYPES = ['applications','servers','databases','integrations','contacts','network_connections'];
    public const PREFIX = ['applications' => 'APP','servers' => 'SRV','databases' => 'DB','integrations' => 'INT','contacts' => 'CON','network_connections' => 'S2S'];
    public static function fields(): array
    {
        return [
        'applications' => ['name' => 'text','description' => 'long','server_id' => 'ref:servers','environment' => 'enum:environment','criticality' => 'enum:criticality','vendor' => 'text','version' => 'text','sla' => 'text','lifecycle_status' => 'enum:lifecycle','go_live_date' => 'date','end_of_support_date' => 'date','hosting_classification' => 'select:internal,external,saas,unknown','network_zone_id' => 'ref:network_zones','legacy_business_owner_text' => 'text','legacy_technical_owner_text' => 'text','legacy_business_areas_text' => 'long','notes' => 'long'],
        'servers' => ['name' => 'text','dns_name' => 'text','hostname' => 'text','environment' => 'enum:environment','hosting_type' => 'select:on_premise,hosted,cloud,other','virtualization_platform' => 'text','os' => 'text','os_version' => 'text','cpu' => 'int','ram_gib' => 'decimal','storage_gb' => 'decimal','datacenter' => 'text','primary_network_zone_id' => 'ref:network_zones','legacy_owner_text' => 'text','notes' => 'long'],
        'databases' => ['name' => 'text','engine' => 'text','version' => 'text','server_id' => 'ref:servers','application_id' => 'ref:applications','backup_enabled' => 'bool','replication_enabled' => 'bool','backup_details' => 'long','replication_details' => 'long','legacy_owner_text' => 'text','notes' => 'long'],
        'integrations' => ['source_application_id' => 'ref:applications','target_application_id' => 'ref:applications','interface_type' => 'text','protocol' => 'text','authentication' => 'text','frequency' => 'text','middleware' => 'text','data_owner_application_id' => 'ref:applications','legacy_data_owner_text' => 'text','criticality' => 'enum:criticality','status' => 'text','notes' => 'long'],
        'contacts' => ['name' => 'text','contact_type' => 'select:person,team,organization,mailbox,unknown','email' => 'text','phone' => 'text','organization' => 'text','job_title' => 'text','notes' => 'long'],
        'network_connections' => ['name' => 'text','connection_type' => 'select:firewall_rule,vpn_flow,direct_flow','action' => 'select:allow,deny,unknown','status' => 'select:planned,active,disabled,expired,unknown','transport_protocol' => 'select:tcp,udp,icmp,any,other','source_ports_mode' => 'select:specified,any,unknown','destination_ports_mode' => 'select:specified,any,unknown','firewall_name' => 'text','external_rule_id' => 'text','priority' => 'int','vpn_tunnel_name' => 'text','nat_description' => 'long','valid_from' => 'date','valid_to' => 'date','last_reviewed_at' => 'date','owner_contact_id' => 'ref:contacts','notes' => 'long'] ];
    }
    public static function migrate(\PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS users (id CHAR(26) PRIMARY KEY, username VARCHAR(100) UNIQUE NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL, capabilities JSON NOT NULL, active BOOLEAN NOT NULL DEFAULT 1)");
        $db->exec("CREATE TABLE IF NOT EXISTS sso_settings (id TINYINT PRIMARY KEY,enabled BOOLEAN NOT NULL DEFAULT 0,provider_type VARCHAR(20) NOT NULL DEFAULT 'entra',display_name VARCHAR(100) NOT NULL DEFAULT 'Microsoft Entra ID',tenant_id VARCHAR(100) NULL,issuer_url VARCHAR(500) NULL,client_id VARCHAR(255) NULL,client_secret_encrypted TEXT NULL,allowed_email_domains JSON NOT NULL,required_group_id VARCHAR(100) NULL,auto_provision BOOLEAN NOT NULL DEFAULT 0,default_role VARCHAR(20) NOT NULL DEFAULT 'viewer',default_capabilities JSON NOT NULL,updated_at DATETIME(6) NULL,updated_by CHAR(26) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; INSERT IGNORE INTO sso_settings(id,allowed_email_domains,default_capabilities) VALUES (1,'[]','[]')");
        $db->exec("CREATE TABLE IF NOT EXISTS user_identities (provider_key CHAR(64) NOT NULL,subject VARCHAR(255) NOT NULL,user_id CHAR(26) NOT NULL,email VARCHAR(255) NULL,created_at DATETIME(6) NOT NULL,last_login_at DATETIME(6) NOT NULL,PRIMARY KEY(provider_key,subject),UNIQUE(provider_key,user_id),FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS sequences (entity VARCHAR(40) PRIMARY KEY, next_value BIGINT NOT NULL)");
        $db->exec("CREATE TABLE IF NOT EXISTS revision (id INT PRIMARY KEY, value BIGINT NOT NULL); INSERT IGNORE INTO revision VALUES (1,0)");
        $db->exec("CREATE TABLE IF NOT EXISTS network_zones (id CHAR(26) PRIMARY KEY, name VARCHAR(255) NOT NULL, scope VARCHAR(20) NOT NULL, parent_zone_id CHAR(26) NULL, color VARCHAR(20) NULL, notes TEXT NULL, FOREIGN KEY(parent_zone_id) REFERENCES network_zones(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS network_boundaries (id CHAR(26) PRIMARY KEY,name VARCHAR(255) NOT NULL,zone_a_id CHAR(26) NOT NULL,zone_b_id CHAR(26) NOT NULL,boundary_type VARCHAR(20) NOT NULL,notes TEXT,FOREIGN KEY(zone_a_id) REFERENCES network_zones(id),FOREIGN KEY(zone_b_id) REFERENCES network_zones(id))");
        foreach (self::fields() as $table => $fields) {
            $cols = ['id CHAR(26) PRIMARY KEY','public_id VARCHAR(128) NOT NULL UNIQUE','created_at DATETIME(6) NOT NULL','updated_at DATETIME(6) NOT NULL','created_by CHAR(26) NULL','updated_by CHAR(26) NULL','lock_version INT NOT NULL DEFAULT 1','archived_at DATETIME(6) NULL',"data_quality_status VARCHAR(30) NOT NULL DEFAULT 'complete'",'quality_warnings JSON NULL','raw_source JSON NULL'];
            foreach ($fields as $field => $kind) {
                $cols[] = "`$field` ".(str_starts_with($kind, 'ref:') ? 'CHAR(26)' : match($kind) {
                    'long' => 'TEXT','int' => 'INT','decimal' => 'DECIMAL(18,6)','bool' => 'BOOLEAN','date' => 'DATE',default => 'VARCHAR(255)'
                }).' NULL';
            }
            $db->exec("CREATE TABLE IF NOT EXISTS `$table` (".implode(',', $cols).") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->prepare('INSERT IGNORE INTO sequences VALUES (?,1)')->execute([$table]);
        }
        foreach (self::fields() as $table => $fields) {
            foreach ($fields as $field => $kind) {
                if (str_starts_with($kind, 'ref:')) {
                    $fk = 'fk_'.$table.'_'.$field;
                    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');
                    $q->execute([$fk]);
                    if (!$q->fetchColumn()) {
                        $db->exec("ALTER TABLE `$table` ADD CONSTRAINT `$fk` FOREIGN KEY (`$field`) REFERENCES `".substr($kind, 4)."`(id)");
                    }
                }
            }
        }
        $q = $db->query("SHOW INDEX FROM `databases` WHERE Key_name='db_application_unique'");
        if (!$q->fetch()) {
            $db->exec('ALTER TABLE `databases` ADD UNIQUE KEY db_application_unique(application_id)');
        }
        foreach (['applications','servers','databases'] as $type) {
            $db->exec("CREATE TABLE IF NOT EXISTS {$type}_contacts (id CHAR(26) PRIMARY KEY,target_id CHAR(26) NOT NULL,contact_id CHAR(26) NOT NULL,role_code VARCHAR(40) NOT NULL,role_description TEXT, is_primary BOOLEAN NOT NULL DEFAULT 0,notes TEXT,UNIQUE(target_id,contact_id,role_code),FOREIGN KEY(target_id) REFERENCES `$type`(id),FOREIGN KEY(contact_id) REFERENCES contacts(id))");
        }
        $db->exec("CREATE TABLE IF NOT EXISTS reference_data (id CHAR(26) PRIMARY KEY, category VARCHAR(60) NOT NULL,code VARCHAR(255) NOT NULL,label VARCHAR(255) NOT NULL,active BOOLEAN NOT NULL DEFAULT 1,UNIQUE(category,code))");
        $db->exec("CREATE TABLE IF NOT EXISTS application_business_areas (application_id CHAR(26) NOT NULL, reference_id CHAR(26) NOT NULL,PRIMARY KEY(application_id,reference_id),FOREIGN KEY(application_id) REFERENCES applications(id),FOREIGN KEY(reference_id) REFERENCES reference_data(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS server_addresses (id CHAR(26) PRIMARY KEY,server_id CHAR(26) NOT NULL,address VARCHAR(255) NOT NULL,zone_id CHAR(26) NOT NULL,is_primary BOOLEAN NOT NULL DEFAULT 0,UNIQUE(server_id,zone_id,address),FOREIGN KEY(server_id) REFERENCES servers(id),FOREIGN KEY(zone_id) REFERENCES network_zones(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS connection_endpoints (id CHAR(26) PRIMARY KEY,connection_id CHAR(26) NOT NULL,side VARCHAR(10) NOT NULL,endpoint_kind VARCHAR(30) NOT NULL,server_id CHAR(26) NULL,server_address_id CHAR(26) NULL,zone_id CHAR(26) NULL,value VARCHAR(255) NULL,FOREIGN KEY(connection_id) REFERENCES network_connections(id),FOREIGN KEY(server_id) REFERENCES servers(id),FOREIGN KEY(server_address_id) REFERENCES server_addresses(id),FOREIGN KEY(zone_id) REFERENCES network_zones(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS connection_services (id CHAR(26) PRIMARY KEY,connection_id CHAR(26) NOT NULL,side VARCHAR(20) NOT NULL,port_from INT NULL,port_to INT NULL,icmp_type INT NULL,icmp_code INT NULL,FOREIGN KEY(connection_id) REFERENCES network_connections(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS integration_connections (integration_id CHAR(26) NOT NULL,connection_id CHAR(26) NOT NULL,mapping_notes TEXT,verified_by CHAR(26),verified_at DATETIME,PRIMARY KEY(integration_id,connection_id),FOREIGN KEY(integration_id) REFERENCES integrations(id),FOREIGN KEY(connection_id) REFERENCES network_connections(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (id CHAR(26) PRIMARY KEY,user_id CHAR(26),created_at DATETIME(6) NOT NULL,action VARCHAR(40) NOT NULL,entity VARCHAR(40) NOT NULL,entity_id CHAR(26),diff JSON NOT NULL,INDEX(entity,entity_id))");
        $db->exec("CREATE TABLE IF NOT EXISTS imports (id CHAR(26) PRIMARY KEY,file_hash CHAR(64) NOT NULL,profile VARCHAR(60) NOT NULL,status VARCHAR(30) NOT NULL,revision BIGINT NOT NULL,preview JSON NOT NULL,created_at DATETIME NOT NULL)");
        $db->exec("CREATE TABLE IF NOT EXISTS import_rows (id CHAR(26) PRIMARY KEY,batch_id CHAR(26) NOT NULL,entity VARCHAR(40) NOT NULL,public_id VARCHAR(128) NOT NULL,sheet VARCHAR(100),`row_number` INT,disposition VARCHAR(30),raw_values JSON NOT NULL,FOREIGN KEY(batch_id) REFERENCES imports(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS diagram_views (id CHAR(26) PRIMARY KEY,owner_id CHAR(26) NOT NULL,name VARCHAR(255) NOT NULL,visibility VARCHAR(20) NOT NULL,config JSON NOT NULL,lock_version INT NOT NULL DEFAULT 1,FOREIGN KEY(owner_id) REFERENCES users(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS jobs (id CHAR(26) PRIMARY KEY,user_id CHAR(26) NOT NULL,status VARCHAR(20) NOT NULL,format VARCHAR(10) NOT NULL,path TEXT NULL,options JSON NOT NULL,error TEXT NULL,created_at DATETIME NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id))");
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (bucket CHAR(64) PRIMARY KEY,attempts INT NOT NULL,reset_at BIGINT NOT NULL)");
        foreach (['environment' => ['DEV','TEST','UAT','PREPROD','PROD','UNKNOWN'],'criticality' => ['Low','Medium','High','Critical'],'lifecycle' => ['Planned','Active','Deprecated','Retired'],'role' => ['business_owner','technical_owner','support','owner','data_owner','vendor_support','other','unspecified']] as $cat => $codes) {
            foreach ($codes as $code) {
                $db->prepare('INSERT IGNORE INTO reference_data VALUES (?,?,?,?,1)')->execute([App::id(),$cat,$code,$code]);
            }
        }
        if (!$db->query('SELECT COUNT(*) FROM network_zones')->fetchColumn()) {
            $db->prepare('INSERT INTO network_zones(id,name,scope,color) VALUES (?,?,?,?)')->execute([App::id(),'Ismeretlen zóna','unknown','#94a3b8']);
        }
        foreach (self::fields() as $table => $fields) {
            foreach (array_intersect(['name','environment','status','criticality'], array_keys($fields)) as $field) {
                $name = 'idx_'.$table.'_'.$field;
                $q = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
                $q->execute([$table,$name]);
                if (!$q->fetchColumn()) {
                    $db->exec("ALTER TABLE `$table` ADD INDEX `$name` (`$field`)");
                }
            }
        }
    }
}
