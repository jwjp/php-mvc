<?php

namespace Controller\Board;

use Controller\HttpException;
use Controller\Response;
use Model\Board\RateLimiter;
use Model\Board\UserModel;

/**
 * 비밀번호를 잊은 회원의 재설정.
 *
 * 인증한 이메일로만 링크를 보냅니다. 인증하지 않은 주소는 다른 사람의 주소일 수 있기 때문입니다.
 */
class PasswordController extends BoardController
{
    private const LINK_MINUTES = 30;
    private const IP_LIMIT = 10;
    private const IP_WINDOW_MINUTES = 15;
    private const SENT_MESSAGE = '가입하고 인증한 이메일이면 비밀번호 재설정 링크를 보냈습니다. 메일함을 확인하세요.';

    /** GET /password/forgot */
    public function forgotForm(): Response
    {
        return $this->page('forgot', '비밀번호 찾기', ['email' => '']);
    }

    /** POST /password/forgot */
    public function sendResetLink(): Response
    {
        $email = $this->text('email');
        if (($message = UserModel::emailError($email)) !== null) {
            return $this->page('forgot', '비밀번호 찾기', ['email' => $email, 'errors' => ['email' => $message]], 422);
        }

        $limiter = new RateLimiter($this->environments);
        if ($limiter->tooMany('reset-ip', Auth::clientIp(), self::IP_LIMIT, self::IP_WINDOW_MINUTES)) {
            return $this->page('forgot', '비밀번호 찾기', [
                'email' => $email,
                'errors' => ['email' => '요청이 너무 많습니다. ' . self::IP_WINDOW_MINUTES . '분 뒤에 다시 시도하세요.'],
            ], 429);
        }
        $limiter->hit('reset-ip', Auth::clientIp());

        // 가입 여부를 알 수 없도록 결과와 관계없이 같은 메시지를 보여 줍니다.
        $user = $this->users()->findByEmail($email);
        if ($user !== null && $user['email_verified_at'] !== null
            && !$this->users()->sentRecently($user['id'], UserModel::RESET_PASSWORD, 60)) {
            $token = $this->users()->issueToken($user['id'], UserModel::RESET_PASSWORD, self::LINK_MINUTES);
            $mailer = new Mailer($this->environments);
            $mailer->send($user['email'], '[PHP MVC 게시판] 비밀번호 재설정', implode("\n", [
                $user['display_name'] . '님, 비밀번호 재설정을 요청하셨습니다.',
                '',
                '아래 링크에서 새 비밀번호를 정하세요. 링크는 ' . self::LINK_MINUTES . '분 동안 한 번 쓸 수 있습니다.',
                $mailer->url('/password/reset?token=' . $token),
                '',
                '요청한 적이 없다면 이 메일을 무시하세요. 비밀번호는 바뀌지 않습니다.',
            ]));
        }
        return $this->redirectWith('/login', self::SENT_MESSAGE);
    }

    /** GET /password/reset?token= */
    public function resetForm(): Response
    {
        $token = $this->variables['token'] ?? null;
        $this->requireValidToken($token);
        return $this->page('reset', '새 비밀번호', ['token' => $token]);
    }

    /** POST /password/reset */
    public function reset(): Response
    {
        $token = $this->variables['token'] ?? null;
        $this->requireValidToken($token);

        $password = $this->text('password');
        $errors = UserModel::passwordErrors($password, $this->text('passwordConfirm'));
        if ($errors !== []) {
            return $this->page('reset', '새 비밀번호', ['token' => $token, 'errors' => $errors], 422);
        }

        // 토큰을 먼저 사용 처리합니다. 같은 링크로 동시에 두 번 보내도 한 번만 바뀝니다.
        $userId = $this->users()->consumeToken(UserModel::RESET_PASSWORD, $token)
            ?? throw new HttpException('재설정 링크가 만료되었거나 이미 사용되었습니다. 다시 요청하세요.', 400);
        $this->users()->changePassword($userId, $password);
        (new RateLimiter($this->environments))->clear('login-id', $this->users()->find($userId)['login_id']);

        Auth::logout();
        return $this->redirectWith('/login', '비밀번호를 바꿨습니다. 새 비밀번호로 로그인하세요.');
    }

    /**
     * @throws HttpException
     */
    private function requireValidToken(mixed $token): void
    {
        if ($this->users()->tokenUser(UserModel::RESET_PASSWORD, $token) === null) {
            throw new HttpException('재설정 링크가 만료되었거나 이미 사용되었습니다. 다시 요청하세요.', 400);
        }
    }
}
