<?php

namespace Model\Board;

use Model\Model;
use Model\QueryBuilder;
use PDO;

/**
 * 게시글과 댓글.
 *
 * 목록과 단순 변경은 QueryBuilder로, 행 잠금이 필요한 쿼리는 PDO로 작성했습니다.
 * 삭제는 deleted_at을 채우는 soft delete입니다.
 */
class PostModel extends Model
{
    public const TITLE_MAX = 100;
    public const BODY_MAX = 5000;
    public const COMMENT_MAX = 500;

    /**
     * @return array<string, string>
     */
    public static function postErrors(mixed &$title, mixed &$body): array
    {
        $errors = [];
        if (($message = Text::check($title, '제목', self::TITLE_MAX)) !== null) {
            $errors['title'] = $message;
        } elseif (str_contains($title, "\n")) {
            $errors['title'] = '제목은 한 줄로 입력하세요.';
        }
        if (($message = Text::check($body, '본문', self::BODY_MAX)) !== null) {
            $errors['body'] = $message;
        }
        return $errors;
    }

    /**
     * @return array<string, string>
     */
    public static function commentErrors(mixed &$body): array
    {
        $message = Text::check($body, '댓글', self::COMMENT_MAX);
        return $message === null ? [] : ['body' => $message];
    }

    /**
     * 최신 글부터 한 페이지를 반환합니다.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function page(int $page, int $perPage): array
    {
        $qb = $this->queryBuilder();
        $total = (int) $qb->table('posts')
            ->select('COUNT(*) AS total')
            ->where('deleted_at', '=', null)
            ->exec()[0]['total'];

        $items = $qb->table('posts p')
            ->select('p.id', 'p.title', 'p.created_at', 'u.display_name AS author')
            ->join('users u', 'INNER', 'u.id', '=', 'p.user_id')
            ->where('p.deleted_at', '=', null)
            ->orderBy('p.id', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->exec();
        if ($items === []) {
            return ['items' => [], 'total' => $total];
        }

        // 글마다 댓글 수를 따로 세지 않고(N+1 쿼리), 이 페이지의 글을 한 번에 집계합니다.
        $counts = [];
        $rows = $qb->table('comments')
            ->select('post_id', 'COUNT(*) AS total')
            ->where('post_id', 'IN', array_map(static fn (array $item): int => (int) $item['id'], $items))
            ->where('deleted_at', '=', null)
            ->groupBy('post_id')
            ->exec();
        foreach ($rows as $row) {
            $counts[(int) $row['post_id']] = (int) $row['total'];
        }
        foreach ($items as &$item) {
            $item['comment_count'] = $counts[(int) $item['id']] ?? 0;
        }
        unset($item);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $rows = $this->queryBuilder()->table('posts p')
            ->select('p.id', 'p.user_id', 'p.title', 'p.body', 'p.created_at', 'p.updated_at', 'u.display_name AS author')
            ->join('users u', 'INNER', 'u.id', '=', 'p.user_id')
            ->where('p.id', '=', $id)
            ->where('p.deleted_at', '=', null)
            ->exec();
        return $rows[0] ?? null;
    }

    public function create(int $userId, string $title, string $body): int
    {
        return (int) $this->queryBuilder()->table('posts')
            ->insert(['user_id' => $userId, 'title' => $title, 'body' => $body])
            ->exec();
    }

    public function update(int $id, string $title, string $body): void
    {
        $this->queryBuilder()->table('posts')
            ->update(['title' => $title, 'body' => $body])
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->exec();
    }

    /**
     * 글과 그 글의 댓글을 한 트랜잭션에서 삭제합니다.
     */
    public function delete(int $id): void
    {
        $this->queryBuilder()->transaction(function (QueryBuilder $qb) use ($id): void {
            $now = date('Y-m-d H:i:s');
            $qb->table('posts')->update(['deleted_at' => $now])
                ->where('id', '=', $id)->where('deleted_at', '=', null)->exec();
            $qb->table('comments')->update(['deleted_at' => $now])
                ->where('post_id', '=', $id)->where('deleted_at', '=', null)->exec();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function comments(int $postId): array
    {
        return $this->queryBuilder()->table('comments c')
            ->select('c.id', 'c.user_id', 'c.body', 'c.created_at', 'u.display_name AS author')
            ->join('users u', 'INNER', 'u.id', '=', 'c.user_id')
            ->where('c.post_id', '=', $postId)
            ->where('c.deleted_at', '=', null)
            ->orderBy('c.id')
            ->exec();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findComment(int $id): ?array
    {
        $rows = $this->queryBuilder()->table('comments')
            ->select('id', 'post_id', 'user_id')
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->exec();
        return $rows[0] ?? null;
    }

    /**
     * 댓글을 추가합니다. 글이 없거나 삭제되었으면 null입니다.
     *
     * 글 행을 FOR UPDATE로 잠가, 글 삭제와 동시에 들어온 댓글이 남지 않게 합니다.
     */
    public function addComment(int $postId, int $userId, string $body): ?int
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM posts WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $lock->bindValue(':id', $postId, PDO::PARAM_INT);
            $lock->execute();
            if ($lock->fetch() === false) {
                $this->pdo->rollBack();
                return null;
            }
            $insert = $this->pdo->prepare('INSERT INTO comments (post_id, user_id, body) VALUES (:post_id, :user_id, :body)');
            $insert->execute([':post_id' => $postId, ':user_id' => $userId, ':body' => $body]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteComment(int $id): void
    {
        $this->queryBuilder()->table('comments')
            ->update(['deleted_at' => date('Y-m-d H:i:s')])
            ->where('id', '=', $id)
            ->where('deleted_at', '=', null)
            ->exec();
    }
}
