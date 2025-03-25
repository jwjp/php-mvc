<?php

namespace Controller;

use Exception;

abstract class RouterImpl
{
    protected function __construct()
    {
        // POST, GET, PATCH, PUT, DELETE 중 요청 온 메소드 함수 실행
        // execute() 실행 안되는 경우, analyse() 실행
        $this->{strtolower($_SERVER['REQUEST_METHOD'])}()->analyse()->end();
    }

    /**
     * HTTP POST 요청 처리
     */
    protected function post(): static
    {
        return $this;
    }

    /**
     * HTTP GET 요청 처리
     */
    protected function get(): static
    {
        return $this;
    }

    /**
     * HTTP PATCH 요청 처리
     */
    protected function patch(): static
    {
        return $this;
    }

    /**
     * HTTP PUT 요청 처리
     */
    protected function put(): static
    {
        return $this;
    }

    /**
     * HTTP DELETE 요청 처리
     */
    protected function delete(): static
    {
        return $this;
    }

    /**
     * 지정된 리소스, 클래스, 메서드를 실행
     *
     * @param string $resource
     * @param string $class
     * @param string $function
     * @return void
     */
    protected function execute(string $resource, string $class, string $function): void
    {
        // {} 사이에 들어오는 값
        $param = [];

        // 요청 URL
        $url = explode('/', parse_url($_SERVER['REQUEST_URI'])['path']);

        // 요청 URL과 비교할 리소스
        $resourceUrl = explode('/', $resource);

        // 요청 URL과 리소스의 길이 비교
        if (count($resourceUrl) !== count($url)) return;

        // 리소스에서 {} 값을 따로 추출
        $matchCount = preg_match_all('/{([^}]*)}/', $resource, $matches);

        // 요청 URL과 {} 값의 길이 비교
        if (count(array_diff_assoc($resourceUrl, $url)) !== $matchCount) return;

        // 요청 URL에서 {} 값을 리소스에서 찾아 따로 저장
        foreach ($matches[0] as $idx => $val) {
            $key = array_search($val, $resourceUrl);
            $param[$matches[1][$idx]] = $url[$key];
        }

        // POST, PATCH, PUT, DELETE 메소드의 Body-Content
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        (new $class($body, $param))->$function();
        exit;
    }

    /**
     * 라우팅 분석 및 요청에 맞는 메서드 호출
     *
     * @return RouterImpl
     */
    protected function analyse(): static
    {
        // 요청 URL
        $url = explode('/', parse_url($_SERVER['REQUEST_URI'])['path']);

        // 첫 '/' 제거
        array_shift($url);

        // 함수명 추출
        $function = array_pop($url);

        // 컨트롤러 경로 생성
        $class = '\\Controller\\' . implode('\\', array_map('ucfirst', array_map('strtolower', $url))) . 'Controller';

        // 메소드가 있는지 확인
        if (empty($class) || empty($function) || !method_exists($class, $function)) return $this;

        // POST, PATCH, PUT, DELETE 메소드의 Body-Content
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        (new $class($body))->$function();
        exit;
    }

    /**
     * 요청 처리 실패시 호출
     *
     * @throws Exception
     */
    protected function end(): void
    {
        throw new Exception('\'' . $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'])['path'] . '\' Not Found.', 404);
    }
}