# loongs/orm

Eloquent-style ORM for the loong-swoole stack (PHP 8.4, Swoole coroutines), built for **SaaS multi-tenancy**:
the connection is chosen **per call** and is **never cached**. No illuminate dependency.

```bash
composer require loongs/orm        # once published; locally via a path repo (see "Development")
```

## Layout

```
src/
  Orm.php                     facade: configure, connection/table/transaction, tenant()/go(), listeners
  Context.php                 coroutine-local storage (Coroutine::getContext(), static array outside coroutines)
  Connection/
    ConnectionConfig.php      immutable config: named / ad-hoc array / DSN; key = name:x | adhoc:sha1(full config)
    ConnectionResolver.php    spec → config (every call), lease = framework pool → TenantPool (opt-in) → fresh PDO
    Connection.php            query runner; holds NO PDO; borrows a Lease per statement; transactions/savepoints
    Lease.php LeaseSource.php one borrowed PDO, released exactly once
    PoolProvider.php FrameworkPoolProvider.php   bridge to loongs/framework DatabaseManager (PDOPool)
    TenantPool.php            OPT-IN bounded pool for ad-hoc tenant configs (default OFF)
    PdoFactory.php QueryExecuted.php TransactionState.php
  Query/  Builder.php JoinClause.php Expression.php Grammar/{Grammar,MySqlGrammar}.php
  Model/  Model.php Builder.php Collection.php Pivot.php Attribute.php Scope.php SoftDeletes.php SoftDeletingScope.php
          Concerns/{HasAttributes,HasRelationships,HasTimestamps,HasGlobalScopes,HidesAttributes}.php
          Relations/{Relation,HasOneOrMany,HasOne,HasMany,BelongsTo,BelongsToMany}.php
  Pagination/Paginator.php  Support/{Collection,Inflector}.php  Casts/CastsAttributes.php  Exceptions/*
```

## Quick start

```php
use Loongs\Orm\Orm;
use Loongs\Orm\Model\Model;
use Loongs\Orm\Model\SoftDeletes;

// Outside loongs/framework: give it a config/database.php-shaped array (or a closure returning one).
// Inside the framework this is read from config('database') automatically.
Orm::configure(['default' => 'mysql', 'connections' => ['mysql' => [
    'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'app',
    'username' => 'root', 'password' => '...', 'charset' => 'utf8mb4',
]]]);

final class User extends Model
{
    use SoftDeletes;
    protected array $fillable = ['name', 'email', 'settings', 'is_admin', 'status'];
    protected array $hidden = ['password'];
    protected function casts(): array
    {
        return ['settings' => 'array', 'is_admin' => 'bool', 'status' => Status::class, 'score' => 'decimal:2'];
    }
    public function posts(): HasMany { return $this->hasMany(Post::class); }
    public function scopeAdmins(Builder $q): void { $q->where('is_admin', true); }
}

$u = User::create(['name' => 'ann', 'settings' => ['theme' => 'dark']]);
$admins = User::admins()->with('posts')->orderBy('name')->paginate(20, page: 2);
Orm::table('users')->where('score', '>', 10)->count();
```

Supported: query builder (where/orWhere/nested closures/whereIn/whereNull/whereBetween/whereColumn/whereExists/
whereRaw, joins, groupBy/having, orderBy/latest, limit/offset, locks, aggregates, insert/insertGetId/insertOrIgnore/
update/increment/delete/truncate, chunk/chunkById/cursor, paginate); models (fillable/guarded, casts incl. enums,
json, decimal, datetime and custom `CastsAttributes`, `Attribute::make()` accessors/mutators and `getXAttribute`,
hidden/visible/appends, dirty tracking, timestamps, soft deletes, global and local scopes, firstOrCreate/
updateOrCreate, events-free lifecycle); relations (hasOne, hasMany, belongsTo, belongsToMany with pivot
columns/timestamps, attach/detach/sync) with eager loading (`with('posts.user', 'roles')`, constrained closures,
`load`/`loadMissing`); transactions with savepoints and deadlock retry; query listeners (`Orm::listen`).
Grammar: MySQL (register others with `Orm::extendGrammar()`).

## Choosing a connection: resolution order

A *connection spec* is one of:

| spec | meaning |
|---|---|
| `null` | current tenant scope (`Orm::tenant`) if any, else the configured default |
| `'central'` | named connection from `config('database.connections')` / `Orm::configure()` |
| `['driver'=>'mysql','host'=>..,'database'=>'t42','username'=>..,'password'=>..]` | ad-hoc config array (self-contained; not merged with any named connection) |
| `'mysql://user:pass@host:3306/db'`, `'mysql://user@unix:/tmp/mysql.sock/db'` | URL DSN |
| `'mysql:host=...;dbname=...'` (+ `ConnectionConfig::fromDsn($dsn, $user, $pass)`) | PDO DSN |

Models resolve: **bound connection** (`User::on($spec)`, `$m->setConnection($spec)`, or the connection the model was
loaded/first saved on) → **declared** `protected ?string $connection = 'central'` → **tenant scope** → **default**.

