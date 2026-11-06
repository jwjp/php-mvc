<?php
$field = static function (string $name) use ($errors, $e): string {
    return isset($errors[$name]) ? 'aria-invalid="true" aria-describedby="' . $e($name) . '-error"' : '';
};
$error = static function (string $name) use ($errors, $e): string {
    return isset($errors[$name]) ? '<p class="field-error" id="' . $e($name) . '-error">' . $e($errors[$name]) . '</p>' : '';
};
?>
<h1>내 정보</h1>
<dl class="account-info">
    <dt>아이디</dt>
    <dd><?= $e($currentUser['login_id']) ?></dd>
    <dt>이름</dt>
    <dd><?= $e($currentUser['display_name']) ?></dd>
    <dt>이메일</dt>
    <dd>
        <?= $e($currentUser['email']) ?>
        <?php if ($currentUser['email_verified_at'] !== null): ?>
            <span class="badge">인증됨</span>
        <?php else: ?>
            <span class="badge warn">인증 전</span>
            <form method="post" action="/account/verify-email" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <button type="submit" class="link">인증 메일 다시 보내기</button>
            </form>
        <?php endif ?>
    </dd>
</dl>

<h2 class="section-title">비밀번호 변경</h2>
<form class="stack-form narrow" method="post" action="/account/password" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">

    <label for="currentPassword">현재 비밀번호</label>
    <input id="currentPassword" name="currentPassword" type="password" autocomplete="current-password" required <?= $field('currentPassword') ?>>
    <?= $error('currentPassword') ?>

    <label for="password">새 비밀번호</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" <?= $field('password') ?>>
    <?= $error('password') ?>
    <p class="hint">8자 이상. 바꾸면 다른 기기에서는 로그아웃됩니다.</p>

    <label for="passwordConfirm">새 비밀번호 확인</label>
    <input id="passwordConfirm" name="passwordConfirm" type="password" autocomplete="new-password" required <?= $field('passwordConfirm') ?>>
    <?= $error('passwordConfirm') ?>

    <div class="form-actions">
        <button type="submit">비밀번호 변경</button>
    </div>
</form>
