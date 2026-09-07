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
     * 전달된 설정 또는 .sample.ini의 DATABASE 설정으로 PDO 연결
     */
    public function __construct(array|false $ini = false)
    {
        if ($ini === false) {
            $configPath = defined('APP_CONFIG_PATH')
                ? APP_CONFIG_PATH
                : dirname(__DIR__) . '/.sample.ini';
            if (!is_file($configPath)) {
                throw new \RuntimeException('.sample.ini 환경설정 파일을 찾을 수 없습니다.', 500);
            }
            $ini = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
        }
        if ($ini === false) {
            throw new \RuntimeException('.sample.ini 환경설정 파일을 찾을 수 없습니다.', 500);
        }

        foreach (['HOST', 'DBNAME', 'USERNAME', 'PASSWORD'] as $key) {
            if (!isset($ini['DATABASE'][$key])) {
                throw new \RuntimeException("DATABASE.{$key} 환경설정이 필요합니다.", 500);
            }
        }

        $this->pdo = new PDO(
            'mysql:host=' . $ini['DATABASE']['HOST'] . ';port=' . ($ini['DATABASE']['PORT'] ?? 3306)
                . ';dbname=' . $ini['DATABASE']['DBNAME'] . ';charset=utf8mb4',
            (string) $ini['DATABASE']['USERNAME'],
            (string) $ini['DATABASE']['PASSWORD'],
            [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }

    /**
     * @return QueryBuilder 쿼리 빌더
     */
    public function queryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->pdo);
    }
}
