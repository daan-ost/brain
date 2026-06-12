<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit', 'Basewebsite');

/*
|--------------------------------------------------------------------------
| Uses - RefreshDatabase for Feature Tests
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class)->in('Feature', 'Basewebsite');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user for testing
 */
function createUser(array $attributes = []): \App\Models\User
{
    return \App\Models\User::factory()->create($attributes);
}

/**
 * Create an organization for testing
 */
function createOrganization(array $attributes = []): \App\Models\Organization
{
    return \App\Models\Organization::factory()->create($attributes);
}

/**
 * Create an order for testing
 */
function createOrder(array $attributes = []): \App\Models\Order
{
    return \App\Models\Order::factory()->create($attributes);
}

/**
 * Create a license for testing
 */
function createLicense(array $attributes = []): \App\Models\License
{
    return \App\Models\License::factory()->create($attributes);
}

/**
 * Create a cover template for testing
 */
function createCoverTemplate(array $attributes = []): \App\Models\CoverTemplate
{
    return \App\Models\CoverTemplate::factory()->create($attributes);
}
