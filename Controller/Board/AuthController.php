<?php

namespace Controller\Board;

use Controller\Response;
use Model\Board\RateLimiter;
use Model\Board\UserModel;

class AuthController extends BoardController
{
    /** 같은 아이디로 15분 안에 5번 틀리면 잠깁니다. */
    private const LOGIN_ID_LIMIT = 5;
    /** 같은 IP에서 15분 안에 30번 틀리면 잠깁니다. 여러 아이디를 돌아가며 시도하는 경우를 막습니다. */
    private const LOGIN_IP_LIMIT = 30;
    private const LOGIN_WINDOW_MINUTES = 15;

    /** GET /signup */
    public function signupForm(): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect('/');
        }
        return $this->page('signup', '회원 가입', ['values' => ['loginId' => '', 'displayName' => '', 'email' => '']]);
    }

    /** POST /signup */
    public function signup(): Response
    {
        $loginId = $this->text('loginId');
        $displayName = $this->text('displayName');
        $email = $this->text('email');
        $password = $this->text('password');
        $errors = UserModel::signupErrors($loginId, $displayName, $email, $password, $this->text('passwordConfirm'));

        $result = $errors === [] ? $this->users()->create($loginId, $displayName, $email, $password) : null;
        if (is_string($result)) {
            $errors[$result] = $result === 'email' ? '이미 사용 중인 이메일입니다.' : '이미 사용 중인 아이디입니다.';
        }
        if ($errors !== []) {
            return $this->page('signup', '회원 가입', [
                'values' => ['loginId' => $loginId, 'displayName' => $displayName, 'email' => $email],
                'errors' => $errors,
            ], 422);
        }

        $user = $this->users()->find($result);
        Auth::login($user);
        try {
            $this->sendVerificationMail($user);
        } catch (\Throwable $exception) {
            // 가입은 이미 끝났으므로 실패를 기록하고, 내 정보에서 다시 보내도록 안내합니다.
            error_log('Verification mail failed: ' . $exception->getMessage());
            return $this->redirectWith('/account', '가입을 마쳤지만 인증 메일을 보내지 못했습니다. 잠시 뒤 다시 보내 주세요.');
        }
        return $this->redirectWith('/', '가입을 마쳤습니다. ' . $email . '으로 보낸 인증 메일의 링크를 열어 주세요.');
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
        $idKey = strtolower(trim($loginId));
        $limiter = new RateLimiter($this->environments);

        // 비밀번호를 확인하기 전에 막아야, 잠긴 동안 맞는 비밀번호를 찾아내지 못합니다.
        if ($limiter->tooMany('login-id', $idKey, self::LOGIN_ID_LIMIT, self::LOGIN_WINDOW_MINUTES)
            || $limiter->tooMany('login-ip', Auth::clientIp(), self::LOGIN_IP_LIMIT, self::LOGIN_WINDOW_MINUTES)) {
            return $this->page('login', '로그인', [
                'loginId' => $loginId,
                'errors' => ['form' => '로그인 시도가 너무 많습니다. ' . self::LOGIN_WINDOW_MINUTES . '분 뒤에 다시 시도하거나 비밀번호를 재설정하세요.'],
            ], 429);
        }

        $user = $this->users()->verify($loginId, $this->text('password'));
        if ($user === null) {
            $limiter->hit('login-id', $idKey);
            $limiter->hit('login-ip', Auth::clientIp());
            return $this->page('login', '로그인', [
                'loginId' => $loginId,
                'errors' => ['form' => '아이디 또는 비밀번호가 올바르지 않습니다.'],
            ], 422);
        }
        $limiter->clear('login-id', $idKey);

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
