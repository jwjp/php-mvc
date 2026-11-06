<?php

declare(strict_types=1);

// smoke.php와 board.php가 함께 쓰는 HTTP 테스트 도구입니다.

$root = dirname(__DIR__, 2);
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

/** 테스트 서버가 응답하지 않으면 PHP 내장 서버를 시작합니다. */
function startServer(): void
{
    global $baseUrl, $root, $serverProcess, $serverPipes, $serverLog;
    if (serverIsReady($baseUrl)) {
        return;
    }
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

/** 테스트 데이터 확인과 정리에 쓰는 DB 연결입니다. */
function connectDatabase(): PDO
{
    global $config;
    $database = $config['DATABASE'];
    return new PDO(
        'mysql:host=' . $database['HOST'] . ';port=' . ($database['PORT'] ?? 3306)
            . ';dbname=' . $database['DBNAME'] . ';charset=utf8mb4',
        (string) $database['USERNAME'],
        (string) $database['PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

/** startServer()가 띄운 서버를 종료합니다. 테스트가 실패했으면 서버 로그를 출력합니다. */
function stopServer(bool $failed): void
{
    global $serverProcess, $serverPipes, $serverLog;
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
        if ($failed) { fwrite(STDERR, file_get_contents($serverLog)); }
        unlink($serverLog);
    }
}
