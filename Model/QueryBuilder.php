<?php

namespace Model;

use PDO, Exception;

class QueryBuilder
{
    /**
     * MySQL 컨트롤을 위해 PDO 사용
     * php.ini 에서 extension=pdo_mysql 활성화 되어 있는 지 확인 필요
     *
     * @var PDO
     */
    private PDO $pdo;

    /**
     * 현재 실행중인 쿼리
     *
     * @var string
     */
    private string $inProgressQuery;

    /**
     * 쿼리 실행 전 호출한 모든 함수
     *
     * @var array
     */
    private array $callFunctionLog;

    /**
     * 테이블 이름
     *
     * @var string
     */
    private string $table;

    /**
     * 쿼리 실행 시, JOIN 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $join = '';

    /**
     * 쿼리 실행 시, SELECT 칼럼 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $select;

    /**
     * 쿼리 실행 시, GROUP BY 칼럼 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $groupByColumn;

    /**
     * 쿼리 실행 시, WHERE 조건절에 들어갈 문자열
     * $this->whereMaker() 에서 만들어진 쿼리 문자열
     *
     * @var string
     */
    private string $where = '';

    /**
     * WHERE 조건 중, PASSWORD 옵션을 받은 경우 동일하게 암호화하여 비교가 불가하므로
     * 우선 SELECT 검색 후 비교 할 Password 칼럼
     *
     * @var string
     */
    private string $wherePasswordColumn = '';

    /**
     * WHERE 조건 중, PASSWORD 옵션을 받은 경우 동일하게 암호화하여 비교가 불가하므로
     * 우선 SELECT 검색 후 비교 할 Password 값
     *
     * @var string
     */
    private string $wherePasswordValue = '';

    /**
     * 쿼리 실행 시, INSERT / UPDATE / UPSERT 에서 SET 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $set = '';

    /**
     * 쿼리 실행 시, UPSERT 에서 ON DUPLICATE KEY UPDATE 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $upsertSet = '';

    /**
     * 쿼리 실행 시, Prepared 된 키 값과 바인딩 될 값
     *
     * @var array
     */
    private array $param = [];

    /**
     * 쿼리 실행 시, HAVING 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $having;

    /**
     * 쿼리 실행 시, ORDER BY 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $orderBy = '';

    /**
     * 쿼리 실행 시, LIMIT 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $limit;

    /**
     * 쿼리 실행 시, OFFSET 영역에 들어갈 문자열
     *
     * @var string
     */
    private string $offset;

    /**
     * 조건 연산자로 사용 할 수 있는 값
     *
     * @var array
     */
    private const USABLE_CONDITION = [
        '>', '>=', '=', '!=', '<=', '<',
        'IN', 'NOT IN',
        'BETWEEN', 'NOT BETWEEN',
        'IS', 'IS NOT',
        'LIKE', 'NOT LIKE'
    ];

    /**
     * JOIN 타입으로 사용 가능한 값
     *
     * @var array
     */
    private const USABLE_JOIN = [
        'INNER',
        'LEFT',
        'RIGHT'
    ];

    /**
     * WHERE 조건에서 값에 부여하는 옵션 종류
     *
     * @var array
     */
    private const USABLE_OPTION = [
        'PASSWORD',
        'NUMBER',
        'ALPHABET',
        'PHONE',
        'EMAIL'
    ];

    /**
     * @param PDO $pdo
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 쿼리 실행 시, 사용 할 테이블 이름 적용
     *
     * @param string $table 테이블 이름
     * @return $this 메소드 체인으로 사용 가능
     */
    public function table(string $table): static
    {
        $this->table = $table;

        // 오토커밋 OFF 후 트랜잭션 직접 컨트롤 하는 경우
        // 변수 중복 사용 방지를 위해서 테이블 마다 별도 초기화
        $this->callFunctionLog = [];
        $this->param = [];
        $this->set = '';
        $this->join = '';
        $this->orderBy = '';
        $this->wherePasswordColumn = '';
        $this->wherePasswordValue = '';

        $this->callFunctionLog[] = 'table';

        return $this;
    }

