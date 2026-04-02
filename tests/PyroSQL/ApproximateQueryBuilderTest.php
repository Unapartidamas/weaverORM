<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\PyroSQL;

use Weaver\ORM\DBAL\Connection;

use PHPUnit\Framework\MockObject\MockObject;
use Weaver\ORM\Tests\Fixture\Entity\User;
use Weaver\ORM\Tests\Fixture\WeaverIntegrationTestCase;
use Weaver\ORM\PyroSQL\Approximate\ApproximateQueryBuilder;
use Weaver\ORM\PyroSQL\Approximate\ApproximateResult;

final class ApproximateQueryBuilderTest extends WeaverIntegrationTestCase
{




    private function makeBuilder(
        ?Connection $connection = null,
        float $within = 5.0,
        float $confidence = 95.0,
    ): ApproximateQueryBuilder {
        $inner = $this->makeQueryBuilder(User::class);
        $conn  = $connection ?? $this->connection;

        return new ApproximateQueryBuilder(
            inner:      $inner,
            connection: $conn,
            within:     $within,
            confidence: $confidence,
        );
    }



    private function makeMockConnection(mixed $row, string &$capturedSql): Connection&MockObject
    {

        $mock = $this->createMock(Connection::class);




        $mock->method('createQueryBuilder')
            ->willReturnCallback(fn () => $this->connection->createQueryBuilder());

        $mock->method('fetchAssociative')
            ->willReturnCallback(function (string $sql) use ($row, &$capturedSql) {
                $capturedSql = $sql;

                return $row;
            });

        return $mock;
    }





    public function test_within_returns_new_instance_with_updated_percentage(): void
    {
        $original = $this->makeBuilder(within: 5.0);
        $modified = $original->within(2.0);

        self::assertNotSame($original, $modified);


        $capturedSql = '';
        $mockConn    = $this->makeMockConnection(false, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $builder->within(2.0)->count();

        self::assertStringContainsString('WITHIN 2%', $capturedSql);
    }

    public function test_within_does_not_mutate_original_instance(): void
    {
        $capturedOriginal = '';
        $capturedModified = '';

        $mockOrig = $this->makeMockConnection(false, $capturedOriginal);
        $mockMod  = $this->makeMockConnection(false, $capturedModified);

        $original = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockOrig,
            within:     5.0,
            confidence: 95.0,
        );

        $modified = $original->within(1.0);


        $original->count();

        $capturedMod = '';
        $mockMod2    = $this->makeMockConnection(false, $capturedMod);
        $rebuilt     = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockMod2,
            within:     1.0,
            confidence: 95.0,
        );
        $rebuilt->count();

