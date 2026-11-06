<?php

namespace Controller\Board;

use Controller\Response;
use Model\Board\UserModel;

class AuthController extends BoardController
{
    /** GET /signup */
    public function signupForm(): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect('/');
        }
        return $this->page('signup', '회원 가입', ['values' => ['loginId' => '', 'displayName' => '']]);
    }

    /** POST /signup */
    public function signup(): Response
    {
        $loginId = $this->text('loginId');
        $displayName = $this->text('displayName');
        $password = $this->text('password');
        $errors = UserModel::signupErrors($loginId, $displayName, $password, $this->text('passwordConfirm'));

        $users = new UserModel($this->environments);
        $id = $errors === [] ? $users->create($loginId, $displayName, $password) : null;
        if ($errors === [] && $id === null) {
            $errors['loginId'] = '이미 사용 중인 아이디입니다.';
        }
        if ($errors !== []) {
            return $this->page('signup', '회원 가입', [
                'values' => ['loginId' => $loginId, 'displayName' => $displayName],
                'errors' => $errors,
            ], 422);
        }

        Auth::login(['id' => $id, 'login_id' => $loginId, 'display_name' => $displayName]);
        return $this->redirectWith('/', '가입을 마쳤습니다. 환영합니다!');
    }

    /** GET /login */
    public function loginForm(): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect('/');
        }
        return $this->page('login', '로그인', ['loginId' => '']);
    }

    /** POST /login */
    public function login(): Response
    {
        $loginId = $this->text('loginId');
        $user = (new UserModel($this->environments))->verify($loginId, $this->text('password'));
        if ($user === null) {
            return $this->page('login', '로그인', [
                'loginId' => $loginId,
                'errors' => ['form' => '아이디 또는 비밀번호가 올바르지 않습니다.'],
            ], 422);
        }

        // 로그인이 필요해 이동한 경우 원래 보려던 화면으로 돌아갑니다.
        // 값은 Router::filter()가 정규화한 내부 경로만 저장합니다.
        $next = $_SESSION['afterLogin'] ?? '/';
        unset($_SESSION['afterLogin']);
        Auth::login($user);
        return $this->redirectWith(is_string($next) ? $next : '/', $user['display_name'] . '님, 반갑습니다.');
    }

    /** POST /logout */
    public function logout(): Response
    {
        Auth::logout();
        return $this->redirectWith('/', '로그아웃했습니다.');
    }
}
