<?php
$field = static function (string $name) use ($errors, $e): string {
    return isset($errors[$name]) ? 'aria-invalid="true" aria-describedby="' . $e($name) . '-error"' : '';
};
$error = static function (string $name) use ($errors, $e): string {
    return isset($errors[$name]) ? '<p class="field-error" id="' . $e($name) . '-error">' . $e($errors[$name]) . '</p>' : '';
};
?>
<h1>회원 가입</h1>
<form class="stack-form narrow" method="post" action="/signup" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">

    <label for="loginId">아이디</label>
    <input id="loginId" name="loginId" autocomplete="username" required maxlength="30" value="<?= $e($values['loginId']) ?>" <?= $field('loginId') ?>>
    <?= $error('loginId') ?>
    <p class="hint">영문 소문자, 숫자, 밑줄 4~30자</p>

    <label for="displayName">이름</label>
    <input id="displayName" name="displayName" autocomplete="nickname" required maxlength="30" value="<?= $e($values['displayName']) ?>" <?= $field('displayName') ?>>
    <?= $error('displayName') ?>

    <label for="password">비밀번호</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="8" <?= $field('password') ?>>
    <?= $error('password') ?>
    <p class="hint">8자 이상</p>

    <label for="passwordConfirm">비밀번호 확인</label>
    <input id="passwordConfirm" name="passwordConfirm" type="password" autocomplete="new-password" required <?= $field('passwordConfirm') ?>>
    <?= $error('passwordConfirm') ?>

    <div class="form-actions">
        <a href="/login">이미 회원인가요?</a>
        <button type="submit">가입</button>
    </div>
</form>
