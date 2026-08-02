<?php
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

function tcpTest($host, $port, $timeout = 6) {
    $start = microtime(true);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
    $ms = round((microtime(true) - $start) * 1000);
    if ($fp) { fclose($fp); return "CONNECT OK in {$ms}ms"; }
    return "FAIL errno=$errno '$errstr' after {$ms}ms";
}

echo "== DNS ==\n";
foreach (['smtp.gmail.com', 'smtp-relay.brevo.com', 'smtp.office365.com', 'api.textbee.dev', 'github.com'] as $h) {
    $ips = @gethostbynamel($h);
    echo "$h => " . ($ips ? implode(',', $ips) : 'NO DNS RESULT') . "\n";
}

echo "\n== TCP connects ==\n";
foreach ([
    ['smtp.gmail.com', 587], ['smtp.gmail.com', 465], ['smtp.gmail.com', 25],
    ['smtp-relay.brevo.com', 587], ['smtp-relay.brevo.com', 465],
    ['smtp.office365.com', 587],
    ['smtp.gmail.com', 993],
] as [$h, $p]) {
    echo "tcp://$h:$p => " . tcpTest($h, $p) . "\n";
}

echo "\n== Outbound HTTPS (public internet check) ==\n";
$ctx = stream_context_create(['http' => ['timeout' => 6], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$ip = @file_get_contents('https://api.ipify.org', false, $ctx);
echo 'ipify: ' . ($ip ? trim($ip) : 'UNREACHABLE') . "\n";
$ip2 = @file_get_contents('https://api.ipify.org?format=json', false, $ctx);
echo 'ipify json: ' . ($ip2 ? trim($ip2) : 'UNREACHABLE') . "\n";
