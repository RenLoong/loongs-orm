# loongs/orm

Eloquent-style ORM for the loong-swoole stack (PHP 8.4, Swoole coroutines), built for **SaaS multi-tenancy**:
the connection is chosen **per call**, every connection comes from a **pool** and goes back to the **same pool**,
and handles / models never hold one. No illuminate dependency.

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
    ConnectionResolver.php    spec → config (every call); lease = framework PDOPool (named) | ORM TenantPool (everything else)
    Connection.php            query runner; plain handles hold NO PDO; pinned handles (Orm::acquire) share a HeldLease
    Lease.php LeaseSource.php one checked-out connection: release() / discard(), idempotent, safety-net reclaim
    HeldLease.php             acquire()/using() lease, ambient for its config in the acquiring coroutine
    TenantPool.php PoolBucket.php PoolConfig.php   the ORM pool (always on): bounded per config, LRU, idle/ttl, validation
    PoolProvider.php FrameworkPoolProvider.php     bridge to loongs/framework DatabaseManager (PDOPool, discard)
    PdoFactory.php QueryExecuted.php TransactionState.php
  Query/  Builder.php JoinClause.php Expression.php Grammar/{Grammar,MySqlGrammar}.php
  Model/  Model.php Builder.php Collection.php Pivot.php Attribute.php Scope.php SoftDeletes.php SoftDeletingScope.php
          Concerns/{HasAttributes,HasRelationships,HasTimestamps,HasGlobalScopes,HidesAttributes,HasEvents}.php
          Relations/{Relation (+ morph map),HasOneOrMany,HasOne,HasMany,BelongsTo,BelongsToMany,
                     MorphTo,MorphOneOrMany,MorphOne,MorphMany,MorphToMany,HasManyThrough,HasOneThrough}.php
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
updateOrCreate, replicate, model events + observers); relations (hasOne, hasMany, belongsTo, belongsToMany with pivot
columns/timestamps, attach/detach/sync; morphTo/morphOne/morphMany/morphToMany/morphedByMany with a morph map;
hasManyThrough/hasOneThrough) with eager loading (`with('posts.user', 'roles')`, constrained closures,
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

## Pools: where every connection comes from

| spec | pool |
|---|---|
| named connection (`'mysql'`, `'central'`, or `null` without a tenant scope → `default`) while loongs/framework pools are booted in this worker | **framework `PDOPool`** (`config/database.php` → `connections.*.pool`) |
| tenant config: array / URL DSN / PDO DSN / `ConnectionConfig` (also via `Orm::tenant()`) | **ORM `TenantPool`**, one bucket per full config fingerprint |
| named connection with no booted framework pool (CLI scripts, tests) | ORM `TenantPool` (bucket keyed by that config) |

**Guarantee.** Handles (`Orm::connection()`, `Model::on()`) and models keep a `ConnectionConfig`, never a PDO. There
is no static per-tenant state outside the pools. A connection is checked out for one statement, one transaction, or
one `acquire()`/`using()` block, and always goes back to the pool it came from; a connection can only be handed to
a config whose fingerprint (host, port, socket, database, user, **password**, charset, options) is identical to the one
that opened it, and `SELECT DATABASE()` is checked on every checkout from the ORM pool. Pools are per worker process.

### ORM pool settings

`Orm::configurePool([...])` → `config('database.tenant_pool')` → `ORM_POOL_*` env → defaults:

| key | env | default | meaning |
|---|---|---|---|
| `size` | `ORM_POOL_SIZE` | 8 | max open connections **per tenant config** (checked out + idle) |
| `max_tenants` | `ORM_POOL_MAX_TENANTS` | 64 | tenant buckets kept; the least recently used bucket with nothing checked out is evicted |
| `idle_seconds` | `ORM_POOL_IDLE_SECONDS` | 60 | idle connections older than this are closed |
| `ttl` | `ORM_POOL_TTL` | 600 | connections older than this are closed on return / checkout (0 = no limit) |
| `wait_timeout` | `ORM_POOL_WAIT_TIMEOUT` | 3 | seconds a coroutine waits for a free connection, then `PoolExhaustedException` (-1 = forever) |
| `validate` | `ORM_POOL_VALIDATE` | true | checkout check: not in a transaction + `SELECT DATABASE()` matches |

```php
// server/config/database.php
'tenant_pool' => ['size' => 8, 'max_tenants' => 64, 'idle_seconds' => 60, 'ttl' => 600, 'wait_timeout' => 3],

Orm::poolStats();          // tenants, open, active, idle, waiting, created, closed, hits, discarded, waits, timeouts, evicted_tenants
Orm::poolStats($tenant);   // open, active, idle, waiting for one tenant
```

Sizing: worst case per worker ≈ `max_tenants × size` open connections (plus the framework pools) — keep it below
MySQL `max_connections / workers`. Idle eviction is lazy (checked on checkout, at most once per second, or
`Orm::pool()->sweep()` from a timer). When every connection of a tenant is busy, coroutines wait (hand-off, FIFO-ish)
up to `wait_timeout`; outside a coroutine nothing can free one, so it throws immediately. `max_tenants` is soft: if
every bucket has connections checked out, a new tenant still gets a bucket rather than failing.

## Releasing connections

**Automatic (default).** Every statement checks a connection out and returns it right after; `Orm::transaction()`
keeps one for the transaction and returns it on commit / rollback.

**Manual.**

```php
$c = Orm::acquire($tenant);            // one connection until release()
try {
    $c->table('invoices')->insert([...]);
    User::on($tenant)->find(7);         // models / builders on the same config in this coroutine use it too
} finally {
    $c->release();                      // idempotent; a second call is a no-op
}

$n = Orm::using($tenant, fn (Connection $c) => $c->table('users')->count());   // acquire + release, always
```

Nested `acquire()` of the same config in one coroutine shares the lease (reference counted; the last `release()`
returns it). A held connection belongs to the acquiring coroutine: other coroutines get their own. `$c->discard()`
closes it instead of reusing it.

**On exceptions** — in a statement, a transaction, or your own code inside `using()` / `transaction()`:

* the connection is always returned (statement `finally`, `transaction()` rollback, `using()` `finally`);
* an open transaction is rolled back first;
* a connection that is lost / killed / out of sync (MySQL 2006, 2013, 1927, 4031 …, "gone away", "Lost connection")
  or whose rollback / commit failed is **discarded** (closed) and the pool opens a replacement later; SQL errors
  (syntax, constraint) keep the connection.

**Safety net.** A handle dropped without `release()` is released by its destructor; a lease still out when its
coroutine ends (kept in a global, or a `beginTransaction()` never committed) is reclaimed by `Coroutine::defer`
(open transaction rolled back). Both log `WARN` via `Orm::onWarning(fn (string $m) => ...)` (default `error_log`).
Treat the warning as a bug to fix, not a feature.

Do not keep the PDO / statements from `withLease(fn ($pdo) => ...)` past the callback, and do not pass a pinned
handle to another coroutine.

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

* Hot request paths: `Orm::using($tenant, fn () => ...)` around the handler keeps one connection for the whole
  request (no per-statement checkout / validation round trip).

* A model **remembers** where it came from: loaded or first saved on t1 ⇒ `save()`, `delete()`, `refresh()` and
  relation queries go to t1 even if called later inside a t2 scope.
* The connection of a query builder is fixed when the builder is created (`User::query()` inside the scope).
* Models that live in the landlord DB should declare `protected ?string $connection = 'central';` — declared
  connections ignore the tenant scope.
* Related models inherit the parent's connection unless they declare their own.
* Scopes nest; the previous tenant is restored when the callback returns or throws.

Verified by `server/bin/smoke_orm.php` (not shipped), in coroutine and plain CLI mode: 200 concurrent coroutines on
random tenants (array / URL DSN / PDO DSN specs) over pooled connections — zero cross-tenant rows, no PDO object or
MySQL connection id on two tenants, 809 statements on 24 PDOs (size 8 × 3 tenants); pinned save after an interleaved
query to another tenant; parallel transactions; LRU / idle / ttl eviction; `USE`-poisoned and server-killed
connections discarded; manual / double / forgotten release; exceptions in user code, SQL and transactions; pool
exhaustion (timeout error, no deadlock); framework default pool fallback, `discard()`, kill and exhaustion.

## Model events

Eloquent names, order and cancellation. Listeners are **class metadata**: register them once in `booted()`
(never per request). They receive the model, which carries its own connection, so whatever a listener does
through `$model` (relations, `$model->newQuery()`, `$model->getConnection()`) runs on that model's tenant, inside
its transaction if one is open.

```php
final class Order extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (Order $o) => $o->total >= 0);            // false → INSERT cancelled, save() returns false
        static::created(fn (Order $o) => $o->audits()->create(['event' => 'created']));   // same tenant as $o
        static::observe(OrderObserver::class);                        // methods named like events: saved(), deleted(), …
    }
}

$order->saveQuietly();                                   // also updateQuietly / deleteQuietly / restoreQuietly / forceDeleteQuietly
Order::withoutEvents(fn () => Order::on($t)->create([...]));     // ALL models, THIS coroutine only
$copy = $order->replicate();                             // unsaved copy, same tenant; fires "replicating"
```

| operation | events (… = cancellable) |
|---|---|
| hydrate (get/find/first/cursor, relations) | `retrieved` |
| insert | `saving`… `creating`… `created` `saved` |
| update (dirty) / clean save | `saving`… `updating`… `updated` `saved` / `saving`… `saved` |
| model `increment()`/`decrement()` | `updating`… `updated` |
| `delete()` (soft or hard) | `deleting`… `deleted` |
| `restore()` | `restoring`… then the save events, `restored` |
| `forceDelete()` | `forceDeleting`… `deleting`… `deleted` `forceDeleted` |
| `replicate()` | `replicating` (on the copy) |

* `withoutEvents()` is stored in the coroutine `Context`: concurrent coroutines keep their events; coroutines
  started inside (including `Orm::go()`) are **not** affected. Restored on exceptions.
* **Mass query `update()` / `delete()` / `increment()` on a builder fire no events** (as Eloquent). Load and save
  models when events matter. `Model::destroy()` loads the models, so it does fire them.
* Observers are instantiated once per worker and shared by every tenant / coroutine: keep them stateless.
* `save()` binds the instance to the connection it resolved to *before* the first listener runs (so listeners and
  the write agree even if a listener switches `Orm::tenant()` or cancels).

## Polymorphic relations

```php
Orm::morphMap(['post' => Post::class, 'video' => Video::class]);   // = Relation::morphMap(); Orm::enforceMorphMap([...]) requires it

class Comment extends Model { public function commentable(): MorphTo { return $this->morphTo(); } }   // commentable_type / _id
class Post extends Model {
    public function comments(): MorphMany { return $this->morphMany(Comment::class, 'commentable'); }
    public function image(): MorphOne { return $this->morphOne(Image::class, 'imageable'); }
    public function tags(): MorphToMany { return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight'); } // taggables(tag_id, taggable_type, taggable_id)
}
class Tag extends Model { public function posts(): MorphToMany { return $this->morphedByMany(Post::class, 'taggable'); } }

Comment::on($t)->with('commentable')->get();             // 1 query + 1 per distinct type
Comment::on($t)->with(['commentable' => fn (MorphTo $q) => $q->morphWith([Post::class => ['user']])
    ->constrain([Video::class => fn ($q) => $q->where('public', 1)])])->get();
$comment->commentable()->associate($video)->save();     // sets commentable_type = 'video', commentable_id
$post->comments()->create(['body' => 'hi']);           // type + id filled
$post->tags()->sync([1, 2 => ['weight' => 5]]);        // attach / detach / sync / updateExistingPivot, type-scoped
```

* Lookups run on the parent's connection (its tenant) unless the target class declares `$connection`.
* The stored type is the morph-map alias, else the class name. With `enforceMorphMap()` an unmapped model throws
  `ClassMorphViolationException` on write and an unmapped type value throws on read. A `*_type` value that is not a
  `Model` subclass is never instantiated (`InvalidArgumentException`).
* Builder calls in a `with(['commentable' => fn ($q) => …])` closure are replayed on every type's query.

## Has-many-through

```php
// countries → users (users.country_id) → posts (posts.user_id)
public function posts(): HasManyThrough { return $this->hasManyThrough(Post::class, User::class); }
// all keys: firstKey (through.fk), secondKey (related.fk), localKey (this), secondLocalKey (through)
public function owner(): HasOneThrough { return $this->hasOneThrough(Owner::class, Car::class, 'mechanic_id', 'car_id', 'id', 'id'); }

Country::on($t)->with(['posts' => fn ($q) => $q->where('posts.published', 1)])->get();   // 2 queries
$country->posts()->orderBy('posts.id')->paginate(20);
```

The through table is joined (same database as the related one; qualify columns in constraints). Soft-deleted
through rows are excluded when the through model uses `SoftDeletes`.

## loongs/framework integration

Nothing to register. When `loongs/framework` is present and its `DatabaseManager` pools are booted (worker start),
named connections — including the default one used when no tenant is in scope — are checked out of the framework
`PDOPool` and put back; broken ones go to `DatabaseManager::discard()` (the pool refills). Pool exhaustion
(`pool.wait_timeout`) surfaces as `Loongs\Orm\Exceptions\PoolExhaustedException`. Tenant configs never enter the
framework pools. Named configs come from `config('database')`. The framework does **not** depend on this package.
`Orm::usePools($provider)` swaps the provider (`null`: named connections use the ORM pool too).

## Caveats

* MySQL grammar only (SQLite/Postgres: add a `Grammar` via `Orm::extendGrammar`).
* A pool used before `fork()` is abandoned in the child (never closed there); do not query from the master before
  the server / process manager forks.
* Session state other than the current database (user variables, `SET SESSION ...`, temporary tables) survives in a
  pooled connection; reset what you change, or `discard()` the connection.
* Model events are not fired by mass query updates / deletes (see "Model events").
* `getOriginal()` applies casts and accessors; use `getRawOriginal()` for stored values.
* MySQL normalises JSON key order; compare decoded arrays with `==`.

## Development

In the loong-swoole monorepo it is linked through `server/composer.dev.json` (path repo, symlink):

```bash
cd server && COMPOSER=composer.dev.json composer update loongs/orm
php -d disable_functions= bin/smoke_orm.php co    # and: bin/smoke_orm.php cli
```

License: MIT
