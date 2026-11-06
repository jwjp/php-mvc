<?php

namespace Controller\Board;

use Controller\Controller;
use Controller\Csrf;
use Controller\HttpException;
use Controller\Response;
use Model\Board\PostModel;
use Model\Board\Text;
use Model\Board\UserModel;

/**
 * 게시판 화면 컨트롤러의 공통 기능.
 */
abstract class BoardController extends Controller
{
    /**
     * 게시판 공통 헤더·푸터로 화면을 그립니다. 로그인 회원, CSRF 토큰, 알림 메시지를 함께 넘깁니다.
     */
    protected function page(string $view, string $title, array $variables = [], int $status = 200): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $response = $this->view('board/' . $view, $variables + [
            'title' => $title,
            'currentUser' => Auth::user(),
            'csrfToken' => Csrf::token(),
            'flash' => is_string($flash) ? $flash : null,
            'errors' => [],
        ], 'boardHeader', 'boardFooter');

        return $status === 200 ? $response : Response::html($response->body, $status);
    }

    /**
     * 다음 화면에 한 번만 보여 줄 메시지를 저장하고 이동합니다.
     */
    protected function redirectWith(string $path, string $message): Response
    {
        $_SESSION['flash'] = $message;
        return Response::redirect($path);
    }

    /**
     * 선택 입력값을 문자열로 읽습니다. 배열 등 다른 형식은 빈 문자열로 처리합니다.
     */
    protected function text(string $key): string
    {
        $value = $this->variables[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * 경로의 ID를 읽습니다. 숫자가 아니면 없는 글과 같게 404로 처리합니다.
     *
     * @throws HttpException
     */
    protected function idParameter(string $key): int
    {
        return Text::id($this->parameter($key)) ?? throw new HttpException('요청한 글을 찾을 수 없습니다.', 404);
    }

    private ?PostModel $posts = null;
    private ?UserModel $users = null;

    protected function users(): UserModel
    {
        return $this->users ??= new UserModel($this->environments);
    }

    /**
     * 이메일 인증 링크를 보냅니다. 링크는 24시간 동안 한 번 쓸 수 있습니다.
     */
    protected function sendVerificationMail(array $user): void
    {
        $token = $this->users()->issueToken($user['id'], UserModel::VERIFY_EMAIL, 24 * 60);
        $mailer = new Mailer($this->environments);
        $mailer->send($user['email'], '[PHP MVC 게시판] 이메일 인증', implode("\n", [
            $user['display_name'] . '님, 가입해 주셔서 감사합니다.',
            '',
            '아래 링크를 열면 이메일 인증이 끝납니다. 링크는 24시간 동안 한 번 쓸 수 있습니다.',
            $mailer->url('/verify-email?token=' . $token),
            '',
            '가입한 적이 없다면 이 메일을 무시하세요.',
        ]));
    }

    /**
     * 한 요청 안에서는 DB 연결 하나를 재사용합니다.
     */
    protected function posts(): PostModel
    {
        return $this->posts ??= new PostModel($this->environments);
    }

    /**
     * @throws HttpException
     */
    protected function findPost(int $id): array
    {
        return $this->posts()->find($id) ?? throw new HttpException('요청한 글을 찾을 수 없습니다.', 404);
    }
}
