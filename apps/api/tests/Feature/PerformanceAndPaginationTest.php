<?php

use App\Shared\Cache\TenantCache;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\OffsetPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Query\QuerySpecification;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    Cache::flush();
    TenancyScope::clear();

    if (! Schema::hasTable('test_records')) {
        Schema::create('test_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    DB::table('test_records')->truncate();
});

// ─── 1. Paginación en servidor ────────────────────────────────────────────────

test('offset paginator enforces server pagination with correct meta', function (): void {
    for ($i = 1; $i <= 30; $i++) {
        DB::table('test_records')->insert([
            'name' => "Record {$i}",
            'status' => $i % 2 === 0 ? 'active' : 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $result = OffsetPaginator::paginate(
        query: DB::table('test_records')->orderBy('id'),
        page: 2,
        perPage: 10,
    );

    expect($result['data'])->toHaveCount(10);
    expect($result['data'][0]['name'])->toBe('Record 11');
    expect($result['meta'])->toBe([
        'page' => 2,
        'per_page' => 10,
        'total' => 30,
        'total_pages' => 3,
        'next_cursor' => null,
    ]);
});

test('cursor paginator navigates sequential pages without returning full collection', function (): void {
    for ($i = 1; $i <= 15; $i++) {
        DB::table('test_records')->insert([
            'name' => "Log {$i}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Page 1: 5 items
    $page1 = CursorPaginator::paginate(
        query: DB::table('test_records'),
        perPage: 5,
    );

    expect($page1['data'])->toHaveCount(5);
    expect($page1['meta']['next_cursor'])->not->toBeNull();

    // Page 2: next 5 items using cursor
    $page2 = CursorPaginator::paginate(
        query: DB::table('test_records'),
        perPage: 5,
        cursor: $page1['meta']['next_cursor'],
    );

    expect($page2['data'])->toHaveCount(5);
    expect($page2['data'][0]['id'])->toBe(6);
    expect($page2['meta']['next_cursor'])->not->toBeNull();
});

test('pagination params reject per_page exceeding maximum limit of 100', function (): void {
    $request = Request::create('/api/v1/resources?per_page=150', 'GET');

    expect(fn () => PaginationParams::fromRequest($request))
        ->toThrow(InvalidFilterException::class);
});

// ─── 2. Filtros y ordenamiento declarados ──────────────────────────────────────

test('query specification accepts declared filters and sorts', function (): void {
    $spec = new QuerySpecification(
        allowedFilters: ['status', 'name'],
        allowedSorts: ['created_at', 'name'],
    );

    $request = Request::create('/api/v1/items?filter[status]=active&sort=-created_at,name', 'GET');

    // Should not throw
    $spec->validate($request);
    expect(true)->toBeTrue();
});

test('query specification rejects undeclared filters with 400 invalid_filter', function (): void {
    $spec = new QuerySpecification(
        allowedFilters: ['status'],
        allowedSorts: ['created_at'],
    );

    $request = Request::create('/api/v1/items?filter[unauthorized_field]=1', 'GET');

    try {
        $spec->validate($request);
        $this->fail('Se esperaba InvalidFilterException');
    } catch (InvalidFilterException $e) {
        expect($e->getStatusCode())->toBe(400);
        expect($e->getErrorCode())->toBe('invalid_filter');
        expect($e->getMeta())->toHaveKey('allowed', ['status']);
    }
});

test('query specification rejects undeclared sort fields with 400 invalid_filter', function (): void {
    $spec = new QuerySpecification(
        allowedFilters: ['status'],
        allowedSorts: ['created_at'],
    );

    $request = Request::create('/api/v1/items?sort=-secret_salary', 'GET');

    try {
        $spec->validate($request);
        $this->fail('Se esperaba InvalidFilterException');
    } catch (InvalidFilterException $e) {
        expect($e->getStatusCode())->toBe(400);
        expect($e->getErrorCode())->toBe('invalid_filter');
        expect($e->getMeta())->toHaveKey('allowed', ['created_at']);
    }
});

// ─── 3. Prevención y detección de consultas N+1 ──────────────────────────────

test('assertQueryCountLessThanOrEqual detects queries and passes within limit', function (): void {
    $this->assertQueryCountLessThanOrEqual(2, function (): void {
        DB::table('test_records')->where('status', 'active')->count();
        DB::table('test_records')->limit(5)->get();
    });
});

test('assertQueryCountLessThanOrEqual fails when queries exceed limit', function (): void {
    $failed = false;

    try {
        $this->assertQueryCountLessThanOrEqual(1, function (): void {
            DB::table('test_records')->count();
            DB::table('test_records')->count();
        });
    } catch (AssertionFailedError) {
        $failed = true;
    }

    expect($failed)->toBeTrue();
});

test('eloquent prevents lazy loading outside production', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue();
});

// ─── 4. Aislamiento de caché por organización ─────────────────────────────────

test('tenant cache isolates keys between organizations', function (): void {
    $tenantCache = app(TenantCache::class);

    // Tenant Alpha writes a key
    TenancyScope::set('tenant-alpha');
    $tenantCache->put('config.max_users', 50);

    expect((int) $tenantCache->get('config.max_users'))->toBe(50);

    // Tenant Beta tries to read the same key
    TenancyScope::set('tenant-beta');
    expect($tenantCache->get('config.max_users'))->toBeNull();

    // Tenant Beta writes their own value
    $tenantCache->put('config.max_users', 100);
    expect((int) $tenantCache->get('config.max_users'))->toBe(100);

    // Verify Tenant Alpha value is unchanged
    TenancyScope::set('tenant-alpha');
    expect((int) $tenantCache->get('config.max_users'))->toBe(50);

    // Verify actual key in store has tenant prefix
    expect(Cache::has('tenant:tenant-alpha:config.max_users'))->toBeTrue();
    expect((int) Cache::get('tenant:tenant-alpha:config.max_users'))->toBe(50);
    expect((int) Cache::get('tenant:tenant-beta:config.max_users'))->toBe(100);

    TenancyScope::clear();
});

test('tenant cache throws exception when no tenant is active in context', function (): void {
    TenancyScope::clear();

    $tenantCache = new TenantCache;

    expect(fn () => $tenantCache->get('any_key'))
        ->toThrow(RuntimeException::class, 'TenantCache requires an active tenant context');
});
