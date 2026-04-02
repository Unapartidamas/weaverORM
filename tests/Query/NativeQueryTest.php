<?php

declare(strict_types=1);

namespace Weaver\ORM\Tests\Query;

use Weaver\ORM\DBAL\Connection;
use Weaver\ORM\DBAL\ConnectionFactory;
use PHPUnit\Framework\TestCase;
use Weaver\ORM\Mapping\MapperRegistry;
use Weaver\ORM\Query\Native\NativeQuery;
use Weaver\ORM\Query\Native\NativeQueryResult;
use Weaver\ORM\Query\Native\ResultSetMapping;

class NqProfile
{
    public int $id       = 0;
    public int $userId   = 0;
    public string $bio   = '';
}

class NqUser
{
    public int $id            = 0;
    public string $name       = '';
    public ?NqProfile $profile = null;
}

final class NativeQueryTest extends TestCase
{
    private Connection $connection;
    private MapperRegistry $registry;

    protected function setUp(): void
    {
        $this->connection = ConnectionFactory::create([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(
            'CREATE TABLE nq_users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'
        );
        $this->connection->executeStatement(
            'CREATE TABLE nq_profiles (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, bio TEXT NOT NULL)'
        );


        $this->connection->executeStatement("INSERT INTO nq_users (id, name) VALUES (1, 'Alice'), (2, 'Bob')");
        $this->connection->executeStatement("INSERT INTO nq_profiles (id, user_id, bio) VALUES (10, 1, 'Alice bio')");

        $this->registry = new MapperRegistry();
    }

    private function makeNativeQuery(): NativeQuery
    {
        return new NativeQuery($this->connection, $this->registry);
    }

    private function makeUserRsm(): ResultSetMapping
    {
        $rsm = new ResultSetMapping();
        $rsm->addRootEntity(NqUser::class, 'u')
            ->addFieldMapping('u', 'u_id',   'id')
            ->addFieldMapping('u', 'u_name', 'name');
        return $rsm;
    }





    public function testSimpleRootOnlyQueryReturnsTwoUsers(): void
    {
        $rsm = $this->makeUserRsm();

        $result = $this->makeNativeQuery()
            ->setSql('SELECT id AS u_id, name AS u_name FROM nq_users ORDER BY id')
            ->setResultSetMapping($rsm)
            ->execute();

        self::assertInstanceOf(NativeQueryResult::class, $result);
        self::assertCount(2, $result->getResults());

        $first = $result->getResults()[0];
        self::assertInstanceOf(NqUser::class, $first);
        self::assertSame(1, $first->id);
        self::assertSame('Alice', $first->name);

        $second = $result->getResults()[1];
        self::assertInstanceOf(NqUser::class, $second);
        self::assertSame(2, $second->id);
        self::assertSame('Bob', $second->name);
    }





    public function testJoinQueryHydratesJoinedEntity(): void
    {
        $rsm = new ResultSetMapping();
        $rsm->addRootEntity(NqUser::class, 'u')
            ->addFieldMapping('u', 'u_id',   'id')
            ->addFieldMapping('u', 'u_name', 'name')
            ->addJoinedEntity(NqProfile::class, 'p', 'u', 'profile')
            ->addFieldMapping('p', 'p_id',      'id')
            ->addFieldMapping('p', 'p_user_id', 'userId')
            ->addFieldMapping('p', 'p_bio',     'bio');

        $sql = 'SELECT u.id AS u_id, u.name AS u_name,
                       p.id AS p_id, p.user_id AS p_user_id, p.bio AS p_bio
                FROM nq_users u
                LEFT JOIN nq_profiles p ON p.user_id = u.id
                ORDER BY u.id';

        $result = $this->makeNativeQuery()
            ->setSql($sql)
            ->setResultSetMapping($rsm)
            ->execute();

        self::assertCount(2, $result->getResults());

        $alice = $result->getResults()[0];
        self::assertInstanceOf(NqUser::class, $alice);
        self::assertNotNull($alice->profile, 'Alice should have a profile');
        self::assertInstanceOf(NqProfile::class, $alice->profile);
        self::assertSame('Alice bio', $alice->profile->bio);
        self::assertSame(1, $alice->profile->userId);

        $bob = $result->getResults()[1];
        self::assertInstanceOf(NqUser::class, $bob);
        self::assertNull($bob->profile, 'Bob should have no profile');
    }





    public function testParametersFilterResults(): void
    {
        $rsm = $this->makeUserRsm();

        $result = $this->makeNativeQuery()
            ->setSql('SELECT id AS u_id, name AS u_name FROM nq_users WHERE id = ?')
            ->setParameters([1])
            ->setResultSetMapping($rsm)
            ->execute();

        self::assertCount(1, $result->getResults());
        self::assertSame(1, $result->getResults()[0]->id);
        self::assertSame('Alice', $result->getResults()[0]->name);
    }





    public function testEmptyResultSet(): void
    {
        $rsm = $this->makeUserRsm();

        $result = $this->makeNativeQuery()
            ->setSql('SELECT id AS u_id, name AS u_name FROM nq_users WHERE id = 9999')
            ->setResultSetMapping($rsm)
            ->execute();

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->count());
        self::assertSame([], $result->getResults());
    }





    public function testGetFirstResultReturnsFirstEntity(): void
    {
        $rsm = $this->makeUserRsm();

        $result = $this->makeNativeQuery()
            ->setSql('SELECT id AS u_id, name AS u_name FROM nq_users ORDER BY id')
            ->setResultSetMapping($rsm)
            ->execute();

        $first = $result->getFirstResult();
        self::assertNotNull($first);
        self::assertInstanceOf(NqUser::class, $first);
        self::assertSame(1, $first->id);
    }

    public function testGetFirstResultReturnsNullOnEmptyResult(): void
    {
        $rsm = $this->makeUserRsm();

        $result = $this->makeNativeQuery()
            ->setSql('SELECT id AS u_id, name AS u_name FROM nq_users WHERE id = 9999')
            ->setResultSetMapping($rsm)
            ->execute();

        self::assertNull($result->getFirstResult());
    }





    public function testExecuteWithoutRsmThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Call setResultSetMapping() before execute().');

        $this->makeNativeQuery()
            ->setSql('SELECT 1')
            ->execute();
    }





