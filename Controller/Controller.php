<?php

namespace Controller;

abstract class Controller
{
    /**
     * 환경변수 설정값
     *
     * @var array|false $env
     */
    private array|false $env;

    /**
     * 환경변수에 접근 가능한 키값
     *
     * @var array|string[] $env_whitelist
     */
    private array $env_whitelist = [
        'SAMPLE'
    ];

    /**
     * POST, PATCH, PUT, DELETE 메소드의 Body-Content
     *
     * @var array
     */
    protected array $body;

    /**
     * 요청 URL에서 {} 값을 리소스에서 찾은 값
     * e.g. 요청 URL : /sports/soccer/player/7
     *      리소스 :   /sports/{sportsId}/player/{playerId}
     *      > ['sportsId' => 'soccer', 'playerId' => '7']
     *
     * @var array
     */
    protected array $param;

    /**
     * @param array|false $env 환경변수 설정값
     * @param array $body POST, PATCH, PUT, DELETE 메소드의 Body-Content
     * @param array $param 요청 URL에서 {} 값을 리소스에서 찾은 값
     */
    public function __construct(array|bool $env, array $body = [], array $param = [])
    {
        $this->env = $env;
        $this->body = $body;
        $this->param = $param;
    }

    /**
     * @param string $viewerFile 뷰어 파일 이름
     * @param array $variables 전달할 변수
     * @param string $headerFile 헤더 파일 이름
     * @param string $footerFile 푸터 파일 이름
     * @return void
     */
    protected function view(string $viewerFile, array $variables = [], string $headerFile = 'defaultHeader', string $footerFile = 'defaultFooter'): void
    {
        // 전달할 값을 global 변수로 등록
        foreach ($variables as $key => $value) {
            $GLOBALS[$key] = $value;
        }

        // View 파일 생성
        include_once $_SERVER['DOCUMENT_ROOT'] . '/Variety/paint/template/' . $headerFile . '.php';
        include_once $_SERVER['DOCUMENT_ROOT'] . '/Variety/paint/' . $viewerFile . '.php';
        include_once $_SERVER['DOCUMENT_ROOT'] . '/Variety/paint/template/' . $footerFile . '.php';
    }

    /**
     * @param string $key
     * @return array|false
     */
    protected function getEnv(string $key): array|false
    {
        if (!in_array($key, $this->env_whitelist)) {
            return false;
        }

        return $this->env[$key];
    }
}