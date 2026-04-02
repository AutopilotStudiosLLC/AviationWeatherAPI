<?php

namespace Staple\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staple\Query\Connection;
use Staple\Query\MockConnection;
use Staple\Query\Query;

class WhereInTest extends TestCase
{
    private function getMockConnection()
    {
        return new MockConnection(NULL);
    }

    #[Test]
    public function testWhereInParameterBinding()
    {
        $connection = $this->getMockConnection();
        $connection->setDriver(Connection::DRIVER_MYSQL);

        $ids = [1, 2, 3];
        $query = Query::select('users', null, $connection)
            ->whereIn('id', $ids, 'user_ids');

        $queryString = $query->build();

        // Expected SQL:
        // SELECT
        // *
        // FROM users
        // WHERE id IN (:user_ids_in_1, :user_ids_in_2, :user_ids_in_3)

        $this->assertStringContainsString('WHERE id IN (:user_ids_in_1, :user_ids_in_2, :user_ids_in_3)', $queryString);

        $params = $query->getParams();

        $this->assertArrayHasKey('user_ids_in_1', $params, 'Missing binding for user_ids_in_1');
        $this->assertArrayHasKey('user_ids_in_2', $params, 'Missing binding for user_ids_in_2');
        $this->assertArrayHasKey('user_ids_in_3', $params, 'Missing binding for user_ids_in_3');

        $this->assertEquals(1, $params['user_ids_in_1']);
        $this->assertEquals(2, $params['user_ids_in_2']);
        $this->assertEquals(3, $params['user_ids_in_3']);
    }
}
