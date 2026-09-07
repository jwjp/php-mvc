<?php

namespace Model;

use Controller\HttpException;
use InvalidArgumentException;
use LogicException;
use PDO;
use Throwable;

class QueryBuilder
{
    private string $table = '';
    private string $operation = '';
    private array $columns = [];
    private array $joins = [];
    private array $groups = [];
    private array $orders = [];
    private array $values = [];
    private array $parameters = [];
    private array $conditions = ['where' => '', 'having' => ''];
    private array $depth = ['where' => 0, 'having' => 0];
    private ?array $password = null;
    private ?int $limit = null;
    private ?int $offset = null;

    private const OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE',
        'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'IS', 'IS NOT'];
    private const OPTIONS = ['PASSWORD', 'NUMBER', 'ALPHABET', 'PHONE', 'EMAIL'];

    public function __construct(private PDO $pdo) {}

    public function table(string $table): static
    {
        $table = $this->aliased($table);
        $this->table = $table;
        $this->operation = '';
        $this->columns = $this->joins = $this->groups = $this->orders = $this->values = $this->parameters = [];
        $this->conditions = ['where' => '', 'having' => ''];
        $this->depth = ['where' => 0, 'having' => 0];
        $this->password = null;
        $this->limit = $this->offset = null;
        return $this;
    }