    /**
     * 쿼리 실행 시, SELECT 에서 사용 할 칼럼 적용
     *
     * @param string ...$columns 칼럼 이름
     * @return $this 메소드 체인으로 사용 가능
     */
    public function select(string ...$columns): static
    {
        $this->inProgressQuery = 'select';
        $this->callFunctionLog[] = 'select';
        $this->select = implode(', ', $columns);

        return $this;
    }

    /**
     * CRUD 작업 중 컬럼에 옵션이 적용되었는 지 확인
     *
     * @param mixed $value 칼럼 값 및 옵션
     * @return array|false
     */
    private function optionalValue(mixed $value): false|array
    {
        $result = false;
        if (is_array($value) && count($value) === 2 && in_array(strtoupper($value[1]), self::USABLE_OPTION)) {
            $result = [
                'value' => $value[0],
                'option' => $value[1]
            ];
        }

        return $result;
    }

    /**
     * WHERE 및 HAVING 에서 사용 할 조건절 반환
     *
     * @param string $leftCondition WHERE 에서 왼쪽 조건, 보통은 칼럼 이름
     * @param string $operator 조건 연산자
     * @param int|string|array|null $rightCondition WHERE 에서 오른쪽 조건
     * @param string $combinators AND, OR, END OR 사용 가능, OR 사용한 경우 END OR 필요
     * @return string
     * @throws Exception
     */
    private function whereMaker(string $leftCondition, string $operator, int|string|array|null $rightCondition, string $combinators = 'AND'): string
    {
        $param = [];
        $where = '';
        if (!in_array(strtoupper($operator), self::USABLE_CONDITION)) {
            throw new Exception('사용 할 수 없는 조건 연산자 입니다.', 500);
        }

        // 옵션이 들어있는 조건인지 확인
        $optionalValue = $this->optionalValue($rightCondition);

        switch (strtoupper($operator)) {
            case 'IN':
            case 'NOT IN':
                if (!is_array($rightCondition)) {
                    throw new Exception('IN/NOT IN 조건은 배열값을 필요로 합니다.');
                }

                $where .= $leftCondition . ' ' . $operator . ' ';
                $where .= ' (';
                foreach($rightCondition as $idx => $value) {
                    $bindKey = ':' . md5(uniqid($leftCondition . $operator . $idx));

                    // 바인딩 할 파라미터 별도 저장
                    $param[$bindKey] = $value;

                    $where .= $bindKey;
                    if ($idx !== array_key_last($rightCondition)) {
                        $where .= ', ';
                    }
                }
                $where .= ') ';
                break;
            case 'BETWEEN':
            case 'NOT BETWEEN':
                if (!is_array($rightCondition)) {
                    throw new Exception('BETWEEN/NOT BETWEEN 조건은 배열값을 필요로 합니다.');
                }

                $where .= $leftCondition . ' ' . $operator . ' ';
                foreach($rightCondition as $idx => $value) {
                    $bindKey = ':' . md5(uniqid($leftCondition . $operator . $idx));

                    // 바인딩 할 파라미터 별도 저장
                    $param[$bindKey] = $value;

                    $where .= $bindKey;
                    if ($idx !== array_key_last($rightCondition)) {
                        $where .= ' AND ';
                    }
                }
                break;
            case 'IS':
            case 'IS NOT':
                $where .= $leftCondition . ' ' . $operator . ' ';

                // IS, IS NOT 연산자는 바인딩 불가
                $where .= $rightCondition ?? 'null';
                break;
            default:
                $bindKey = ':' . md5(uniqid($leftCondition . $operator));

                // 옵션에 따라 값 별도처리
                if ($optionalValue) {
                    switch (strtoupper($optionalValue['option'])) {
                        case 'PASSWORD':
                            // 암호화 : password_hash('PASSWORD', PASSWORD_DEFAULT)
                            // 암호화 검증 : password_verify('PASSWORD', $hashValue)

                            // SELECT 에서 패스워드는 마지막에 비교
                            // 다른 조건으로 검색 후 결과가 1개인 경우에만 패스워드 비교
                            $this->wherePasswordColumn = $leftCondition;
                            $this->wherePasswordValue = $optionalValue['value'];
                            break;
                        case 'NUMBER':
                            if (!is_numeric($optionalValue['value'])) {
                                throw new Exception($optionalValue['value'] . ' 는 숫자 형태가 아닙니다.');
                            }

                            $where .= $leftCondition . ' ' . $operator . ' ';
                            $param[$bindKey] = $optionalValue['value'];
                            $where .= $bindKey;
                            break;
                        case 'ALPHABET':
                            if (!ctype_alpha($optionalValue['value'])) {
                                throw new Exception($optionalValue['value'] . ' 는 알파벳 형태가 아닙니다.');
                            }

                            $where .= $leftCondition . ' ' . $operator . ' ';
                            $param[$bindKey] = $optionalValue['value'];
                            $where .= $bindKey;
                            break;
                        case 'PHONE':
                            if (!preg_match("/^[0-9]{3}-[0-9]{4}-[0-9]{4}$/", $optionalValue['value'])) {
                                throw new Exception($optionalValue['value'] . ' 는 하이픈을 포함한 전화번호 형식이 아닙니다.');
                            }

                            $where .= $leftCondition . ' ' . $operator . ' ';
                            $param[$bindKey] = $optionalValue['value'];
                            $where .= $bindKey;
                            break;
                        case 'EMAIL':
                            if (!(filter_var($optionalValue['value'], FILTER_VALIDATE_EMAIL) && preg_match('/@.+\./', $optionalValue['value']))) {
                                throw new Exception($optionalValue['value'] . ' 는 이메일 형식이 아닙니다.');
                            }

                            $where .= $leftCondition . ' ' . $operator . ' ';
                            $param[$bindKey] = $optionalValue['value'];
                            $where .= $bindKey;
                            break;
                    }
                } else {
                    $where .= $leftCondition . ' ' . $operator . ' ';

                    // 바인딩 할 파라미터 별도 저장
                    $param[$bindKey] = $rightCondition;

                    $where .= $bindKey;
                }

                break;
        }

        // 옵션으로 PASSWORD 값을 받은 경우, 쿼리 문자열에 직접 추가 되지않으므로 예외처리
        if (!$optionalValue || (strtoupper($optionalValue['option']) !== 'PASSWORD')) {
            if (strtoupper($combinators) === 'OR') {
                $where .= ' OR (';
            } else if (strtoupper($combinators) === 'END OR') {
                $where .= ') AND ';
            } else {
                $where .= ' AND ';
            }
        }

        $this->param += $param;

        return $where;
    }

