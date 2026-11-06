<?php

namespace Controller\Board;

use Controller\Response;

/**
 * Router::filter()가 화면 요청의 오류를 그릴 때 사용합니다. 라우트에는 등록하지 않습니다.
 */
class ErrorController extends BoardController
{
    public function render(int $status, string $message): Response
    {
        return $this->page('error', '오류 ' . $status, ['status' => $status, 'message' => $message], $status);
    }
}
