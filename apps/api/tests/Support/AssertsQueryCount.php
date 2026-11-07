<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

trait AssertsQueryCount
{
    /**
     * Assert that the given callback executes at most $max queries.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function assertQueryCountLessThanOrEqual(int $max, callable $callback): mixed
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = $callback();
            $queries = DB::getQueryLog();
            $count = count($queries);

            Assert::assertLessThanOrEqual(
                $max,
                $count,
                sprintf('Expected at most %d queries, but %d were executed.', $max, $count),
            );

            return $result;
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * Assert that the given callback executes exactly $expected queries.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function assertQueryCount(int $expected, callable $callback): mixed
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $result = $callback();
            $queries = DB::getQueryLog();
            $count = count($queries);

            Assert::assertSame(
                $expected,
                $count,
                sprintf('Expected exactly %d queries, but %d were executed.', $expected, $count),
            );

            return $result;
        } finally {
            DB::disableQueryLog();
        }
    }
}
