<?php

declare(strict_types=1);

// 게시판 예제의 HTTP 테스트입니다. 실제 서버와 MySQL로 가입, 이메일 인증, 로그인 제한,
// 비밀번호 재설정·변경, 글·댓글, 권한을 확인합니다. MAIL.TRANSPORT = log 설정이 필요합니다.

require __DIR__ . '/support/http.php';

$createdUserIds = [];

/** 브라우저처럼 HTML을 요청합니다. 본문은 form 형식으로 보냅니다. */
function page(string $method, string $path, ?array $form = null): array
{
    global $baseUrl;
    return request($baseUrl, $method, $path, $form, $form === null ? null : 'application/x-www-form-urlencoded', true, ['Accept: text/html']);
}

/** 새 세션을 열고 CSRF 토큰을 받습니다. 다른 회원으로 바꿀 때 사용합니다. */
function freshSession(): void
{
    global $baseUrl, $requestCookie, $csrfToken;
    $requestCookie = $csrfToken = '';
    refreshToken();
}

/** 로그인·로그아웃으로 세션이 바뀐 뒤 새 CSRF 토큰을 받습니다. */
function refreshToken(): void
{
    global $baseUrl, $csrfToken;
    $csrfToken = request($baseUrl, 'GET', '/csrf-token')['body']['token'] ?? '';
    verify(strlen($csrfToken) === 64, 'A CSRF token must be issued.');
}

/** 메일 로그 파일 경로입니다. 이 테스트는 MAIL.TRANSPORT = log 설정이 필요합니다. */
function mailLogPath(): string
{
    global $config, $root;
    verify(($config['MAIL']['TRANSPORT'] ?? 'log') === 'log', 'Board tests require MAIL.TRANSPORT = log.');
    $path = $config['MAIL']['LOG_PATH'] ?? 'mail.log';
    return preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) ? $path : $root . '/' . $path;
}

/** 받는 사람에게 마지막으로 보낸 메일에서 링크 경로(/...?token=...)를 꺼냅니다. */
function lastMailLink(string $to, string $path): ?string
{
    $log = is_file(mailLogPath()) ? (string) file_get_contents(mailLogPath()) : '';
    $found = null;
    foreach (explode("\n=== ", "\n" . $log) as $message) {
        if (str_contains($message, "\nTo: {$to}\n")
            && preg_match('#https?://[^/\s]+(' . preg_quote($path, '#') . '\?token=[a-f0-9]{64})#', $message, $match)) {
            $found = $match[1];
        }
    }
    return $found;
}

function mailCount(string $to): int
{
    $log = is_file(mailLogPath()) ? (string) file_get_contents(mailLogPath()) : '';
    return substr_count($log, "\nTo: {$to}\n");
}

function signup(string $loginId, string $password): int
{
    global $pdo, $createdUserIds, $requestCookie;
    $before = $requestCookie;
    $response = page('POST', '/signup', [
        'loginId' => $loginId, 'displayName' => 'Tester ' . $loginId, 'email' => $loginId . '@example.test',
        'password' => $password, 'passwordConfirm' => $password,
    ]);
    verify($response['status'] === 303 && ($response['headers']['location'] ?? '') === '/', 'Signup must redirect to the list.');
    verify($requestCookie !== $before, 'Signup must issue a new session ID.');
    $statement = $pdo->prepare('SELECT id FROM users WHERE login_id = ?');
    $statement->execute([$loginId]);
    $id = (int) $statement->fetchColumn();
    verify($id > 0, 'Signup must create the user.');
    $createdUserIds[] = $id;
    refreshToken();
    return $id;
}

function createdId(array $response, string $pattern): int
{
    verify($response['status'] === 303, 'Expected a 303 redirect, got ' . $response['status'] . '.');
    verify(preg_match($pattern, $response['headers']['location'] ?? '', $match) === 1, 'Unexpected redirect target.');
    return (int) $match[1];
}

