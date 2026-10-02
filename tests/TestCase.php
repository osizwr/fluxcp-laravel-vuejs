<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Laravel's own setUpTraits() already invokes a setUp<TraitName> hook for
 * every trait a test uses, which is how the suite's Interacts* traits get
 * their setup run. Nothing needs adding here.
 */
abstract class TestCase extends BaseTestCase
{
    //
}
