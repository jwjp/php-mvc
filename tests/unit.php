<?php

declare(strict_types=1);

use Controller\Controller;
use Controller\Csrf;
use Controller\HttpException;
use Controller\Response;
use Controller\RouterImpl;
use Model\Board\PostModel;
use Model\Board\Text;
use Model\Board\UserModel;
use Model\QueryBuilder;

require dirname(__DIR__) . '/Variety/resources/libs/vendor/autoload.php';

ob_start();
session_start();
$passed = [];

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $action, string $type = LogicException::class): void
{
    try { $action(); } catch (Throwable $exception) {
        check($exception instanceof $type, 'Unexpected exception: ' . $exception::class . ': ' . $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $type);
}

function test(string $name, callable $action): void
{
    global $passed;
    $action();
    $passed[] = $name;
}

class UnitController extends Controller
{
    public function index(): array { return ['id' => $this->parameter('id')]; }
    public function html(): Response { return $this->view('sample/index', ['title' => '<script>alert(1)</script>']); }
    public function number(): int { return $this->integerInput('number'); }
    public function text(): string { return $this->stringInput('text'); }
    public function badView(): Response { return $this->view('../index'); }
    public function missingView(): Response { return $this->view('missing'); }
}

class UnitRouter extends RouterImpl
{
    public array $events = [];
    public mixed $output;
    protected function get(): static
    {
        return $this->execute('/items/{id}', UnitController::class, 'index')
            ->execute('/view', UnitController::class, 'html');
    }
    protected function middleware(): static { $this->events[] = 'middleware'; return $this; }
    protected function guard(): static { $this->events[] = 'guard'; return $this; }
    protected function interceptor($when): static { $this->events[] = $when; return $this; }
    protected function pipe(): static { $this->events[] = 'pipe'; return $this; }
    protected function filter(): static
    {
        $this->events[] = 'filter';
        if ($this->exception !== null && isset($_GET['recover'])) {
            $this->result = Response::json(['recovered' => true], $this->exception->getCode());
            $this->exception = null;
        }
        return $this;
    }
    protected function end(): void { $this->events[] = 'end'; $this->output = $this->result; }
}

$pdo = new class extends PDO { public function __construct() {} };
$qb = new QueryBuilder($pdo);

try {
    test('SQL identifiers reject expressions and injection on every entry point', function () use ($qb): void {
        foreach ([
            fn () => $qb->table('foo; DROP TABLE bar'),
            fn () => $qb->table('foo')->select('id, (SELECT 1)'),
            fn () => $qb->table('foo')->where('id OR 1=1', '=', 1),
            fn () => $qb->table('foo')->orderBy('id DESC --'),
            fn () => $qb->table('foo')->groupBy('id; SELECT 1'),
            fn () => $qb->table('foo')->having('COUNT(*) OR 1=1', '>', 0),
            fn () => $qb->table('foo')->join('bar', 'INNER', 'foo.id', '=', 'bar.foo_id OR 1=1'),
            fn () => $qb->table('foo')->update(['message = NULL --' => 'text']),
            fn () => $qb->table('foo')->where('id', '= 1 OR', 1),
            fn () => $qb->table('foo')->orderBy('id', 'DESC; SELECT 1'),
        ] as $action) { rejects($action, InvalidArgumentException::class); }
    });

    test('debug is deterministic and can be followed by more conditions', function () use ($qb): void {
        $qb->table('foo f')->select('f.id', 'COUNT(*) AS total')->where('f.id', 'IN', [1, 2]);
        $first = $qb->debug();
        check($first === $qb->debug(), 'debug changed the builder');
        $qb->where('f.deleted_at', '=', null)->groupBy('f.id')->having('COUNT(*)', '>', 0)->orderBy('f.id');
        $result = $qb->debug();
        check(str_contains($result['query'], ' AND '), 'Missing condition separator after debug');
        check(str_contains($result['query'], 'IS NULL'), 'NULL must not use equality binding');
        check(count($result['parameters']) === 3, 'Parameter count does not match predicates');
        check(str_contains($result['query'], 'COUNT(*) AS `total`'), 'Aggregate alias missing');
    });

    test('list values, typed values and LIMIT/OFFSET stay distinct', function () use ($qb): void {
        $result = $qb->table('foo')->select()->where('message', 'IN', ['text', 'EMAIL'])->limit(0)->debug();
        check(array_values($result['parameters']) === ['text', 'EMAIL'], 'IN list mistaken for an option');
        check(str_contains($result['query'], 'LIMIT 0'), 'Zero limit missing');
        check(str_contains($qb->table('foo')->select()->offset(1)->debug()['query'], 'LIMIT 18446744073709551615 OFFSET 1'), 'Standalone offset invalid');
        $result = $qb->table('foo')->insert(['message' => [12, 'NUMBER'], 'deleted_at' => null])->debug();
        check(array_values($result['parameters']) === [12, null], 'Scalar options not normalized');
        rejects(fn () => $qb->table('foo')->insert(['message' => ['unknown']]), InvalidArgumentException::class);
    });

    test('unsafe or silently ignored write conditions are rejected', function () use ($qb): void {
        rejects(fn () => $qb->table('foo')->update(['message' => 'x'])->debug());
        rejects(fn () => $qb->table('foo')->delete()->debug());
        rejects(fn () => $qb->table('foo')->delete()->where('id', '=', 1)->where('password', '=', ['x', 'PASSWORD'])->debug());
        rejects(fn () => $qb->table('foo')->update(['message' => 'x'])->where('id', '=', 1)->limit(1)->debug());
        rejects(fn () => $qb->table('foo')->insert(['message' => 'x'])->where('id', '=', 1)->debug());
        rejects(fn () => $qb->table('foo')->select()->delete());
        rejects(fn () => $qb->table('foo')->select()->where('password', '=', ['', 'PASSWORD'])->debug());
    });

    test('OR groups must be balanced and survive repeated compilation', function () use ($qb): void {
        rejects(fn () => $qb->table('foo')->select()->where('id', '=', 1, 'OR')->debug());
        rejects(fn () => $qb->table('foo')->select()->where('id', '=', 1, 'END OR'));
        $qb->table('foo')->select()->where('id', '=', 1, 'OR')->where('id', '=', 2, 'END OR');
        check($qb->debug() === $qb->debug(), 'OR compilation mutates conditions');
    });

    test('input helpers reject arrays, wrong scalar types and integer overflow', function (): void {
        check((new UnitController([], ['number' => '12']))->number() === 12, 'Integer helper should return int');
        foreach (['12x', '1.2', true, [], (string) PHP_INT_MAX . '0'] as $value) {
            rejects(fn () => (new UnitController([], ['number' => $value]))->number(), HttpException::class);
        }
        rejects(fn () => (new UnitController([], ['text' => 123]))->text(), HttpException::class);
        rejects(fn () => (new UnitController())->text(), HttpException::class);
    });

    test('views escape output, reject traversal and return without exit', function (): void {
        $level = ob_get_level();
        $controller = new UnitController();
        $response = $controller->html();
        check(str_contains($response->body, '&lt;script&gt;'), 'View text is not escaped');
        check(!str_contains($response->body, '<script>alert'), 'Unsafe view output');
        rejects(fn () => $controller->badView(), HttpException::class);
        rejects(fn () => $controller->missingView(), HttpException::class);
        check(ob_get_level() === $level, 'View leaked an output buffer');
    });

    test('HTML runs after hooks and filters, and unknown routes can be recovered', function (): void {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/view';
        $router = new UnitRouter();
        check($router->output instanceof Response, 'View response lost');
        check($router->events === ['middleware', 'guard', 'before', 'pipe', 'after', 'filter', 'end'], 'Unexpected lifecycle');
        $_SERVER['REQUEST_URI'] = '/missing';
        $_GET['recover'] = true;
        $router = new UnitRouter();
        check($router->output->status === 404, '404 did not reach filter');
        $_GET = [];
    });

    test('HEAD and OPTIONS work without invoking unsupported actions', function (): void {
        $_SERVER['REQUEST_URI'] = '/items/%31/';
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        check((new UnitRouter())->output === ['id' => '1'], 'HEAD should resolve the GET route');
        ob_start();
        Response::json(['hello' => true])->send();
        check(ob_get_clean() === '', 'HEAD must not send a body');
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        check((new UnitRouter())->output->status === 204, 'OPTIONS should return 204');
        $_SERVER['REQUEST_METHOD'] = 'TRACE';
        rejects(fn () => new UnitRouter(), HttpException::class);
    });

    test('CSRF rejects missing, malformed, cross-site and other-session tokens', function (): void {
        $token = Csrf::token();
        check(strlen($token) === 64 && Csrf::token() === $token, 'Token should be stable within a session');
        Csrf::verify($token);
        foreach ([null, [], '', str_repeat('0', 64)] as $bad) {
            rejects(fn () => Csrf::verify($bad), HttpException::class);
        }
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        rejects(fn () => Csrf::verify($token), HttpException::class);
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
        rejects(fn () => Csrf::verify(Csrf::token()), HttpException::class);
    });

    test('redirects accept only local paths and send a Location header', function (): void {
        $response = Response::redirect('/posts/1#comment-2');
        check($response->status === 303 && $response->headers === ['Location' => '/posts/1#comment-2'], 'Redirect lost its target');
        foreach (['https://example.com', '//example.com', '/\\example.com', 'posts', "/a\r\nSet-Cookie: x=1"] as $target) {
            rejects(fn () => Response::redirect($target), InvalidArgumentException::class);
        }
        rejects(fn () => Response::redirect('/', 200), InvalidArgumentException::class);
    });

    test('board input is trimmed, normalized and length-checked by characters', function (): void {
        $title = '  제목  ';
        $body = "a\r\nb";
        check(PostModel::postErrors($title, $body) === [] && $title === '제목' && $body === "a\nb", 'Input was not normalized');
        $title = str_repeat('가', 100);
        $body = 'ok';
        check(PostModel::postErrors($title, $body) === [], '100 Korean characters must fit in a title');
        $title = str_repeat('가', 101);
        $body = "x\0y";
        check(array_keys(PostModel::postErrors($title, $body)) === ['title', 'body'], 'Long titles and control characters must fail');
        $title = "a\nb";
        $body = ['array'];
        check(array_keys(PostModel::postErrors($title, $body)) === ['title', 'body'], 'Multiline titles and arrays must fail');
        check(Text::id('7') === 7 && Text::id('0') === null && Text::id('1e3') === null, 'IDs must be positive integers');

        $loginId = ' Admin_1 ';
        $name = '관리자';
        check(UserModel::signupErrors($loginId, $name, 'password1', 'password1') === [] && $loginId === 'admin_1', 'Login IDs must be normalized');
        $loginId = 'a-b';
        check(array_keys(UserModel::signupErrors($loginId, $name, str_repeat('x', 73), '')) === ['loginId', 'password'], 'Invalid signup must fail');
        $loginId = 'user';
        check(array_keys(UserModel::signupErrors($loginId, $name, 'password1', 'password2')) === ['passwordConfirm'], 'Password confirmation must match');
    });
} catch (Throwable $exception) {
    ob_end_clean();
    fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    exit(1);
}

session_write_close();
ob_end_clean();
foreach ($passed as $name) { echo '[PASS] ' . $name . "\n"; }
echo "\n" . count($passed) . " unit checks passed.\n";