    private function identifier(string $name, bool $wildcard = false): string
    {
        $parts = explode('.', trim($name));
        foreach ($parts as $index => &$part) {
            if ($wildcard && $part === '*' && $index === count($parts) - 1) {
                continue;
            }
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part)) {
                throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
            }
            $part = '`' . $part . '`';
        }
        return implode('.', $parts);
    }

    private function expression(string $column): string
    {
        if (preg_match('/^(COUNT|SUM|AVG|MIN|MAX)\((\*|[A-Za-z_][A-Za-z0-9_.]*)\)$/iD', trim($column), $match)) {
            if ($match[2] === '*' && strtoupper($match[1]) !== 'COUNT') {
                throw new InvalidArgumentException('Only COUNT supports a wildcard.');
            }
            return strtoupper($match[1]) . '(' . $this->identifier($match[2], true) . ')';
        }
        return $this->identifier($column, true);
    }

    private function aliased(string $name, bool $expression = false): string
    {
        $parts = preg_split('/\s+(?:AS\s+)?/i', trim($name));
        if (count($parts) > 2 || isset($parts[1]) && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $parts[1])) {
            throw new InvalidArgumentException('Invalid SQL alias.');
        }
        $sql = $expression ? $this->expression($parts[0]) : $this->identifier($parts[0]);
        return $sql . (isset($parts[1]) ? ' AS ' . $this->identifier($parts[1]) : '');
    }

    private function operation(string $operation): void
    {
        if ($this->operation !== '' && $this->operation !== $operation) {
            throw new LogicException('Start a new query with table() before changing the operation.');
        }
        $this->operation = $operation;
    }

    public function select(string ...$columns): static
    {
        $this->operation('select');
        $this->columns = array_map(fn (string $column) => $this->aliased($column, true), $columns ?: ['*']);
        return $this;
    }

    private function option(mixed $value): ?array
    {
        if (is_array($value) && array_is_list($value) && count($value) === 2
            && is_string($value[1]) && in_array(strtoupper($value[1]), self::OPTIONS, true)) {
            return [$value[0], strtoupper($value[1])];
        }
        return null;
    }

    private function normalize(mixed $value): mixed
    {
        $option = $this->option($value);
        if ($option !== null) {
            [$value, $type] = $option;
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('Option values must be scalar.');
            }
            $valid = match ($type) {
                'PASSWORD' => is_string($value) && $value !== '' && strlen($value) <= 72 && !str_contains($value, "\0"),
                'NUMBER' => is_numeric($value),
                'ALPHABET' => ctype_alpha((string) $value),
                'PHONE' => preg_match('/^[0-9]{3}-[0-9]{4}-[0-9]{4}$/D', (string) $value) === 1,
                'EMAIL' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            };
            if (!$valid) {
                throw new HttpException("Invalid {$type} value.");
            }
            if ($type === 'PASSWORD') {
                $value = password_hash($value, PASSWORD_DEFAULT);
            }
        }
        if ((!is_scalar($value) && $value !== null) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('SQL values must be finite scalars or null.');
        }
        return $value;
    }

    private function bind(mixed $value): string
    {
        $value = $this->normalize($value);
        $key = ':p' . count($this->parameters);
        $this->parameters[$key] = $value;
        return $key;
    }

    private function condition(string $clause, string $column, string $operator, mixed $value, string $combinator): static
    {
        $left = $clause === 'having' ? $this->expression($column) : $this->identifier($column);
        $operator = strtoupper(trim($operator));
        $combinator = strtoupper(trim($combinator));
        if (!in_array($operator, self::OPERATORS, true) || !in_array($combinator, ['AND', 'OR', 'END OR'], true)) {
            throw new InvalidArgumentException('Invalid condition operator or combinator.');
        }

        // PASSWORD verifies a SELECT result; it must never disappear from a write predicate.
        $option = in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true) ? null : $this->option($value);
        if ($option !== null && $option[1] === 'PASSWORD') {
            if ($clause !== 'where' || $operator !== '=' || $combinator !== 'AND' || $this->password !== null
                || !is_string($option[0])) {
                throw new InvalidArgumentException('PASSWORD requires one WHERE equality with AND.');
            }
            $this->password = [substr(strrchr('.' . trim($column), '.'), 1), $option[0]];
            return $this;
        }

        if (in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true)) {
            if (!is_array($value) || $value === [] || (str_contains($operator, 'BETWEEN') && count($value) !== 2)) {
                throw new InvalidArgumentException('IN requires a nonempty array; BETWEEN requires two values.');
            }
            $bindings = array_map(fn ($item) => $this->bind($item), array_values($value));
            $right = str_contains($operator, 'BETWEEN')
                ? implode(' AND ', $bindings) : '(' . implode(', ', $bindings) . ')';
        } elseif (in_array($operator, ['IS', 'IS NOT'], true)) {
            $right = $value === null ? 'NULL' : (is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (is_string($value) ? strtoupper($value) : ''));
            if (!in_array($right, ['NULL', 'TRUE', 'FALSE'], true)) {
                throw new InvalidArgumentException('IS only accepts NULL, TRUE or FALSE.');
            }
        } elseif ($value === null) {
            if (!in_array($operator, ['=', '!=', '<>'], true)) {
                throw new InvalidArgumentException('Use = or != to compare null.');
            }
            $operator = $operator === '=' ? 'IS' : 'IS NOT';
            $right = 'NULL';
        } else {
            $right = $this->bind($value);
        }

        if ($combinator === 'END OR' && $this->depth[$clause] === 0) {
            throw new LogicException('END OR requires an open OR group.');
        }
        // Group the entire OR expression so earlier AND filters still apply.
        $this->conditions[$clause] .= ($combinator === 'OR' ? '(' : '') . "{$left} {$operator} {$right}";
        if ($combinator === 'OR') {
            $this->conditions[$clause] .= ' OR ';
            $this->depth[$clause]++;
        } elseif ($combinator === 'END OR') {
            $this->conditions[$clause] .= ') AND ';
            $this->depth[$clause]--;
        } else {
            $this->conditions[$clause] .= ' AND ';
        }
        return $this;
    }

    public function where(string $column, string $operator, mixed $value, string $combinators = 'AND'): static
    {
        return $this->condition('where', $column, $operator, $value, $combinators);
    }

    public function having(string $column, string $operator, mixed $value, string $combinators = 'AND'): static
    {
        return $this->condition('having', $column, $operator, $value, $combinators);
    }

    public function join(string $table, string $joinType, string $leftCondition, string $operator, string $rightCondition): static
    {
        $joinType = strtoupper(trim($joinType));
        $operator = strtoupper(trim($operator));
        if (!in_array($joinType, ['INNER', 'LEFT', 'RIGHT'], true) || !in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            throw new InvalidArgumentException('Invalid JOIN type or comparison.');
        }
        $this->joins[] = $joinType . ' JOIN ' . $this->aliased($table) . ' ON '
            . $this->identifier($leftCondition) . ' ' . $operator . ' ' . $this->identifier($rightCondition);
        return $this;
    }

    public function groupBy(string ...$columns): static
    {
        $this->groups = array_map(fn (string $column) => $this->identifier($column), $columns);
        return $this;
    }

    public function orderBy(string $column, string $order = 'ASC'): static
    {
        $order = strtoupper(trim($order));
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sort direction must be ASC or DESC.');
        }
        $this->orders[] = $this->identifier($column) . ' ' . $order;
        return $this;
    }

    public function limit(int $limit): static
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('LIMIT must be nonnegative.');
        }
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('OFFSET must be nonnegative.');
        }
        $this->offset = $offset;
        return $this;
    }

    private function set(string $operation, array $values): static
    {
        if ($values === [] || $this->values !== []) {
            throw new InvalidArgumentException('Supply a nonempty value map once per query.');
        }
        $normalized = [];
        foreach ($values as $column => $value) {
            if (!is_string($column) || str_contains($column, '.')) {
                throw new InvalidArgumentException('SET requires unqualified column names.');
            }
            $normalized[$this->identifier($column)] = $this->normalize($value);
        }
        $this->operation($operation);
        $this->values = $normalized;
        return $this;
    }

    public function insert(array $set): static { return $this->set('insert', $set); }
    public function upsert(array $set): static { return $this->set('upsert', $set); }
    public function update(array $set): static { return $this->set('update', $set); }

    public function delete(): static
    {
        $this->operation('delete');
        return $this;
    }

    public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->inTransaction() && $this->pdo->rollBack(); }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    /** Compilation is read-only, so debug() cannot change the next query. */
    private function compile(): array
    {
        if ($this->table === '' || $this->operation === '') {
            throw new LogicException('Call table() and select(), insert(), upsert(), update() or delete().');
        }
        if ($this->depth['where'] !== 0 || $this->depth['having'] !== 0) {
            throw new LogicException('Close every OR group with END OR.');
        }
        $where = preg_replace('/ AND $/', '', $this->conditions['where']);
        $having = preg_replace('/ AND $/', '', $this->conditions['having']);
        if ($this->password !== null && ($this->operation !== 'select' || $where === '' || str_contains($where, ' OR '))) {
            throw new LogicException('PASSWORD only supports SELECT with additional AND conditions.');
        }
        if ($this->operation !== 'select' && ($this->joins || $this->groups || $having !== '' || $this->orders || $this->limit !== null || $this->offset !== null)) {
            throw new LogicException('JOIN, GROUP BY, HAVING, ORDER BY, LIMIT and OFFSET require SELECT.');
        }
        if (in_array($this->operation, ['insert', 'upsert'], true) && $where !== '') {
            throw new LogicException('INSERT and UPSERT do not accept WHERE.');
        }
        if (in_array($this->operation, ['update', 'delete'], true) && $where === '') {
            throw new LogicException('UPDATE and DELETE require a WHERE condition.');
        }

        $parameters = $this->parameters;
        if ($this->operation === 'select') {
            $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->table;
            if ($this->joins) { $sql .= ' ' . implode(' ', $this->joins); }
            if ($where !== '') { $sql .= ' WHERE ' . $where; }
            if ($this->groups) { $sql .= ' GROUP BY ' . implode(', ', $this->groups); }
            if ($having !== '') { $sql .= ' HAVING ' . $having; }
            if ($this->orders) { $sql .= ' ORDER BY ' . implode(', ', $this->orders); }
            if ($this->limit !== null || $this->offset !== null) { $sql .= ' LIMIT ' . ($this->limit ?? '18446744073709551615'); }
            if ($this->offset !== null) { $sql .= ' OFFSET ' . $this->offset; }
        } elseif ($this->operation === 'delete') {
            $sql = 'DELETE FROM ' . $this->table . ' WHERE ' . $where;
        } else {
            $set = $upsert = [];
            foreach ($this->values as $column => $value) {
                $key = ':p' . count($parameters);
                $parameters[$key] = $value;
                $set[] = $column . ' = ' . $key;
                if ($this->operation === 'upsert') {
                    $key = ':p' . count($parameters);
                    $parameters[$key] = $value;
                    $upsert[] = $column . ' = ' . $key;
                }
            }
            $sql = ($this->operation === 'update' ? 'UPDATE ' : 'INSERT INTO ') . $this->table . ' SET ' . implode(', ', $set);
            if ($this->operation === 'update') { $sql .= ' WHERE ' . $where; }
            if ($upsert) { $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $upsert); }
        }
        return [$sql, $parameters];
    }

    public function exec(): false|array|int|string
    {
        [$sql, $parameters] = $this->compile();
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $key => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($key, $value, $type);
        }
        $statement->execute();
        if ($this->operation === 'select') {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($this->password !== null) {
                if ($rows === []) { return false; }
                [$column, $password] = $this->password;
                if (count($rows) !== 1 || !isset($rows[0][$column])) {
                    throw new LogicException('PASSWORD requires one row containing the password column.');
                }
                if ($password === '' || strlen($password) > 72 || str_contains($password, "\0")
                    || !password_verify($password, $rows[0][$column])) { return false; }
            }
            return $rows;
        }
        return in_array($this->operation, ['insert', 'upsert'], true) ? $this->pdo->lastInsertId() : $statement->rowCount();
    }

    public function debug(): array
    {
        [$sql, $parameters] = $this->compile();
        return ['query' => $sql, 'parameters' => $parameters, 'dump' => print_r($parameters, true)];
    }
}
