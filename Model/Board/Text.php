<?php

namespace Model\Board;

/**
 * 게시판 입력값 정리와 검증.
 *
 * 각 함수는 정리한 값을 참조로 돌려주고, 문제가 있으면 화면에 보여 줄 메시지를 반환합니다.
 */
final class Text
{
    /**
     * 앞뒤 공백을 지우고 줄바꿈을 \n으로 통일한 뒤 길이를 검사합니다.
     */
    public static function check(mixed &$value, string $label, int $max, int $min = 1): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return "{$label}은(는) UTF-8 문자열이어야 합니다.";
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            return "{$label}에 사용할 수 없는 제어 문자가 있습니다.";
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min) {
            return $min === 1 ? "{$label}을(를) 입력하세요." : "{$label}은(는) {$min}자 이상이어야 합니다.";
        }
        if ($length > $max) {
            return "{$label}은(는) {$max}자 이하로 입력하세요.";
        }
        return null;
    }

    /**
     * 양의 정수 ID만 받습니다. 경로 파라미터처럼 문자열로 들어온 값도 처리합니다.
     */
    public static function id(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }
}
