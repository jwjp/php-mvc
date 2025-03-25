<?php

namespace Controller;

abstract class Controller
{
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
     * @param array $body POST, PATCH, PUT, DELETE 메소드의 Body-Content
     * @param array $param 요청 URL에서 {} 값을 리소스에서 찾은 값
     */
    public function __construct(array $body = [], array $param = [])
    {
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
        include_once $_SERVER['DOCUMENT_ROOT'] . '/Variety/template/' . $headerFile . '.php';
        include_once $_SERVER['DOCUMENT_ROOT'] . '/Variety/' . $viewerFile . '.php';
        include_once $_SERVER['DOCUMENT_ROOT'] . '/Variety/template/' . $footerFile . '.php';
    }
}