<!doctype html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <title><?= htmlspecialchars($title ?? 'PHP MVC', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/css/sample.css?v=2">
    <script type="module" src="/js/sample.js?v=2"></script>
</head>
<body>