    /**
     * 쿼리 실행 시, WHERE 조건으로 사용 할 문자열
     *
     * @param string $leftCondition WHERE 에서 왼쪽 조건, 보통은 칼럼 이름
     * @param string $operator 조건 연산자
     * @param int|string|array|null $rightCondition WHERE 에서 오른쪽 조건
     * @param string $combinators AND, OR, END OR 사용 가능, OR 사용한 경우 END OR 필요
     * @return $this 메소드 체인으로 사용 가능
     * @throws Exception
     */
    public function where(string $leftCondition, string $operator, int|string|array|null $rightCondition, string $combinators = 'AND'): static
    {
        $this->where .= $this->whereMaker($leftCondition, $operator, $rightCondition, $combinators);
        $this->callFunctionLog[] = 'where';

        return $this;
    }

    /**
     * 쿼리 실행 시, JOIN 으로 사용 할 쿼리 문자열
     *
     * @param string $table 테이블 이름
     * @param string $joinType JOIN 타입 (INNER, LEFT, RIGHT)
     * @param string $leftCondition JOIN ON 에서 왼쪽 조건, 보통은 칼럼 이름
     * @param string $operator 조건 연산자
     * @param int|string|array|null $rightCondition  JOIN ON 에서 오른쪽 조건
     * @return $this 메소드 체인으로 사용 가능
     * @throws Exception
     */
    public function join(string $table, string $joinType, string $leftCondition, string $operator, int|string|array|null $rightCondition): static
    {
        $this->callFunctionLog[] = 'join';

        if (!in_array(strtoupper($joinType), self::USABLE_JOIN)) {
            throw new Exception('사용 할 수 없는 조인 타입입니다.');
        }

        if (!in_array(strtoupper($operator), self::USABLE_CONDITION)) {
            throw new Exception('사용 할 수 없는 조건 연산자 입니다.', 500);
        }

        // JOIN (INNER), LEFT JOIN (OUTER), RIGHT JOIN (OUTER)
        // JOIN 조건에는 preparedStatement 사용 불가
        $this->join .= strtoupper($joinType) . ' JOIN ' . $table . ' ON ' . $leftCondition . ' ' . $operator . ' ' . $rightCondition . ' ';

        return $this;
    }

