<?php $isOwner = $currentUser !== null && (int) $post['user_id'] === $currentUser['id']; ?>
<article class="post">
    <header class="post-head">
        <h1><?= $e($post['title']) ?></h1>
        <p class="post-meta">
            <?= $e($post['author']) ?> · <time datetime="<?= $e($post['created_at']) ?>"><?= $e(substr($post['created_at'], 0, 16)) ?></time>
            <?php if ($post['updated_at'] !== null): ?> · 수정됨<?php endif ?>
        </p>
    </header>
    <div class="post-body"><?= nl2br($e($post['body']), false) ?></div>
    <footer class="post-actions">
        <a href="/">목록</a>
        <?php if ($isOwner): ?>
            <a href="/posts/<?= $e($post['id']) ?>/edit">수정</a>
            <form method="post" action="/posts/<?= $e($post['id']) ?>" data-confirm="이 글과 댓글을 모두 삭제할까요?">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="link danger">삭제</button>
            </form>
        <?php endif ?>
    </footer>
</article>

<section class="comments" id="comments" aria-labelledby="comments-heading">
    <h2 id="comments-heading">댓글 <span class="muted"><?= $e(count($comments)) ?></span></h2>
    <?php if ($comments !== []): ?>
        <ol class="comment-list">
            <?php foreach ($comments as $comment): ?>
                <li id="comment-<?= $e($comment['id']) ?>">
                    <p class="post-meta">
                        <?= $e($comment['author']) ?> · <time datetime="<?= $e($comment['created_at']) ?>"><?= $e(substr($comment['created_at'], 0, 16)) ?></time>
                    </p>
                    <p class="comment-body"><?= nl2br($e($comment['body']), false) ?></p>
                    <?php if ($currentUser !== null && (int) $comment['user_id'] === $currentUser['id']): ?>
                        <form method="post" action="/comments/<?= $e($comment['id']) ?>" data-confirm="댓글을 삭제할까요?">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="_method" value="DELETE">
                            <button type="submit" class="link danger">삭제</button>
                        </form>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>
    <?php endif ?>

    <?php if ($currentUser !== null): ?>
        <form class="comment-form" method="post" action="/posts/<?= $e($post['id']) ?>/comments" novalidate>
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <label for="comment-body">댓글 쓰기</label>
            <textarea id="comment-body" name="body" rows="3" maxlength="500" required
                <?php if (isset($errors['body'])): ?>aria-invalid="true" aria-describedby="comment-body-error"<?php endif ?>><?= $e($commentBody) ?></textarea>
            <?php if (isset($errors['body'])): ?><p class="field-error" id="comment-body-error"><?= $e($errors['body']) ?></p><?php endif ?>
            <button type="submit">등록</button>
        </form>
    <?php else: ?>
        <p class="empty"><a href="/login">로그인</a>하면 댓글을 쓸 수 있습니다.</p>
    <?php endif ?>
</section>
