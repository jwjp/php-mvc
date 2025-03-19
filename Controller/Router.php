<?php

namespace Controller;

use Controller\Sample\SampleController;
use Controller\Sample\SampleViewController;
use Exception;

class Router
{
    public function __construct()
    {
        // POST, GET, PATCH, PUT, DELETE 중 요청 온 메소드 함수 실행
        // execute() 실행 안되는 경우, analyse() 실행
        $this->{strtolower($_SERVER['REQUEST_METHOD'])}()->analyse()->end();
    }

    private function post(): Router
    {
        // $this->execute('/foo', SampleController::class, 'createFoo');
        $this->execute('/foo', SampleController::class, 'createFooWithQB');
        $this->execute('/bar', SampleController::class, 'createBarWithFooId');
        $this->execute('/foobar', SampleController::class, 'createFooBarWithQB');

        return $this;
    }

    private function get(): Router
    {
        $this->execute('/foo', SampleController::class, 'readFooAll');
        $this->execute('/foo/{fooId}', SampleController::class, 'readFooById');
        $this->execute('/bar', SampleController::class, 'readBarAll');
        $this->execute('/bar/{barId}', SampleController::class, 'readBarById');
        $this->execute('/foobar/{fooId}', SampleController::class, 'readFooBarByFooIdWithQB');

        $this->execute('/sample/view', SampleViewController::class, 'index');

        return $this;
    }

    private function patch(): Router
    {

        return $this;
    }

    private function put(): Router
    {
        $this->execute('/foo/{fooId}', SampleController::class, 'updateFooById');
        $this->execute('/bar/{barId}', SampleController::class, 'updateBarById');

        return $this;
    }

    private function delete(): Router
    {
        // $this->execute('/foo/{fooId}', SampleController::class, 'deleteFooById');
        $this->execute('/foo/{fooId}', SampleController::class, 'deleteFooByIdWithQB');
        $this->execute('/bar/{barId}', SampleController::class, 'deleteBarById');

        return $this;
    }

    private function execute(string $resource, string $class, string $function): void
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

    private function analyse(): Router
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
        if (!method_exists($class, $function)) return $this;

        // POST, PATCH, PUT, DELETE 메소드의 Body-Content
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        (new $class($body))->$function();
        exit;
    }

    /**
     * @throws Exception
     */
    private function end()
    {
        throw new Exception('\'' . $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'])['path'] . '\' Not Found.', 404);
    }
}