    /**
     * 쿼리 실행 시, GROUP BY 으로 사용 할 쿼리 문자열
     *
     * @param string ...$columns 칼럼 아름
     * @return $this 메소드 체인으로 사용 가능
     */
    public function groupBy(string ...$columns): static
    {
        $this->callFunctionLog[] = 'groupBy';
        $this->groupByColumn = implode(', ', $columns);

        return $this;
    }

    /**
     * 쿼리 실행 시, HAVING 으로 사용 할 쿼리 문자열
     *
     * @param string $leftCondition HAVING 에서 왼쪽 조건, 보통은 칼럼 이름
     * @param string $operator 조건 연산자
     * @param int|string|array|null $rightCondition HAVING 에서 오른쪽 조건
     * @param string $combinators AND, OR, END OR 사용 가능, OR 사용한 경우 END OR 필요
     * @return $this 메소드 체인으로 사용 가능
     * @throws Exception
     */
    public function having(string $leftCondition, string $operator, int|string|array|null $rightCondition, string $combinators = 'AND'): static
    {
        $this->callFunctionLog[] = 'having';
        $this->having = $this->whereMaker($leftCondition, $operator, $rightCondition, $combinators);

        return $this;
    }

    /**
     * 쿼리 실행 시, ORDER BY 으로 사용 할 쿼리 문자열
     *
     * @param string $column 칼럼 이름
     * @param string $order 오름차순(ASC), 내림차순(DESC)
     * @return $this 메소드 체인으로 사용 가능
     */
    public function orderBy(string $column, string $order): static
    {
        $this->callFunctionLog[] = 'orderBy';
        if (!empty($this->orderBy)) {
            $this->orderBy .= ', ';
        }

        $this->orderBy .= ' ' . $column . ' ' . strtoupper($order);

        return $this;
    }

    /**
     * 쿼리 실행 시, LIMIT 으로 사용 할 쿼리 문자열
     *
     * @param int $limit 숫자값
     * @return $this 메소드 체인으로 사용 가능
     */
    public function limit(int $limit): static
    {
        $this->callFunctionLog[] = 'limit';
        $this->limit = $limit;

        return $this;
    }

    /**
     * 쿼리 실행 시, OFFSET 으로 사용 할 쿼리 문자열
     *
     * @param int $offset 숫자값
     * @return $this 메소드 체인으로 사용 가능
     */
    public function offset(int $offset): static
    {
        $this->callFunctionLog[] = 'offset';
        $this->offset = $offset;

        return $this;
    }