    public function testRsmWithoutRootEntityThrowsOnGetRootAlias(): void
    {
        $this->expectException(\LogicException::class);

        $rsm = new ResultSetMapping();
        $rsm->getRootAlias();
    }

    public function testRsmWithoutRootEntityThrowsOnGetRootEntityClass(): void
    {
        $this->expectException(\LogicException::class);

        $rsm = new ResultSetMapping();
        $rsm->getRootEntityClass();
    }





    public function testLeftJoinNullColumnsDoNotCreateEmptyJoinedEntity(): void
    {
        $rsm = new ResultSetMapping();
        $rsm->addRootEntity(NqUser::class, 'u')
            ->addFieldMapping('u', 'u_id',   'id')
            ->addFieldMapping('u', 'u_name', 'name')
            ->addJoinedEntity(NqProfile::class, 'p', 'u', 'profile')
            ->addFieldMapping('p', 'p_id',      'id')
            ->addFieldMapping('p', 'p_user_id', 'userId')
            ->addFieldMapping('p', 'p_bio',     'bio');

        $sql = 'SELECT u.id AS u_id, u.name AS u_name,
                       p.id AS p_id, p.user_id AS p_user_id, p.bio AS p_bio
                FROM nq_users u
                LEFT JOIN nq_profiles p ON p.user_id = u.id
                WHERE u.id = 2';

        $result = $this->makeNativeQuery()
            ->setSql($sql)
            ->setResultSetMapping($rsm)
            ->execute();

        self::assertCount(1, $result->getResults());
        $bob = $result->getResults()[0];
        self::assertNull($bob->profile, 'Bob has no profile — property must stay null, not an empty NqProfile');
    }
}
