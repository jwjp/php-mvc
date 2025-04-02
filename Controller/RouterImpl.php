<?php

namespace Controller;

use Exception;

abstract class RouterImpl
{
    /**
     * .ini 환경변수 값
     *
     * @var array|false $environments
     */
    private array|false $environments = false;

    /**
     * @var array $variables
     */
    private array $variables = [];

    /**
     * execute 함수에서 동적 인스턴스화 실행중인지 확인
     *
     * @var bool $in_progress
     */
    private bool $in_progress = false;

    /**
     * 컨트롤러 실행 결과값
     *
     * @var mixed $result
     */
    private mixed $result = false;

    /**
     *
     * @throws Exception
     */
    protected function __construct()
    {
        // GET 메소드의 Query-String
        // POST, PATCH, PUT, DELETE 메소드의 Body-Content
        // PATCH, PUT, DELETE 메소드의 경우에는 $_POST['_method'] 값 필요
        $this->variables = $this->parseRequestVariables();

        // 환경변수 설정값
        $this->environments = parse_ini_file($_SERVER['DOCUMENT_ROOT'] . '/.sample.ini', true);

        /**
         * TODO: 요청 & 응답 생명주기
         * 미들웨어 > 가드 > 인터셉터 > 파이프 > 컨트롤러<>서비스 > 인터셉터 > 예외필터
         * 1. 미들웨어 (전처리 작업 & 로그 기록)
         * 2. 가드 (권한 인증)
         * 3. 인터셉터 (요청 전/후 데이터 수정)
         * 4. 파이프 (값 검증)
         * 5. 예외 필터 (에러 처리 & 에러 로그 기록)
         *
         * POST, GET, PATCH, PUT, DELETE 중 요청 온 메소드 함수 실행
         * execute() 실행 안되는 경우, analyse() 실행
         */
        $this
            ->middleware()
            ->guard()
            ->interceptor('before')
            ->{strtolower($_SERVER['REQUEST_METHOD'])}()->analyse()
            ->interceptor('after')
            ->filter()
            ->end();
    }

    /**
     * HTTP 요청 본문 데이터를 처리
     *
     * @return array
     * @throws Exception
     */
    private function parseRequestVariables(): array
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $_method = isset($_POST['_method']) ? strtoupper($_POST['_method']) : '';

        switch ($method) {
            case 'POST':
                if (in_array($_method, ['PATCH', 'PUT', 'DELETE'])) {
                    // 사용한 메소드 임시값 제거
                    unset($_POST['_method']);
                    // 요청 메소드 덮어쓰기
                    $_SERVER['REQUEST_METHOD'] = $_method;
                }
                return $_POST;
            case 'GET':
                return $_GET;
            case 'PATCH':
            case 'PUT':
            case 'DELETE':
                throw new Exception('POST \'_method\' value is required for this request', 405);
            default:
                throw new Exception('Invalid request method', 405);
        }
    }

    /**
     * 전처리 작업 & 로그 기록
     */
    protected function middleware(): static
    {
        return $this;
    }

    /**
     * 권한 인증
     */
    protected function guard(): static
    {
        return $this;
    }

    /**
     * 요청 전/후 데이터 수정
     */
    protected function interceptor($when): static
    {
        return $this;
    }

    /**
     * 값 검증
     */
    protected function pipe(): static
    {
        return $this;
    }

    /**
     * 에러 처리 & 에러 로그 기록
     */
    protected function filter(): static
    {
        return $this;
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
     * @return RouterImpl
     */
    protected function execute(string $resource, string $class, string $function): static
    {
        // 메서드가 이미 실행중인 경우 건너뜀
        if ($this->in_progress) return $this;

        // {} 사이에 들어오는 값
        $parameters = [];

        // 요청 URL
        $url = explode('/', parse_url($_SERVER['REQUEST_URI'])['path']);

        // 요청 URL과 비교할 리소스
        $resourceUrl = explode('/', $resource);

        // 요청 URL과 리소스의 길이 비교
        if (count($resourceUrl) !== count($url)) return $this;

        // 리소스에서 {} 값을 따로 추출
        $matchCount = preg_match_all('/{([^}]*)}/', $resource, $matches);

        // 요청 URL과 {} 값의 길이 비교
        if (count(array_diff_assoc($resourceUrl, $url)) !== $matchCount) return $this;

        // 요청 URL에서 {} 값을 리소스에서 찾아 따로 저장
        foreach ($matches[0] as $idx => $val) {
            $key = array_search($val, $resourceUrl);
            $parameters[$matches[1][$idx]] = $url[$key];
        }

        // 메소드가 있는지 확인
        if (empty($class) || empty($function) || !method_exists($class, $function)) return $this;

        // 실행중으로 표시
        $this->in_progress = true;

        // 동적 인스턴스화
        $this->result = (new $class($this->environments, $this->variables, $parameters))->$function();

        return $this;
    }

    /**
     * 라우팅 분석 및 요청에 맞는 메서드 호출
     *
     * @return RouterImpl
     */
    protected function analyse(): static
    {
        // 메서드가 이미 실행중인 경우 건너뜀
        if ($this->in_progress) return $this;

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

        // 동적 인스턴스화
        $this->result = (new $class($this->environments, $this->variables))->$function();

        // 실행중으로 표시
        $this->in_progress = true;

        return $this;
    }

    /**
     * 최종 출력 및 오류 표시
     *
     * @throws Exception
     */
    protected function end(): void
    {
        if ($this->in_progress) {
            echo json_encode($this->result, JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('\'' . $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'])['path'] . '\' Not Found.', 404);
        }
    }
}