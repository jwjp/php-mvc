<?php

namespace Model\Sample;

use Model\Model;
use Model\QueryBuilder;
use PDO, Throwable;
use Controller\HttpException;

class SampleModel extends Model
{
    /**
     * @param string $value
     * @param string $label
     * @return string
     * @throws HttpException
     */
    private function validateText(string $value, string $label = '메시지'): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new HttpException('입력 값이 올바르지 않습니다.', 400);
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new HttpException('입력 값은 UTF-8 문자열이어야 합니다.', 400);
        }
        if (mb_strlen($value, 'UTF-8') > 150) {
            throw new HttpException($label . '의 제한 길이 150자를 초과하였습니다.', 400);
        }

        return $value;
    }

    /**
     * @param int|string $id
     * @return int
     * @throws HttpException
     */
    private function validateId(int|string $id): int
    {
        if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
            throw new HttpException('ID 값이 올바르지 않습니다.', 400);
        }

        return (int) $id;
    }

    /**
     * @param string $message
     * @return false|int
     * @throws HttpException
     */
    public function createFoo(string $message): false|int
    {
        $message = $this->validateText($message);

        $query = 'INSERT INTO foo SET message = :message';
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':message', $message);

        $result = false;
        if ($stmt->execute()) {
            $result = $stmt->rowCount();
        }

        return $result;
    }

    /**
     * @param int $id
     * @return false|array
     * @throws HttpException
     */
    public function readFooById(int|string $id): false|array
    {
        $id = $this->validateId($id);

        $query = 'SELECT * FROM foo WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        $result = false;
        if ($stmt->execute()) {
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    /**
     * @return false|array
     */
    public function readFooAll(): false|array
    {
        $query = 'SELECT * FROM foo WHERE deleted_at IS NULL ORDER BY id DESC';
        $stmt = $this->pdo->prepare($query);

        $result = false;
        if ($stmt->execute()) {
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    /**
     * @param string $message
     * @param int $id
     * @return false|int
     * @throws HttpException
     */
    public function updateFooById(string $message, int|string $id): false|int
    {
        $id = $this->validateId($id);
        $message = $this->validateText($message);

        $query = 'UPDATE foo SET message = :message, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($query);
        $execute = $stmt->execute([
            ':message' => $message,
            ':id' => $id
        ]);

        return $execute ? $stmt->rowCount() : false;
    }

    /**
     * @param int $id
     * @return false|int
     * @throws HttpException
     */
    public function deleteFooById(int|string $id): false|int
    {
        $id = $this->validateId($id);

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                'UPDATE foo SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL'
            );
            $stmt->execute([':id' => $id]);
            $result = $stmt->rowCount();

            if ($result > 0) {
                $stmt = $this->pdo->prepare(
                    'UPDATE bar SET deleted_at = NOW() WHERE foo_id = :foo_id AND deleted_at IS NULL'
                );
                $stmt->execute([':foo_id' => $id]);
            }

            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param string $comment
     * @param int $fooId
     * @return false|int
     * @throws HttpException
     */
    public function createBarWithFooId(string $comment, int|string $fooId): false|int
    {
        $fooId = $this->validateId($fooId);
        $comment = $this->validateText($comment, '코멘트');

        return $this->queryBuilder()->transaction(function () use ($fooId, $comment): int {
            // Lock the parent first, matching the soft-delete transaction's lock order.
            $stmt = $this->pdo->prepare('SELECT id FROM foo WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([':id' => $fooId]);
            if ($stmt->fetchColumn() === false) {
                throw new HttpException('연결할 foo를 찾을 수 없습니다.', 404);
            }
            $stmt = $this->pdo->prepare('INSERT INTO bar SET comment = :comment, foo_id = :foo_id');
            $stmt->execute([':comment' => $comment, ':foo_id' => $fooId]);
            return $stmt->rowCount();
        });
    }

    /**
     * @param int $id
     * @return false|array
     * @throws HttpException
     */
    public function readBarById(int|string $id): false|array
    {
        $id = $this->validateId($id);

        $query = 'SELECT b.* FROM bar b INNER JOIN foo f ON f.id = b.foo_id '
            . 'WHERE b.id = :id AND b.deleted_at IS NULL AND f.deleted_at IS NULL';
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id);

        $result = false;
        if ($stmt->execute()) {
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    /**
     * @return false|array
     */
    public function readBarAll(): false|array
    {
        $query = 'SELECT b.* FROM bar b INNER JOIN foo f ON f.id = b.foo_id '
            . 'WHERE b.deleted_at IS NULL AND f.deleted_at IS NULL ORDER BY b.id DESC';
        $stmt = $this->pdo->prepare($query);

        $result = false;
        if ($stmt->execute()) {
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $result;
    }

    /**
     * @param string $comment
     * @param int $id
     * @return false|int
     * @throws HttpException
     */
    public function updateBarById(string $comment, int|string $id): false|int
    {
        $id = $this->validateId($id);
        $comment = $this->validateText($comment, '코멘트');

        $query = 'UPDATE bar b INNER JOIN foo f ON f.id = b.foo_id '
            . 'SET b.comment = :comment, b.updated_at = NOW() '
            . 'WHERE b.id = :id AND b.deleted_at IS NULL AND f.deleted_at IS NULL';
        $stmt = $this->pdo->prepare($query);
        $execute = $stmt->execute([
            ':comment' => $comment,
            ':id' => $id
        ]);

        return $execute ? $stmt->rowCount() : false;
    }

    /**
     * @param int $id
     * @return false|int
     * @throws HttpException
     */
    public function deleteBarById(int|string $id): false|int
    {
        $id = $this->validateId($id);

        $query = 'UPDATE bar SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL';
        $stmt = $this->pdo->prepare($query);
        $stmt->bindParam(':id', $id);

        $result = false;
        if ($stmt->execute()) {
            $result = $stmt->rowCount();
        }

        return $result;
    }

    /**
     * @param int $fooId
     * @return array
     * @throws HttpException
     */
    public function readFooBarByFooIdWithQB(int|string $fooId): array
    {
        $fooId = $this->validateId($fooId);

        $qb = $this->queryBuilder();
        return $qb->table('foo f')
            ->join('bar b', 'INNER', 'b.foo_id', '=', 'f.id')
            ->select('b.*')
            ->where('b.foo_id', '=', [$fooId, 'NUMBER'])
            ->where('b.deleted_at', 'IS', 'NULL')
            ->where('f.deleted_at', 'IS', 'NULL')
            ->orderBy('b.id', 'DESC')
            ->exec();
    }

    /**
     * @param string $fooMessage
     * @param string $barComment
     * @return bool
     * @throws HttpException
     */
    public function createFooBarWithQB(string $fooMessage, string $barComment): bool
    {
        $message = $this->validateText($fooMessage);
        $comment = $this->validateText($barComment, '코멘트');

        return $this->queryBuilder()->transaction(function (QueryBuilder $qb) use ($message, $comment): bool {
            $fooId = $qb->table('foo')
                ->insert([
                    'message' => $message
                ])
                ->exec();
            $qb->table('bar')
                ->insert([
                    'foo_id' => $fooId,
                    'comment' => $comment
                ])
                ->exec();

            return true;
        });
    }

    /**
     * @param string $message
     * @return array|false|int|string
     * @throws HttpException
     */
    public function createFooWithQB(string $message): false|array|int|string
    {
        $msg = $this->validateText($message);

        $qb = $this->queryBuilder();
        return $qb->table('foo')
            ->insert([
                'message' => $msg
            ])
            ->exec();
    }

    /**
     * @param string $fooId
     * @return array|false|int|string
     * @throws HttpException
     */
    public function deleteFooByIdWithQB(int|string $fooId): false|array|int|string
    {
        $fooId = $this->validateId($fooId);

        return $this->queryBuilder()->transaction(function (QueryBuilder $qb) use ($fooId): int {
            $deletedAt = date('Y-m-d H:i:s');
            $result = $qb->table('foo')
                ->update(['deleted_at' => $deletedAt])
                ->where('id', '=', [$fooId, 'NUMBER'])
                ->where('deleted_at', 'IS', 'NULL')
                ->exec();

            if ($result > 0) {
                $qb->table('bar')
                    ->update(['deleted_at' => $deletedAt])
                    ->where('foo_id', '=', [$fooId, 'NUMBER'])
                    ->where('deleted_at', 'IS', 'NULL')
                    ->exec();
            }

            return $result;
        });
    }
}
