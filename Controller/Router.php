<?php

namespace Controller;

use Controller\Board\Auth;
use Controller\Board\AuthController;
use Controller\Board\CommentController;
use Controller\Board\ErrorController;
use Controller\Board\PostController;
use Controller\Sample\SampleController;
use Controller\Sample\SampleViewController;

class Router extends RouterImpl
{
    protected function post(): static
    {
        // 게시판
        $this->execute('/posts', PostController::class, 'store');
        $this->execute('/posts/{postId}/comments', CommentController::class, 'store');
        $this->execute('/signup', AuthController::class, 'signup');
        $this->execute('/login', AuthController::class, 'login');
        $this->execute('/logout', AuthController::class, 'logout');

        // foo/bar JSON API 샘플
        $this->execute('/foo', SampleController::class, 'createFooWithQB');
        $this->execute('/bar', SampleController::class, 'createBarWithFooId');
        $this->execute('/foobar', SampleController::class, 'createFooBarWithQB');

        return $this;
    }

    protected function get(): static
    {
        // 게시판. /posts/new를 /posts/{postId}보다 먼저 등록해야 new가 ID로 해석되지 않습니다.
        $this->execute('/', PostController::class, 'index');
        $this->execute('/posts/new', PostController::class, 'create');
        $this->execute('/posts/{postId}', PostController::class, 'show');
        $this->execute('/posts/{postId}/edit', PostController::class, 'edit');
        $this->execute('/signup', AuthController::class, 'signupForm');
        $this->execute('/login', AuthController::class, 'loginForm');

        // foo/bar JSON API 샘플
        $this->execute('/sample/view', SampleViewController::class, 'index');
        $this->execute('/csrf-token', SampleViewController::class, 'csrfToken');
        $this->execute('/foo', SampleController::class, 'readFooAll');
        $this->execute('/foo/{fooId}', SampleController::class, 'readFooById');
        $this->execute('/bar', SampleController::class, 'readBarAll');
        $this->execute('/bar/{barId}', SampleController::class, 'readBarById');
        $this->execute('/foobar/{fooId}', SampleController::class, 'readFooBarByFooIdWithQB');

        return $this;
    }

    protected function put(): static
    {
        $this->execute('/posts/{postId}', PostController::class, 'update');

        $this->execute('/foo/{fooId}', SampleController::class, 'updateFooById');
        $this->execute('/bar/{barId}', SampleController::class, 'updateBarById');

        return $this;
    }

    protected function delete(): static
    {
        $this->execute('/posts/{postId}', PostController::class, 'destroy');
        $this->execute('/comments/{commentId}', CommentController::class, 'destroy');

        $this->execute('/foo/{fooId}', SampleController::class, 'deleteFooByIdWithQB');
        $this->execute('/bar/{barId}', SampleController::class, 'deleteBarById');

        return $this;
    }

    /**
     * 세션의 로그인 회원을 읽습니다. 화면별 권한은 컨트롤러가 Auth::require()로 확인합니다.
     */
    protected function guard(): static
    {
        Auth::load($this->environments);
        return $this;
    }

    /**
     * 브라우저 화면 요청의 오류를 JSON 대신 HTML로 바꿉니다.
     * JSON API 요청(Accept에 text/html이 없는 요청)은 그대로 전역 오류 처리로 넘깁니다.
     */
    protected function filter(): static
    {
        if ($this->exception === null || !str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
            return $this;
        }

        $exception = $this->exception;
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status === 401) {
            // 로그인 뒤 돌아올 경로입니다. 화면 요청(GET)만 기억합니다.
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                $path = '/' . trim(preg_replace('#/+#', '/', explode('?', $_SERVER['REQUEST_URI'] ?? '/')[0]), '/');
                $_SESSION['afterLogin'] = str_contains($path, '\\') ? '/' : $path;
            }
            $_SESSION['flash'] = $exception->getMessage();
            $this->result = Response::redirect('/login');
        } else {
            if ($status >= 500) {
                error_log(get_class($exception) . ': ' . $exception->getMessage()
                    . ' in ' . $exception->getFile() . ':' . $exception->getLine());
            }
            $message = $status >= 500 ? '서버 처리 중 문제가 발생했습니다.' : $exception->getMessage();
            $this->result = (new ErrorController($this->environments))->render($status, $message);
        }
        $this->exception = null;
        return $this;
    }
}
