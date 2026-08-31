<?php

declare(strict_types=1);

use justinholtweb\stub\models\ServiceCriteria;

/**
 * `ServiceCriteria` is the whole reason frontend service filtering can be trusted: it turns
 * a loose Twig hash into typed lists before anything reaches a query. It is pure by design
 * — no database, no booted Craft — so every normalization rule is asserted here rather than
 * discovered in production.
 *
 * Elements are stood in for by anonymous objects with an `id`, which is all the normalizer
 * ever looks at.
 */

function userWithId(int $id): object
{
    return new class($id) {
        public function __construct(public int $id)
        {
        }
    };
}

it('applies no filter at all when given nothing', function() {
    $criteria = ServiceCriteria::fromArray([]);

    expect($criteria->isFiltered())->toBeFalse()
        ->and($criteria->matchesNothing)->toBeFalse()
        ->and($criteria->hasProviderFilter())->toBeFalse()
        ->and($criteria->ids)->toBeNull()
        ->and($criteria->handles)->toBeNull()
        ->and($criteria->includeDisabled)->toBeFalse();
});

it('rejects an unknown key instead of silently returning everything', function() {
    expect(fn() => ServiceCriteria::fromArray(['providor' => 'jane']))
        ->toThrow(InvalidArgumentException::class, 'Unknown service filter key: providor');
});

it('names every valid key when it rejects one', function() {
    expect(fn() => ServiceCriteria::fromArray(['nope' => 1]))
        ->toThrow(InvalidArgumentException::class, 'includeDisabled');
});

it('accepts singular and plural spellings of the same filter', function() {
    expect(ServiceCriteria::fromArray(['id' => 4])->ids)->toBe([4])
        ->and(ServiceCriteria::fromArray(['ids' => [4, 5]])->ids)->toBe([4, 5])
        ->and(ServiceCriteria::fromArray(['handle' => 'massage'])->handles)->toBe(['massage'])
        ->and(ServiceCriteria::fromArray(['handles' => ['a', 'b']])->handles)->toBe(['a', 'b']);
});

it('merges both spellings and drops duplicates', function() {
    $criteria = ServiceCriteria::fromArray(['id' => 4, 'ids' => [4, 5]]);

    expect($criteria->ids)->toBe([4, 5]);
});

/*
 |--------------------------------------------------------------------------
 | The empty-filter rule
 |--------------------------------------------------------------------------
 |
 | `{ user: currentUser }` on a logged-out request passes null. Treating that as "no
 | filter given" would show the full service list to precisely the visitor who should
 | see none of it, so an asked-for filter that resolves to nothing matches nothing.
 |
 */

it('matches nothing when a filter is given but resolves to nothing', function(string $key, mixed $value) {
    $criteria = ServiceCriteria::fromArray([$key => $value]);

    expect($criteria->matchesNothing)->toBeTrue()
        ->and($criteria->isFiltered())->toBeTrue();
})->with([
    'null user' => ['user', null],
    'null provider' => ['provider', null],
    'empty provider list' => ['providers', []],
    'list of nulls' => ['users', [null, null]],
    'empty string handle' => ['handle', ''],
]);

it('does not treat includeDisabled on its own as a filter', function() {
    $criteria = ServiceCriteria::fromArray(['includeDisabled' => true]);

    expect($criteria->includeDisabled)->toBeTrue()
        ->and($criteria->isFiltered())->toBeFalse()
        ->and($criteria->matchesNothing)->toBeFalse();
});

/*
 |--------------------------------------------------------------------------
 | Providers
 |--------------------------------------------------------------------------
 |
 | A provider can arrive three ways, and handles are told apart from IDs by being
 | non-numeric — the handle validator requires a leading letter, so the two can never
 | collide.
 |
 */

it('reads a provider ID, a handle and a model into the right buckets', function() {
    $criteria = ServiceCriteria::fromArray([
        'providers' => [7, 'jane', userWithId(9)],
    ]);

    expect($criteria->providerIds)->toBe([7, 9])
        ->and($criteria->providerHandles)->toBe(['jane'])
        ->and($criteria->hasProviderFilter())->toBeTrue();
});

it('reads a numeric string provider as an ID, not a handle', function() {
    $criteria = ServiceCriteria::fromArray(['provider' => '7']);

    expect($criteria->providerIds)->toBe([7])
        ->and($criteria->providerHandles)->toBeNull();
});

it('deduplicates providers within a bucket', function() {
    $criteria = ServiceCriteria::fromArray(['providers' => [7, '7', 'jane', 'jane']]);

    expect($criteria->providerIds)->toBe([7])
        ->and($criteria->providerHandles)->toBe(['jane']);
});

it('trims a provider handle', function() {
    expect(ServiceCriteria::fromArray(['provider' => '  jane  '])->providerHandles)->toBe(['jane']);
});

it('rejects a provider that is neither an ID, a handle nor an object with an ID', function() {
    expect(fn() => ServiceCriteria::fromArray(['provider' => 0]))
        ->toThrow(InvalidArgumentException::class, 'positive ID');
});

/*
 |--------------------------------------------------------------------------
 | Users
 |--------------------------------------------------------------------------
 */

it('reads a User element and a bare user ID alike', function() {
    expect(ServiceCriteria::fromArray(['user' => userWithId(12)])->userIds)->toBe([12])
        ->and(ServiceCriteria::fromArray(['user' => 12])->userIds)->toBe([12])
        ->and(ServiceCriteria::fromArray(['users' => [12, 13]])->userIds)->toBe([12, 13]);
});

it('points at currentUser when handed a username string', function() {
    expect(fn() => ServiceCriteria::fromArray(['user' => 'jane']))
        ->toThrow(InvalidArgumentException::class, 'takes a User element or its ID');
});

it('counts a user filter as a provider filter, since it resolves to providers', function() {
    expect(ServiceCriteria::fromArray(['user' => 12])->hasProviderFilter())->toBeTrue();
});

it('combines a provider filter with a handle filter', function() {
    $criteria = ServiceCriteria::fromArray([
        'user' => userWithId(12),
        'handles' => ['massage'],
        'includeDisabled' => true,
    ]);

    expect($criteria->userIds)->toBe([12])
        ->and($criteria->handles)->toBe(['massage'])
        ->and($criteria->includeDisabled)->toBeTrue()
        ->and($criteria->matchesNothing)->toBeFalse();
});

it('rejects a numeric service handle and points at the id key', function() {
    // Handles must start with a letter, so a number here is a mistyped `id`.
    expect(fn() => ServiceCriteria::fromArray(['handle' => 12]))
        ->toThrow(InvalidArgumentException::class, 'uses the `id` key');
});

it('flattens a list of handles rather than requiring one call per handle', function() {
    expect(ServiceCriteria::fromArray(['handle' => ['massage', 'facial']])->handles)
        ->toBe(['massage', 'facial']);
});
