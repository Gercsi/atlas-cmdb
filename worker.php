<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/vendor/autoload.php';
$once = in_array('--once', $argv, true);
$unavailable = false;
do {
    try {
        // First-run workers wait until the installer creates configuration.
        if (Cmdb\Config::exists()) {
            $a = new Cmdb\App();
            $unavailable = false;
            foreach ($a->all("SELECT id FROM jobs WHERE status='queued' ORDER BY created_at LIMIT 5") as $job) {
                try { (new Cmdb\Exporter($a))->process($job['id']); }
                catch (Throwable) { error_log('Export job failed: '.$job['id']); }
            }
        }
    } catch (Throwable) {
        if (!$unavailable) error_log('Export worker: configured database unavailable; retrying.');
        $unavailable = true;
        if ($once) exit(1);
    }
    if (!$once) sleep(2);
} while (!$once);
