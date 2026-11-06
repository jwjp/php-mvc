<?php
$field = static function (string $name) use ($errors, $e): string {
    return isset($errors[$name]) ? 'aria-invalid="true" aria-describedby="' . $e($name) . '-error"' : '';
};
$error = static function (string $name) use ($errors, $e): string {
    return isset($errors[$name]) ? '<p class="field-error" id="' . $e($name) . '-error">' . $e($errors[$name]) . '</p>' : '';
};
?>
<h1>새 비밀번호</h1>
<form class="stack-form narrow" method="post" action="/password/reset" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="token" value="<?= $e($token) ?>">

    <label for="password">새 비밀번호</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" <?= $field('password') ?>>
    <?= $error('password') ?>
    <p class="hint">8자 이상. 바꾸면 모든 기기에서 로그아웃됩니다.</p>

    <label for="passwordConfirm">새 비밀번호 확인</label>
    <input id="passwordConfirm" name="passwordConfirm" type="password" autocomplete="new-password" required <?= $field('passwordConfirm') ?>>
    <?= $error('passwordConfirm') ?>

    <div class="form-actions">
        <button type="submit">비밀번호 변경</button>
    </div>
</form>
