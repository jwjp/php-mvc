# PHP MVC

요청부터 DB 조회와 HTML·JSON 응답까지 직접 읽고 수정할 수 있는 작은 PHP MVC 프레임워크입니다. 명시적 라우팅, PDO 기반 Model, PHP View, QueryBuilder를 제공하며 별도의 프론트 빌드 없이 회원 가입·로그인과 작성자별 권한이 있는 **게시판 예제**와 foo/bar JSON API 샘플을 실행할 수 있습니다.

## 빠른 시작

PHP 8.1 이상, Composer, MySQL 8.x 또는 호환 DB가 필요합니다. PHP의 `mbstring`, `pdo`, `pdo_mysql` 확장을 활성화하세요. 전체 테스트에는 `curl` 확장도 필요합니다. Node.js와 npm은 필요하지 않습니다.

모든 명령은 **프로젝트 루트**에서 실행합니다.

1. 설정 파일을 준비하고 DB 접속 정보를 수정합니다.

~~~bash
cp .sample.ini.example .sample.ini
~~~

PowerShell에서는 `Copy-Item .sample.ini.example .sample.ini`를 사용합니다.

2. 의존성과 샘플 테이블을 준비합니다.

~~~bash
composer --working-dir=Variety/resources/libs install
mysql -u root -p -e "source database/schema.sql"
~~~

DB 스크립트는 `sample` 데이터베이스와 샘플용 `foo`, `bar`, 게시판용 `users`, `posts`, `comments` 테이블을 생성하며 기존 테이블을 덮어쓰지 않습니다. 다른 DB 이름을 쓰려면 스크립트의 `CREATE DATABASE`, `USE`와 설정의 `DBNAME`을 함께 변경하세요.

3. 개발 서버를 실행합니다.

~~~bash
composer --working-dir=Variety/resources/libs serve
~~~

