<?php

namespace Controller\Board;

use Controller\HttpException;
use Controller\Response;
use Model\Board\PostModel;

class PostController extends BoardController
{
    private const PER_PAGE = 10;

    /** GET / */
    public function index(): Response
    {
        $page = filter_var($this->variables['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($page === false) {
            throw new HttpException('페이지 번호가 올바르지 않습니다.', 400);
        }
        $result = $this->posts()->page($page, self::PER_PAGE);

        return $this->page('index', '게시판', [
            'posts' => $result['items'],
            'page' => $page,
            'lastPage' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'total' => $result['total'],
        ]);
    }

    /** GET /posts/{postId} */
    public function show(): Response
    {
        $post = $this->findPost($this->idParameter('postId'));
        return $this->page('show', $post['title'], [
            'post' => $post,
            'comments' => $this->posts()->comments((int) $post['id']),
            'commentBody' => '',
        ]);
    }

    /** GET /posts/new */
    public function create(): Response
    {
        Auth::requireVerified();
        return $this->page('form', '새 글', ['post' => null, 'values' => ['title' => '', 'body' => '']]);
    }

    /** POST /posts */
    public function store(): Response
    {
        $user = Auth::requireVerified();
        $title = $this->text('title');
        $body = $this->text('body');
        $errors = PostModel::postErrors($title, $body);
        if ($errors !== []) {
            return $this->page('form', '새 글', [
                'post' => null,
                'values' => ['title' => $title, 'body' => $body],
                'errors' => $errors,
            ], 422);
        }

        $id = $this->posts()->create($user['id'], $title, $body);
        return $this->redirectWith('/posts/' . $id, '글을 등록했습니다.');
    }

    /** GET /posts/{postId}/edit */
    public function edit(): Response
    {
        $post = $this->findPost($this->idParameter('postId'));
        Auth::requireOwner($post);
        return $this->page('form', '글 수정', ['post' => $post, 'values' => $post]);
    }

    /** PUT /posts/{postId} */
    public function update(): Response
    {
        $post = $this->findPost($this->idParameter('postId'));
        Auth::requireOwner($post);

        $title = $this->text('title');
        $body = $this->text('body');
        $errors = PostModel::postErrors($title, $body);
        if ($errors !== []) {
            return $this->page('form', '글 수정', [
                'post' => $post,
                'values' => ['title' => $title, 'body' => $body],
                'errors' => $errors,
            ], 422);
        }

        $this->posts()->update((int) $post['id'], $title, $body);
        return $this->redirectWith('/posts/' . $post['id'], '글을 수정했습니다.');
    }

    /** DELETE /posts/{postId} */
    public function destroy(): Response
    {
        $post = $this->findPost($this->idParameter('postId'));
        Auth::requireOwner($post);
        $this->posts()->delete((int) $post['id']);
        return $this->redirectWith('/', '글을 삭제했습니다.');
    }
}