try {
    startServer();
    $pdo = connectDatabase();
    $startedAt = (string) $pdo->query('SELECT NOW() - INTERVAL 1 SECOND')->fetchColumn();
    $suffix = bin2hex(random_bytes(4));
    $password = 'pw-' . bin2hex(random_bytes(8));
    mailLogPath();

    // 로그인 전
    freshSession();
    $response = page('GET', '/');
    verify($response['status'] === 200 && str_contains($response['raw'], 'href="/signup"'), 'The list must be public.');
    verify(str_contains($response['headers']['content-security-policy'] ?? '', "default-src 'self'"), 'CSP must cover board pages.');
    $response = page('GET', '/posts/new');
    verify($response['status'] === 303 && $response['headers']['location'] === '/login', 'Guests must be sent to the login page.');
    $response = page('GET', '/login');
    verify(str_contains($response['raw'], '로그인이 필요합니다.'), 'The login page must explain the redirect.');
    $response = request($baseUrl, 'POST', '/posts', ['title' => 't', 'body' => 'b'], 'application/json');
    verify($response['status'] === 401 && ($response['body']['code'] ?? 0) === 401, 'API clients must receive a 401 JSON error.');
    pass('guests can read but are redirected to log in before writing');

    // 가입 검증과 가입
    $response = page('POST', '/signup', ['loginId' => 'x', 'displayName' => '', 'email' => 'not-mail', 'password' => 'short', 'passwordConfirm' => 'short']);
    verify($response['status'] === 422, 'Invalid signup must return 422.');
    verify(substr_count($response['raw'], 'class="field-error"') === 4, 'Each invalid signup field must show an error.');
    $ownerLogin = 'owner_' . $suffix;
    $ownerId = signup($ownerLogin, $password);
    $response = page('GET', '/');
    verify(str_contains($response['raw'], 'Tester ' . $ownerLogin) && str_contains($response['raw'], '가입을 마쳤습니다'), 'Signed-in users must see their name and the flash message.');
    $response = page('GET', '/');
    verify(!str_contains($response['raw'], '가입을 마쳤습니다'), 'Flash messages must be shown once.');
    pass('signup validates input, starts a new session and shows a one-time message');

    // 이메일 인증
    $ownerEmail = $ownerLogin . '@example.test';
    $response = page('GET', '/posts/new');
    verify($response['status'] === 403 && str_contains($response['raw'], '이메일 인증을 마친 뒤'), 'Unverified users must not write posts.');
    page('POST', '/account/verify-email', []);
    verify(str_contains(page('GET', '/account')['raw'], '1분에 한 번'), 'Verification mail must not be resent within a minute.');
    verify(mailCount($ownerEmail) === 1, 'Signup must send exactly one verification mail.');
    $link = lastMailLink($ownerEmail, '/verify-email');
    verify($link !== null, 'The verification mail must contain a link.');
    $token = substr($link, strpos($link, '=') + 1);
    $statement = $pdo->prepare('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ? AND token_hash = ?');
    $statement->execute([$ownerId, hash('sha256', $token)]);
    verify((int) $statement->fetchColumn() === 1, 'Only the token hash must be stored.');
    $response = page('GET', $link);
    verify($response['status'] === 303 && $response['headers']['location'] === '/', 'Verification must redirect away from the token URL.');
    verify(page('GET', $link)['status'] === 400, 'Verification links must work once.');
    verify(page('GET', '/verify-email?token=' . str_repeat('a', 64))['status'] === 400, 'Unknown tokens must fail.');
    verify(page('GET', '/posts/new')['status'] === 200, 'Verified users must be able to write.');
    verify(str_contains(page('GET', '/account')['raw'], '인증됨'), 'The account page must show the verified state.');
    pass('verification links are hashed, work once and unlock writing');

    // 글 작성과 escape
    $response = page('POST', '/posts', ['title' => '   ', 'body' => 'body']);
    verify($response['status'] === 422 && str_contains($response['raw'], '제목을(를) 입력하세요.'), 'Empty titles must be rejected.');
    verify(str_contains($response['raw'], '>body</textarea>'), 'Rejected forms must keep the entered body.');
    $title = '<script>alert(1)</script> 첫 글';
    $postId = createdId(page('POST', '/posts', ['title' => $title, 'body' => "첫 줄\r\n<b>둘째 줄</b>"]), '#^/posts/(\d+)$#');
    $response = page('GET', '/posts/' . $postId);
    verify($response['status'] === 200 && !str_contains($response['raw'], '<script>alert(1)</script>'), 'Post titles must be escaped.');
    verify(str_contains($response['raw'], '&lt;script&gt;alert(1)&lt;/script&gt; 첫 글'), 'The escaped title must be shown.');
    verify(str_contains($response['raw'], "첫 줄<br>\n&lt;b&gt;둘째 줄&lt;/b&gt;"), 'Body line breaks must be kept and HTML escaped.');
    verify(str_contains($response['raw'], '/posts/' . $postId . '/edit'), 'Owners must see the edit link.');
    pass('posts are validated, created and rendered with escaped text');

    // 댓글
    $response = page('POST', '/posts/' . $postId . '/comments', ['body' => '']);
    verify($response['status'] === 422 && str_contains($response['raw'], '댓글을(를) 입력하세요.'), 'Empty comments must be rejected.');
    $commentId = createdId(page('POST', '/posts/' . $postId . '/comments', ['body' => '첫 댓글']), '#^/posts/' . $postId . '\#comment-(\d+)$#');
    $response = page('GET', '/posts/' . $postId);
    verify(str_contains($response['raw'], 'id="comment-' . $commentId . '"') && str_contains($response['raw'], '첫 댓글'), 'The comment must be shown.');
    $response = page('POST', '/posts/999999999/comments', ['body' => '없는 글']);
    verify($response['status'] === 404 && str_contains($response['raw'], '요청한 글을 찾을 수 없습니다.'), 'Comments on missing posts must return an HTML 404.');
    pass('comments are validated and attached to existing posts');

    // 다른 회원의 권한
    $response = page('POST', '/logout', []);
    verify($response['status'] === 303 && $response['headers']['location'] === '/', 'Logout must redirect to the list.');
    refreshToken();
    $otherLogin = 'other_' . $suffix;
    $otherId = signup($otherLogin, $password);
    verify(page('POST', '/posts/' . $postId . '/comments', ['body' => 'x'])['status'] === 403, 'Unverified users must not comment.');
    $response = page('GET', '/posts/' . $postId);
    verify(!str_contains($response['raw'], '/posts/' . $postId . '/edit'), 'Other users must not see the edit link.');
    foreach ([
        ['GET', '/posts/' . $postId . '/edit', null],
        ['POST', '/posts/' . $postId, ['_method' => 'PUT', 'title' => 'x', 'body' => 'y']],
        ['POST', '/posts/' . $postId, ['_method' => 'DELETE']],
        ['POST', '/comments/' . $commentId, ['_method' => 'DELETE']],
    ] as [$method, $path, $form]) {
        $response = page($method, $path, $form);
        verify($response['status'] === 403 && str_contains($response['raw'], '작성자만'), "{$method} {$path} must be forbidden for other users.");
    }
    $statement = $pdo->prepare('SELECT title FROM posts WHERE id = ? AND deleted_at IS NULL');
    $statement->execute([$postId]);
    verify($statement->fetchColumn() === $title, 'Forbidden requests must not change the post.');
    pass('only the author can edit or delete a post or comment');

    // 로그인 실패, 원래 화면으로 복귀
    page('POST', '/logout', []);
    refreshToken();
    page('GET', '/posts/' . $postId . '/edit');
    $response = page('POST', '/login', ['loginId' => $ownerLogin, 'password' => 'wrong-password']);
    verify($response['status'] === 422 && str_contains($response['raw'], '아이디 또는 비밀번호가 올바르지 않습니다.'), 'Wrong passwords must fail.');
    $response = page('POST', '/login', ['loginId' => 'missing_' . $suffix, 'password' => $password]);
    verify($response['status'] === 422 && str_contains($response['raw'], '아이디 또는 비밀번호가 올바르지 않습니다.'), 'Unknown IDs must show the same message.');
    $before = $requestCookie;
    $response = page('POST', '/login', ['loginId' => strtoupper($ownerLogin), 'password' => $password]);
    verify($response['status'] === 303 && $response['headers']['location'] === '/posts/' . $postId . '/edit', 'Login must return to the requested page.');
    verify($requestCookie !== $before, 'Login must issue a new session ID.');
    $response = page('POST', '/posts', ['title' => 'stale token', 'body' => 'x']);
    verify($response['status'] === 403, 'The CSRF token from before login must stop working.');
    refreshToken();
    pass('login hides which field was wrong, rotates the session and returns to the requested page');

    // 수정과 삭제
    $response = page('POST', '/posts/' . $postId, ['_method' => 'PUT', 'title' => '수정한 제목', 'body' => '수정한 본문']);
    verify($response['status'] === 303 && $response['headers']['location'] === '/posts/' . $postId, 'Update must redirect to the post.');
    $response = page('GET', '/posts/' . $postId);
    verify(str_contains($response['raw'], '수정한 제목') && str_contains($response['raw'], '수정됨'), 'The updated post must be shown.');
    $response = page('POST', '/posts/' . $postId, ['_method' => 'DELETE']);
    verify($response['status'] === 303 && $response['headers']['location'] === '/', 'Delete must redirect to the list.');
    $response = page('GET', '/posts/' . $postId);
    verify($response['status'] === 404 && str_contains($response['headers']['content-type'] ?? $response['contentType'], 'text/html'), 'Deleted posts must return an HTML 404.');
    $statement = $pdo->prepare('SELECT deleted_at IS NOT NULL FROM comments WHERE id = ?');
    $statement->execute([$commentId]);
    verify((int) $statement->fetchColumn() === 1, 'Deleting a post must soft delete its comments.');
    pass('authors can update and delete posts, and comments are deleted with the post');

    // 목록과 페이지
    $insert = $pdo->prepare('INSERT INTO posts (user_id, title, body) VALUES (?, ?, ?)');
    for ($i = 1; $i <= 11; $i++) {
        $insert->execute([$ownerId, "목록 테스트 {$suffix} {$i}", '본문']);
    }
    $latest = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO comments (post_id, user_id, body) VALUES (?, ?, ?), (?, ?, ?)')
        ->execute([$latest, $otherId, 'a', $latest, $otherId, 'b']);
    $pdo->prepare('INSERT INTO comments (post_id, user_id, body, deleted_at) VALUES (?, ?, ?, NOW())')
        ->execute([$latest, $otherId, 'deleted']);
    $response = page('GET', '/');
    verify(substr_count($response['raw'], 'class="post-title"') === 10, 'A page must show ten posts.');
    verify(str_contains($response['raw'], "목록 테스트 {$suffix} 11") && !str_contains($response['raw'], "목록 테스트 {$suffix} 1<"), 'The newest posts must come first.');
    verify(preg_match('#href="/posts/' . $latest . '">[^<]+</a>\s*<span class="comment-count"[^>]*>\[2\]#', $response['raw']) === 1, 'Comment counts must ignore deleted comments.');
    verify(str_contains($response['raw'], 'href="/?page=2" rel="next"'), 'The list must link to the next page.');
    $response = page('GET', '/?page=2');
    verify($response['status'] === 200 && str_contains($response['raw'], 'rel="prev"'), 'The second page must render.');
    foreach (['/?page=0', '/?page=abc', '/?page[]=1'] as $path) {
        verify(page('GET', $path)['status'] === 400, "{$path} must be rejected.");
    }
    pass('the list is paginated, newest first, with comment counts');

    // 로그인 시도 제한
    $ownerDevice = $requestCookie;
    freshSession();
    for ($i = 0; $i < 5; $i++) {
        verify(page('POST', '/login', ['loginId' => $otherLogin, 'password' => 'wrong-' . $i])['status'] === 422, 'Wrong passwords must fail.');
    }
    $response = page('POST', '/login', ['loginId' => strtoupper($otherLogin), 'password' => $password]);
    verify($response['status'] === 429 && str_contains($response['raw'], '로그인 시도가 너무 많습니다.'), 'Five failures must lock the login ID.');
    pass('five wrong passwords lock the login ID, even for the right password');

    // 비밀번호 재설정
    $resetMails = mailCount($ownerEmail);
    $otherMails = mailCount($otherLogin . '@example.test');
    foreach ([strtoupper($ownerEmail), 'missing_' . $suffix . '@example.test', $otherLogin . '@example.test'] as $email) {
        $response = page('POST', '/password/forgot', ['email' => $email]);
        verify($response['status'] === 303 && $response['headers']['location'] === '/login', 'Reset requests must always redirect to login.');
    }
    verify(str_contains(page('GET', '/login')['raw'], '재설정 링크를 보냈습니다'), 'Reset requests must show the same message.');
    verify(mailCount($ownerEmail) === $resetMails + 1, 'A verified address must receive one reset mail.');
    verify(mailCount($otherLogin . '@example.test') === $otherMails, 'Unverified addresses must not receive reset mail.');
    $link = lastMailLink($ownerEmail, '/password/reset');
    verify($link !== null, 'The reset mail must contain a link.');
    $token = substr($link, strpos($link, '=') + 1);
    $response = page('GET', $link);
    verify($response['status'] === 200 && str_contains($response['raw'], 'name="token"'), 'The reset form must open.');
    $response = page('POST', '/password/reset', ['token' => $token, 'password' => 'new-password-1', 'passwordConfirm' => 'different-1']);
    verify($response['status'] === 422, 'Mismatched reset passwords must fail.');
    $newPassword = 'new-' . bin2hex(random_bytes(8));
    $response = page('POST', '/password/reset', ['token' => $token, 'password' => $newPassword, 'passwordConfirm' => $newPassword]);
    verify($response['status'] === 303 && $response['headers']['location'] === '/login', 'Reset must send the user to login.');
    refreshToken();
    verify(page('POST', '/password/reset', ['token' => $token, 'password' => $newPassword, 'passwordConfirm' => $newPassword])['status'] === 400, 'Reset links must work once.');
    verify(page('POST', '/login', ['loginId' => $ownerLogin, 'password' => $password])['status'] === 422, 'The old password must stop working.');
    $resetDevice = $requestCookie;
    $requestCookie = $ownerDevice;
    $response = page('GET', '/account');
    verify($response['status'] === 303 && $response['headers']['location'] === '/login', 'Reset must log out existing sessions.');
    $requestCookie = $resetDevice;
    verify(page('POST', '/login', ['loginId' => $ownerLogin, 'password' => $newPassword])['status'] === 303, 'The new password must work.');
    refreshToken();
    pass('reset links go only to verified addresses, work once and end other sessions');

    // 비밀번호 변경
    $firstDevice = $requestCookie;
    freshSession();
    verify(page('POST', '/login', ['loginId' => $ownerLogin, 'password' => $newPassword])['status'] === 303, 'A second session must log in.');
    refreshToken();
    $response = page('POST', '/account/password', ['currentPassword' => 'wrong-password', 'password' => 'another-pass-1', 'passwordConfirm' => 'another-pass-1']);
    verify($response['status'] === 422 && str_contains($response['raw'], '현재 비밀번호가 올바르지 않습니다.'), 'The current password must be checked.');
    $changedPassword = 'chg-' . bin2hex(random_bytes(8));
    $response = page('POST', '/account/password', ['currentPassword' => $newPassword, 'password' => $changedPassword, 'passwordConfirm' => $changedPassword]);
    verify($response['status'] === 303 && $response['headers']['location'] === '/account', 'Password change must redirect to the account page.');
    verify(str_contains(page('GET', '/account')['raw'], '비밀번호를 바꿨습니다'), 'The current session must stay logged in.');
    $requestCookie = $firstDevice;
    verify(page('GET', '/account')['status'] === 303, 'Other sessions must be logged out after a password change.');
    pass('changing the password checks the current one and ends other sessions');

    echo "\n{$passed} board checks passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "[FAIL] {$exception->getMessage()}\n");
    $exitCode = 1;
} finally {
    try {
        if (isset($pdo, $startedAt, $suffix)) {
            // 이 테스트가 남긴 시도 기록만 지웁니다. 같은 IP의 다른 기록은 그대로 둡니다.
            // RateLimiter와 같은 방식(버킷 . "\0" . 키)으로 해시를 만듭니다.
            $hashes = [hash('sha256', "login-ip\0" . '127.0.0.1'), hash('sha256', "reset-ip\0" . '127.0.0.1')];
            foreach (['owner_', 'other_', 'missing_'] as $prefix) {
                $hashes[] = hash('sha256', "login-id\0" . $prefix . $suffix);
            }
            $placeholders = implode(',', array_fill(0, count($hashes), '?'));
            $pdo->prepare("DELETE FROM rate_limits WHERE created_at >= ? AND key_hash IN ({$placeholders})")
                ->execute([$startedAt, ...$hashes]);
        }
        if (isset($pdo) && $createdUserIds !== []) {
            $placeholders = implode(',', array_fill(0, count($createdUserIds), '?'));
            $pdo->prepare("DELETE c FROM comments c JOIN posts p ON p.id = c.post_id WHERE p.user_id IN ({$placeholders})")->execute($createdUserIds);
            $pdo->prepare("DELETE FROM comments WHERE user_id IN ({$placeholders})")->execute($createdUserIds);
            $pdo->prepare("DELETE FROM posts WHERE user_id IN ({$placeholders})")->execute($createdUserIds);
            $pdo->prepare("DELETE FROM users WHERE id IN ({$placeholders})")->execute($createdUserIds);
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, '[FAIL] Test cleanup: ' . $cleanupError->getMessage() . "\n");
        $exitCode = 1;
    }
    stopServer(isset($exitCode));
}

exit($exitCode ?? 0);
