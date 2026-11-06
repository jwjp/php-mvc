<?php

declare(strict_types=1);

use Model\QueryBuilder;

require __DIR__ . '/support/http.php';

try {
    startServer();
    $pdo = connectDatabase();

    foreach (['/.sample.ini', '/.git/config', '/index.php', '/static.php', '/dev-server.php', '/Controller/Router.php',
        '/Variety/assets/js/sample.js', '/modules/moment/dist/moment.js', '/images/../css/sample.css',
        '/images/%2e%2e/%2e%2e/.sample.ini', '/images/%00.png', '/js/.hidden.js'] as $protectedPath) {
        $response = request($baseUrl, 'GET', $protectedPath);
        verify($response['status'] === 404, "{$protectedPath} must return 404.");
    }
    pass('protected files are not served');

    $response = request($baseUrl, 'GET', '/sample/view');
    verify($response['status'] === 200 && str_contains($response['raw'], 'id="foo-form"'), 'The foo/bar sample view must be usable.');
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

    stopServer(isset($exitCode));
}

exit($exitCode ?? 0);
