<?php
$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (!$isCli) {
    require_admin();
    header('Content-Type: text/plain; charset=utf-8');
}

$result = sync_go2rtc_config();

echo "Streams: " . $result['stream_count'] . "\n";
echo "Write: " . ($result['write_ok'] ? 'OK' : 'FAILED') . "\n";
echo "Service: " . ($result['restart_ok'] ? 'OK' : 'FAILED') . "\n\n";
if ($result['restart_log'] !== '') {
    echo "Restart log: " . $result['restart_log'] . "\n";
}
echo $result['yaml'];
