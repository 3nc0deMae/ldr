<?php
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ob_implicit_flush(true);
@ob_end_flush();
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(55);
echo "start " . date('H:i:s') . " PHP " . PHP_VERSION . "\n";
flush();

function tcpTest($label, $host, $port, $timeout = 6) {
    $start = microtime(true);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
    $ms = round((microtime(true) - $start) * 1000);
    $line = $fp ? "CONNECT OK in {$ms}ms" : "FAIL errno=$errno '$errstr' after {$ms}ms";
    echo "$label tcp://$host:$port => $line\n";
    flush();
    if ($fp) fclose($fp);
}

tcpTest('G587', 'smtp.gmail.com', 587);
tcpTest('G465', 'smtp.gmail.com', 465);
tcpTest('C443', '1.1.1.1', 443);
tcpTest('D53', '8.8.8.8', 53);
tcpTest('B587', 'smtp-relay.brevo.com', 587);
tcpTest('O587', 'smtp.office365.com', 587);
echo "done " . date('H:i:s') . "\n";
flush();
