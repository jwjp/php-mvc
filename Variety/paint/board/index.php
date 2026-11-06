<div class="page-head">
    <h1>게시판 <span class="muted"><?= $e(number_format($total)) ?></span></h1>
    <?php if ($currentUser !== null && $currentUser['email_verified_at'] !== null): ?>
        <a class="button" href="/posts/new">글쓰기</a>
    <?php endif ?>
</div>

<?php if ($posts === []): ?>
    <p class="empty">
        <?= $page > 1 ? '이 페이지에는 글이 없습니다.' : '아직 글이 없습니다.' ?>
        <?php if ($currentUser === null && $page === 1): ?>
            <a href="/signup">가입</a>하고 첫 글을 남겨 보세요.
        <?php endif ?>
    </p>
<?php else: ?>
    <ol class="post-list">
        <?php foreach ($posts as $post): ?>
            <li>
                <a class="post-title" href="/posts/<?= $e($post['id']) ?>"><?= $e($post['title']) ?></a>
                <?php if ($post['comment_count'] > 0): ?>
                    <span class="comment-count" title="댓글 <?= $e($post['comment_count']) ?>개">[<?= $e($post['comment_count']) ?>]</span>
                <?php endif ?>
                <span class="post-meta"><?= $e($post['author']) ?> · <time datetime="<?= $e($post['created_at']) ?>"><?= $e(substr($post['created_at'], 0, 16)) ?></time></span>
            </li>
        <?php endforeach ?>
    </ol>
<?php endif ?>

<?php if ($lastPage > 1 || $page > 1): ?>
    <nav class="pager" aria-label="페이지">
        <?php if ($page > 1): ?>
            <a href="/?page=<?= $e(min($page - 1, $lastPage)) ?>" rel="prev">이전</a>
        <?php endif ?>
        <span><?= $e($page) ?> / <?= $e($lastPage) ?></span>
        <?php if ($page < $lastPage): ?>
            <a href="/?page=<?= $e($page + 1) ?>" rel="next">다음</a>
        <?php endif ?>
    </nav>
<?php endif ?>
