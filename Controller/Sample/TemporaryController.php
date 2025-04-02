<?php

namespace Controller\Sample;

use Controller\Controller;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use SodiumException;
use stdClass;

class TemporaryController extends Controller
{
    /**
     * @return void
     */
    function test(): void
    {
        /*
        var_dump($this->variables);
        var_dump($_FILES);
        var_dump($this->parameters);
        var_dump($this->environments);
        */

        // 헤더에서 인증 토큰 가져오기
        $headers = getallheaders();
        if (isset($headers['Authorization']) && str_starts_with($headers['Authorization'], 'Bearer ')) {
            $bearerToken = substr($headers['Authorization'], 7);
        } else {
            throw new RuntimeException('Authorization header not found or invalid.');
        }

        // JWT 예시 (Composer 사용해서 다운로드)
        $key = 'EXAMPLE_KEY';
        $iat = time();
        $jwt = JWT::encode([
            'iss' => 'http://example.org',
            'aud' => 'http://example.com',
            'iat' => $iat,
            'nbf' => $iat,
            'exp' => $iat + 60 * 60 * 24
        ], $key, 'HS256');
        $jwtDecoded = (array) JWT::decode($jwt, new Key($key, 'HS256'));
        $bearerTokenDecoded = (array) JWT::decode($bearerToken, new Key($key, 'HS256'));

        // getID3 예시 (Composer 대신 직접 다운로드)
        $getID3 = new \getID3();
        $analyze = $getID3->analyze('D:\\Your\\Downloads\\Path\\sample.mp3');
    }
}