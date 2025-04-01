<?php

namespace Model;

use PDO;

abstract class Model
{
    /**
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * 기본 설정 값
     * 필요에 따라서 환경변수 지정 후 protected 변수로 사용
     *
     * e.g.
     * [DATABASE]
     * HOST = localhost
     * DBNAME = sample
     * USERNAME = root
     * PASSWORD = root
     *
     * [SAMPLE]
     * phpversion[] = "7.2"
     * phpversion[] = "7.3"
     * phpversion[] = "7.4"
     * phpversion[] = "8.1"
     *
     * [MQTT]
     * USERNAME = sample
     * PASSWORD = sample
     */
    public function __construct()
    {
        $ini = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/.sample.ini', true);
        $this->pdo = new PDO(
            "mysql:host={$ini['DATABASE']['HOST']};dbname={$ini['DATABASE']['DBNAME']}",
            $ini['DATABASE']['USERNAME'],
            $ini['DATABASE']['PASSWORD']
        );

        // PDO 기능이 아닌 데이터베이스 자체의 Prepared Statement 사용
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // 에러가 발생하는 경우 Exception 던지도록 설정
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return QueryBuilder 쿼리 빌더
     */
    public function queryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->pdo);
    }
}