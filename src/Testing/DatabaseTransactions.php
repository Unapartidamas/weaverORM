<?php
declare(strict_types=1);
namespace Weaver\ORM\Testing;

trait DatabaseTransactions
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }
}
