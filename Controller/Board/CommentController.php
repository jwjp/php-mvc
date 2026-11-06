<?php

namespace Controller\Board;

use Controller\HttpException;
use Controller\Response;
use Model\Board\PostModel;

class CommentController extends BoardController
{
    /** POST /posts/{postId}/comments */
    public function store(): Response
    {
        $user = Auth::require();
        $post = $this->findPost($this->idParameter('postId'));

        $body = $this->text('body');
        $errors = PostModel::commentErrors($body);
        if ($errors !== []) {
            return $this->page('show', $post['title'], [
                'post' => $post,
                'comments' => $this->posts()->comments((int) $post['id']),
                'commentBody' => $body,
                'errors' => $errors,
            ], 422);
        }

        $id = $this->posts()->addComment((int) $post['id'], $user['id'], $body);
        if ($id === null) {
            // 화면을 연 뒤 다른 요청에서 글이 삭제된 경우입니다.
            throw new HttpException('요청한 글을 찾을 수 없습니다.', 404);
        }
        return $this->redirectWith('/posts/' . $post['id'] . '#comment-' . $id, '댓글을 등록했습니다.');
    }

    /** DELETE /comments/{commentId} */
    public function destroy(): Response
    {
        $id = $this->idParameter('commentId');
        $comment = $this->posts()->findComment($id)
            ?? throw new HttpException('요청한 댓글을 찾을 수 없습니다.', 404);
        Auth::requireOwner($comment);

        $this->posts()->deleteComment($id);
        return $this->redirectWith('/posts/' . $comment['post_id'] . '#comments', '댓글을 삭제했습니다.');
    }
}
