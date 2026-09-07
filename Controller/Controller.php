<?php

namespace Controller;



abstract class Controller
{
    /**
     * @var array $environments
     */
    protected array $environments;

    /**
     * GET 메소드의 Query-String
     * POST, PATCH, PUT, DELETE 메소드의 Body-Content
     *
     * @var array
     */
    protected array $variables;

    /**
     * 요청 URL에서 {} 값을 리소스에서 찾은 값
     * e.g. 요청 URL : /sports/soccer/player/7
     *      리소스 :   /sports/{sportsId}/player/{playerId}
     *      > ['sportsId' => 'soccer', 'playerId' => '7']
     *
     * @var array
     */
    protected array $parameters;

    /**
     * @param array $environments .ini 환경변수 값
     * @param array $variables POST, PATCH, PUT, DELETE 메소드의 Body-Content
     * @param array $parameters 요청 URL에서 {} 값을 리소스에서 찾은 값
     */
    public function __construct(array $environments = [], array $variables = [], array $parameters = [])
    {
        $this->environments = $environments;
        $this->variables = $variables;
        $this->parameters = $parameters;
    }

    /**
     * 필수 요청값을 반환
     *
     * @throws HttpException
     */
    protected function input(string $key): int|float|string|bool
    {
        if (!array_key_exists($key, $this->variables)) {
            throw new HttpException("필수 요청값 '{$key}'이(가) 없습니다.", 400);
        }
        if (!is_scalar($this->variables[$key])) {
            throw new HttpException("요청값 '{$key}'의 형식이 올바르지 않습니다.", 400);
        }

        return $this->variables[$key];
    }

    /**
     * 문자열 요청값을 반환
     *
     * @throws HttpException
     */
    protected function stringInput(string $key): string
    {
        $value = $this->input($key);
        if (!is_string($value)) {
            throw new HttpException("요청값 '{$key}'은(는) 문자열이어야 합니다.", 400);
        }

        return $value;
    }

    /**
     * 정수 검증에 사용할 요청값을 반환
     *
     * @throws HttpException
     */
    protected function integerInput(string $key): int
    {
        $value = $this->input($key);
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new HttpException("요청값 '{$key}'은(는) 정수여야 합니다.", 400);
        }

        return (int) $value;
    }

    /**
     * 필수 경로 파라미터를 반환
     *
     * @throws HttpException
     */
    protected function parameter(string $key): string
    {
        if (!array_key_exists($key, $this->parameters)) {
            throw new HttpException("경로 파라미터 '{$key}'을(를) 찾을 수 없습니다.", 400);
        }

        return $this->parameters[$key];
    }

    /**
     * @param string $viewerFile 뷰어 파일 이름
     * @param array $variables 전달할 변수
     * @param string $headerFile 헤더 파일 이름
     * @param string $footerFile 푸터 파일 이름
     * @return Response
     */
    protected function view(string $viewerFile, array $variables = [], string $headerFile = 'defaultHeader', string $footerFile = 'defaultFooter'): Response
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
        $paintDirectory = $root . '/Variety/paint';
        $templateDirectory = $paintDirectory . '/template';
        $headerPath = $this->resolveViewFile($templateDirectory, $headerFile);
        $viewPath = $this->resolveViewFile($paintDirectory, $viewerFile);
        $footerPath = $this->resolveViewFile($templateDirectory, $footerFile);

        $render = static function (array $__files, array $__variables): string {
            extract($__variables, EXTR_SKIP);
            ob_start();
            try {
                foreach ($__files as $__file) {
                    include $__file;
                }
                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        return Response::html($render([$headerPath, $viewPath, $footerPath], $variables));
    }

    /**
     * 지정된 View 디렉터리 밖의 파일이 include되지 않도록 실제 경로를 검증
     *
     * @throws HttpException
     */
    private function resolveViewFile(string $directory, string $file): string
    {
        if (!preg_match('#^[A-Za-z0-9/_-]+$#', $file)) {
            throw new HttpException('View 파일 이름이 올바르지 않습니다.', 500);
        }

        $basePath = realpath($directory);
        $filePath = realpath($directory . '/' . $file . '.php');
        if (
            $basePath === false
            || $filePath === false
            || !str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR)
            || !is_file($filePath)
        ) {
            throw new HttpException("View 파일 '{$file}'을(를) 찾을 수 없습니다.", 500);
        }

        return $filePath;
    }
}