        self::assertStringContainsString('WITHIN 5%', $capturedOriginal);
        self::assertStringContainsString('WITHIN 1%', $capturedMod);
    }

    public function test_confidence_returns_new_instance_with_updated_percentage(): void
    {
        $original = $this->makeBuilder(confidence: 95.0);
        $modified = $original->confidence(99.0);

        self::assertNotSame($original, $modified);

        $capturedSql = '';
        $mockConn    = $this->makeMockConnection(false, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $builder->confidence(99.0)->count();

        self::assertStringContainsString('CONFIDENCE 99%', $capturedSql);
    }

    public function test_confidence_does_not_mutate_original_instance(): void
    {
        $capturedOriginal = '';
        $mockOrig         = $this->makeMockConnection(false, $capturedOriginal);

        $original = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockOrig,
            within:     5.0,
            confidence: 95.0,
        );


        $original->confidence(99.0);
        $original->count();

        self::assertStringContainsString('CONFIDENCE 95%', $capturedOriginal);
    }

    public function test_with_fallback_returns_instance_with_fallback_enabled(): void
    {
        $builder  = $this->makeBuilder();
        $withFb   = $builder->withFallback();


        self::assertNotSame($builder, $withFb);
    }





    public function test_count_generates_approximate_count_sql(): void
    {
        $capturedSql = '';
        $mockConn    = $this->makeMockConnection(false, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     3.0,
            confidence: 97.0,
        );

        $builder->count();

        self::assertStringContainsString('APPROXIMATE COUNT(*)', $capturedSql);
        self::assertStringContainsString('WITHIN 3%', $capturedSql);
        self::assertStringContainsString('CONFIDENCE 97%', $capturedSql);
    }

    public function test_count_sql_contains_from_clause(): void
    {
        $capturedSql = '';
        $mockConn    = $this->makeMockConnection(false, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $builder->count();

        self::assertStringContainsString('FROM', $capturedSql);
        self::assertStringContainsString('users', $capturedSql);
    }





    public function test_count_returns_approximate_result_when_aqp_metadata_present(): void
    {
        $row = [
            'aggregate'          => '42000',
            '_vk_is_approximate' => '1',
            '_vk_error_margin'   => '2.3',
            '_vk_confidence'     => '95.0',
            '_vk_sampled_rows'   => '10000',
            '_vk_total_rows'     => '50000',
        ];

        $capturedSql = '';
        $mockConn    = $this->makeMockConnection($row, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $result = $builder->count();

        self::assertInstanceOf(ApproximateResult::class, $result);
        self::assertTrue($result->isApproximate);
    }

    public function test_count_returns_not_approximate_when_no_aqp_metadata(): void
    {

        $row = ['aggregate' => '1234'];

        $capturedSql = '';
        $mockConn    = $this->makeMockConnection($row, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $result = $builder->count();

        self::assertInstanceOf(ApproximateResult::class, $result);
        self::assertFalse($result->isApproximate);
    }

    public function test_count_returns_zero_result_when_fetchassociative_returns_false(): void
    {
        $capturedSql = '';
        $mockConn    = $this->makeMockConnection(false, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $result = $builder->count();

        self::assertSame(0, $result->toInt());
        self::assertFalse($result->isApproximate);
    }





    public function test_count_uses_exact_fallback_when_exception_and_fallback_enabled(): void
    {

        $mockConn = $this->createMock(Connection::class);

        $mockConn->method('createQueryBuilder')
            ->willReturnCallback(fn () => $this->connection->createQueryBuilder());

        $callCount = 0;
        $mockConn->method('fetchAssociative')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;

                if ($callCount === 1) {

                    throw new \RuntimeException('AQP engine unavailable');
                }


                return ['_value' => '9999'];
            });

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $result = $builder->withFallback()->count();

        self::assertInstanceOf(ApproximateResult::class, $result);
        self::assertFalse($result->isApproximate);
        self::assertSame(2, $callCount, 'Expected exactly two fetchAssociative calls (AQP then fallback)');
    }

    public function test_count_throws_exception_when_query_fails_and_fallback_not_active(): void
    {

        $mockConn = $this->createMock(Connection::class);

        $mockConn->method('createQueryBuilder')
            ->willReturnCallback(fn () => $this->connection->createQueryBuilder());

        $mockConn->method('fetchAssociative')
            ->willThrowException(new \RuntimeException('AQP engine unavailable'));

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AQP engine unavailable');

        $builder->count();
    }





    public function test_where_returns_same_instance_for_chaining(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->where('role', 'admin');

        self::assertSame($builder, $result);
    }

    public function test_where_in_returns_same_instance_for_chaining(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->whereIn('role', ['admin', 'user']);

        self::assertSame($builder, $result);
    }

    public function test_where_null_returns_same_instance_for_chaining(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->whereNull('role');

        self::assertSame($builder, $result);
    }

    public function test_where_not_in_returns_same_instance_for_chaining(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->whereNotIn('role', ['banned']);

        self::assertSame($builder, $result);
    }

    public function test_where_not_null_returns_same_instance_for_chaining(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->whereNotNull('email');

        self::assertSame($builder, $result);
    }

    public function test_where_raw_returns_same_instance_for_chaining(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->whereRaw('active = 1');

        self::assertSame($builder, $result);
    }

    public function test_or_where_returns_same_instance_for_chaining(): void
    {
        $builder = $this->makeBuilder();
        $result  = $builder->orWhere('role', 'admin');

        self::assertSame($builder, $result);
    }





    public function test_count_parses_aqp_metadata_columns_correctly(): void
    {
        $row = [
            'aggregate'          => '31415',
            '_vk_is_approximate' => '1',
            '_vk_error_margin'   => '1.5',
            '_vk_confidence'     => '99.0',
            '_vk_sampled_rows'   => '20000',
            '_vk_total_rows'     => '100000',
        ];

        $capturedSql = '';
        $mockConn    = $this->makeMockConnection($row, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $result = $builder->count();

        self::assertSame(1.5, $result->errorMargin);
        self::assertSame(99.0, $result->confidence);
        self::assertSame(20000, $result->sampledRows);
        self::assertSame(100000, $result->totalRows);
        self::assertTrue($result->isApproximate);
    }

    public function test_count_with_where_filters_affect_generated_sql(): void
    {
        $capturedSql = '';
        $mockConn    = $this->makeMockConnection(false, $capturedSql);

        $builder = new ApproximateQueryBuilder(
            inner:      $this->makeQueryBuilder(User::class),
            connection: $mockConn,
            within:     5.0,
            confidence: 95.0,
        );

        $builder->where('active', 1)->count();

        self::assertStringContainsString('WHERE', $capturedSql);
    }
}