```php
User::on($tenantCfg)->where('active', true)->get();   // explicit, per query
Orm::connection('mysql://root@unix:/tmp/mysql.sock/t7')->select('select 1');
$m = new User(['name' => 'x']); $m->setConnection($cfg)->save();
```

## The "never cached" guarantee

* `Orm::connection()` / `Model::on()` return a **new lightweight handle** each call; handles hold a `ConnectionConfig`,
  **never a PDO**.
* No static per-tenant state: named configs are re-read from config on every resolution; ad-hoc/DSN configs live
  only in the handle/model that received them.
* Every statement **borrows** a PDO (a `Lease`) and **returns** it immediately after — the only exception is a
  transaction, which keeps its lease in coroutine context (`loongs.orm.tx[<config key>]`) until commit/rollback.
* Tenant scope (`Orm::tenant`) is coroutine-local; concurrent coroutines never see each other's tenant.
* Lease sources, in order: framework **PDOPool** (named connections only) → **TenantPool** (only if you enabled it) →
  **fresh PDO** (closed when the statement finishes).

**Cost.** With the default settings an ad-hoc tenant config opens a **new MySQL connection per statement**
(a connect + auth round trip: sub-millisecond to a few ms on a local socket, more over TCP/TLS, plus MySQL
thread/`max_connections` churn). A request doing 10
queries does 10 connects. That is the price of zero cross-tenant state. Mitigations:

1. Wrap hot paths in `Orm::transaction(fn () => ..., $tenant)` — one connection for the whole block.
2. Use a **named** connection (pooled by the framework) where the database is shared (central/landlord DB).
3. Enable the **opt-in TenantPool**:

```php
$pool = Orm::enableTenantPool(maxTenants: 32, perTenant: 4, idleSeconds: 30); // default: OFF
$pool->stats(); // ['tenants'=>..,'idle'=>..,'hits'=>..,'misses'=>..,'discarded'=>..]
Orm::disableTenantPool();
```

TenantPool is keyed by the **full** config fingerprint (host, port, db, user, **password**, options), so two
tenants can never share an entry. On checkout it verifies `SELECT DATABASE()` matches the config (a connection
someone `USE`d elsewhere is discarded); connections returned inside an open transaction or after an error are
discarded; at most `maxTenants` configs (LRU eviction), `perTenant` idle connections each, idle for
`idleSeconds`. Each Swoole worker has its own pool: worst-case idle connections ≈ workers × maxTenants × perTenant.

## SaaS tenants

```php
// 1. per request: resolve the tenant (e.g. from the host) then scope the whole handler
$tenant = ['database' => 'tenant_' . $id];      // or a DSN string, or ConnectionConfig
return Orm::tenant($tenant, function () use ($request) {
    $user = User::where('email', $request->post('email'))->firstOrFail();   // → tenant DB
    $user->posts()->create(['title' => 'hi']);                               // → tenant DB
    Plan::find($user->plan_id);          // Plan declares $connection = 'central' → central DB (pooled)
    return Orm::transaction(fn () => Invoice::create([...]));               // tenant tx, one connection
});

// 2. explicit, no scope
User::on($tenantB)->count();

// 3. background work that must keep the tenant: Orm::go() copies the current tenant into the child coroutine
Orm::go(fn () => Audit::create([...]));   // plain go()/Coroutine::create start with NO tenant (falls back to default)
```

Rules of thumb:

* A model **remembers** where it came from: loaded or first saved on t1 ⇒ `save()`, `delete()`, `refresh()` and
  relation queries go to t1 even if called later inside a t2 scope.
* The connection of a query builder is fixed when the builder is created (`User::query()` inside the scope).
* Models that live in the landlord DB should declare `protected ?string $connection = 'central';` — declared
  connections ignore the tenant scope.
* Related models inherit the parent's connection unless they declare their own.
* Scopes nest; the previous tenant is restored when the callback returns or throws.

Verified by `server/bin/smoke_orm.php` (not shipped): 200 concurrent coroutines on random tenants (array / URL
DSN / PDO DSN specs), zero cross-tenant rows, no PDO object or MySQL connection id used for two tenants,
pinned save after an interleaved query to another tenant, parallel transactions, run in both coroutine and plain CLI.

## loongs/framework integration

Nothing to register. When `loongs/framework` is present and its `DatabaseManager` pools are booted (worker start),
**named** connections are borrowed from and returned to the framework `PDOPool`; ad-hoc and DSN configs never
enter the pool. Named configs come from `config('database')`. The framework does **not** depend on this package.
Override with `Orm::usePools($provider)` (`null` disables pooling) or `Orm::configure([...])`.

## Caveats

* MySQL grammar only (SQLite/Postgres: add a `Grammar` via `Orm::extendGrammar`).
* No model events/observers, polymorphic or has-many-through relations yet.
* `getOriginal()` applies casts and accessors; use `getRawOriginal()` for stored values.
* MySQL normalises JSON key order; compare decoded arrays with `==`.

## Development

In the loong-swoole monorepo it is linked through `server/composer.dev.json` (path repo, symlink):

```bash
cd server && COMPOSER=composer.dev.json composer update loongs/orm
php -d disable_functions= bin/smoke_orm.php co    # and: bin/smoke_orm.php cli
```

License: MIT
