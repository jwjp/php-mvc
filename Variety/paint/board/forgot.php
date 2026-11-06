<h1>비밀번호 찾기</h1>
<p class="lead">가입할 때 쓴 이메일을 입력하세요. 인증한 이메일이면 비밀번호 재설정 링크를 보냅니다.</p>
<form class="stack-form narrow" method="post" action="/password/forgot" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">

    <label for="email">이메일</label>
    <input id="email" name="email" type="email" autocomplete="email" required value="<?= $e($email) ?>"
        <?php if (isset($errors['email'])): ?>aria-invalid="true" aria-describedby="email-error"<?php endif ?>>
    <?php if (isset($errors['email'])): ?><p class="field-error" id="email-error"><?= $e($errors['email']) ?></p><?php endif ?>

    <div class="form-actions">
        <a href="/login">로그인</a>
        <button type="submit">재설정 링크 받기</button>
    </div>
</form>