[http://127.0.0.1:8088/](http://127.0.0.1:8088/)에서 게시판을 엽니다. 회원 가입 후 글과 댓글을 써 보세요. foo/bar JSON API 샘플 화면은 [/sample/view](http://127.0.0.1:8088/sample/view)에 있습니다. 다른 포트는 `php -S 127.0.0.1:8090 -t public dev-server.php`로 실행하세요.

## 구조와 흐름

~~~text
index.php                    설정, 세션, 오류 처리, Router 실행
public/index.php             웹서버 DocumentRoot의 진입점
static.php                   공개 정적 파일 검사 및 응답
Controller/Router.php        HTTP 메서드별 라우트 선언
Controller/RouterImpl.php    요청 파싱, 매칭, CSRF 검증, lifecycle
Controller/Controller.php    입력 검증과 PHP View 렌더링
Controller/Response.php      HTML·JSON 응답과 HTTP 상태
Controller/HttpException.php 공개 가능한 HTTP 오류
Controller/Csrf.php          세션 토큰 생성과 검증
Model/Model.php              PDO 연결
Model/QueryBuilder.php       값 바인딩과 SQL 조립
Controller/Board/            게시판 예제 컨트롤러와 세션 로그인(Auth)
Controller/Sample/           foo/bar JSON API 샘플 컨트롤러
Model/Board/                 게시판 회원·글·댓글 Model과 입력 검증
Model/Sample/                PDO·QueryBuilder 사용 예제
Variety/paint/               PHP View와 공통 헤더·푸터 (board/, sample/, template/)
Variety/assets/              공개 CSS, JS, 이미지, 폰트
database/schema.sql         샘플 스키마
tests/                      단위 테스트와 HTTP·MySQL 테스트 (support/에 공용 HTTP 도구)
~~~

요청 파싱 → 라우트 선언 → middleware → guard → interceptor(before) → pipe → 라우트 매칭·CSRF 검증 → Controller / Model / View → interceptor(after) → filter → 응답.

## 라우트와 컨트롤러

`Controller/Router.php`의 `get()`, `post()`, `put()`, `patch()`, `delete()`에 라우트를 선언합니다. 같은 메서드에서 먼저 등록한 일치 경로가 우선합니다.

~~~php
protected function get(): static
{
    $this->execute('/foo', SampleController::class, 'readFooAll');
    $this->execute('/foo/{fooId}', SampleController::class, 'readFooById');
    return $this;
}
~~~

각 메서드는 **라우트 선언 전용**이며 요청마다 모두 호출됩니다. 요청 전처리는 lifecycle hook에 넣으세요. URL로 등록되지 않은 컨트롤러를 자동 호출하는 기능은 없습니다.

컨트롤러의 일반 반환값은 JSON으로 변환됩니다.

~~~php
public function readFooById(): array|false
{
    $sample = new SampleModel($this->environments);
    return $sample->readFooById($this->parameter('fooId'));
}
~~~

| Helper | 동작 |
| --- | --- |
| `input(key)` | 필수 스칼라 값. 누락, null, 배열, 객체는 `400` |
| `stringInput(key)` | 필수 문자열. 숫자·불리언을 강제 변환하지 않음 |
| `integerInput(key)` | 정수 또는 정수 문자열을 검증하고 `int` 반환 |
| `parameter(key)` | URL의 `{key}`를 한 번 디코딩한 문자열 |

선택 입력은 `$this->variables['page'] ?? 1`처럼 읽고 사용 전에 검증합니다. 중복 슬래시와 마지막 슬래시를 정규화합니다. 없는 경로는 `404`, 지원하지 않는 메서드는 `405`와 `Allow` 헤더를 반환합니다. `HEAD`는 GET 라우트를 실행하되 본문을 보내지 않고, `OPTIONS`는 허용 메서드와 `204`를 반환합니다.

상태 코드를 지정하거나 오류를 반환할 때:

~~~php
use Controller\HttpException;
use Controller\Response;

return Response::json(['id' => $id], 201);
// 오류인 경우:
throw new HttpException('레코드를 찾을 수 없습니다.', 404);
~~~

`HttpException`의 메시지는 사용자에게 공개됩니다. 내부 오류에는 일반 예외를 쓰세요. 운영 모드의 일반 예외는 세부 정보가 없는 `500` JSON이 되며 실제 오류는 PHP 서버 로그에 기록됩니다.

## View와 Lifecycle

~~~php
use Controller\Response;

public function index(): Response
{
    return $this->view('sample/index', ['title' => 'PHP MVC']);
}
~~~

`view()`는 `Variety/paint` 안의 PHP 파일을 읽고 HTML 응답을 **반환**합니다. 반드시 컨트롤러에서도 `return`하세요. 기본 헤더·푸터는 `template/defaultHeader.php`, `template/defaultFooter.php`이며 세 번째·네 번째 인자로 이름을 바꿀 수 있습니다.

View 변수는 별도 include scope로 전달됩니다. DB에는 원본 문자열을 저장하고 HTML 출력 시 escape하세요. 모든 View에는 escape 함수 `$e`가 들어 있습니다. 같은 이름의 View 변수를 넘겨도 `$e`는 바뀌지 않습니다.

~~~php
<h1><?= $e($title) ?></h1>
<!-- 위와 같은 결과 -->
<h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
~~~

폼을 처리한 뒤에는 `Response::redirect()`로 목록이나 상세 화면으로 이동하세요. 기본 상태는 `303`이라 새로고침해도 POST가 다시 전송되지 않습니다. 외부 주소로 보내지 않도록 `/`로 시작하는 사이트 내부 경로만 받습니다.

~~~php
return Response::redirect('/posts/' . $id);
~~~

`Router`에서 필요한 hook만 override하고 `$this`를 반환하세요.

| Hook | 역할 |
| --- | --- |
| `middleware()` | 공통 전처리, 요청 로그 |
| `guard()` | 인증과 권한 확인 |
| `interceptor($when)` | `before` / `after` 처리 |
| `pipe()` | 입력값 변환과 검증 |
| `filter()` | 파싱·라우팅·컨트롤러 오류 처리 |

`$environments`, `$variables`, `$result`, `$exception`에 접근할 수 있습니다. 예외가 발생하면 나머지 정상 처리를 건너뛰고 `filter()`를 호출합니다. 필터에서 처리한 예외는 `$this->exception = null`로 지우고 `$this->result`에 응답을 설정하세요. HTML 응답도 후처리와 필터를 거칩니다.

## 요청 본문과 CSRF

POST는 JSON, URL-encoded form, multipart form을 받습니다. PUT·PATCH·DELETE는 JSON과 URL-encoded form을 받습니다. JSON 최상위 값은 객체여야 합니다. 잘못된 본문은 `400`, 미지원 Content-Type은 `415`, 본문 제한 초과는 `413`입니다.

변경 요청에는 **같은 세션의 CSRF 토큰**이 필요합니다. 샘플 화면은 자동으로 전송합니다. 직접 만든 View에는 `\Controller\Csrf::token()` 값을 전달한 뒤 hidden input을 사용하세요.

~~~html
<input type="hidden" name="_csrf"
       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="_method" value="PUT">
~~~

`_method`는 POST에서만 PUT·PATCH·DELETE로 변경할 수 있습니다. API 클라이언트는 `GET /csrf-token`에서 세션 쿠키와 토큰을 받은 뒤 쿠키와 `X-CSRF-Token` 헤더를 함께 보냅니다. 토큰을 URL에 넣지 마세요.

PowerShell:

~~~powershell
$base = 'http://127.0.0.1:8088'
$csrf = Invoke-RestMethod "$base/csrf-token" -SessionVariable mvcSession
$request = @{
    Uri = "$base/foo"
    Method = 'Post'
    WebSession = $mvcSession
    Headers = @{ 'X-CSRF-Token' = $csrf.token }
    ContentType = 'application/json'
    Body = '{"message":"Hello MVC"}'
}
Invoke-RestMethod @request
~~~

Bash:

~~~bash
cookie_file=$(mktemp)
token=$(curl -sS -c "$cookie_file" http://127.0.0.1:8088/csrf-token \
  | php -r '$data = json_decode(stream_get_contents(STDIN), true); echo $data["token"];')
curl -b "$cookie_file" -H "X-CSRF-Token: $token" \
  -H 'Content-Type: application/json' -d '{"message":"Hello MVC"}' \
  http://127.0.0.1:8088/foo
rm "$cookie_file"
~~~

**CSRF 토큰은 인증 수단이 아닙니다.** 샘플 API는 로그인 없이 접근 가능하므로 공개 서비스로 배포하기 전에 `guard()`에서 인증과 레코드별 권한을 적용해야 합니다.

## Model과 QueryBuilder

`Model\Model`을 상속하면 `$this->pdo`와 `$this->queryBuilder()`를 사용할 수 있습니다. PDO는 예외 모드, 실제 prepared statement, UTF-8 연결을 사용합니다.

~~~php
return $this->queryBuilder()
    ->table('foo')
    ->select()
    ->where('deleted_at', '=', null)
    ->orderBy('id', 'DESC')
    ->limit(20)
    ->offset(0)
    ->exec();
~~~

테이블·컬럼 식별자는 검증 후 인용하고 값은 타입에 맞게 바인딩합니다. `foo f`, `foo AS f`, `f.id`, `f.*`, `COUNT(*) AS total`을 지원합니다. 집계 함수는 COUNT·SUM·AVG·MIN·MAX를 지원합니다. 임의 SQL 표현식과 서브쿼리는 받지 않습니다. 복잡한 SQL은 PDO로 직접 작성하고 값을 바인딩하세요.

~~~php
$stmt = $this->pdo->prepare('SELECT * FROM foo WHERE id = :id');
$stmt->execute([':id' => $id]);
return $stmt->fetchAll();
~~~

외부 입력으로 정렬 컬럼 등을 고를 때는 `['newest' => 'created_at', 'id' => 'id'][$requestedSort] ?? 'id'`처럼 허용 목록으로 매핑하세요. 식별자 검증만으로 접근 권한까지 제한되지는 않습니다.

- `table()`은 이전 쿼리 상태를 초기화합니다. `select()`의 기본 컬럼은 `*`, `orderBy()`의 기본 방향은 ASC입니다.
- IN·NOT IN은 비어 있지 않은 배열, BETWEEN은 두 값의 배열을 받습니다. `= null`, `!= null`은 IS NULL, IS NOT NULL로 변환합니다.
- `where()`는 기본 AND 연결입니다. `OR`는 **현재 조건부터** OR 그룹을 열고 `END OR`는 닫습니다. 예: `where('id', '=', 1, 'OR')->where('id', '=', 2, 'END OR')`는 `(id = 1 OR id = 2)`입니다. 앞선 공통 AND 조건은 그룹 전체에 적용됩니다.
- UPDATE·DELETE는 WHERE가 필수입니다. 지원하지 않는 조합은 무시하지 않고 예외를 발생시킵니다.
- `exec()`는 SELECT 배열, INSERT·UPSERT ID 문자열, UPDATE·DELETE 변경 행 수를 반환합니다. UPSERT가 기존 행을 갱신한 경우 ID는 PDO/MySQL 동작을 따르므로 레코드 식별에 의존하지 마세요.
- `debug()`는 실행 없이 `query`, `parameters`, `dump`를 반환하며 상태를 바꾸지 않습니다. 값이 포함되므로 사용자 응답이나 공개 로그로 보내지 마세요.
- `[값, 옵션]` 형태의 NUMBER·ALPHABET·PHONE·EMAIL·PASSWORD 옵션을 지원합니다. PASSWORD 저장값은 비어 있지 않은 72바이트 이하 문자열입니다. PASSWORD 검색은 다른 AND 조건으로 단일 행을 선택하는 SELECT에서만 허용하며 불일치는 `false`입니다. 일반 로그인 구현은 `password_hash()` / `password_verify()`를 명시적으로 사용하는 편이 이해하기 쉽습니다.

트랜잭션 콜백은 자동 commit·rollback을 적용하고 콜백 결과를 반환합니다. 중첩 트랜잭션은 지원하지 않습니다.

~~~php
return $this->queryBuilder()->transaction(function (\Model\QueryBuilder $qb) {
    $id = $qb->table('foo')->insert(['message' => 'Hello'])->exec();
    $qb->table('bar')->insert(['foo_id' => $id, 'comment' => 'First comment'])->exec();
    return $id;
});
~~~

## 게시판 예제

프레임워크 자체에는 인증 기능이 없습니다. 게시판 예제는 lifecycle hook과 컨트롤러만으로 회원 가입, 로그인, 작성자별 권한을 만드는 방법을 보여 줍니다. 화면은 서버에서 그린 HTML 폼으로 동작하며 JavaScript는 삭제 확인에만 씁니다.

| Method | Path | 동작 | 권한 |
| --- | --- | --- | --- |
| GET | `/` | 글 목록, `?page=`로 10개씩 | 누구나 |
| GET | `/posts/{postId}` | 글과 댓글 | 누구나 |
| GET·POST | `/posts/new`, `/posts` | 글쓰기 화면, 등록 | 로그인 |
| GET·PUT | `/posts/{postId}/edit`, `/posts/{postId}` | 수정 화면, 저장 | 작성자 |
| DELETE | `/posts/{postId}` | 글과 댓글 삭제 (soft delete) | 작성자 |
| POST | `/posts/{postId}/comments` | 댓글 등록 | 로그인 |
| DELETE | `/comments/{commentId}` | 댓글 삭제 | 작성자 |
| GET·POST | `/signup`, `/login` | 가입, 로그인 | 누구나 |
| POST | `/logout` | 로그아웃 | 누구나 |

PUT·DELETE는 HTML 폼의 `_method` hidden input으로 보냅니다.

요청은 다음 순서로 처리됩니다.

1. **`Router::guard()`** 가 `Auth::load()`로 세션의 회원을 읽습니다. 화면별 권한은 여기서 판단하지 않습니다. guard는 어느 라우트가 선택될지 모르기 때문입니다.
2. **컨트롤러**가 `Auth::require()`(로그인)나 `Auth::requireOwner($post)`(작성자)를 호출합니다. 조건이 맞지 않으면 `401`·`403` `HttpException`을 던집니다.
3. **`Router::filter()`** 는 브라우저 요청(`Accept`에 `text/html`이 있음)의 오류를 HTML로 바꿉니다. `401`은 로그인 화면으로 보내고, 로그인 뒤 원래 화면으로 돌아오도록 경로를 세션에 저장합니다. 나머지 오류는 오류 화면으로 보여 줍니다. JSON API 요청의 오류는 기존처럼 JSON입니다.
4. 입력 검증에 실패하면 입력값과 필드별 오류를 담아 폼을 `422`로 다시 그립니다. 성공하면 `303`으로 이동하고 알림 메시지를 세션에 한 번만 남깁니다.

보안과 관련해 예제에서 처리한 내용입니다.

- 비밀번호는 `password_hash()`로 저장하고 `password_verify()`로 비교합니다. bcrypt가 무시하는 72바이트를 넘는 비밀번호는 받지 않습니다.
- 없는 아이디도 더미 해시와 비교해, 응답 시간과 메시지로 아이디 존재 여부를 알 수 없게 합니다.
- 로그인·가입·로그아웃 때 `session_regenerate_id(true)`로 세션 ID를 바꾸고 CSRF 토큰도 새로 만듭니다.
- 글과 댓글은 저장할 때 앞뒤 공백을 지우고 줄바꿈을 통일하며, 출력할 때 `$e()`로 escape합니다.
- 댓글을 추가할 때 글 행을 `FOR UPDATE`로 잠가, 동시에 삭제된 글에 댓글이 남지 않게 합니다.
- 목록의 댓글 수는 글마다 쿼리하지 않고 한 페이지 분량을 `IN`과 `GROUP BY`로 한 번에 셉니다.

로그인 시도 횟수 제한, 비밀번호 재설정, 이메일 인증은 포함하지 않습니다. 공개 서비스에 쓰려면 함께 구현하세요.

## 샘플 API

`foo`는 메시지, `bar`는 연결된 코멘트입니다. 문자열은 앞뒤 공백을 제거하고 UTF-8 기준 1~150자를 받습니다. ID는 양의 정수입니다.

| Method | Path | 본문 / 반환값 |
| --- | --- | --- |
| GET | `/foo`, `/bar` | 활성 레코드 배열 |
| GET | `/foo/{fooId}`, `/bar/{barId}` | 레코드 배열, 없으면 `[]` |
| POST | `/foo` | `message` → 생성 ID 문자열 |
| POST | `/bar` | `fooId`, `comment` → 생성 행 수 |
| PUT | `/foo/{fooId}` | `message` → 변경 행 수 |
| PUT | `/bar/{barId}` | `comment` → 변경 행 수 |
| DELETE | `/foo/{fooId}`, `/bar/{barId}` | soft delete 행 수 |
| POST | `/foobar` | `fooMessage`, `barComment` → `true` |
| GET | `/foobar/{fooId}` | 부모에 연결된 활성 코멘트 배열 |
| GET | `/csrf-token` | `{"token":"..."}` |

기존 샘플과의 호환을 위해 성공 상태는 200을 유지합니다. 부모 메시지 삭제 시 코멘트도 같은 트랜잭션에서 soft delete합니다. 코멘트 추가 시 부모 행을 잠가 동시 삭제와 충돌하지 않게 처리합니다. 목록 API는 학습용 전체 조회입니다. 서비스에서는 `limit()`·`offset()`과 사용자별 조회 조건을 적용하세요.

## 설정과 배포

`.sample.ini.example`을 기준으로 설정하세요. `.sample.ini`는 Git에서 제외됩니다. `PHP_MVC_CONFIG` 환경변수에 별도 INI 파일 경로를 지정할 수도 있습니다.

| 설정 | 기본값 / 의미 |
| --- | --- |
| `APP.DEBUG` | false. 개발 중에만 true |
| `APP.TIMEZONE` | 예제는 Asia/Seoul, 생략 시 UTC |
| `APP.MAX_BODY_BYTES` | 1048576, 요청 본문 최대 바이트 |
| `APP.SESSION_SECURE` | false. 직접 HTTPS는 자동 적용; HTTPS 종료 프록시 뒤에서는 true |
| `DATABASE.PORT` | 생략 시 3306 |

비밀번호 등 문자열은 INI에서 큰따옴표로 감싸세요. 운영에서는 전용 DB 계정에 필요한 권한만 부여하고 PHP의 `display_errors=Off`, `post_max_size`와 웹서버 본문 제한을 함께 설정하세요. 앱 본문 제한은 PHP·웹서버 제한 이하로 두세요.

내장 서버는 로컬 개발용입니다. 운영 웹서버의 DocumentRoot는 **프로젝트의 `public/` 폴더**로 설정하세요. 설정·소스·의존성이 웹 루트 밖에 있어 rewrite 설정이 빠져도 직접 노출되지 않습니다. Apache 2.4에서는 `mod_rewrite`와 `public/.htaccess`의 Options·rewrite 지시문을 허용하는 `AllowOverride` 설정이 필요합니다. 루트 `.htaccess`는 기존 구성 호환용입니다.

Nginx도 파일 존재 여부와 관계없이 **단일 진입점**으로 전달합니다.

~~~nginx
server {
    listen 80;
    server_name example.test;
    root /path/to/php-mvc/public;
    client_max_body_size 1m;

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
~~~

`/css`, `/js`, `/fonts`, `/images`, `/html` 및 `/favicon.ico`만 정적 파일로 제공하며 확장자와 실제 경로를 검사합니다. 숨김 파일, 상위 경로, PHP 소스, 의존성 폴더는 직접 제공하지 않습니다. 개발 서버와 Apache·Nginx에서 동일한 정책을 사용하며, 이 작은 샘플에서는 정적 응답도 PHP를 거칩니다.

세션에는 strict mode, HttpOnly, SameSite=Lax를 적용하고 CSP·nosniff·프레임 차단 헤더를 보냅니다. CSP 기본값은 동일 출처 자산을 허용합니다. 외부 자산을 추가할 때는 `index.php`에서 필요한 출처만 명시하세요. 설계 참고: [PHP 세션 설정](https://www.php.net/manual/en/session.security.ini.php), [OWASP CSRF 방어](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).

## 테스트

~~~bash
composer --working-dir=Variety/resources/libs test
~~~

- `test:unit`: DB 없이 입력 검증, SQL 식별자, 쿼리 상태, View·lifecycle, CSRF를 검사합니다.
- `test:smoke`: 실제 HTTP 서버와 MySQL로 CRUD, CSRF, 요청 제한, 정적 파일 차단, soft delete, QueryBuilder 실행과 rollback, 운영 모드 오류 응답을 검사합니다.
- `test:board`: 실제 HTTP 서버와 MySQL로 게시판의 가입, 로그인, 세션 교체, 글·댓글 작성과 escape, 작성자 권한, 페이지네이션을 검사합니다. 테스트가 만든 회원과 글은 종료 시 삭제합니다.

테스트 DB 설정을 따로 준비하는 것을 권장합니다. `PHP_MVC_CONFIG`는 테스트와 자동 시작 서버 모두에 적용됩니다. 테스트가 만든 행은 종료 시 정리하고 QueryBuilder 상세 검사는 연결 전용 임시 테이블을 사용합니다. 테스트 계정에는 `CREATE TEMPORARY TABLES` 권한이 필요합니다.

사용 중인 서버를 검사하려면 `PHP_MVC_BASE_URL`을 지정하세요. 서버와 테스트의 DB 설정은 같아야 합니다.

## 이전 코드에서 변경할 점

- View action에서 `return $this->view(...)`를 사용하고 반환형을 `Response`로 바꾸세요.
- 변경 요청에 세션 쿠키와 CSRF 토큰을 추가하세요.
- `ROUTER.DYNAMIC_ANALYSIS`는 제거되었습니다. 모든 action을 명시적으로 등록하세요.
- `get()`, `post()` 등에는 라우트 선언만 두고 전처리는 lifecycle hook으로 옮기세요.
- 공개할 HTTP 오류에는 `new HttpException(메시지, 상태코드)`를 사용하세요. 일반 예외의 숫자 코드는 HTTP 상태로 해석하지 않습니다.
- QueryBuilder의 raw SQL 식별자·표현식은 허용된 식별자 또는 PDO 쿼리로 바꾸세요. 쓰기 쿼리의 SELECT 전용 옵션은 예외를 발생시킵니다.
- OR 그룹은 현재 조건부터 묶이도록 수정했습니다. 기존 OR 체인은 앞선 AND 필터와의 결합 결과를 확인하세요.
- `/modules` 제공과 npm 의존성, 중복 autoloader를 제거했습니다. Composer 설치 후 자체 PHP·CSS·JS만으로 실행합니다.
- `/`는 이제 게시판 목록입니다. foo/bar 샘플 화면은 `/sample/view`에서 엽니다. 기존 DB에는 `database/schema.sql`을 다시 실행해 게시판 테이블을 추가하세요.
- View 변수 이름 `e`는 escape 함수로 예약되어 있습니다.

프레임워크에는 인증·인가, ORM, DI 컨테이너, migration runner, template engine이 포함되어 있지 않습니다. 인증·인가는 게시판 예제처럼 애플리케이션 코드에서 구현합니다. 기능을 추가할 때도 직접 읽을 수 있는 요청 흐름을 우선합니다.

## License

[The Unlicense](LICENSE)
