<?php $editing = $post !== null; ?>
<h1><?= $e($title) ?></h1>
<form class="stack-form" method="post" action="<?= $editing ? '/posts/' . $e($post['id']) : '/posts' ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <?php if ($editing): ?><input type="hidden" name="_method" value="PUT"><?php endif ?>

    <label for="post-title">제목</label>
    <input id="post-title" name="title" maxlength="100" required value="<?= $e($values['title']) ?>"
        <?php if (isset($errors['title'])): ?>aria-invalid="true" aria-describedby="post-title-error"<?php endif ?>>
    <?php if (isset($errors['title'])): ?><p class="field-error" id="post-title-error"><?= $e($errors['title']) ?></p><?php endif ?>

    <label for="post-body">본문</label>
    <textarea id="post-body" name="body" rows="14" maxlength="5000" required
        <?php if (isset($errors['body'])): ?>aria-invalid="true" aria-describedby="post-body-error"<?php endif ?>><?= $e($values['body']) ?></textarea>
    <?php if (isset($errors['body'])): ?><p class="field-error" id="post-body-error"><?= $e($errors['body']) ?></p><?php endif ?>

    <div class="form-actions">
        <a href="<?= $editing ? '/posts/' . $e($post['id']) : '/' ?>">취소</a>
        <button type="submit"><?= $editing ? '저장' : '등록' ?></button>
    </div>
</form>
