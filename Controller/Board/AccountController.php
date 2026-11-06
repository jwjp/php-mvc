<?php

namespace Controller\Board;

use Controller\HttpException;
use Controller\Response;
use Model\Board\RateLimiter;
use Model\Board\UserModel;

/**
 * 내 정보: 이메일 인증과 비밀번호 변경.
 */
class AccountController extends BoardController
{
    /** 인증 메일은 1분에 한 번만 다시 보냅니다. */
    private const RESEND_INTERVAL_SECONDS = 60;

    /** GET /account */
    public function show(): Response
    {
        Auth::require();
        return $this->page('account', '내 정보');
    }

    /** POST /account/password */
    public function changePassword(): Response
    {
        $user = Auth::require();
        $password = $this->text('password');
        $errors = UserModel::passwordErrors($password, $this->text('passwordConfirm'));

        // 로그인된 세션을 가로챈 사람이 현재 비밀번호를 추측하지 못하도록 로그인과 같은 제한을 씁니다.
        $limiter = new RateLimiter($this->environments);
        if ($limiter->tooMany('login-id', $user['login_id'], 5, 15)) {
            return $this->page('account', '내 정보', [
                'errors' => ['currentPassword' => '시도가 너무 많습니다. 15분 뒤에 다시 시도하세요.'],
            ], 429);
        }
        if ($this->users()->verify($user['login_id'], $this->text('currentPassword')) === null) {
            $limiter->hit('login-id', $user['login_id']);
            $errors = ['currentPassword' => '현재 비밀번호가 올바르지 않습니다.'] + $errors;
        }
        if ($errors !== []) {
            return $this->page('account', '내 정보', ['errors' => $errors], 422);
        }

        $this->users()->changePassword($user['id'], $password);
        // session_version이 올라가 다른 기기는 로그아웃됩니다. 지금 세션은 새 값으로 다시 로그인합니다.
        Auth::login($this->users()->find($user['id']));
        return $this->redirectWith('/account', '비밀번호를 바꿨습니다. 다른 기기에서는 로그아웃되었습니다.');
    }

    /** POST /account/verify-email */
    public function resendVerification(): Response
    {
        $user = Auth::require();
        if ($user['email_verified_at'] !== null) {
            return $this->redirectWith('/account', '이미 인증한 이메일입니다.');
        }
        if ($this->users()->sentRecently($user['id'], UserModel::VERIFY_EMAIL, self::RESEND_INTERVAL_SECONDS)) {
            return $this->redirectWith('/account', '인증 메일은 1분에 한 번 보낼 수 있습니다. 잠시 뒤 다시 시도하세요.');
        }
        $this->sendVerificationMail($user);
        return $this->redirectWith('/account', $user['email'] . '으로 인증 메일을 다시 보냈습니다.');
    }

    /** GET /verify-email?token= */
    public function verifyEmail(): Response
    {
        $userId = $this->users()->consumeToken(UserModel::VERIFY_EMAIL, $this->variables['token'] ?? null)
            ?? throw new HttpException('인증 링크가 만료되었거나 이미 사용되었습니다. 내 정보에서 인증 메일을 다시 받으세요.', 400);
        $this->users()->markEmailVerified($userId);
        // 토큰이 주소창과 방문 기록에 남지 않도록 바로 다른 화면으로 이동합니다.
        return $this->redirectWith(Auth::user() === null ? '/login' : '/', '이메일 인증을 마쳤습니다.');
    }
}
