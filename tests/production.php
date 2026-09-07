<?php

// Verify production error responses on an isolated server without a database.
$productionUrl = 'http://127.0.0.1:' . findFreePort();
$productionLog = tempnam(sys_get_temp_dir(), 'php-mvc-prod-');
$productionPipes = [];
$productionServer = proc_open(
    [PHP_BINARY, '-S', substr($productionUrl, 7), '-t', 'public', 'dev-server.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $productionLog, 'a'], 2 => ['file', $productionLog, 'a']],
    $productionPipes,
    $root,
    array_merge(getenv(), ['PHP_MVC_CONFIG' => __DIR__ . '/fixtures/production.ini'])
);
try {
    verify(is_resource($productionServer), 'Unable to start the production-mode test server.');
    fclose($productionPipes[0]);
    for ($attempt = 0; $attempt < 50 && !serverIsReady($productionUrl); $attempt++) {
        usleep(100_000);
    }
    $response = request($productionUrl, 'GET', '/foo');
    verify($response['status'] === 500 && ($response['body']['code'] ?? null) === 500, 'Internal exceptions must return HTTP 500.');
    verify(!str_contains($response['raw'], 'DATABASE') && !str_contains($response['raw'], $root)
        && !str_contains($response['raw'], 'production-test'), 'Production must not expose configuration or exception details.');
    verify(str_contains(file_get_contents($productionLog), 'DATABASE.HOST'), 'Internal details must be recorded in server logs.');
    $response = request($productionUrl, 'HEAD', '/foo');
    verify($response['status'] === 500 && $response['raw'] === '', 'HEAD errors must not have a body.');
    pass('production masks internal details, logs errors and respects HEAD');
} finally {
    if (is_resource($productionServer)) {
        proc_terminate($productionServer);
        foreach ($productionPipes as $pipe) {
            if (is_resource($pipe)) { fclose($pipe); }
        }
        proc_close($productionServer);
    }
    if (is_file($productionLog)) { unlink($productionLog); }
}
