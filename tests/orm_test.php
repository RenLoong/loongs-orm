<?php

declare(strict_types=1);

/**
 * loongs/orm tests against real MySQL (throwaway databases loongs_orm_t1/t2/t3 + loongs_orm_central + loongs_orm_px (table prefixes),
 * dropped at the end). No tenancy: t1/t2/t3 are just three databases reached through per-call connection specs
 * (config array, URL DSN, PDO DSN); the generic default-connection hook and LeaseProvider extension point are exercised directly.
 *
 *   php -d disable_functions= tests/orm_test.php cli|co
 *     cli = plain PHP (no coroutines) · co = inside Swoole\Coroutine\run with SWOOLE_HOOK_ALL
 *   MySQL credentials: DB_SOCKET / DB_USERNAME / DB_PASSWORD env, or LOONGS_TEST_ENV=/path/to/.env (default socket /tmp/mysql.sock, root, no password).
 */

use Loongs\Orm\Connection\ConnectionConfig;
use Loongs\Orm\Connection\ConnectionPool;
use Loongs\Orm\Connection\FrameworkPoolProvider;
use Loongs\Orm\Connection\Lease;
use Loongs\Orm\Connection\LeaseProvider;
use Loongs\Orm\Connection\LeaseSource;
use Loongs\Orm\Connection\PdoFactory;
use Loongs\Orm\Connection\PoolConfig;
use Loongs\Orm\Connection\QueryExecuted;
use Loongs\Orm\Context;
use Loongs\Orm\Exceptions\MassAssignmentException;
use Loongs\Orm\Exceptions\ClassMorphViolationException;
use Loongs\Orm\Model\Relations\HasManyThrough;
use Loongs\Orm\Model\Relations\MorphTo;
use Loongs\Orm\Model\Relations\Relation;
use Loongs\Orm\Exceptions\ModelNotFoundException;
use Loongs\Orm\Orm;
use OrmTest\Agent;
use OrmTest\Article;
use OrmTest\Author;
use OrmTest\Book;
use OrmTest\Comment;
use OrmTest\Country;
use OrmTest\EventLog;
use OrmTest\Image;
use OrmTest\Mark;
use OrmTest\Plan;
use OrmTest\Post;
use OrmTest\Profile;
use OrmTest\Role;
use OrmTest\Status;
use OrmTest\Tag;
use OrmTest\User;
use OrmTest\Video;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/models.php';

$mode = $argv[1] ?? 'cli';
$env = [];
$envFile = (string) getenv('LOONGS_TEST_ENV');
foreach ($envFile !== '' && is_file($envFile) ? (file($envFile, FILE_IGNORE_NEW_LINES) ?: []) : [] as $l) {
    if (preg_match('/^\s*([A-Z_]+)\s*=\s*(.*)$/', $l, $m)) {
        $env[$m[1]] = trim($m[2], "\"'");
    }
}
foreach (['DB_SOCKET', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
    if (is_string($v = getenv($k))) {
        $env[$k] = $v;
    }
}
$SOCK = ($env['DB_SOCKET'] ?? '') !== '' ? $env['DB_SOCKET'] : '/tmp/mysql.sock';
$USER = $env['DB_USERNAME'] ?? 'root';
$PASS = $env['DB_PASSWORD'] ?? '';
$DBS = ['t1' => 'loongs_orm_t1', 't2' => 'loongs_orm_t2', 't3' => 'loongs_orm_t3', 'central' => 'loongs_orm_central', 'px' => 'loongs_orm_px'];

// Three databases, three different spec forms: config array, URL DSN, PDO DSN (+credentials).
$T = [
    't1' => ['driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => $DBS['t1'], 'username' => $USER, 'password' => $PASS],
    't2' => sprintf('mysql://%s:%s@localhost/%s?unix_socket=%s', rawurlencode($USER), rawurlencode($PASS), $DBS['t2'], rawurlencode($SOCK)),
    't3' => ConnectionConfig::fromDsn(sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $SOCK, $DBS['t3']), $USER, $PASS),
];
Orm::configure([
    'default' => 'central',
    'connections' => [
        'central' => ['driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => $DBS['central'], 'username' => $USER, 'password' => $PASS,
            'pool' => ['size' => 4, 'wait_timeout' => 5.0]],
        // named connection with a table prefix (section 18)
        'prefixed' => ['driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => 'loongs_orm_px', 'username' => $USER, 'password' => $PASS, 'prefix' => 'n_',
            'pool' => ['size' => 4, 'wait_timeout' => 5.0]],
    ],
    // ORM pool settings (same key works in server/config/database.php)
    'orm_pool' => ['size' => 8, 'max_pools' => 16, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 5],
]);
Orm::usePools(null); // no framework pool until section 9: named connections use the ORM pool too

$fails = 0;
$passes = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fails, $passes;
    $ok ? $passes++ : $fails++;
    printf("%s  %-58s %s\n", $ok ? 'PASS' : 'FAIL', $name, $detail);
}
function napms(int $ms): void
{
    Context::inCoroutine() ? Swoole\Coroutine::sleep($ms / 1000) : usleep($ms * 1000);
}
function section(string $t): void
{
    echo "\n== {$t}\n";
}

// loongs/orm has no tenant concept. Scoped "default connection" behaviour is tested through the generic
// hook Orm::resolveDefaultUsing() driven by a coroutine-local value — the way a package such as loongs/saas uses it.
const DEFAULT_KEY = 'test.orm.default';
Orm::resolveDefaultUsing(static fn (): ?ConnectionConfig => currentDefault());
function withDefault(string|array|ConnectionConfig $spec, callable $fn): mixed
{
    return Context::with(DEFAULT_KEY, Orm::config($spec), $fn);
}
function currentDefault(): ?ConnectionConfig
{
    $c = Context::get(DEFAULT_KEY);

    return $c instanceof ConnectionConfig ? $c : null;
}
function goWithDefault(callable $fn): int|false
{
    $c = currentDefault();

    return Swoole\Coroutine::create(static fn () => $c !== null ? withDefault($c, $fn) : $fn());
}

/** @var list<QueryExecuted> $events */
$events = [];
Orm::listen(static function (QueryExecuted $e) use (&$events): void {
    $events[] = $e;
});
/** @var list<string> $warnings safety-net warnings (forgotten leases) */
$warnings = [];
Orm::onWarning(static function (string $m) use (&$warnings): void {
    $warnings[] = $m;
});

$admin = ['driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => '', 'username' => $USER, 'password' => $PASS];
$schema = <<<'SQL'
CREATE TABLE users (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL, email VARCHAR(128) NULL, origin VARCHAR(16) NOT NULL,
  settings JSON NULL, is_admin TINYINT(1) NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'active', score DECIMAL(8,2) NOT NULL DEFAULT 0,
  created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL);
CREATE TABLE posts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, title VARCHAR(128) NOT NULL, draft TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, KEY (user_id));
CREATE TABLE profiles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, bio VARCHAR(255) NULL);
CREATE TABLE roles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NOT NULL);
CREATE TABLE role_user (user_id INT UNSIGNED NOT NULL, role_id INT UNSIGNED NOT NULL, level INT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, PRIMARY KEY (user_id, role_id));
CREATE TABLE marks (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, origin VARCHAR(16) NOT NULL, coro INT NOT NULL, n INT NOT NULL, created_at DATETIME NULL, updated_at DATETIME NULL);
CREATE TABLE plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NOT NULL, price INT NOT NULL);
CREATE TABLE videos (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(64) NOT NULL);
CREATE TABLE comments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, commentable_type VARCHAR(64) NULL, commentable_id INT UNSIGNED NULL, body VARCHAR(64) NOT NULL, KEY (commentable_type, commentable_id));
CREATE TABLE images (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, imageable_type VARCHAR(64) NOT NULL, imageable_id INT UNSIGNED NOT NULL, url VARCHAR(64) NOT NULL);
CREATE TABLE tags (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NOT NULL);
CREATE TABLE taggables (tag_id INT UNSIGNED NOT NULL, taggable_type VARCHAR(64) NOT NULL, taggable_id INT UNSIGNED NOT NULL, weight INT NOT NULL DEFAULT 1, PRIMARY KEY (tag_id, taggable_type, taggable_id));
CREATE TABLE articles (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft', views INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL);
CREATE TABLE audits (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, auditable_type VARCHAR(64) NOT NULL, auditable_id INT UNSIGNED NOT NULL, event VARCHAR(16) NOT NULL, db VARCHAR(64) NOT NULL);
CREATE TABLE countries (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) NOT NULL);
CREATE TABLE authors (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, country_id INT UNSIGNED NOT NULL, name VARCHAR(32) NOT NULL, deleted_at DATETIME NULL);
CREATE TABLE books (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, author_id INT UNSIGNED NOT NULL, title VARCHAR(64) NOT NULL);
CREATE TABLE agents (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, author_ref INT UNSIGNED NOT NULL, name VARCHAR(64) NOT NULL);
SQL;

function setup(array $admin, array $DBS, string $schema): void
{
    $c = Orm::connection($admin);
    foreach ($DBS as $db) {
        $c->unprepared("DROP DATABASE IF EXISTS `{$db}`");
        $c->unprepared("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
        // one lease for USE + DDL (every unprepared() call would borrow a new connection)
        $c->withLease(static function (object $pdo) use ($db, $schema): void {
            $pdo->exec("USE `{$db}`");
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
                $pdo->exec($stmt);
            }
        });
    }
}

function teardown(array $admin, array $DBS): void
{
    $c = Orm::connection($admin);
    foreach ($DBS as $db) {
        $c->unprepared("DROP DATABASE IF EXISTS `{$db}`");
    }
    $left = $c->select("SHOW DATABASES LIKE 'loongs_orm%'");
    Orm::pool()->flush();
    echo "\n(teardown) dropped " . implode(', ', $DBS) . ' · left: ' . json_encode($left) . "\n";
}

