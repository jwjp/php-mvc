<?php

declare(strict_types=1);

use Model\QueryBuilder;

$root = dirname(__DIR__);
$configPath = getenv('PHP_MVC_CONFIG') ?: $root . '/.sample.ini';
$autoloadPath = $root . '/Variety/resources/libs/vendor/autoload.php';

if (!is_file($configPath) || !is_file($autoloadPath)) {
    fwrite(STDERR, "Run composer install and create .sample.ini before testing.\n");
    exit(1);
}

require $autoloadPath;

$config = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
if ($config === false || !isset($config['DATABASE'])) {
    fwrite(STDERR, "DATABASE settings are missing from .sample.ini.\n");
    exit(1);
}

$configuredBaseUrl = getenv('PHP_MVC_BASE_URL');
$baseUrl = rtrim(
    $configuredBaseUrl !== false && $configuredBaseUrl !== ''
        ? $configuredBaseUrl
        : 'http://127.0.0.1:' . findFreePort(),
    '/'
);
$serverProcess = null;
$serverPipes = [];
$createdFooIds = [];
$createdBarIds = [];
$passed = 0;
$requestCookie = '';
$csrfToken = '';

/** @return array{status: int, contentType: string, raw: string, body: mixed} */
function request(string $baseUrl, string $method, string $path, mixed $body = null, ?string $contentType = null, bool $withCsrf = true, array $extraHeaders = []): array
{
    global $requestCookie, $csrfToken;
    $curl = curl_init($baseUrl . $path);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $headers = $extraHeaders;
    $responseHeaders = [];
    if ($withCsrf && $csrfToken !== '' && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $headers[] = 'X-CSRF-Token: ' . $csrfToken;
    }
    if ($body !== null) {
        if ($contentType === 'application/json' && !is_string($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } elseif ($contentType === 'application/x-www-form-urlencoded' && is_array($body)) {
            $body = http_build_query($body);
        }
        $headers[] = 'Content-Type: ' . $contentType;
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_COOKIE => $requestCookie,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_PATH_AS_IS => true,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders, &$requestCookie): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $responseHeaders[$name] = trim($parts[1]);
                if ($name === 'set-cookie' && str_starts_with(trim($parts[1]), 'php-mvc=')) {
                    $requestCookie = explode(';', trim($parts[1]))[0];
                }
            }
            return strlen($line);
        },
    ]);

    $raw = curl_exec($curl);
    if ($raw === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP request failed: ' . $message);
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $responseContentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);

    $decoded = json_decode($raw, true);
    return [
        'status' => $status,
        'contentType' => $responseContentType,
        'raw' => $raw,
        'body' => json_last_error() === JSON_ERROR_NONE ? $decoded : $raw,
        'headers' => $responseHeaders,
    ];
}

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $message): void
{
    global $passed;
    $passed++;
    echo "[PASS] {$message}\n";
}

