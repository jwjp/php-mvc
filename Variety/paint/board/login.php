<h1>로그인</h1>
<form class="stack-form narrow" method="post" action="/login" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <?php if (isset($errors['form'])): ?><p class="field-error" role="alert"><?= $e($errors['form']) ?></p><?php endif ?>

    <label for="login-id">아이디</label>
    <input id="login-id" name="loginId" autocomplete="username" required value="<?= $e($loginId) ?>">

    <label for="login-password">비밀번호</label>
    <input id="login-password" name="password" type="password" autocomplete="current-password" required>

    <div class="form-actions">
        <a href="/signup">회원 가입</a>
        <button type="submit">로그인</button>
    </div>
</form>