$main = function () use (&$events, &$warnings, $T, $DBS, $admin, $schema, $mode, $SOCK, $USER, $PASS): void {
    $t0 = microtime(true);
    setup($admin, $DBS, $schema);
    echo sprintf("mode=%s  php=%s  swoole=%s  mysql=%s  in_coroutine=%s\n", $mode, PHP_VERSION, defined('SWOOLE_VERSION') ? SWOOLE_VERSION : '-',
        Orm::connection($T['t1'])->selectOne('select version() v')['v'], Context::inCoroutine() ? 'yes' : 'no');
    echo 'specs: t1=' . Orm::resolver()->spec($T['t1'])->describe() . ' | t2=' . Orm::resolver()->spec($T['t2'])->describe() . ' | t3=' . $T['t3']->describe() . "\n";

    try {
        // ------------------------------------------------------------ 1. query builder
        section('1. query builder (bound parameters)');
        $q = Orm::table('users', $T['t1']);
        $id1 = $q->insertGetId(['name' => 'alice', 'email' => 'a@x', 'origin' => 't1', 'score' => 10, 'is_admin' => true]);
        Orm::table('users', $T['t1'])->insert([
            ['name' => 'bob', 'email' => 'b@x', 'origin' => 't1', 'score' => 20, 'is_admin' => false],
            ['name' => "o'neil; DROP TABLE users; --", 'email' => null, 'origin' => 't1', 'score' => 30, 'is_admin' => false],
        ]);
        check('insertGetId + bulk insert', $id1 === 1 && Orm::table('users', $T['t1'])->count() === 3, "id={$id1}");
        $sql = Orm::table('users', $T['t1'])->where('name', "o'neil; DROP TABLE users; --")->toSql();
        check('injection-looking value is bound, not inlined', $sql === 'select * from `users` where `name` = ?' && Orm::table('users', $T['t1'])->where('name', "o'neil; DROP TABLE users; --")->exists(), $sql);
        $nested = Orm::table('users', $T['t1'])->where('origin', 't1')->where(fn ($w) => $w->where('score', '>', 25)->orWhere('name', 'alice'));
        check('nested where closure', $nested->pluck('name')->all() === ['alice', "o'neil; DROP TABLE users; --"], $nested->toSql());
        check('whereIn / whereNotIn / whereNull / whereNotNull', Orm::table('users', $T['t1'])->whereIn('name', ['alice', 'bob'])->count() === 2
            && Orm::table('users', $T['t1'])->whereNotIn('name', ['alice'])->count() === 2 && Orm::table('users', $T['t1'])->whereNull('email')->count() === 1
            && Orm::table('users', $T['t1'])->whereNotNull('email')->count() === 2 && Orm::table('users', $T['t1'])->whereIn('id', [])->count() === 0);
        check('whereBetween / orWhereBetween', Orm::table('users', $T['t1'])->whereBetween('score', [15, 30])->count() === 2);
        check('aggregates count/sum/avg/max/min', Orm::table('users', $T['t1'])->sum('score') == 60 && Orm::table('users', $T['t1'])->avg('score') == 20.0
            && Orm::table('users', $T['t1'])->max('score') == 30 && Orm::table('users', $T['t1'])->min('score') == 10, 'sum=' . Orm::table('users', $T['t1'])->sum('score'));
        Orm::table('posts', $T['t1'])->insert([['user_id' => 1, 'title' => 'p1'], ['user_id' => 1, 'title' => 'p2'], ['user_id' => 2, 'title' => 'p3']]);
        $rows = Orm::table('users as u', $T['t1'])->join('posts as p', 'p.user_id', '=', 'u.id')->select('u.name', Orm::raw('count(p.id) as n'))
            ->groupBy('u.name')->having('n', '>=', 1)->orderBy('n', 'desc')->get()->all();
        check('join + groupBy + having + orderBy + raw', $rows === [['name' => 'alice', 'n' => 2], ['name' => 'bob', 'n' => 1]], json_encode($rows));
        check('leftJoin + whereColumn', Orm::table('users', $T['t1'])->leftJoin('posts', 'posts.user_id', '=', 'users.id')->whereNull('posts.id')->count() === 1);
        check('limit / offset / orderBy', Orm::table('users', $T['t1'])->orderBy('id')->offset(1)->limit(1)->value('name') === 'bob');
        $pg = Orm::table('users', $T['t1'])->orderBy('id')->paginate(2, 2);
        check('paginate (query builder)', $pg->total === 3 && $pg->lastPage === 2 && $pg->count() === 1 && $pg->from === 3 && !$pg->hasMorePages, json_encode(array_diff_key($pg->toArray(), ['data' => 1])));
        $n = Orm::table('users', $T['t1'])->where('name', 'bob')->update(['score' => 21]);
        Orm::table('users', $T['t1'])->where('name', 'bob')->increment('score', 4);
        Orm::table('users', $T['t1'])->where('name', 'bob')->decrement('score');
        check('update / increment / decrement', $n === 1 && Orm::table('users', $T['t1'])->where('name', 'bob')->value('score') === '24.00');
        check('whereRaw with bindings / exists / doesntExist', Orm::table('users', $T['t1'])->whereRaw('score > ? and origin = ?', [20, 't1'])->count() === 2
            && Orm::table('users', $T['t1'])->where('name', 'nobody')->doesntExist());
        check('delete', Orm::table('posts', $T['t1'])->where('title', 'p3')->delete() === 1);
        Orm::table('users', $T['t1'])->truncate();
        Orm::table('posts', $T['t1'])->truncate();
        check('truncate', Orm::table('users', $T['t1'])->count() === 0);

        // ------------------------------------------------------------ 2. models
        section('2. models: CRUD, casts, accessors/mutators, hidden, dirty, scopes');
        $u = User::on($T['t1'])->create(['name' => '  ALICE ', 'email' => 'Alice@X.COM', 'origin' => 't1', 'settings' => ['theme' => 'dark', 'n' => 1], 'is_admin' => true, 'status' => Status::Active, 'score' => 12.5]);
        $raw = Orm::table('users', $T['t1'])->find($u->id);
        check('create + mutators (Attribute set, setXAttribute)', $u->exists && $u->wasRecentlyCreated && $raw['name'] === 'alice' && $raw['email'] === 'alice@x.com', json_encode(['name' => $raw['name'], 'email' => $raw['email']]));
        $f = User::on($T['t1'])->find($u->id);
        check('casts array/bool/enum/decimal/datetime', $f->settings == ['theme' => 'dark', 'n' => 1] && $f->is_admin === true && $f->status === Status::Active
            && $f->score === '12.50' && $f->created_at instanceof DateTimeImmutable && is_int($f->id), json_encode(['settings' => $f->settings, 'is_admin' => $f->is_admin, 'status' => $f->status, 'score' => $f->score]));
        check('accessor (get) + appends', $f->name === 'Alice' && $f->label === 'alice@t1');
        $arr = $f->toArray();
        check('toArray: hidden email, appended label, dates formatted', !array_key_exists('email', $arr) && $arr['label'] === 'alice@t1' && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $arr['created_at']) === 1 && $arr['status'] === 'active', $f->toJson());
        check('makeVisible', array_key_exists('email', $f->makeVisible('email')->toArray()));
        check('dirty tracking clean after load', $f->isClean() && $f->getDirty() === []);
        $f->score = 12.50;
        $f->settings = ['n' => 1, 'theme' => 'dark'];
        check('equivalent values are not dirty (decimal / json order)', $f->isClean(), json_encode($f->getDirty()));
        $f->name = 'Alicia';
        $f->is_admin = false;
        check('isDirty / getOriginal', $f->isDirty('name') && $f->isDirty('is_admin') && !$f->isDirty('email') && $f->getOriginal('name') === 'Alice', json_encode(array_keys($f->getDirty())));
        $f->save();
        check('save → getChanges / wasChanged', $f->wasChanged('name') && $f->isClean() && User::on($T['t1'])->find($u->id)->name === 'Alicia', json_encode(array_keys($f->getChanges())));
        try {
            User::on($T['t1'])->findOrFail(999);
            check('findOrFail throws', false);
        } catch (ModelNotFoundException $e) {
            check('findOrFail throws ModelNotFoundException', true, $e->getMessage());
        }
        try {
            new Profile(['user_id' => 1]);
            (new Mark())->fill(['origin' => 'x', 'nope' => 1]);
            check('mass assignment: non-fillable ignored', (new Mark())->fill(['origin' => 'x', 'nope' => 1])->getAttributes() === ['origin' => 'x']);
        } catch (MassAssignmentException $e) {
            check('mass assignment', false, $e->getMessage());
        }
        $b = User::on($T['t1'])->firstOrCreate(['name' => 'bob'], ['email' => 'b@x', 'origin' => 't1']);
        $b2 = User::on($T['t1'])->firstOrCreate(['name' => 'bob'], ['email' => 'other', 'origin' => 't1']);
        $b3 = User::on($T['t1'])->updateOrCreate(['name' => 'bob'], ['score' => 7]);
        check('firstOrCreate / updateOrCreate', $b->id === $b2->id && $b3->id === $b->id && User::on($T['t1'])->find($b->id)->score === '7.00');
        check('local scopes (+ args)', User::on($T['t1'])->admins()->count() === 0 && User::on($T['t1'])->originIs('t1')->count() === 2);
        $b->delete();
        check('soft delete: hidden by default, withTrashed, onlyTrashed, trashed()', User::on($T['t1'])->count() === 1 && User::on($T['t1'])->withTrashed()->count() === 2
            && User::on($T['t1'])->onlyTrashed()->first()?->trashed() === true && $b->exists);
        $tb = User::on($T['t1'])->onlyTrashed()->find($b->id);
        $tb->restore();
        check('restore', User::on($T['t1'])->count() === 2 && User::on($T['t1'])->find($b->id)?->trashed() === false);
        User::on($T['t1'])->find($b->id)->forceDelete();
        check('forceDelete', User::on($T['t1'])->withTrashed()->count() === 1);
        check('orWhere + soft-delete scope grouped correctly', User::on($T['t1'])->where('name', 'x')->orWhere('name', 'alicia')->toSql()
            === 'select * from `users` where (`name` = ? or `name` = ?) and `users`.`deleted_at` is null');
        check('model increment', $f->increment('score', 5) === 1 && $f->score === '17.50' && User::on($T['t1'])->find($f->id)->score === '17.50');

        // ------------------------------------------------------------ 3. relations + eager loading
        section('3. relations + eager loading');
        withDefault($T['t2'], static function (): void {
            $users = [];
            foreach (['ann', 'ben', 'cid'] as $i => $n) {
                $users[$n] = User::create(['name' => $n, 'email' => "{$n}@t2", 'origin' => 't2']);
                $users[$n]->profile()->create(['bio' => "bio of {$n}"]);
                foreach (range(1, $i + 1) as $k) {
                    $users[$n]->posts()->create(['title' => "{$n}-{$k}"]);
                }
            }
            $users['ann']->posts()->create(['title' => 'ann-draft', 'draft' => true]);
            $admin = Role::create(['name' => 'admin']);
            $editor = Role::create(['name' => 'editor']);
            $users['ann']->roles()->attach([$admin->id => ['level' => 9], $editor->id]);
            $users['ben']->roles()->attach($editor);
        });
        $before = count($events);
        $list = User::on($T['t2'])->with('posts', 'profile', 'roles')->orderBy('id')->get();
        $queries = count($events) - $before;
        $ann = $list->first();
        check('with(posts, profile, roles): 1 + 3 queries for 3 users', $queries === 4, "queries={$queries}");
        check('hasMany eager (global scope hides draft)', $list->map(fn (User $u) => $u->posts->count())->all() === [1, 2, 3], json_encode($list->map(fn (User $u) => $u->posts->pluck('title')->all())->all()));
        check('hasOne eager', $ann->profile?->bio === 'bio of ann');
        check('belongsToMany eager + pivot', $ann->roles->pluck('name')->all() === ['admin', 'editor'] && $ann->roles->first()->pivot->level === 9 && $ann->roles->first()->pivot->created_at !== null);
        $post = Post::on($T['t2'])->with('user')->where('title', 'cid-2')->first();
        check('belongsTo eager', $post->user?->name === 'Cid');
        $nestedList = User::on($T['t2'])->with('posts.user')->orderBy('id')->get();
        check('nested eager posts.user', $nestedList->first()->posts->first()->user->name === 'Ann');
        $constrained = User::on($T['t2'])->with(['posts' => fn ($q) => $q->where('title', 'like', '%-1')])->orderBy('id')->get();
        check('constrained eager load', $constrained->map(fn (User $u) => $u->posts->count())->all() === [1, 1, 1]);
        $lazy = User::on($T['t2'])->where('name', 'ben')->first();
        check('lazy relation property + relation query', $lazy->posts->count() === 2 && $lazy->posts()->where('title', 'ben-2')->exists() && $lazy->roles->count() === 1);
        $sync = $lazy->roles()->sync([1 => ['level' => 3], 2]);
        check('sync / detach', $sync['attached'] === [1] && User::on($T['t2'])->find($lazy->id)->roles->count() === 2 && $lazy->roles()->detach(1) === 1, json_encode($sync));
        check('withoutGlobalScope', Post::on($T['t2'])->count() === 6 && Post::on($T['t2'])->withoutGlobalScope('published')->count() === 7);
        check('load() on a collection', User::on($T['t2'])->orderBy('id')->get()->load('profile')->first()->relationLoaded('profile'));

        // ------------------------------------------------------------ 4. pagination / chunk / cursor
        section('4. pagination, chunk, cursor');
        foreach (range(1, 25) as $i) {
            Mark::on($T['t3'])->create(['origin' => 't3', 'coro' => 0, 'n' => $i]);
        }
        $p = Mark::on($T['t3'])->orderBy('n')->paginate(10, 3);
        check('model paginate', $p->total === 25 && $p->lastPage === 3 && $p->items->pluck('n')->all() === [21, 22, 23, 24, 25], json_encode(array_diff_key($p->toArray(), ['data' => 1])));
        $chunks = [];
        Mark::on($T['t3'])->chunk(10, function ($c) use (&$chunks) { $chunks[] = $c->count(); });
        $byId = [];
        Mark::on($T['t3'])->chunkById(7, function ($c) use (&$byId) { $byId[] = $c->count(); });
        check('chunk / chunkById', $chunks === [10, 10, 5] && $byId === [7, 7, 7, 4], json_encode([$chunks, $byId]));
        $before = count($events);
        $sum = 0;
        foreach (Mark::on($T['t3'])->where('n', '<=', 10)->cursor() as $m) {
            $sum += $m->n;
        }
        check('cursor streams models on one connection (1 query)', $sum === 55 && count($events) - $before === 1);

        // ------------------------------------------------------------ 5. transactions
        section('5. transactions');
        $before = count($events);
        Orm::transaction(static function () use ($T): void {
            Mark::on($T['t3'])->create(['origin' => 't3', 'coro' => -1, 'n' => 100]);
            Orm::table('marks', $T['t3'])->where('n', 100)->update(['coro' => -2]);
        }, $T['t3']);
        $txEvents = array_slice($events, $before);
        $serials = array_unique(array_map(fn (QueryExecuted $e) => $e->pdoSerial, $txEvents));
        check('commit; all statements used the transaction connection', Mark::on($T['t3'])->where('n', 100)->value('coro') === -2 && count($serials) === 1 && $txEvents[0]->inTransaction, 'serials=' . json_encode(array_values($serials)));
        try {
            Orm::transaction(static function () use ($T): void {
                Mark::on($T['t3'])->create(['origin' => 't3', 'coro' => -1, 'n' => 200]);
                throw new RuntimeException('boom');
            }, $T['t3']);
        } catch (RuntimeException) {
        }
        check('rollback on exception', Mark::on($T['t3'])->where('n', 200)->doesntExist());
        $c3 = Orm::connection($T['t3']);
        $c3->transaction(static function ($c) use ($T): void {
            Mark::on($T['t3'])->create(['origin' => 't3', 'coro' => -1, 'n' => 300]);
            try {
                $c->transaction(static function () use ($T): void {
                    Mark::on($T['t3'])->create(['origin' => 't3', 'coro' => -1, 'n' => 301]);
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }
        });
        check('nested transaction = savepoint (inner rolled back, outer committed)', Mark::on($T['t3'])->where('n', 300)->exists() && Mark::on($T['t3'])->where('n', 301)->doesntExist());
        check('transaction does not leak to another database', (function () use ($T): bool {
            return Orm::transaction(static function () use ($T): bool {
                $t3 = Orm::connection($T['t3'])->transactionLevel();
                $t1 = Orm::connection($T['t1'])->transactionLevel();
                return $t3 === 1 && $t1 === 0;
            }, $T['t3']);
        })());
        check('context clean after transactions', Context::keys() === [], json_encode(Context::keys()));

        // ------------------------------------------------------------ 6. pinned connection
        section('6. model remembers its connection');
        $u1 = User::on($T['t1'])->where('name', 'alicia')->first();
        $interleaved = User::on($T['t2'])->create(['name' => 'intruder', 'email' => 'i@t2', 'origin' => 't2']);
        withDefault($T['t2'], static function () use ($u1): void {
            $u1->name = 'alicia-saved';
            $u1->save();
            $u1->posts()->create(['title' => 'written-from-t2-scope']);
            $u1->refresh();
        });
        $t1Row = Orm::table('users', $T['t1'])->where('id', $u1->id)->value('name');
        $t2Hit = Orm::table('users', $T['t2'])->where('name', 'alicia-saved')->count() + Orm::table('posts', $T['t2'])->where('title', 'written-from-t2-scope')->count();
        check('model loaded from t1 saves to t1 after a t2 query, inside a t2 scope', $t1Row === 'alicia-saved' && $t2Hit === 0 && $interleaved->exists,
            "t1.name={$t1Row} t2 rows touched={$t2Hit}");
        check('relation create from t1 model lands in t1', Orm::table('posts', $T['t1'])->where('title', 'written-from-t2-scope')->count() === 1);
        $moved = User::on($T['t1'])->find($u1->id);
        $moved->delete();
        check('delete goes to the pinned connection', Orm::table('users', $T['t1'])->where('id', $u1->id)->whereNotNull('deleted_at')->count() === 1
            && Orm::table('users', $T['t2'])->whereNotNull('deleted_at')->count() === 0);
        $new = new User(['name' => 'scoped', 'email' => 's@x', 'origin' => 't3']);
        withDefault($T['t3'], fn () => $new->save());
        $new->name = 'scoped-later';
        $new->save(); // outside any scope: stays on t3 (pinned at first save)
        check('new model saved in t3 scope stays on t3', Orm::table('users', $T['t3'])->where('name', 'scoped-later')->count() === 1
            && Orm::table('users', $T['t1'])->where('name', 'scoped-later')->count() === 0);
        Plan::create(['name' => 'pro', 'price' => 99]);
        $planInScope = withDefault($T['t1'], fn () => Plan::query()->count());
        check('declared connection (central) ignores default scope', $planInScope === 1 && Orm::table('plans', $T['t1'])->count() === 0);
        check('default scope nesting restores previous', withDefault($T['t1'], fn () => [currentDefault()?->database(), withDefault($T['t2'], fn () => currentDefault()?->database()), currentDefault()?->database()])
            === ['loongs_orm_t1', 'loongs_orm_t2', 'loongs_orm_t1'] && currentDefault() === null);

        // ------------------------------------------------------------ 7. concurrency / isolation
        section('7. database isolation under concurrency, pooled (' . (Context::inCoroutine() ? '200 coroutines' : '200 sequential iterations, no coroutines') . ')');
        foreach (['t1', 't2', 't3'] as $t) {
            Orm::table('marks', $T[$t])->truncate();
        }
        $size = Orm::pool()->config->size;
        $before = Orm::poolStats();
        $events = [];
        $N = 200;
        $wrongRead = 0;
        $wrongDb = 0;
        $cidDb = [];
        $maxOpen = ['t1' => 0, 't2' => 0, 't3' => 0];
        $expected = ['t1' => 0, 't2' => 0, 't3' => 0];
        $work = static function (int $i) use ($T, &$wrongRead, &$wrongDb, &$cidDb, &$expected, &$maxOpen): void {
            $t = ['t1', 't2', 't3'][random_int(0, 2)];
            $expected[$t]++;
            $insert = static function () use ($i, $t, $T): Mark {
                // alternate: explicit on() / default scope
                return $i % 2 === 0
                    ? Mark::on($T[$t])->create(['origin' => $t, 'coro' => $i, 'n' => $i])
                    : withDefault($T[$t], static fn () => Mark::create(['origin' => $t, 'coro' => $i, 'n' => $i]));
            };
            $m = $insert();
            if (Context::inCoroutine()) {
                Swoole\Coroutine::sleep(random_int(1, 20) / 1000); // let other databases interleave
            }
            $row = Orm::connection($T[$t])->selectOne('select database() as db, connection_id() as cid');
            $cidDb[(int) $row['cid']][$t] = true;
            $maxOpen[$t] = max($maxOpen[$t], Orm::poolStats($T[$t])['open']);
            if ($row['db'] !== 'loongs_orm_' . $t) {
                $wrongDb++;
            }
            $back = $i % 3 === 0 ? withDefault($T[$t], static fn () => Mark::find($m->id)) : Mark::on($T[$t])->find($m->id);
            if ($back === null || $back->origin !== $t || $back->coro !== $i) {
                $wrongRead++;
            }
            $m->n = $m->n + 1000; // pinned save while other coroutines use other databases
            $m->save();
        };
        $start = microtime(true);
        if (Context::inCoroutine()) {
            $wg = new Swoole\Coroutine\WaitGroup();
            for ($i = 1; $i <= $N; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($work, $i, $wg): void {
                    try {
                        $work($i);
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();
        } else {
            for ($i = 1; $i <= $N; $i++) {
                $work($i);
            }
        }
        $elapsed = microtime(true) - $start;
        $cross = 0;
        $counts = [];
        foreach (['t1', 't2', 't3'] as $t) {
            $counts[$t] = Orm::table('marks', $T[$t])->count();
            $cross += Orm::table('marks', $T[$t])->where('origin', '!=', $t)->count();
            $cross += Orm::table('marks', $T[$t])->where('n', '<', 1000)->count(); // pinned save missed its own row
        }
        $serialKeys = [];
        $perDbSerials = [];
        foreach ($events as $e) {
            $serialKeys[$e->pdoSerial][$e->connectionKey] = true;
            $perDbSerials[$e->database][$e->pdoSerial] = true;
        }
        $multiKey = count(array_filter($serialKeys, static fn (array $k) => count($k) > 1));
        $multiCid = count(array_filter($cidDb, static fn (array $t) => count($t) > 1));
        $after = Orm::poolStats();
        $perT = implode(' ', array_map(static fn ($db, $s) => substr($db, 11) . '=' . count($s), array_keys($perDbSerials), $perDbSerials));
        printf("  rows per db: t1=%d t2=%d t3=%d (expected %d/%d/%d, total %d) · %.2fs\n", $counts['t1'], $counts['t2'], $counts['t3'], $expected['t1'], $expected['t2'], $expected['t3'], array_sum($counts), $elapsed);
        printf("  statements=%d  distinct PDOs=%d (per database: %s; pool size=%d)  max open per database: t1=%d t2=%d t3=%d  PDOs on >1 config=%d\n",
            count($events), count($serialKeys), $perT, $size, $maxOpen['t1'], $maxOpen['t2'], $maxOpen['t3'], $multiKey);
        printf("  pool: created=%d hits=%d waits=%d discarded=%d · now open=%d active=%d idle=%d\n", $after['created'] - $before['created'], $after['hits'] - $before['hits'],
            $after['waits'] - $before['waits'], $after['discarded'] - $before['discarded'], $after['open'], $after['active'], $after['idle']);
        printf("  distinct MySQL connection ids=%d  ids seen on >1 database=%d  wrong DATABASE()=%d  wrong read-backs=%d  cross-database/misplaced rows=%d\n", count($cidDb), $multiCid, $wrongDb, $wrongRead, $cross);
        check('zero cross-database rows', $cross === 0 && $counts === $expected && array_sum($counts) === $N);
        check('every read-back / DATABASE() matched its database', $wrongRead === 0 && $wrongDb === 0);
        check('no PDO object served two configs', $multiKey === 0);
        check('no MySQL connection id seen on two databases', $multiCid === 0);
        check('pool reuse: statements far exceed PDOs; per-database open ≤ size', count($events) >= 10 * count($serialKeys) && max($maxOpen) <= $size
            && max(array_map('count', $perDbSerials)) <= $size && $after['active'] === 0, sprintf('%d statements / %d PDOs', count($events), count($serialKeys)));
        if (Context::inCoroutine()) {
            $sleepStart = microtime(true);
            $w0 = Orm::poolStats()['waits'];
            $peak = 0;
            $wg = new Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 20; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($T, $wg, &$peak): void {
                    Orm::connection($T['t1'])->select('select sleep(0.2)');
                    $peak = max($peak, Orm::poolStats($T['t1'])['open']);
                    $wg->done();
                });
            }
            $wg->wait();
            $w = microtime(true) - $sleepStart;
            $waves = (int) ceil(20 / $size);
            check("concurrency bounded by pool size (20 × sleep 0.2s, size {$size} → {$waves} waves)", $w >= 0.2 * $waves - 0.05 && $w < 0.2 * $waves + 0.6 && $peak <= $size,
                sprintf('%.2fs, peak open=%d, waits=%d', $w, $peak, Orm::poolStats()['waits'] - $w0));

            // transactions in parallel coroutines, each on its own database, half rolled back
            $wg = new Swoole\Coroutine\WaitGroup();
            $want = ['t1' => 0, 't2' => 0, 't3' => 0];
            $txLeak = 0;
            for ($i = 0; $i < 30; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($T, $wg, $i, &$want, &$txLeak): void {
                    $t = ['t1', 't2', 't3'][$i % 3];
                    try {
                        Orm::transaction(static function () use ($T, $t, $i, &$txLeak): void {
                            Mark::on($T[$t])->create(['origin' => $t, 'coro' => 10000 + $i, 'n' => 1]);
                            Swoole\Coroutine::sleep(0.01);
                            if (Orm::connection($T[$t])->transactionLevel() !== 1) {
                                $txLeak++;
                            }
                            foreach (['t1', 't2', 't3'] as $o) {
                                if ($o !== $t && Orm::connection($T[$o])->transactionLevel() !== 0) {
                                    $txLeak++;
                                }
                            }
                            if ($i % 2 === 1) {
                                throw new RuntimeException('rollback');
                            }
                        }, $T[$t]);
                        $want[$t]++;
                    } catch (RuntimeException) {
                    } finally {
                        if (Context::keys() !== []) {
                            $txLeak++;
                        }
                        $wg->done();
                    }
                });
            }
            $wg->wait();
            $got = [];
            foreach (['t1', 't2', 't3'] as $t) {
                $got[$t] = Orm::table('marks', $T[$t])->where('coro', '>=', 10000)->count();
            }
            check('30 parallel transactions: commits kept, rollbacks gone, no tx leak', $got === $want && $txLeak === 0 && Orm::poolStats()['active'] === 0, json_encode(['committed' => $got, 'leaks' => $txLeak]));
            $child = null;
            withDefault($T['t2'], static function () use (&$child): void {
                $wg = new Swoole\Coroutine\WaitGroup(2);
                Swoole\Coroutine::create(static function () use (&$child, $wg): void {
                    $child['plain'] = currentDefault()?->database();
                    $wg->done();
                });
                goWithDefault(static function () use (&$child, $wg): void {
                    $child['go'] = currentDefault()?->database();
                    $wg->done();
                });
                $wg->wait();
            });
            check('default scope is coroutine-local (child sees none; goWithDefault() carries it)', $child === ['plain' => null, 'go' => 'loongs_orm_t2'] || $child === ['go' => 'loongs_orm_t2', 'plain' => null], json_encode($child));
        }

        // ------------------------------------------------------------ 8. ORM pool behaviour
        section('8. ORM pool: config, LRU max_pools, idle / ttl eviction, DATABASE() validation');
        check('pool is on by default; config from database.orm_pool', Orm::pool()->config->size === 8 && Orm::pool()->config->maxPools === 16, json_encode(Orm::pool()->config->toArray()));
        $events = [];
        $pool = Orm::configurePool(['size' => 2, 'max_pools' => 2, 'idle_seconds' => 0.3, 'ttl' => 600, 'wait_timeout' => 1]);
        foreach (range(1, 6) as $i) {
            Orm::connection($T['t1'])->selectOne('select 1');
        }
        Orm::connection($T['t2'])->selectOne('select 1');
        Orm::connection($T['t3'])->selectOne('select 1');
        $st = $pool->stats();
        $pooledSerialKeys = [];
        foreach ($events as $e) {
            $pooledSerialKeys[$e->pdoSerial][$e->connectionKey] = true;
        }
        check('6 statements on 1 connection; LRU keeps ≤ max_pools', $st['created'] === 3 && $st['hits'] === 5 && $st['pools'] <= 2 && $st['evicted_pools'] === 1
            && count(array_filter($pooledSerialKeys, fn ($k) => count($k) > 1)) === 0, json_encode($st));
        $closed0 = $pool->stats()['closed'];
        napms(450);
        $cidBefore = Orm::connection($T['t3'])->selectOne('select connection_id() c')['c'];
        $st = $pool->stats();
        check('idle connections older than idle_seconds are closed', $st['closed'] > $closed0 && Orm::poolStats($T['t3'])['open'] === 1 && $st['created'] === 4, json_encode($st));
        $pool = Orm::configurePool(['size' => 2, 'max_pools' => 4, 'idle_seconds' => 60, 'ttl' => 0.3, 'wait_timeout' => 1]);
        $a = Orm::connection($T['t3'])->selectOne('select connection_id() c')['c'];
        $b = Orm::connection($T['t3'])->selectOne('select connection_id() c')['c'];
        napms(450);
        $c = Orm::connection($T['t3'])->selectOne('select connection_id() c')['c'];
        check('connections older than ttl are replaced', $a === $b && $c !== $a && $pool->stats()['created'] === 2, "cid {$a} → {$b} → {$c}");
        $pool = Orm::configurePool(['size' => 2, 'max_pools' => 4, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 1]);
        Orm::connection($T['t1'])->withLease(static fn (object $pdo) => $pdo->exec('USE loongs_orm_t2'));
        $db = Orm::connection($T['t1'])->selectOne('select database() db')['db'];
        check('connection switched by USE fails DATABASE() check → discarded', $db === 'loongs_orm_t1' && $pool->stats()['discarded'] === 1, "database()={$db} " . json_encode($pool->stats()));
        Orm::configurePool(['size' => 8, 'max_pools' => 16, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 5]);

        // ------------------------------------------------------------ 9. framework pool
        section('9. framework PDOPool: named + plain default, discard, kill, exhaustion');
        if (Context::inCoroutine() && class_exists(\Loongs\Database\DatabaseManager::class)) {
            $dir = sys_get_temp_dir() . '/loongs_orm_cfg_' . getmypid();
            @mkdir($dir);
            file_put_contents($dir . '/database.php', '<?php return ' . var_export(['default' => 'central', 'connections' => ['central' => [
                'driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => 'loongs_orm_central', 'username' => $USER, 'password' => $PASS, 'pool' => ['size' => 4, 'wait_timeout' => 0.5]]]], true) . ';');
            $dbm = new \Loongs\Database\DatabaseManager(new \Loongs\Config\Repository($dir));
            unlink($dir . '/database.php');
            rmdir($dir);
            $dbm->bootPools();
            Orm::usePools(new FrameworkPoolProvider($dbm));
            $events = [];
            $wg = new Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 40; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($wg, $T): void {
                    Plan::query()->count();                                   // declared named connection
                    Orm::table('plans')->count();                             // no scope → default → framework pool
                    Orm::connection($T['t1'])->selectOne('select 1');        // ad-hoc config → ORM pool
                    $wg->done();
                });
            }
            $wg->wait();
            $fw = array_filter($events, fn ($e) => $e->source === LeaseSource::Framework);
            $tp = array_filter($events, fn ($e) => $e->source === LeaseSource::Pool);
            $fwSerials = count(array_unique(array_map(fn ($e) => $e->pdoSerial, $fw)));
            $fwStats = $dbm->stats('central');
            check('named + plain default use the framework pool (≤ 4 PDOs for 80 statements)', count($fw) === 80 && $fwSerials <= 4 && $fwStats['open'] <= 4 && $fwStats['active'] === 0,
                'framework statements=' . count($fw) . " distinct PDOs={$fwSerials} stats=" . json_encode($fwStats));
            check('ad-hoc config never enters the framework pool', count($tp) === 40 && count(array_intersect(array_map(fn ($e) => $e->pdoSerial, $tp), array_map(fn ($e) => $e->pdoSerial, $fw))) === 0);
            $open0 = $dbm->stats('central')['open'];
            $p = $dbm->connection('central');
            $dbm->discard($p, 'central');
            unset($p);
            check('DatabaseManager::discard() drops the connection and refills the pool', $dbm->stats('central')['open'] === $open0 && $dbm->stats('central')['active'] === 0, json_encode($dbm->stats('central')));
            $killNote = '';
            $cidA = Orm::using(null, static function ($c) use ($admin, &$killNote): int {
                $cid = (int) $c->selectOne('select connection_id() c')['c'];
                Orm::connection($admin)->unprepared("KILL {$cid}");
                try {
                    $new = (int) $c->selectOne('select connection_id() c')['c'];
                    $killNote = "PDOProxy reconnected transparently: {$cid} → {$new}";
                } catch (\Loongs\Orm\Exceptions\QueryException $e) {
                    $killNote = 'lost → lease discarded: ' . substr($e->getPrevious()?->getMessage() ?? '', 0, 60);
                }
                return $cid;
            });
            $ok = Plan::query()->count() >= 1;
            $s = $dbm->stats('central');
            check('killed framework connection: recovered, pool size intact', $ok && $s['open'] <= 4 && $s['active'] === 0, $killNote . ' ' . json_encode($s));
            try {
                $dbm->run(static function (object $pdo): void {
                    $pdo->beginTransaction();
                    throw new RuntimeException('boom');
                }, 'central');
            } catch (RuntimeException) {
            }
            check('framework run(): exception → rolled back and returned', $dbm->stats('central')['active'] === 0);
            // exhaustion: 4 holders for 1s, pool size 4, wait_timeout 0.5
            $wg = new Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 4; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($wg): void {
                    Orm::using(null, static fn ($c) => $c->select('select sleep(0.8)'));
                    $wg->done();
                });
            }
            Swoole\Coroutine::sleep(0.05);
            $t = microtime(true);
            $ex = null;
            try {
                Orm::table('plans')->count();
            } catch (\Throwable $e) {
                $ex = $e;
            }
            $waited = microtime(true) - $t;
            $wg->wait();
            $after = Orm::table('plans')->count();
            check('framework pool exhausted → PoolExhaustedException after wait_timeout, no deadlock', $ex instanceof \Loongs\Orm\Exceptions\PoolExhaustedException && $waited >= 0.45 && $waited < 0.8 && $after >= 1 && $dbm->stats('central')['active'] === 0,
                sprintf('%s after %.2fs: %s', $ex !== null ? get_class($ex) : 'none', $waited, substr($ex?->getMessage() ?? '', 0, 70)));
            $txSerial = Orm::transaction(static function () use (&$events): int {
                Plan::create(['name' => 'tx', 'price' => 1]);
                return end($events)->pdoSerial;
            });
            check('transaction on a framework connection returns it to the pool', Plan::where('name', 'tx')->exists() && $dbm->stats('central')['active'] === 0, "serial={$txSerial}");
            Orm::usePools(null);
            $dbm->closePools();
        } else {
            echo "  (skipped: needs a coroutine and loongs/framework; named connections use the ORM pool here)\n";
            $events = [];
            Orm::table('plans')->count();
            check('no scope, no framework pool → default named config via the ORM pool', count($events) === 1 && $events[0]->source === LeaseSource::Pool && $events[0]->database === 'loongs_orm_central');
        }

        // ------------------------------------------------------------ 10. manual lease API
        section('10. manual release: Orm::acquire() / release() / Orm::using()');
        $t1 = Orm::resolver()->spec($T['t1']);
        $events = [];
        $h = Orm::acquire($t1);
        $active = Orm::poolStats($t1)['active'];
        $cids = [$h->selectOne('select connection_id() c')['c'], $h->selectOne('select connection_id() c')['c']];
        Mark::on($T['t1'])->count();                 // ambient: models on t1 use the held connection
        Orm::table('marks', $T['t1'])->count();
        withDefault($T['t1'], static fn () => Mark::query()->count());
        $other = Orm::connection($T['t2'])->selectOne('select database() d')['d'];
        $t1Serials = array_unique(array_map(fn ($e) => $e->pdoSerial, array_filter($events, fn ($e) => $e->database === 'loongs_orm_t1')));
        check('acquire(): one connection for handle, models, builders on t1', $active === 1 && $cids[0] === $cids[1] && count($t1Serials) === 1 && $other === 'loongs_orm_t2',
            'serials=' . json_encode(array_values($t1Serials)));
        $h2 = Orm::acquire($T['t1']);
        $shared = $h2->leaseInfo()['serial'] === $h->leaseInfo()['serial'] && Orm::poolStats($t1)['active'] === 1;
        $h2->release();
        $stillHeld = Orm::poolStats($t1)['active'] === 1;
        $h->release();
        $h->release();
        $h2->release();
        $threw = false;
        try {
            $h->selectOne('select 1');
        } catch (LogicException) {
            $threw = true;
        }
        check('nested acquire shares; last release returns it; double release is a no-op', $shared && $stillHeld && Orm::poolStats($t1)['active'] === 0 && $h->isReleased() && $threw && Context::keys() === []);
        $r = Orm::using($T['t1'], static fn ($c) => [$c->selectOne('select connection_id() c')['c'], Orm::poolStats($T['t1'])['active']]);
        check('Orm::using() returns the callback value and releases', $r[1] === 1 && Orm::poolStats($t1)['active'] === 0);
        if (Context::inCoroutine()) {
            $mine = null;
            $theirs = null;
            Orm::using($T['t1'], static function ($c) use ($T, &$mine, &$theirs): void {
                $mine = $c->leaseInfo()['serial'];
                $wg = new Swoole\Coroutine\WaitGroup(1);
                Swoole\Coroutine::create(static function () use ($T, $wg, &$theirs): void {
                    $theirs = Orm::acquire($T['t1']);
                    $theirs = [$theirs->leaseInfo()['serial'], $theirs->release()][0];
                    $wg->done();
                });
                $wg->wait();
            });
            check('a held connection is coroutine-local (child gets its own)', $mine !== null && $theirs !== null && $mine !== $theirs && Orm::poolStats($t1)['active'] === 0, "parent #{$mine} child #{$theirs}");
        }

        // ------------------------------------------------------------ 11. forgotten leases
        section('11. safety net: forgotten leases are returned (WARN)');
        $warnings = [];
        (static function () use ($T): void {
            $leak = Orm::acquire($T['t1']);
            $leak->selectOne('select 1');
        })();
        check('handle dropped without release() → destructor returns it', Orm::poolStats($t1)['active'] === 0 && count($warnings) === 1, $warnings[0] ?? '');
        if (Context::inCoroutine()) {
            $warnings = [];
            $GLOBALS['__leaked'] = [];
            $wg = new Swoole\Coroutine\WaitGroup(1);
            Swoole\Coroutine::create(static function () use ($T, $wg): void {
                Swoole\Coroutine::defer(static fn () => $wg->done());
                $GLOBALS['__leaked'][] = Orm::acquire($T['t1']);        // still referenced after the coroutine ends
                $GLOBALS['__leaked'][0]->selectOne('select 1');
            });
            $wg->wait();
            $activeAfterCo = Orm::poolStats($t1)['active'];
            unset($GLOBALS['__leaked']);
            check('lease still referenced when its coroutine ends → Coroutine::defer returns it', $activeAfterCo === 0 && count($warnings) === 1, $warnings[0] ?? '');
            $warnings = [];
            $wg = new Swoole\Coroutine\WaitGroup(1);
            Swoole\Coroutine::create(static function () use ($T, $wg): void {
                Swoole\Coroutine::defer(static fn () => $wg->done());
                $c = Orm::connection($T['t1']);
                $c->beginTransaction();                                   // never committed
                $c->insert('insert into marks (origin, coro, n) values (?, ?, ?)', ['t1', -77, 1]);
            });
            $wg->wait();
            check('transaction left open at coroutine end → rolled back and returned', Orm::poolStats($t1)['active'] === 0 && Orm::table('marks', $T['t1'])->where('coro', -77)->doesntExist() && count($warnings) === 1, $warnings[0] ?? '');
        }
        $warnings = [];

        // ------------------------------------------------------------ 12. exceptions release
        section('12. exceptions always return the connection');
        $s0 = Orm::poolStats($t1);
        $g0 = Orm::poolStats();
        $caught = [];
        try {
            Orm::using($T['t1'], static function ($c): void {
                $c->selectOne('select 1');
                throw new RuntimeException('user code');
            });
        } catch (RuntimeException $e) {
            $caught[] = $e->getMessage();
        }
        try {
            Orm::connection($T['t1'])->select('select * from no_such_table');
        } catch (\Loongs\Orm\Exceptions\QueryException $e) {
            $caught[] = 'sql:' . ($e->isRecoverable() ? 'recoverable' : 'lost');
        }
        try {
            Orm::using($T['t1'], static fn ($c) => $c->select('selec broken'));
        } catch (\Loongs\Orm\Exceptions\QueryException) {
            $caught[] = 'sql-in-using';
        }
        try {
            Orm::transaction(static function () use ($T): void {
                Mark::on($T['t1'])->create(['origin' => 't1', 'coro' => -88, 'n' => 1]);
                throw new RuntimeException('tx user code');
            }, $T['t1']);
        } catch (RuntimeException $e) {
            $caught[] = $e->getMessage();
        }
        try {
            Orm::transaction(static function () use ($T): void {
                Mark::on($T['t1'])->create(['origin' => 't1', 'coro' => -89, 'n' => 1]);
                Orm::connection($T['t1'])->insert('insert into marks (nope) values (1)');
            }, $T['t1']);
        } catch (\Loongs\Orm\Exceptions\QueryException) {
            $caught[] = 'tx-sql';
        }
        try {
            Orm::using($T['t1'], static function ($c) use ($T): void {
                $c->beginTransaction();
                Mark::on($T['t1'])->create(['origin' => 't1', 'coro' => -90, 'n' => 1]);
                throw new RuntimeException('open tx in using');
            });
        } catch (RuntimeException $e) {
            $caught[] = $e->getMessage();
        }
        $s1 = Orm::poolStats($t1);
        $g1 = Orm::poolStats();
        $noRows = Orm::table('marks', $T['t1'])->whereIn('coro', [-88, -89, -90])->doesntExist();
        printf("  caught: %s\n  t1 before %s → after %s · discarded +%d created +%d\n", implode(' | ', $caught), json_encode($s0), json_encode($s1), $g1['discarded'] - $g0['discarded'], $g1['created'] - $g0['created']);
        check('user / SQL / transaction exceptions: all released, rolled back, pool intact', count($caught) === 6 && $s1['active'] === 0 && $s1['open'] <= $s0['open'] + 1
            && $g1['discarded'] === $g0['discarded'] && $noRows && Context::keys() === []);

        // ------------------------------------------------------------ 13. killed connections
        section('13. killed connections are discarded and replaced');
        $pool = Orm::configurePool(['size' => 3, 'max_pools' => 16, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 2]);
        $t2 = Orm::resolver()->spec($T['t2']);
        $cid = (int) Orm::connection($T['t2'])->selectOne('select connection_id() c')['c'];
        Orm::connection($admin)->unprepared("KILL {$cid}");
        napms(50);
        $cid2 = (int) Orm::connection($T['t2'])->selectOne('select connection_id() c')['c'];
        $st = $pool->stats();
        check('idle connection killed by the server → fails checkout validation, replaced', $cid2 !== $cid && $st['discarded'] === 1 && Orm::poolStats($t2)['open'] === 1, "cid {$cid} → {$cid2} " . json_encode($st));
        $lost = null;
        Orm::using($T['t2'], static function ($c) use ($admin, &$lost): void {
            $cid = (int) $c->selectOne('select connection_id() c')['c'];
            Orm::connection($admin)->unprepared("KILL {$cid}");
            napms(50);
            try {
                $c->selectOne('select 1');
                $lost = 'no error?';
            } catch (\Loongs\Orm\Exceptions\QueryException $e) {
                $lost = ($e->isRecoverable() ? 'recoverable?? ' : 'lost: ') . substr($e->getPrevious()?->getMessage() ?? '', 0, 60);
            }
        });
        $st = $pool->stats();
        $cid3 = (int) Orm::connection($T['t2'])->selectOne('select connection_id() c')['c'];
        check('connection killed while held → statement fails, lease discarded on release', str_starts_with((string) $lost, 'lost') && $st['discarded'] === 2 && Orm::poolStats($t2)['active'] === 0 && $cid3 > 0, $lost);
        $txErr = null;
        try {
            Orm::transaction(static function ($c) use ($admin, $T): void {
                Mark::on($T['t2'])->create(['origin' => 't2', 'coro' => -99, 'n' => 1]);
                $cid = (int) $c->selectOne('select connection_id() c')['c'];
                Orm::connection($admin)->unprepared("KILL {$cid}");
                napms(50);
                Mark::on($T['t2'])->create(['origin' => 't2', 'coro' => -98, 'n' => 1]);
            }, $T['t2']);
        } catch (\Loongs\Orm\Exceptions\QueryException $e) {
            $txErr = $e->isRecoverable() ? 'recoverable' : 'lost';
        }
        $st = $pool->stats();
        check('connection killed inside a transaction → rolled back by the server, discarded', $txErr === 'lost' && $st['discarded'] === 3 && Orm::poolStats($t2)['active'] === 0
            && Orm::table('marks', $T['t2'])->whereIn('coro', [-99, -98])->doesntExist() && Context::keys() === [], json_encode($st));

        // ------------------------------------------------------------ 14. exhaustion
        section('14. pool exhaustion: bounded wait, clear error, no deadlock');
        $pool = Orm::configurePool(['size' => 2, 'max_pools' => 16, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 0.3]);
        $t3 = Orm::resolver()->spec($T['t3']);
        $l1 = Orm::resolver()->lease($t3);
        $l2 = Orm::resolver()->lease($t3);
        $t = microtime(true);
        $ex = null;
        try {
            Orm::connection($T['t3'])->select('select 1');
        } catch (\Loongs\Orm\Exceptions\PoolExhaustedException $e) {
            $ex = $e;
        }
        $waited = microtime(true) - $t;
        $l1->release();
        $ok = Orm::connection($T['t3'])->selectOne('select 1 as one')['one'] === 1;
        $l2->release();
        $expectWait = Context::inCoroutine() ? ($waited >= 0.28 && $waited < 0.6) : $waited < 0.05;
        check('all connections out → PoolExhaustedException (' . (Context::inCoroutine() ? 'after wait_timeout' : 'immediately: no coroutine to wait in') . ')', $ex !== null && $expectWait && $ok && Orm::poolStats($t3)['active'] === 0,
            sprintf('%.2fs: %s', $waited, substr($ex?->getMessage() ?? '', 0, 90)));
        if (Context::inCoroutine()) {
            $res = ['ok' => 0, 'exhausted' => 0];
            $t = microtime(true);
            $wg = new Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 8; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($T, $wg, &$res): void {
                    try {
                        Orm::using($T['t3'], static fn ($c) => $c->select('select sleep(0.25)'));
                        $res['ok']++;
                    } catch (\Loongs\Orm\Exceptions\PoolExhaustedException) {
                        $res['exhausted']++;
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();
            $el = microtime(true) - $t;
            $st = $pool->stats();
            check('8 coroutines × 0.25s on size 2 / wait 0.3s: waiters served, rest time out, pool clean', $res['ok'] >= 4 && $res['exhausted'] >= 1 && $res['ok'] + $res['exhausted'] === 8
                && $el < 1.0 && Orm::poolStats($t3)['active'] === 0 && Orm::poolStats($t3)['waiting'] === 0 && Orm::poolStats($t3)['open'] <= 2,
                json_encode($res) . sprintf(' in %.2fs · waits=%d timeouts=%d', $el, $st['waits'], $st['timeouts']));
        }
        Orm::configurePool(['size' => 8, 'max_pools' => 16, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 5]);

        // ------------------------------------------------------------ 15. model events
        section('15. model events: order, cancellation, observers, quiet, withoutEvents, listener connection');
        EventLog::$log = [];
        $a = Article::on($T['t1'])->create(['title' => 'e1', 'status' => 'draft']);
        check('create: saving → creating → created → saved (+ observer)', EventLog::for('e1') === ['saving', 'creating', 'created', 'saved', 'observer:saved'], json_encode(EventLog::for('e1')));
        EventLog::$log = [];
        $f = Article::on($T['t1'])->find($a->id);
        check('find → retrieved', EventLog::for('e1') === ['retrieved'], json_encode(EventLog::for('e1')));
        EventLog::$log = [];
        $f->status = 'live';
        $f->save();
        $f->save();
        check('update: saving → updating → updated → saved; clean save: saving → saved', EventLog::for('e1') === ['saving', 'updating', 'updated', 'saved', 'observer:saved', 'saving', 'saved', 'observer:saved'], json_encode(EventLog::for('e1')));
        EventLog::$log = [];
        $f->increment('views');
        check('model increment: updating → updated', EventLog::for('e1') === ['updating', 'updated'] && $f->views === 1, json_encode(EventLog::for('e1')));
        EventLog::$log = [];
        $f->delete();
        $f->restore();
        check('soft delete → deleting/deleted · restore → restoring … restored', EventLog::for('e1') === ['deleting', 'deleted', 'observer:deleted', 'restoring', 'saving', 'updating', 'updated', 'saved', 'observer:saved', 'restored'], json_encode(EventLog::for('e1')));
        EventLog::$log = [];
        $f->forceDelete();
        check('forceDelete: forceDeleting → deleting → deleted → forceDeleted', EventLog::for('e1') === ['forceDeleting', 'deleting', 'deleted', 'observer:deleted', 'forceDeleted'] && Article::on($T['t1'])->withTrashed()->find($a->id) === null, json_encode(EventLog::for('e1')));
        $src = Article::on($T['t1'])->create(['title' => 'orig', 'status' => 'draft']);
        EventLog::$log = [];
        $copy = $src->replicate();
        check('replicate: "replicating" on the unsaved copy, no key / timestamps, same connection', EventLog::for('orig') === ['replicating'] && !$copy->exists && $copy->getKey() === null
            && $copy->created_at === null && $copy->status === 'draft' && $copy->getConnectionConfig()->key === $src->getConnectionConfig()->key);

        EventLog::$log = [];
        $before = Orm::table('articles', $T['t1'])->count();
        $blocked = Article::on($T['t1'])->create(['title' => 'blocked']);
        $nosave = (new Article(['title' => 'nosave']))->setConnection($T['t1']);
        $r = $nosave->save();
        check('saving / creating returning false cancel the INSERT', !$blocked->exists && $r === false && Orm::table('articles', $T['t1'])->count() === $before
            && EventLog::for('blocked') === ['saving', 'creating'] && EventLog::for('nosave') === ['saving'], json_encode([EventLog::for('blocked'), EventLog::for('nosave')]));
        $fr = Article::on($T['t1'])->create(['title' => 'thaw']);
        $fr->title = 'frozen';
        $ok = $fr->save();
        check('updating returning false cancels the UPDATE (model stays dirty)', $ok === false && $fr->isDirty('title') && Orm::table('articles', $T['t1'])->where('id', $fr->id)->value('title') === 'thaw');
        $fr->title = 'thaw';
        $lk = Article::on($T['t1'])->create(['title' => 'lk', 'status' => 'locked']);
        $keep = Article::on($T['t1'])->create(['title' => 'kp', 'status' => 'keep']);
        $nr = Article::on($T['t1'])->create(['title' => 'nr', 'status' => 'norestore']);
        $nr->delete();
        check('deleting / forceDeleting / restoring returning false cancel', $lk->delete() === false && $keep->forceDelete() === false && $nr->restore() === false
            && Article::on($T['t1'])->find($lk->id) !== null && Article::on($T['t1'])->find($keep->id) !== null && Article::on($T['t1'])->onlyTrashed()->find($nr->id) !== null);

        EventLog::$log = [];
        $auditsBefore = Orm::table('audits', $T['t1'])->count();
        $qa = (new Article(['title' => 'quiet']))->setConnection($T['t1']);
        $qa->saveQuietly();
        $qa->status = 'x';
        $qa->saveQuietly();
        $qa->updateQuietly(['views' => 3]);
        $qa->deleteQuietly();
        $qa->restoreQuietly();
        check('saveQuietly / updateQuietly / deleteQuietly / restoreQuietly fire nothing', EventLog::for('quiet') === [] && $qa->exists && !$qa->trashed()
            && Orm::table('articles', $T['t1'])->where('title', 'quiet')->value('views') === 3 && Orm::table('audits', $T['t1'])->count() === $auditsBefore && Context::keys() === [], json_encode(EventLog::for('quiet')));
        $ret = Article::withoutEvents(static fn () => Article::withoutEvents(static fn () => 42) + (Article::eventsDisabled() ? 1 : 0));
        try {
            Article::withoutEvents(static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        check('withoutEvents returns the value, nests, restores after exceptions', $ret === 43 && !Article::eventsDisabled() && Context::keys() === []);
        EventLog::$log = [];
        $n = Article::on($T['t1'])->where('title', 'thaw')->update(['views' => 9]);
        $d = Article::on($T['t1'])->where('title', 'kp')->delete();
        check('mass query update() / delete() fire no model events (as Eloquent)', $n === 1 && $d === 1 && EventLog::$log === [], json_encode(EventLog::$log));

        $a2 = Article::on($T['t2'])->create(['title' => 'e-t2']);
        $a3 = withDefault($T['t2'], static fn () => Article::on($T['t1'])->create(['title' => 'e-t1-in-t2-scope']));
        $aud1 = Orm::table('audits', $T['t1'])->pluck('db')->unique()->values()->all();
        $aud2 = Orm::table('audits', $T['t2'])->pluck('db')->unique()->values()->all();
        check('listener querying through $model runs on the model\'s database (not the ambient scope)', $aud1 === [$DBS['t1']] && $aud2 === [$DBS['t2']]
            && Orm::table('audits', $T['t1'])->where('auditable_id', $a3->id)->where('auditable_type', Article::class)->count() === 1 && Orm::table('audits', $T['t2'])->where('auditable_id', $a2->id)->count() === 1,
            json_encode(['t1' => $aud1, 't2' => $aud2]));
        $auditsBefore = Orm::table('audits', $T['t1'])->count();
        try {
            Orm::transaction(static function () use ($T): void {
                Article::on($T['t1'])->create(['title' => 'tx-rollback']);
                throw new \RuntimeException('rollback');
            }, $T['t1']);
        } catch (\RuntimeException) {
        }
        check('listener writes join the model\'s transaction (rolled back together)', Orm::table('articles', $T['t1'])->where('title', 'tx-rollback')->count() === 0
            && Orm::table('audits', $T['t1'])->count() === $auditsBefore && Orm::poolStats()['active'] === 0);
        if (Context::inCoroutine()) {
            EventLog::$log = [];
            $cnt = static fn (string $t): int => Orm::table('audits', $T[$t])->count();
            $b1 = $cnt('t1');
            $b2 = $cnt('t2');
            $childSaw = null;
            $wg = new Swoole\Coroutine\WaitGroup();
            $wg->add(3);
            Swoole\Coroutine::create(static function () use ($T, $wg, &$childSaw): void {
                try {
                    Article::withoutEvents(static function () use ($T, &$childSaw): void {
                        $x = Article::on($T['t1'])->create(['title' => 'co-quiet']);
                        napms(40);
                        $x->status = 'b';
                        $x->save();
                        $inner = new Swoole\Coroutine\WaitGroup();
                        $inner->add();
                        goWithDefault(static function () use ($inner, &$childSaw): void {
                            $childSaw = Article::eventsDisabled();
                            $inner->done();
                        });
                        $inner->wait();
                    });
                } finally {
                    $wg->done();
                }
            });
            foreach (['t2' => 'co-loud', 't1' => 'co-loud2'] as $tn => $title) {
                Swoole\Coroutine::create(static function () use ($T, $wg, $tn, $title): void {
                    try {
                        napms(10);
                        $y = Article::on($T[$tn])->create(['title' => $title]);
                        napms(40);
                        $y->status = 'c';
                        $y->save();
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();
            $loud = ['saving', 'creating', 'created', 'saved', 'observer:saved', 'saving', 'updating', 'updated', 'saved', 'observer:saved'];
            check('withoutEvents is coroutine-local: concurrent coroutines keep their events', EventLog::for('co-quiet') === [] && EventLog::for('co-loud') === $loud
                && EventLog::for('co-loud2') === $loud && $cnt('t1') === $b1 + 1 && $cnt('t2') === $b2 + 1 && Context::keys() === [],
                json_encode(['quiet' => EventLog::for('co-quiet'), 'loud' => count(EventLog::for('co-loud')), 'audits+' => [$cnt('t1') - $b1, $cnt('t2') - $b2]]));
            check('withoutEvents is not inherited by goWithDefault() children', $childSaw === false, json_encode($childSaw));
        }
        EventLog::$log = [];

        // ------------------------------------------------------------ 16. polymorphic
        section('16. polymorphic: morphTo (mixed eager) / morphOne / morphMany / morphToMany, morph map');
        Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
        $seed = static function (array|string|ConnectionConfig $t, string $tag): array {
            $u = User::on($t)->create(['name' => "m{$tag}", 'origin' => $tag]);
            $p = $u->posts()->create(['title' => "post-{$tag}"]);
            $draft = $u->posts()->create(['title' => "draft-{$tag}", 'draft' => true]);
            $v = Video::on($t)->create(['title' => "video-{$tag}"]);
            $p->comments()->createMany([['body' => "c1-{$tag}"], ['body' => "c2-{$tag}"]]);
            $v->comments()->create(['body' => "c3-{$tag}"]);
            $draft->comments()->create(['body' => "c4-{$tag}"]);
            $u->image()->create(['url' => "img-{$tag}.png"]);

            return ['u' => $u, 'p' => $p, 'draft' => $draft, 'v' => $v];
        };
        $m1 = $seed($T['t1'], 't1');
        $m2 = $seed($T['t2'], 't2');
        // decoy: a video comment whose commentable_id equals the post's id
        Orm::table('comments', $T['t1'])->insert(['commentable_type' => 'video', 'commentable_id' => $m1['p']->id, 'body' => 'decoy']);
        $rawTypes = Orm::table('comments', $T['t1'])->orderBy('id')->pluck('commentable_type')->unique()->values()->all();
        check('morph map: aliases stored in *_type, unmapped class name otherwise', $rawTypes === ['post', 'video'] && Orm::table('images', $T['t1'])->value('imageable_type') === User::class, json_encode($rawTypes));
        $before = count($events);
        $cs = Comment::on($T['t1'])->with('commentable')->where('body', 'like', 'c%')->orderBy('id')->get();
        $qn = count($events) - $before;
        $shape = $cs->map(static fn (Comment $c): string => $c->body . '→' . ($c->commentable === null ? 'null' : substr(strrchr($c->commentable::class, '\\'), 1) . ':' . $c->commentable->title))->all();
        check('morphTo eager, mixed types: 1 + 1 query per type (draft post hidden by its scope)', $qn === 3 && $shape === ['c1-t1→Post:post-t1', 'c2-t1→Post:post-t1', 'c3-t1→Video:video-t1', 'c4-t1→null'], "queries={$qn} " . json_encode($shape, JSON_UNESCAPED_UNICODE));
        $cs2 = Comment::on($T['t1'])->with(['commentable' => static fn ($q) => $q->withoutGlobalScopes()])->where('body', 'like', 'c%')->orderBy('id')->get();
        check('eager constraint replayed on every type query (withoutGlobalScopes → draft loaded)', $cs2->last()->commentable?->title === 'draft-t1' && $cs2->first()->commentable?->title === 'post-t1');
        $cs3 = Comment::on($T['t1'])->with(['commentable' => static fn (MorphTo $q) => $q->morphWith([Post::class => ['user']])])->where('body', 'like', 'c%')->orderBy('id')->get();
        check('morphWith: per-type nested eager load', $cs3->first()->commentable->relationLoaded('user') && $cs3->first()->commentable->user->name === 'Mt1' && !$cs3[2]->commentable->relationLoaded('user'));
        $cs4 = Comment::on($T['t1'])->with('commentable.comments')->where('body', 'like', 'c%')->orderBy('id')->get();
        check('nested with(commentable.comments) on every type (type-scoped: decoy not on the post)', $cs4->first()->commentable->comments->pluck('body')->all() === ['c1-t1', 'c2-t1']
            && $cs4[2]->commentable->comments->pluck('body')->all() === ['c3-t1'], json_encode($cs4->first()->commentable->comments->pluck('body')->all()));
        $c3 = Comment::on($T['t1'])->where('body', 'c3-t1')->first();
        check('morphTo lazy', $c3->commentable instanceof Video && $c3->commentable->title === 'video-t1');
        $pl = Post::on($T['t1'])->with('comments')->whereKey($m1['p']->id)->get();
        check('morphMany eager + lazy (type-scoped)', $pl->first()->comments->pluck('body')->all() === ['c1-t1', 'c2-t1'] && $m1['v']->comments->pluck('body')->all() === ['c3-t1']
            && $m1['p']->comments()->count() === 2);
        $ul = User::on($T['t1'])->with('image')->whereKey($m1['u']->id)->get();
        check('morphOne eager + lazy; inverse morphTo → User', $ul->first()->image?->url === 'img-t1.png' && $m1['u']->image?->url === 'img-t1.png'
            && Image::on($T['t1'])->first()->imageable?->name === 'Mt1');
        $nc = (new Comment(['body' => 'assoc']))->setConnection($T['t1']);
        $nc->commentable()->associate($m1['v']);
        $nc->save();
        $rawNc = Orm::table('comments', $T['t1'])->find($nc->id);
        $viaDb = Comment::on($T['t1'])->find($nc->id)->commentable?->title;
        $nc->commentable()->dissociate();
        $nc->save();
        $rawNc2 = Orm::table('comments', $T['t1'])->find($nc->id);
        check('morphTo associate / dissociate', $rawNc['commentable_type'] === 'video' && $rawNc['commentable_id'] === $m1['v']->id && $viaDb === 'video-t1'
            && $rawNc2['commentable_type'] === null && $rawNc2['commentable_id'] === null && $nc->commentable === null);

        $tg = [];
        foreach (['php', 'go', 'rust'] as $tn) {
            $tg[$tn] = Tag::on($T['t1'])->create(['name' => "{$tn}-t1"]);
        }
        $m1['p']->tags()->attach([$tg['php']->id => ['weight' => 5], $tg['go']->id]);
        $m1['v']->tags()->attach($tg['rust']);
        $sync = $m1['p']->tags()->sync([$tg['go']->id, $tg['rust']->id => ['weight' => 7]]);
        $pt = Post::on($T['t1'])->with('tags')->whereKey($m1['p']->id)->first();
        $names = $pt->tags->pluck('name')->all();
        sort($names);
        check('morphToMany attach / sync (type column written; the video\'s tags untouched)', $sync === ['attached' => [$tg['rust']->id], 'detached' => [$tg['php']->id], 'updated' => []]
            && $names === ['go-t1', 'rust-t1'] && $pt->tags->firstWhere('name', 'rust-t1')?->pivot->weight === 7 && $m1['v']->tags->pluck('name')->all() === ['rust-t1']
            && Orm::table('taggables', $T['t1'])->orderBy('taggable_type')->pluck('taggable_type')->unique()->values()->all() === ['post', 'video'], json_encode($sync));
        $rust = Tag::on($T['t1'])->with('posts', 'videos')->find($tg['rust']->id);
        check('morphedByMany eager: posts vs videos split by type, pivot', $rust->posts->pluck('title')->all() === ['post-t1'] && $rust->videos->pluck('title')->all() === ['video-t1']
            && $rust->posts->first()->pivot->weight === 7);
        $tg['php']->posts()->attach($m1['p']);
        $inverseType = Orm::table('taggables', $T['t1'])->where('tag_id', $tg['php']->id)->value('taggable_type');
        check('morphedByMany attach / detach from the inverse side (only its type)', $inverseType === 'post' && $tg['rust']->posts()->detach() === 1 && $m1['v']->tags()->count() === 1);

        Relation::enforceMorphMap([]);
        $errWrite = $errRead = $errEvil = null;
        try {
            (new Comment())->setConnection($T['t1'])->commentable()->associate($m1['u']);
        } catch (ClassMorphViolationException $e) {
            $errWrite = $e->getMessage();
        }
        Orm::table('comments', $T['t1'])->insert([['commentable_type' => Video::class, 'commentable_id' => $m1['v']->id, 'body' => 'raw-class'], ['commentable_type' => 'stdClass', 'commentable_id' => 1, 'body' => 'evil']]);
        try {
            Comment::on($T['t1'])->where('body', 'raw-class')->first()->commentable;
        } catch (ClassMorphViolationException $e) {
            $errRead = $e->getMessage();
        }
        $mappedOk = Comment::on($T['t1'])->where('body', 'c3-t1')->first()->commentable?->title === 'video-t1';
        Relation::requireMorphMap(false);
        $fallback = Comment::on($T['t1'])->where('body', 'raw-class')->first()->commentable?->title;
        try {
            Comment::on($T['t1'])->where('body', 'evil')->first()->commentable;
        } catch (\InvalidArgumentException $e) {
            $errEvil = $e->getMessage();
        }
        check('enforceMorphMap: unmapped class / type rejected, mapped fine; off → class names; non-model type never instantiated', $errWrite !== null && $errRead !== null
            && $mappedOk && $fallback === 'video-t1' && $errEvil !== null, json_encode([$errWrite, $errRead, $errEvil]));

        $tg2 = Tag::on($T['t2'])->create(['name' => 'php-t2']);
        $m2['p']->tags()->attach($tg2);
        $iso = withDefault($T['t2'], static fn () => [
            Comment::with('commentable')->orderBy('id')->get()->map(static fn (Comment $c) => $c->commentable?->title)->all(),
            Post::with('tags', 'comments')->whereKey($m2['p']->id)->first(),
            $m1['p']->comments()->count(),
            $cs->load('commentable')->map(static fn (Comment $c) => $c->commentable?->title)->all(),
        ]);
        check('database isolation: t2 resolves t2 rows; t1-bound models stay on t1 inside a t2 scope', $iso[0] === ['post-t2', 'post-t2', 'video-t2', null]
            && $iso[1]->tags->pluck('name')->all() === ['php-t2'] && $iso[1]->comments->count() === 2 && $iso[2] === 2 && $iso[3] === ['post-t1', 'post-t1', 'video-t1', null]
            && Tag::on($T['t2'])->find($tg2->id)->posts->pluck('title')->all() === ['post-t2'], json_encode([$iso[0], $iso[3]]));
        Relation::morphMap([], false);

        // ------------------------------------------------------------ 17. through relations
        section('17. hasManyThrough / hasOneThrough (countries → authors → books, custom keys)');
        $seedThrough = static function (array|string|ConnectionConfig $t, string $tag): array {
            $cn = Country::on($t)->create(['name' => "cn-{$tag}"]);
            $us = Country::on($t)->create(['name' => "us-{$tag}"]);
            $a1 = Author::on($t)->create(['country_id' => $cn->id, 'name' => "a1-{$tag}"]);
            $a2 = Author::on($t)->create(['country_id' => $cn->id, 'name' => "a2-{$tag}"]);
            $a3 = Author::on($t)->create(['country_id' => $us->id, 'name' => "a3-{$tag}"]);
            foreach ([[$a1, 'A-one'], [$a1, 'A-two'], [$a2, 'B-one'], [$a3, 'C-one']] as [$au, $title]) {
                Book::on($t)->create(['author_id' => $au->id, 'title' => "{$title}-{$tag}"]);
            }
            Agent::on($t)->create(['author_ref' => $a1->id, 'name' => "agent-a1-{$tag}"]);

            return ['cn' => $cn, 'us' => $us, 'a1' => $a1, 'a2' => $a2, 'a3' => $a3];
        };
        $h1 = $seedThrough($T['t1'], 't1');
        $h2 = $seedThrough($T['t2'], 't2');
        $titles = $h1['cn']->books->pluck('title')->all();
        sort($titles);
        check('hasManyThrough lazy', $titles === ['A-one-t1', 'A-two-t1', 'B-one-t1'] && $h1['us']->books->pluck('title')->all() === ['C-one-t1'], json_encode($titles));
        $before = count($events);
        $cl = Country::on($T['t1'])->with('books')->orderBy('id')->get();
        $qn = count($events) - $before;
        $firstBook = $cl->first()->books->first();
        check('hasManyThrough eager: 2 queries, grouped per country, through key not leaked', $qn === 2 && $cl->map(static fn (Country $c) => $c->books->count())->all() === [3, 1]
            && !array_key_exists(HasManyThrough::THROUGH_KEY, $firstBook->getAttributes()) && Book::on($T['t1'])->find($firstBook->id)?->title === $firstBook->title, "queries={$qn} " . json_encode($firstBook->toArray()));
        $pg = $h1['cn']->books()->orderBy('books.id')->paginate(2);
        check('constrained eager + relation query / count / paginate', Country::on($T['t1'])->with(['books' => static fn ($q) => $q->where('books.title', 'like', 'A-%')])->orderBy('id')->get()
            ->map(static fn (Country $c) => $c->books->count())->all() === [2, 0] && $h1['cn']->books()->where('books.title', 'like', 'B-%')->count() === 1
            && $pg->total === 3 && $pg->items()->first()->title === 'A-one-t1' && $pg->items()->first()->author_id === $h1['a1']->id);
        $h1['a2']->delete();
        $afterSoft = Country::on($T['t1'])->find($h1['cn']->id)->books->count();
        $h1['a2']->restore();
        check('soft-deleted intermediate rows are excluded', $afterSoft === 2 && $h1['cn']->books()->count() === 3);
        $bk = Book::on($T['t1'])->where('title', 'A-two-t1')->first();
        $bl = Book::on($T['t1'])->with('agent')->orderBy('id')->get();
        check('hasOneThrough (4 custom keys) lazy + eager', $bk->agent?->name === 'agent-a1-t1' && $bl->map(static fn (Book $b) => $b->agent?->name)->all() === ['agent-a1-t1', 'agent-a1-t1', null, null],
            json_encode($bl->map(static fn (Book $b) => $b->agent?->name)->all()));
        $nestedThrough = Country::on($T['t1'])->with('books.agent')->orderBy('id')->first();
        check('nested eager books.agent', $nestedThrough->books->filter(static fn (Book $b) => $b->agent !== null)->count() === 2);
        $isoT = withDefault($T['t2'], static fn () => [
            Country::with('books')->orderBy('id')->first()->books->pluck('title')->all(),
            $h1['cn']->books()->count(),
            Book::with('agent')->orderBy('id')->first()->agent?->name,
        ]);
        sort($isoT[0]);
        check('through relations stay on the parent\'s database', $isoT[0] === ['A-one-t2', 'A-two-t2', 'B-one-t2'] && $isoT[1] === 3 && $isoT[2] === 'agent-a1-t2', json_encode($isoT));
        check('no connection left checked out after sections 15-17', Orm::poolStats()['active'] === 0 && Context::keys() === []);


        // ------------------------------------------------------------ 18. table prefixes
        section('18. table prefixes: per connection / default scope / named + framework pool, builder, models, relations, concurrency');
        $PA = ['driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => $DBS['px'], 'username' => $USER, 'password' => $PASS,
            'prefix' => 'a_', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'];
        $PB = sprintf('mysql://%s:%s@localhost/%s?unix_socket=%s&prefix=b_', rawurlencode($USER), rawurlencode($PASS), $DBS['px'], rawurlencode($SOCK));
        Orm::connection($admin)->withLease(static function (object $pdo) use ($DBS, $schema): void {
            $pdo->exec("USE `{$DBS['px']}`");
            foreach (['a_', 'b_', 'n_'] as $pfx) {
                foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
                    $pdo->exec((string) preg_replace('/^CREATE TABLE (\w+)/', "CREATE TABLE {$pfx}\$1", $stmt));
                }
            }
        });
        $counts = static fn (string $table) => array_map('intval', Orm::connection($admin)->selectOne(sprintf(
            'select (select count(*) from `%1$s`.`a_%2$s`) a, (select count(*) from `%1$s`.`b_%2$s`) b, (select count(*) from `%1$s`.`n_%2$s`) n, (select count(*) from `%1$s`.`%2$s`) plain',
            $DBS['px'], $table)));
        $bad = 'accepted';
        try {
            Orm::config(['driver' => 'mysql', 'database' => 'x', 'prefix' => 'a`; drop']);
        } catch (InvalidArgumentException) {
            $bad = 'rejected';
        }
        check('config API: prefix / engine / charset / collation kept; URL ?prefix=; named; default scope; invalid prefix rejected',
            Orm::config($PA)->tableOptions() === ['prefix' => 'a_', 'engine' => 'InnoDB', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']
            && Orm::prefix($PB) === 'b_' && Orm::prefix('prefixed') === 'n_' && Orm::prefix($T['t1']) === '' && Orm::prefix() === ''
            && withDefault($PB, static fn () => Orm::prefix() . '|' . Orm::tableName('users') . '|' . Orm::connection()->wrapTable('users')) === 'b_|b_users|`b_users`'
            && Orm::connection($PA)->prefix() === 'a_' && Orm::connection('prefixed')->tableName('plans') === 'n_plans' && $bad === 'rejected',
            json_encode(Orm::config($PA)->tableOptions()) . " invalid={$bad}");
        $sql = Orm::table('users as u', $PA)->join('posts as p', 'p.user_id', '=', 'u.id')->select('u.name', 'p.*')->where('u.id', '>', 1)->toSql();
        check('compiled SQL: tables, aliases and qualified columns prefixed', $sql === 'select `a_u`.`name`, `a_p`.* from `a_users` as `a_u` inner join `a_posts` as `a_p` on `a_p`.`user_id` = `a_u`.`id` where `a_u`.`id` > ?', $sql);
        // query builder on three prefixes in one database
        foreach (['a' => $PA, 'b' => $PB, 'n' => 'prefixed'] as $tag => $spec) {
            $uid = Orm::table('users', $spec)->insertGetId(['name' => "u-{$tag}", 'origin' => $tag]);
            Orm::table('users', $spec)->insert([['name' => "v-{$tag}", 'origin' => $tag], ['name' => "w-{$tag}", 'origin' => $tag]]);
            Orm::table('posts', $spec)->insert([['user_id' => $uid, 'title' => "p1-{$tag}"], ['user_id' => $uid, 'title' => "p2-{$tag}"]]);
        }
        check('insert / insertGetId land in the prefixed tables only (plain `users` untouched)', $counts('users') === ['a' => 3, 'b' => 3, 'n' => 3, 'plain' => 0]
            && $counts('posts') === ['a' => 2, 'b' => 2, 'n' => 2, 'plain' => 0], json_encode($counts('users')));
        $c = Orm::connection($PA);
        $rows = Orm::table('users as u', $PA)->join('posts as p', 'p.user_id', '=', 'u.id')->select('u.name', Orm::raw('count(' . $c->wrap('p.id') . ') as n'))
            ->groupBy('u.name')->having('n', '>=', 1)->get()->all();
        $left = Orm::table('users', $PB)->leftJoin('posts', 'posts.user_id', '=', 'users.id')->whereNull('posts.id')->orderBy('users.id')->pluck('users.name')->all();
        $raw = (int) $c->selectOne('select count(*) c from ' . $c->wrapTable('users') . ' where ' . $c->wrap('users.origin') . ' = ?', ['a'])['c'];
        check('join + alias + raw via wrap(); leftJoin + whereNull + pluck; raw SQL via wrapTable()', $rows === [['name' => 'u-a', 'n' => 2]] && $left === ['v-b', 'w-b'] && $raw === 3,
            json_encode([$rows, $left, $raw]));
        Orm::table('users', $PA)->where('name', 'v-a')->update(['score' => 5]);
        Orm::table('users', $PA)->where('name', 'v-a')->increment('score', 2);
        $joinUpd = Orm::table('users as u', $PB)->join('posts as p', 'p.user_id', '=', 'u.id')->where('p.title', 'p1-b')->update(['u.status' => 'banned']);
        $joinDel = Orm::table('posts as p', $PB)->join('users as u', 'u.id', '=', 'p.user_id')->where('p.title', 'p2-b')->delete();
        check('update / increment / join-update / join-delete (aliases) on prefixed tables', Orm::table('users', $PA)->where('name', 'v-a')->value('score') === '7.00'
            && $joinUpd === 1 && Orm::table('users', $PB)->where('status', 'banned')->count() === 1 && $joinDel === 1 && $counts('posts') === ['a' => 2, 'b' => 1, 'n' => 2, 'plain' => 0],
            "joinUpdate={$joinUpd} joinDelete={$joinDel} " . json_encode($counts('posts')));
        $txOut = '';
        try {
            Orm::transaction(static function () use ($PA): void {
                Orm::table('users', $PA)->insert(['name' => 'tx', 'origin' => 'a']);
                throw new RuntimeException('rollback');
            }, $PA);
        } catch (RuntimeException $e) {
            $txOut = $e->getMessage();
        }
        Orm::table('marks', $PA)->truncate();
        check('transaction rollback + truncate on a prefixed connection', $txOut === 'rollback' && Orm::table('users', $PA)->where('name', 'tx')->doesntExist() && $counts('users')['a'] === 3);

        // models + relations (hasMany, belongsTo, belongsToMany pivot, morphOne / morphMany / morphTo / morphToMany, through)
        $seedP = static function (array|string|null $spec, string $tag): User {
            $u = User::on($spec)->create(['name' => "Model-{$tag}", 'email' => "m@{$tag}", 'origin' => $tag]);
            $p1 = $u->posts()->create(['title' => "mp1-{$tag}"]);
            $u->posts()->create(['title' => "mp-draft-{$tag}", 'draft' => true]);
            $r = Role::on($spec)->create(['name' => "role-{$tag}"]);
            $u->roles()->attach($r->id, ['level' => 3]);
            $u->image()->create(['url' => "img-{$tag}"]);
            $p1->comments()->create(['body' => "c-{$tag}"]);
            $t = Tag::on($spec)->create(['name' => "tag-{$tag}"]);
            $p1->tags()->attach($t->id, ['weight' => 9]);
            $cn = Country::on($spec)->create(['name' => "cn-{$tag}"]);
            $au = Author::on($spec)->create(['country_id' => $cn->id, 'name' => "au-{$tag}"]);
            Book::on($spec)->create(['author_id' => $au->id, 'title' => "bk-{$tag}"]);
            Agent::on($spec)->create(['author_ref' => $au->id, 'name' => "ag-{$tag}"]);

            return $u;
        };
        $ua = $seedP($PA, 'a');
        $ub = withDefault($PB, static fn () => $seedP(null, 'b'));
        $readRel = static function (User $u): array {
            $u = User::on($u->getConnectionConfig())->with('posts', 'roles', 'image')->find($u->id);
            $post = $u->posts->first();
            $comment = Comment::on($u->getConnectionConfig())->with('commentable')->first();
            $country = Country::on($u->getConnectionConfig())->with('books.agent')->orderBy('id')->first();

            return [$u->posts->pluck('title')->all(), $u->roles->first()?->pivot?->level, $u->image?->url, $post->comments->pluck('body')->all(),
                $post->tags->first()?->pivot?->weight, $comment->commentable?->title, $country->books->first()?->title, $country->books->first()?->agent?->name,
                $u->getPrefixedTable(), $u->roles()->count()];
        };
        $ra = $readRel($ua);
        $rb = withDefault($PB, static fn () => $readRel($ub));
        check('models + every relation type on prefix a_ (global scope, pivot, morph, through)', $ra === [['mp1-a'], 3, 'img-a', ['c-a'], 9, 'mp1-a', 'bk-a', 'ag-a', 'a_users', 1], json_encode($ra));
        check('…same on prefix b_ via an withDefault() scope (URL spec)', $rb === [['mp1-b'], 3, 'img-b', ['c-b'], 9, 'mp1-b', 'bk-b', 'ag-b', 'b_users', 1], json_encode($rb));
        check('pivot / morph / through rows only in their own prefixed tables', $counts('role_user') === ['a' => 1, 'b' => 1, 'n' => 0, 'plain' => 0]
            && $counts('taggables') === ['a' => 1, 'b' => 1, 'n' => 0, 'plain' => 0] && $counts('images') === ['a' => 1, 'b' => 1, 'n' => 0, 'plain' => 0]
            && $counts('books') === ['a' => 1, 'b' => 1, 'n' => 0, 'plain' => 0], json_encode([$counts('role_user'), $counts('taggables'), $counts('books')]));
        $cross = withDefault($PB, static fn () => [$ua->posts()->count(), $ua->getTablePrefix(), (new User())->getTablePrefix(), User::where('name', 'model-a')->count()]);
        check('a model keeps its own connection prefix inside another default scope', $cross === [1, 'a_', 'b_', 0], json_encode($cross));
        $ua->roles()->sync([]);
        $ua->delete();
        check('sync / soft delete on prefixed tables', $counts('role_user')['a'] === 0 && User::on($PA)->withTrashed()->where('name', 'model-a')->first()?->deleted_at !== null
            && User::on($PA)->where('name', 'model-a')->count() === 0);

        // concurrency: interleaved specs with different prefixes in one worker
        $N = Context::inCoroutine() ? 100 : 20;
        $res = [];
        $job = static function (int $i) use ($PA, $PB, &$res): void {
            [$spec, $tag] = $i % 2 === 0 ? [$PA, 'a'] : [$PB, 'b'];
            $res[$i] = withDefault($spec, static function () use ($i, $tag): string {
                $m = Mark::create(['origin' => $tag, 'coro' => $i, 'n' => 1]);
                napms(random_int(1, 5));
                $seen = Mark::where('coro', $i)->count() . Orm::prefix() . (new Mark())->getPrefixedTable();
                $m->increment('n');

                return $seen . Mark::find($m->id)?->n;
            });
        };
        if (Context::inCoroutine()) {
            $wg = new Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < $N; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($job, $i, $wg): void {
                    $job($i);
                    $wg->done();
                });
            }
            $wg->wait();
        } else {
            for ($i = 0; $i < $N; $i++) {
                $job($i);
            }
        }
        $expect = [];
        for ($i = 0; $i < $N; $i++) {
            $expect[$i] = $i % 2 === 0 ? '1a_a_marks2' : '1b_b_marks2';
        }
        ksort($res);
        $mc = $counts('marks');
        check("{$N} " . (Context::inCoroutine() ? 'concurrent coroutines' : 'sequential jobs') . ' alternating a_/b_ specs: every row in its own prefix',
            $res === $expect && $mc === ['a' => $N / 2, 'b' => $N / 2, 'n' => 0, 'plain' => 0]
            && Orm::table('marks', $PA)->where('origin', '!=', 'a')->count() === 0 && Orm::table('marks', $PB)->where('origin', '!=', 'b')->count() === 0
            && Orm::table('marks', $PA)->where('n', 2)->count() === $N / 2, json_encode($mc));

        // named prefixed connection through the framework PDOPool (co) or the ORM pool (cli)
        if (Context::inCoroutine() && class_exists(\Loongs\Database\DatabaseManager::class)) {
            $dir = sys_get_temp_dir() . '/loongs_orm_cfg_px_' . getmypid();
            @mkdir($dir);
            file_put_contents($dir . '/database.php', '<?php return ' . var_export(['default' => 'central', 'connections' => [
                'central' => ['driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => 'loongs_orm_central', 'username' => $USER, 'password' => $PASS, 'pool' => ['size' => 2]],
                'prefixed' => ['driver' => 'mysql', 'unix_socket' => $SOCK, 'database' => $DBS['px'], 'username' => $USER, 'password' => $PASS, 'prefix' => 'n_', 'pool' => ['size' => 3]],
            ]], true) . ';');
            $dbm = new \Loongs\Database\DatabaseManager(new \Loongs\Config\Repository($dir));
            unlink($dir . '/database.php');
            rmdir($dir);
            $dbm->bootPools();
            Orm::usePools(new FrameworkPoolProvider($dbm));
            $events = [];
            $wg = new Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 20; $i++) {
                $wg->add();
                Swoole\Coroutine::create(static function () use ($wg, $i): void {
                    Orm::table('plans', 'prefixed')->insert(['name' => "fw-{$i}", 'price' => $i]);
                    withDefault('prefixed', static fn () => Role::create(['name' => "fw-role-{$i}"]));
                    $wg->done();
                });
            }
            $wg->wait();
            $fw = array_filter($events, static fn ($e) => $e->source === LeaseSource::Framework);
            check('named prefixed connection via the framework PDOPool: n_ tables, ≤ 3 PDOs', count($fw) === 40 && count(array_unique(array_map(static fn ($e) => $e->pdoSerial, $fw))) <= 3
                && $counts('plans') === ['a' => 0, 'b' => 0, 'n' => 20, 'plain' => 0] && $counts('roles')['n'] === 20 && $dbm->stats('prefixed')['active'] === 0,
                'framework statements=' . count($fw) . ' ' . json_encode($counts('plans')) . ' ' . json_encode($dbm->stats('prefixed')));
            Orm::usePools(null);
            $dbm->closePools();
        } else {
            $events = [];
            for ($i = 0; $i < 20; $i++) {
                Orm::table('plans', 'prefixed')->insert(['name' => "orm-{$i}", 'price' => $i]);
                withDefault('prefixed', static fn () => Role::create(['name' => "orm-role-{$i}"]));
            }
            check('named prefixed connection via the ORM pool: n_ tables', count($events) === 40 && $events[0]->source === LeaseSource::Pool
                && $counts('plans') === ['a' => 0, 'b' => 0, 'n' => 20, 'plain' => 0] && $counts('roles')['n'] === 20, json_encode($counts('plans')));
        }
        check('no connection left checked out after section 18', Orm::poolStats()['active'] === 0 && Context::keys() === []);

        // ------------------------------------------------------------ 19. extension points
        section('19. extension points: Orm::resolveDefaultUsing(), LeaseProvider, ConnectionPool, PoolConfig env, Context keys');
        $hook = Orm::resolver()->defaultResolver();
        Orm::resolveDefaultUsing(null);
        $noHook = Orm::config()->name;
        Orm::resolveDefaultUsing(static fn () => 'prefixed');
        $named = [Orm::config()->name, Orm::prefix(), Orm::table('plans')->toSql()];
        Orm::resolveDefaultUsing(static fn () => $T['t2']);
        $url = Orm::connection()->selectOne('select database() d')['d'];
        Orm::resolveDefaultUsing(static fn () => null);
        $fallback = Orm::config()->name;
        Orm::resolveDefaultUsing($hook);
        check('resolveDefaultUsing: unset → default; name / URL spec per call; null → default; restored', $noHook === 'central' && $named === ['prefixed', 'n_', 'select * from `n_plans`']
            && $url === $DBS['t2'] && $fallback === 'central' && currentDefault() === null, json_encode([$noHook, $named, $url, $fallback]));

        // a provider serving t3 from its own pool (LeaseSource::Provider); everything else falls through
        $provider = new class (new ConnectionPool(new PoolConfig(size: 2, maxPools: 4, waitTimeout: 0.3), static fn (ConnectionConfig $c): object => PdoFactory::make($c),
            static fn (object $pdo): int => Orm::resolver()->serial($pdo), LeaseSource::Provider), Orm::config($T['t3'])) implements LeaseProvider {
            public int $asked = 0;

            public function __construct(public readonly ConnectionPool $pool, private readonly ConnectionConfig $mine)
            {
            }

            public function lease(ConnectionConfig $c): ?Lease
            {
                $this->asked++;

                return $c->fingerprint() === $this->mine->fingerprint() ? $this->pool->acquire($c) : null;
            }
        };
        Orm::addLeaseProvider($provider);
        Orm::addLeaseProvider($provider);   // idempotent
        $events = [];
        $ormBefore = Orm::poolStats($T['t3']);
        Mark::on($T['t3'])->count();
        Orm::table('marks', $T['t3'])->count();
        Orm::table('marks', $T['t1'])->count();
        $viaUsing = Orm::using($T['t3'], static fn ($c) => [$c->leaseInfo()['source'], $provider->pool->stats(Orm::config($T['t3']))['active']]);
        $tx = Orm::transaction(static fn ($c) => Mark::on($T['t3'])->create(['origin' => 't3', 'coro' => -500, 'n' => 1])->exists, $T['t3']);
        $srcs = array_map(static fn ($e) => $e->database . ':' . $e->source->value, $events);
        $broken = null;
        try {
            Orm::using($T['t3'], static function ($c) use ($admin): void {
                Orm::connection($admin)->unprepared('KILL ' . (int) $c->selectOne('select connection_id() c')['c']);
                napms(50);
                $c->selectOne('select 1');
            });
        } catch (\Loongs\Orm\Exceptions\QueryException $e) {
            $broken = $e->isRecoverable() ? 'recoverable' : 'lost';
        }
        $pst = $provider->pool->stats();
        check('LeaseProvider: claimed config served by its pool (source=provider), others fall through to the ORM pool',
            count(array_filter($srcs, static fn ($x) => $x === $DBS['t3'] . ':provider')) === 3 && in_array($DBS['t1'] . ':pool', $srcs, true)
            && !in_array($DBS['t3'] . ':pool', $srcs, true) && $viaUsing === ['provider', 1] && $tx === true && $provider->asked >= 5
            && Orm::poolStats($T['t3'])['open'] === $ormBefore['open'] && count(Orm::resolver()->leaseProviders()) === 1, json_encode([$srcs, $viaUsing]));
        check('LeaseProvider pool: released after using / transaction / exception; broken connection discarded', $pst['active'] === 0 && $broken === 'lost' && $pst['discarded'] >= 1
            && $provider->pool->has(Orm::config($T['t3'])), json_encode($pst));
        $forgot = $provider->pool->forget(Orm::config($T['t3']));
        Orm::removeLeaseProvider($provider);
        $events = [];
        Mark::on($T['t3'])->count();
        check('ConnectionPool::forget() retires one bucket; removeLeaseProvider() → back to the ORM pool', $forgot && !$provider->pool->has(Orm::config($T['t3']))
            && $provider->pool->stats()['pools'] === 0 && $events[0]->source === LeaseSource::Pool && Orm::resolver()->leaseProviders() === []);

        putenv('XTEST_POOL_SIZE=5');
        putenv('XTEST_POOL_MAX_POOLS=7');
        $envCfg = PoolConfig::fromArray(PoolConfig::envOverrides('XTEST_POOL_'));
        putenv('XTEST_POOL_SIZE');
        putenv('XTEST_POOL_MAX_POOLS');
        $keys = Context::with('test.other.k', 1, static fn () => [Context::keys(), Context::keys('test.other.')]);
        check('PoolConfig::envOverrides(prefix) / max_pools; Context::keys(prefix)', $envCfg->size === 5 && $envCfg->maxPools === 7 && $envCfg->toArray()['max_pools'] === 7
            && $keys === [[], ['test.other.k']], json_encode([$envCfg->toArray(), $keys]));
        check('no tenant API left in loongs/orm', !method_exists(Orm::class, 'tenant') && !method_exists(Orm::class, 'currentTenant') && !method_exists(Orm::class, 'go')
            && !class_exists('Loongs\\Orm\\Connection\\TenantPool') && !defined(\Loongs\Orm\Connection\ConnectionResolver::class . '::TENANT_KEY'));

        check('no unexpected safety-net warnings', $warnings === [], json_encode($warnings));

        check('context empty at the end', Context::keys() === [], json_encode(Context::keys()));
    } catch (\Throwable $e) {
        check('uncaught exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo $e->getTraceAsString(), "\n";
    } finally {
        teardown($admin, $DBS);
    }
    printf("\n%s  passed=%d failed=%d  (%.2fs)\n", $GLOBALS['fails'] === 0 ? 'LOONGS_ORM TESTS ALL PASS' : 'LOONGS_ORM TESTS FAILED', $GLOBALS['passes'], $GLOBALS['fails'], microtime(true) - $t0);
};

if ($mode === 'co') {
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    Swoole\Coroutine\run($main);
} else {
    $main();
}
exit($fails === 0 ? 0 : 1);
