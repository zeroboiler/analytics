<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

/**
 * Trait for swapping the Analytics facade with AnalyticsFake in tests.
 *
 * @codeCoverageIgnore
 *
 * @mixin \PHPUnit\Framework\TestCase
 *
 * @since 10.4.0
 */
trait WithAnalyticsFake
{
    /**
     * Bind AnalyticsFake into the container to intercept all analytics events.
     *
     * Call in setUp() or beforeEach() to activate the test fake.
     *
     * @return AnalyticsFake The AnalyticsFake instance bound to the container.
     */
    protected function withAnalyticsFake(): AnalyticsFake
    {
        $fake = new AnalyticsFake;
        app()->instance('zeroboiler.analytics', $fake);

        return $fake;
    }
}