    /**
     * 쿼리 실행 시, INSERT SET 구문에 사용 할 쿼리 스트링
     *
     * @param array $set 칼럼 이름 및 값을 MAP 형태의 배열로 작성
     * @return $this 메소드 체인으로 사용 가능
     * @throws Exception
     */
    public function insert(array $set): static
    {
        $this->inProgressQuery = 'insert';
        $this->callFunctionLog[] = 'insert';

        // 트랜잭션 중 $this->set 중복 사용 방지를 위해 임시 값 사용
        $tempSet = '';

        foreach ($set as $column => $value) {
            $bindKey = ':' . md5(uniqid($column));

            // 바인딩 할 파라미터 별도 저장
            $this->param[$bindKey] = $value;

            // 옵션이 들어있는 조건인지 확인
            if ($optionalValue = $this->optionalValue($value)) {
                switch (strtoupper($optionalValue['option'])) {
                    case 'PASSWORD':
                        // 암호화 : password_hash('PASSWORD', PASSWORD_DEFAULT)
                        // 암호화 검증 : password_verify('PASSWORD', $hashValue)
                        $this->param[$bindKey] = password_hash($optionalValue['value'], PASSWORD_DEFAULT);
                        break;
                    case 'NUMBER':
                        if (!is_numeric($optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 숫자 형태가 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'ALPHABET':
                        if (!ctype_alpha($optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 알파벳 형태가 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'PHONE':
                        if (!preg_match("/^[0-9]{3}-[0-9]{4}-[0-9]{4}$/", $optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 하이픈을 포함한 전화번호 형식이 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'EMAIL':
                        if (!(filter_var($optionalValue['value'], FILTER_VALIDATE_EMAIL) && preg_match('/@.+\./', $optionalValue['value']))) {
                            throw new Exception($optionalValue['value'] . ' 는 이메일 형식이 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                }
            }

            $tempSet .= ($column . '=' . $bindKey);
            if ($column !== array_key_last($set)) {
                $tempSet .= ', ';
            }
        }

        $this->set = $tempSet;

        return $this;
    }

    /**
     * 쿼리 실행 시, UPSERT 구문으로 사용 할 쿼리 문자열
     * Unique Key 값이 있어야 정상적으로 동작, 없는 경우 계속해서 INSERT 실행
     * Unique Key 값이 있으면 INSERT 문에 있는 Unique Key 값을 기준으로 INSERT / UPDATE 자동으로 실행
     *
     * @param array $set 칼럼 이름 및 값을 MAP 형태의 배열로 작성
     * @return $this 메소드 체인으로 사용 가능
     * @throws Exception
     */
    public function upsert(array $set): static
    {
        $this->inProgressQuery = 'upsert';
        $this->callFunctionLog[] = 'upsert';

        // 트랜잭션 중 $this->set 중복 사용 방지를 위해 임시 값 사용
        $tempSet = '';
        $tempUpsertSet = '';

        foreach ($set as $column => $value) {
            $bindKey = ':' . md5(uniqid($column));
            $upsertKey = ':' . md5(uniqid($column, true));

            // 바인딩 할 파라미터 별도 저장
            $this->param[$bindKey] = $value;
            $this->param[$upsertKey] = $value;

            // 옵션이 들어있는 조건인지 확인
            if ($optionalValue = $this->optionalValue($value)) {
                switch (strtoupper($optionalValue['option'])) {
                    case 'PASSWORD':
                        // 암호화 : password_hash('PASSWORD', PASSWORD_DEFAULT)
                        // 암호화 검증 : password_verify('PASSWORD', $hashValue)
                        $this->param[$bindKey] = password_hash($optionalValue['value'], PASSWORD_DEFAULT);
                        break;
                    case 'NUMBER':
                        if (!is_numeric($optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 숫자 형태가 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'ALPHABET':
                        if (!ctype_alpha($optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 알파벳 형태가 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'PHONE':
                        if (!preg_match("/^[0-9]{3}-[0-9]{4}-[0-9]{4}$/", $optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 하이픈을 포함한 전화번호 형식이 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'EMAIL':
                        if (!(filter_var($optionalValue['value'], FILTER_VALIDATE_EMAIL) && preg_match('/@.+\./', $optionalValue['value']))) {
                            throw new Exception($optionalValue['value'] . ' 는 이메일 형식이 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                }
            }

            $tempSet .= ($column . ' = ' . $bindKey);
            $tempUpsertSet .= ($column . ' = ' . $upsertKey);
            if ($column !== array_key_last($set)) {
                $tempSet .= ', ';
                $tempUpsertSet .= ', ';
            }
        }

        $this->set = $tempSet;
        $this->upsertSet = $tempUpsertSet;

        return $this;
    }

    /**
     * 쿼리 실행 시, UPDATE SET 구문에 사용 할 쿼리 스트링
     *
     * @param array $set 칼럼 이름 및 값을 MAP 형태의 배열로 작성
     * @return $this 메소드 체인으로 사용 가능
     * @throws Exception
     */
    public function update(array $set): static
    {
        $this->inProgressQuery = 'update';
        $this->callFunctionLog[] = 'update';

        // 트랜잭션 중 $this->set 중복 사용 방지를 위해 임시 값 사용
        $tempSet = '';

        foreach ($set as $column => $value) {
            $bindKey = ':' . md5(uniqid($column));

            // 바인딩 할 파라미터 별도 저장
            $this->param[$bindKey] = $value;

            // 옵션이 들어있는 조건인지 확인
            if ($optionalValue = $this->optionalValue($value)) {
                switch (strtoupper($optionalValue['option'])) {
                    case 'PASSWORD':
                        // 암호화 : password_hash('PASSWORD', PASSWORD_DEFAULT)
                        // 암호화 검증 : password_verify('PASSWORD', $hashValue)
                        $this->param[$bindKey] = password_hash($optionalValue['value'], PASSWORD_DEFAULT);
                        break;
                    case 'NUMBER':
                        if (!is_numeric($optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 숫자 형태가 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'ALPHABET':
                        if (!ctype_alpha($optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 알파벳 형태가 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'PHONE':
                        if (!preg_match("/^[0-9]{3}-[0-9]{4}-[0-9]{4}$/", $optionalValue['value'])) {
                            throw new Exception($optionalValue['value'] . ' 는 하이픈을 포함한 전화번호 형식이 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                    case 'EMAIL':
                        if (!(filter_var($optionalValue['value'], FILTER_VALIDATE_EMAIL) && preg_match('/@.+\./', $optionalValue['value']))) {
                            throw new Exception($optionalValue['value'] . ' 는 이메일 형식이 아닙니다.');
                        }

                        $this->param[$bindKey] = $optionalValue['value'];
                        break;
                }
            }

            $tempSet .= ($column . ' = ' . $bindKey);
            if ($column !== array_key_last($set)) {
                $tempSet .= ', ';
            }
        }

        $this->set = $tempSet;

        return $this;
    }

    /**
     * 쿼리 실행 시, DELETE 구문 실행을 위한 함수
     * 별도 파라미터는 불필요하고, where 함수를 통해서 조건절 작성
     *
     * @return $this 메소드 체인으로 사용 가능
     */
    public function delete(): static
    {
        $this->inProgressQuery = 'delete';
        $this->callFunctionLog[] = 'delete';

        return $this;
    }

    /**
     * 오토커밋 OFF 및 트랜잭션 수동으로 시작
     * 오토커밋 적용되어 있는 지 확인 필요 (SHOW VARIABLES LIKE 'autocommit')
     * 오토커밋 적용되어 있는 경우, 오류 발생 시 자동으로 롤백처리
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * 트랜잭션 중 커밋
     *
     * @return bool
     * @throws Exception
     */
    public function commit(): bool
    {
        // 트랜잭션 중인지 확인
        if ($this->pdo->inTransaction()) {
            return $this->pdo->commit();
        } else {
            throw new Exception('트랜잭션을 시작한 후에 커밋할 수 있습니다.', 500);
        }
    }

    /**
     * 쿼리 실행에 사용 할 쿼리 문자열 생성
     *
     * @return string 쿼리 문자열
     * @throws Exception
     */
    private function queryMaker(): string
    {
        if (empty($this->table)) {
            throw new Exception('테이블은 비울 수 없습니다.', 500);
        }

        // where 함수에서 만들어진 가장 마지막 AND 값은 제거
        if (strlen($this->where) - strrpos($this->where, 'AND') === 4) {
            $this->where = substr($this->where, 0, -4);
        }

        $query = '';
        switch ($this->inProgressQuery) {
            case 'select':
                if (empty($this->select)) {
                    throw new Exception('컬럼은 비울 수 없습니다.', 500);
                }

                $query = "SELECT {$this->select} FROM {$this->table}";

                if (!empty($this->join)) {
                    $query .= " {$this->join} ";
                }
                if (!empty($this->where)) {
                    $query .= " WHERE {$this->where} ";
                }
                if (!empty($this->groupByColumn)) {
                    $query .= " GROUP BY {$this->groupByColumn} ";
                }
                if (!empty($this->having)) {
                    $query .= " HAVING {$this->having} ";
                }
                if (!empty($this->orderBy)) {
                    $query .= " ORDER BY {$this->orderBy} ";
                }
                if (!empty($this->limit)) {
                    $query .= " LIMIT {$this->limit} ";
                }
                if (!empty($this->offset)) {
                    $query .= " OFFSET {$this->offset} ";
                }
                break;
            case 'insert':
                $query = "INSERT INTO {$this->table} SET {$this->set}";
                break;
            case 'upsert':
                $query = "INSERT INTO {$this->table} SET {$this->set} ON DUPLICATE KEY UPDATE {$this->upsertSet}";
                break;
            case 'update':
                if (!in_array('where', $this->callFunctionLog)) {
                    throw new Exception('조건문이 반드시 포함되어야 합니다.', 500);
                }

                $query = "UPDATE {$this->table} SET {$this->set} WHERE {$this->where}";
                break;
            case 'delete':
                if (!in_array('where', $this->callFunctionLog)) {
                    throw new Exception('조건문이 반드시 포함되어야 합니다.', 500);
                }

                $query = "DELETE FROM {$this->table} WHERE {$this->where}";
                break;
        }

        return $query;
    }

    /**
     * 쿼리 문자열 생성 후 실행
     *
     * @return array|false|int|string array: SELECT, false: 쿼리 실행 실패, int: UPDATE / DELETE, string: INSERT / UPSERT
     * @throws Exception
     */
    public function exec(): false|array|int|string
    {
        // 생성 된 쿼리 문자열
        $query = $this->queryMaker();

        $stmt = $this->pdo->prepare($query);

        // 파라미터로 별도 저장해두었던 배열값 바인딩 처리
        foreach ($this->param as $key => $value) {
            $stmt->bindParam($key, $this->param[$key]);
        }

        // 쿼리 실행
        $execute = $stmt->execute();

        $result = false;
        if ($execute) {
            switch ($this->inProgressQuery) {
                case 'select':
                    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // SELECT WHERE 조건에서 옵션으로 PASSWORD 받은 경우
                    // 다른 조건으로 먼저 검색 후, 검색 결과가 한 개의 열 일 때만 패스워드 비교 처리
                    if (!empty($this->wherePasswordValue)) {
                        if ($stmt->rowCount() !== 1) {
                            throw new Exception('PASSWORD 검색은 단일 검색만 가능합니다.', 500);
                        }

                        // 패스워드 비교
                        if (!password_verify($this->wherePasswordValue, $result[0][$this->wherePasswordColumn])) {
                            $result = false;
                        }
                    }
                    break;
                case 'insert':
                case 'upsert':
                    $result = $this->pdo->lastInsertId();
                    break;
                case 'update':
                case 'delete':
                    $result = $stmt->rowCount();
                    break;
            }
        }

        return $result;
    }

    /**
     * MySQL 실행 로그 확인을 위해서는 설정 값 확인 필요
     * SHOW VARIABLES LIKE 'general%'
     * SET GLOBAL general_log = on
     *
     * @return array
     * @throws Exception
     */
    public function debug(): array
    {
        // 생성 된 쿼리 문자열
        $query = $this->queryMaker();

        $stmt = $this->pdo->prepare($query);

        // 파라미터로 별도 저장해두었던 배열값 바인딩 처리
        foreach ($this->param as $key => $value) {
            $stmt->bindParam($key, $this->param[$key]);
        }

        $return['query'] = $stmt->queryString;

        ob_start();
        $stmt->debugDumpParams();
        $return['dump'] = ob_get_contents();
        ob_end_clean();
        return $return;
    }
}