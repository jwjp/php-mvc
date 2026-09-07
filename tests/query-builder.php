<?php

// Included by smoke.php; all rows live in a connection-local temporary table.
$pdo->exec('CREATE TEMPORARY TABLE qb_checks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bucket VARCHAR(30) NOT NULL,
    amount DECIMAL(8,2) NOT NULL DEFAULT 0,
    enabled BOOLEAN NOT NULL DEFAULT 0,
    secret VARCHAR(255) NULL,
    note VARCHAR(150) NULL
) ENGINE=InnoDB');

$qb = new Model\QueryBuilder($pdo);
try {
    $id = $qb->table('qb_checks')->insert([
        'bucket' => 'alpha', 'amount' => 1.25, 'enabled' => true, 'secret' => ['known', 'PASSWORD'],
    ])->exec();
    $qb->table('qb_checks')->insert(['bucket' => 'alpha', 'amount' => 2.5, 'note' => 'EMAIL'])->exec();
    $qb->table('qb_checks')->insert(['bucket' => 'beta', 'amount' => 4, 'note' => 'text'])->exec();
    $row = $qb->table('qb_checks')->select()->where('id', '=', (int) $id)->exec()[0];
    verify($row['enabled'] === 1 && $row['note'] === null && $row['amount'] === '1.25', 'Typed bindings must preserve bool, null and decimals.');

    $rows = $qb->table('qb_checks')->select()->where('note', 'IN', ['text', 'EMAIL'])->exec();
    verify(count($rows) === 2, 'IN values must not be mistaken for an option tuple.');
    $rows = $qb->table('qb_checks')->select()->where('amount', 'BETWEEN', [1, 3])->exec();
    verify(count($rows) === 2, 'BETWEEN must bind both bounds.');
    $rows = $qb->table('qb_checks')->select('bucket', 'COUNT(*) AS total')
        ->groupBy('bucket')->having('COUNT(*)', '>', 1)->exec();
    verify(count($rows) === 1 && $rows[0]['bucket'] === 'alpha' && $rows[0]['total'] === 2, 'GROUP BY and HAVING must execute.');
    $qb->table('qb_checks')->select()->where('bucket', '=', 'alpha');
    $qb->debug();
    $rows = $qb->where('amount', '>', 2)->exec();
    verify(count($rows) === 1, 'Appending a condition after debug must execute correctly.');
    $rows = $qb->table('qb_checks')->select()->where('bucket', '=', 'beta', 'OR')
        ->where('amount', '=', 1.25, 'END OR')->exec();
    verify(count($rows) === 2, 'OR groups must execute with the documented semantics.');
    $rows = $qb->table('qb_checks')->select()->where('bucket', '=', 'alpha')
        ->where('amount', '=', 1.25, 'OR')->where('note', '=', 'text', 'END OR')->exec();
    verify(count($rows) === 1 && $rows[0]['bucket'] === 'alpha', 'OR must not bypass an earlier scope filter.');
    pass('QueryBuilder scalar types, IN, BETWEEN, HAVING, OR and debug execute correctly');

    foreach (['', '0', 'wrong'] as $password) {
        $result = $qb->table('qb_checks')->select()->where('id', '=', $id)
            ->where('secret', '=', [$password, 'PASSWORD'])->exec();
        verify($result === false, 'Empty or zero passwords must not bypass verification.');
    }
    $result = $qb->table('qb_checks')->select()->where('id', '=', $id)
        ->where('secret', '=', ['known', 'PASSWORD'])->exec();
    verify(count($result) === 1, 'Correct password must pass verification.');
    $longId = $qb->table('qb_checks')->insert(['bucket' => 'password-limit', 'secret' => [str_repeat('x', 72), 'PASSWORD']])->exec();
    $result = $qb->table('qb_checks')->select()->where('id', '=', $longId)
        ->where('secret', '=', [str_repeat('x', 72) . 'suffix', 'PASSWORD'])->exec();
    verify($result === false, 'A password beyond the bcrypt limit must not match its prefix.');
    foreach (['update', 'delete'] as $operation) {
        $query = $qb->table('qb_checks');
        $operation === 'update' ? $query->update(['note' => 'unsafe']) : $query->delete();
        try {
            $query->where('id', '=', $id)->where('secret', '=', ['wrong', 'PASSWORD'])->exec();
            throw new RuntimeException('PASSWORD must not be ignored on writes.');
        } catch (LogicException) {
            // The builder rejects these writes before preparing SQL.
        }
    }
    $row = $qb->table('qb_checks')->select()->where('id', '=', $id)->exec()[0];
    verify($row['note'] === null, 'Rejected password writes must leave data unchanged.');
    pass('password verification has no empty-value bypass and cannot weaken writes');

    $qb->table('qb_checks')->upsert(['id' => (int) $id, 'bucket' => 'alpha', 'note' => 'upserted'])->exec();
    $row = $qb->table('qb_checks')->select()->where('id', '=', $id)->exec()[0];
    verify($row['note'] === 'upserted', 'UPSERT must update an existing row.');
    $result = $qb->transaction(function (Model\QueryBuilder $query): string {
        return $query->table('qb_checks')->insert(['bucket' => 'committed'])->exec();
    });
    verify(ctype_digit($result), 'transaction must return the callback result.');
    try {
        $qb->transaction(function (Model\QueryBuilder $query): void {
            $query->table('qb_checks')->insert(['bucket' => 'rolled-back'])->exec();
            throw new RuntimeException('Expected rollback.');
        });
    } catch (RuntimeException $exception) {
        verify($exception->getMessage() === 'Expected rollback.', 'Unexpected transaction failure.');
    }
    verify(!$pdo->inTransaction(), 'Transaction must not remain open after failure.');
    verify($qb->table('qb_checks')->select()->where('bucket', '=', 'rolled-back')->exec() === [], 'Failed transaction must roll back all writes.');
    pass('UPSERT, transaction results and automatic rollback work');
} finally {
    $pdo->exec('DROP TEMPORARY TABLE qb_checks');
}