function serverIsReady(string $baseUrl): bool
{
    try {
        request($baseUrl, 'GET', '/__smoke_ready__');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function findFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException("Unable to select a test port: {$errorMessage}", $errorCode);
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if ($address === false || !str_contains($address, ':')) {
        throw new RuntimeException('Unable to read the selected test port.');
    }

    return (int) substr($address, strrpos($address, ':') + 1);
}

try {
    if (!serverIsReady($baseUrl)) {
        $url = parse_url($baseUrl);
        if (($url['host'] ?? '') !== '127.0.0.1' || !isset($url['port'])) {
            throw new RuntimeException('Automatic server start requires a 127.0.0.1 URL with a port.');
        }

        $serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $url['port'], '-t', 'public', 'dev-server.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $serverLog = tempnam(sys_get_temp_dir(), 'php-mvc-test-'), 'a'],
                2 => ['file', $serverLog, 'a'],
            ],
            $serverPipes,
            $root
        );
        if (!is_resource($serverProcess)) {
            throw new RuntimeException('Unable to start the PHP development server.');
        }
        fclose($serverPipes[0]);

        for ($attempt = 0; $attempt < 50 && !serverIsReady($baseUrl); $attempt++) {
            usleep(100_000);
        }
        if (!serverIsReady($baseUrl)) {
            throw new RuntimeException('The PHP development server did not become ready.');
        }
    }

    $database = $config['DATABASE'];
    $pdo = new PDO(
        'mysql:host=' . $database['HOST'] . ';port=' . ($database['PORT'] ?? 3306)
            . ';dbname=' . $database['DBNAME'] . ';charset=utf8mb4',
        (string) $database['USERNAME'],
        (string) $database['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );

    foreach (['/.sample.ini', '/.git/config', '/index.php', '/static.php', '/dev-server.php', '/Controller/Router.php',
        '/Variety/assets/js/sample.js', '/modules/moment/dist/moment.js', '/images/../css/sample.css',
        '/images/%2e%2e/%2e%2e/.sample.ini', '/images/%00.png', '/js/.hidden.js'] as $protectedPath) {
        $response = request($baseUrl, 'GET', $protectedPath);
        verify($response['status'] === 404, "{$protectedPath} must return 404.");
    }
    pass('protected files are not served');

    $response = request($baseUrl, 'GET', '/');
    verify($response['status'] === 200 && str_contains($response['raw'], 'id="foo-form"'), 'Root must serve the usable sample view.');
    verify(str_starts_with($response['contentType'], 'text/html'), 'View must have HTML content type.');
    verify(($response['headers']['x-content-type-options'] ?? '') === 'nosniff', 'nosniff header is missing.');
    verify(str_contains($response['headers']['content-security-policy'] ?? '', "frame-ancestors 'none'"), 'CSP is missing.');
    $response = request($baseUrl, 'GET', '/js/sample.js');
    verify($response['status'] === 200 && str_contains($response['contentType'], 'javascript'), 'Sample JavaScript must be served.');
    $response = request($baseUrl, 'HEAD', '/');
    verify($response['status'] === 200 && $response['raw'] === '', 'HEAD view must not return a body.');
    $response = request($baseUrl, 'HEAD', '/css/sample.css');
    verify($response['status'] === 200 && $response['raw'] === '', 'HEAD asset must not return a body.');
    $response = request($baseUrl, 'OPTIONS', '/foo');
    verify($response['status'] === 204 && str_contains($response['headers']['allow'] ?? '', 'POST'), 'OPTIONS must advertise allowed methods.');
    $response = request($baseUrl, 'PATCH', '/foo');
    verify($response['status'] === 405 && str_contains($response['headers']['allow'] ?? '', 'GET'), 'Wrong method must return 405 and Allow.');
    $response = request($baseUrl, 'GET', '/sample/sample/createFoo');
    verify($response['status'] === 404, 'Dynamic controller URLs must be disabled.');
    pass('views, static assets, security headers, HEAD, OPTIONS and 405 work');

    $response = request($baseUrl, 'POST', '/foo', ['message' => 'blocked'], 'application/json', false);
    verify($response['status'] === 403, 'A missing CSRF token must be rejected.');
    $response = request($baseUrl, 'GET', '/csrf-token');
    $csrfToken = $response['body']['token'] ?? '';
    verify(strlen($csrfToken) === 64 && $requestCookie !== '', 'Token endpoint must initialize a session.');
    verify(str_contains($response['headers']['cache-control'] ?? '', 'no-store'), 'Token response must not be cached.');
    $response = request($baseUrl, 'POST', '/foo', ['message' => 'blocked'], 'application/json', false, ['X-CSRF-Token: invalid']);
    verify($response['status'] === 403, 'An invalid CSRF token must be rejected.');
    $response = request($baseUrl, 'POST', '/foo', ['message' => 'blocked'], 'application/json', true, ['Sec-Fetch-Site: cross-site']);
    verify($response['status'] === 403, 'Cross-site mutations must be rejected.');
    pass('missing, invalid and cross-site CSRF requests are rejected');

    foreach ([['message' => []], ['message' => 123], ['message' => true], ['message' => str_repeat('x', 151)]] as $body) {
        $response = request($baseUrl, 'POST', '/foo', $body, 'application/json');
        verify($response['status'] === 400, 'Invalid message must return 400.');
    }
    $response = request($baseUrl, 'POST', '/foo', ['_method' => ['DELETE']], 'application/x-www-form-urlencoded');
    verify($response['status'] === 400, 'Array method override must return 400.');
    $response = request($baseUrl, 'POST', '/foo', '[]', 'application/json');
    verify($response['status'] === 400, 'JSON arrays must not be accepted as request objects.');
    $response = request($baseUrl, 'POST', '/foo', 'message=text', 'text/plain');
    verify($response['status'] === 415, 'Unsupported body content type must return 415.');
    $response = request($baseUrl, 'POST', '/foo', str_repeat('x', (int) ($config['APP']['MAX_BODY_BYTES'] ?? 1048576) + 1), 'application/json');
    verify($response['status'] === 413, 'Oversized body must return 413.');
    pass('input types, method override, content type and body size are validated');

    $response = request($baseUrl, 'POST', '/foo', '{}', 'application/json');
    verify(
        $response['status'] === 400,
        "Missing message must return 400; got {$response['status']} with {$response['raw']}"
    );
    pass('missing required input returns 400');

    $response = request($baseUrl, 'PUT', '/foo/1', '{', 'application/json');
    verify($response['status'] === 400, 'Malformed JSON must return 400.');
    pass('malformed JSON returns 400');

    $response = request($baseUrl, 'GET', '/foo/not-a-number');
    verify($response['status'] === 400, 'Invalid route id must return 400.');
    pass('invalid route id returns 400');

    $marker = 'smoke-' . bin2hex(random_bytes(5));
    $message = '<MVC & "' . $marker . '">';
    $response = request($baseUrl, 'POST', '/foo', ['message' => $message], 'application/json');
    verify($response['status'] === 200, 'POST /foo must return 200.');
    verify(is_string($response['body']) && ctype_digit($response['body']), 'POST /foo must return an id.');
    $fooId = (int) $response['body'];
    $createdFooIds[] = $fooId;
    pass('foo is created from a JSON request');

    $response = request($baseUrl, 'GET', "/foo/{$fooId}/");
    verify($response['status'] === 200, 'GET /foo/{id} must return 200.');
    verify(($response['body'][0]['message'] ?? null) === $message, 'Stored text must remain unencoded.');
    verify(str_starts_with(strtolower($response['contentType']), 'application/json'), 'API must return JSON content type.');
    pass('foo is read with its original text and JSON content type');

    $updatedMessage = 'updated-' . $marker;
    $response = request($baseUrl, 'PUT', "/foo/{$fooId}", ['message' => $updatedMessage], 'application/json');
    verify($response['status'] === 200 && $response['body'] === 1, 'PUT /foo/{id} must update one row.');
    pass('foo is updated from a JSON request');

    $response = request($baseUrl, 'POST', "/foo/{$fooId}", [
        '_method' => 'PUT', '_csrf' => $csrfToken, 'message' => $updatedMessage . '-form',
    ], 'application/x-www-form-urlencoded', false);
    verify($response['status'] === 200 && $response['body'] === 1, 'Form override with hidden CSRF token must update one row.');
    pass('form method override and hidden CSRF token work');

    $barComment = 'bar-' . $marker;
    $response = request(
        $baseUrl,
        'POST',
        '/bar',
        ['fooId' => $fooId, 'comment' => $barComment],
        'application/x-www-form-urlencoded'
    );
    verify($response['status'] === 200 && $response['body'] === 1, 'POST /bar must create one row.');

    $response = request($baseUrl, 'GET', '/bar');
    $bar = null;
    foreach ($response['body'] as $row) {
        if (($row['comment'] ?? null) === $barComment) {
            $bar = $row;
            break;
        }
    }
    verify(is_array($bar), 'Created bar must be returned by GET /bar.');
    $barId = (int) $bar['id'];
    $createdBarIds[] = $barId;
    pass('bar is created from a form request');

    $response = request($baseUrl, 'GET', "/foobar/{$fooId}");
    verify(($response['body'][0]['id'] ?? null) === $barId, 'GET /foobar/{fooId} must return the related bar.');
    pass('foo and bar relationship is returned');

    $response = request($baseUrl, 'DELETE', "/foo/{$fooId}");
    verify($response['status'] === 200 && $response['body'] === 1, 'DELETE /foo/{id} must delete one row.');
    $fooResponse = request($baseUrl, 'GET', "/foo/{$fooId}");
    $barResponse = request($baseUrl, 'GET', "/bar/{$barId}");
    verify($fooResponse['body'] === [] && $barResponse['body'] === [], 'Deleting foo must hide its related bar.');
    pass('foo soft delete cascades to bar');

    $response = request(
        $baseUrl,
        'POST',
        '/bar',
        ['fooId' => $fooId, 'comment' => 'invalid-' . $marker],
        'application/x-www-form-urlencoded'
    );
    verify($response['status'] === 404, 'A deleted foo must not accept a new bar.');
    pass('deleted foo rejects new bar records');

    $limited = (new QueryBuilder($pdo))->table('foo')->select('*')->limit(0)->exec();
    $offset = (new QueryBuilder($pdo))->table('foo')->select('*')->offset(1)->exec();
    verify($limited === [], 'limit(0) must return no rows.');
    verify(is_array($offset), 'offset() without limit() must produce valid MySQL.');
    pass('QueryBuilder LIMIT/OFFSET boundaries work');

    require __DIR__ . '/query-builder.php';
    require __DIR__ . '/production.php';

    echo "\n{$passed} smoke checks passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "[FAIL] {$exception->getMessage()}\n");
    $exitCode = 1;
} finally {
    try {
        if (isset($pdo)) {
            if ($createdBarIds !== []) {
                $placeholders = implode(',', array_fill(0, count($createdBarIds), '?'));
                $pdo->prepare("DELETE FROM bar WHERE id IN ({$placeholders})")->execute($createdBarIds);
            }
            if ($createdFooIds !== []) {
                $placeholders = implode(',', array_fill(0, count($createdFooIds), '?'));
                $pdo->prepare("DELETE FROM bar WHERE foo_id IN ({$placeholders})")->execute($createdFooIds);
                $pdo->prepare("DELETE FROM foo WHERE id IN ({$placeholders})")->execute($createdFooIds);
            }
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, '[FAIL] Test cleanup: ' . $cleanupError->getMessage() . "\n");
        $exitCode = 1;
    }

    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        foreach ($serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($serverProcess);
    }
    if (isset($serverLog) && is_file($serverLog)) {
        if (isset($exitCode)) { fwrite(STDERR, file_get_contents($serverLog)); }
        unlink($serverLog);
    }
}

exit($exitCode ?? 0);
