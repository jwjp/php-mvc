<!doctype html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $e($title) ?> · PHP MVC 게시판</title>
    <link rel="stylesheet" href="/css/board.css?v=2">
    <script type="module" src="/js/board.js?v=1"></script>
</head>
<body>
<header class="site-header">
    <div class="site-inner">
        <a class="site-name" href="/">PHP MVC 게시판</a>
        <nav class="site-nav" aria-label="계정">
            <?php if ($currentUser !== null): ?>
                <a class="site-user" href="/account"><?= $e($currentUser['display_name']) ?></a>
                <form method="post" action="/logout">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <button type="submit" class="link">로그아웃</button>
                </form>
            <?php else: ?>
                <a href="/login">로그인</a>
                <a class="button" href="/signup">회원 가입</a>
            <?php endif ?>
        </nav>
    </div>
</header>
<main class="site-inner site-main">
<?php if ($flash !== null): ?>
    <p class="flash" role="status"><?= $e($flash) ?></p>
<?php endif ?>
<?php if ($currentUser !== null && $currentUser['email_verified_at'] === null): ?>
    <p class="notice">이메일 인증을 마치면 글과 댓글을 쓸 수 있습니다. 메일을 받지 못했다면 <a href="/account">내 정보</a>에서 다시 보내세요.</p>
<?php endif ?>
