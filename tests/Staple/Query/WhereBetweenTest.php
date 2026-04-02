<?php

namespace Staple\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Staple\Query\Connection;
use Staple\Query\MockConnection;
use Staple\Query\Query;

class WhereBetweenTest extends TestCase
{
    private function getMockConnection()
    {
        return new MockConnection(NULL);
    }

    #[Test]
    public function testWhereBetweenDefaultParameterBinding()
    {
        $connection = $this->getMockConnection();
        $connection->setDriver(Connection::DRIVER_MYSQL);

        $query = Query::select('users', null, $connection)
            ->whereBetween('age', 18, 65);

        $queryString = $query->build();

        // Expected SQL part:
        // WHERE age BETWEEN :age_start AND :age_end

        $this->assertStringContainsString('WHERE age BETWEEN :age_start AND :age_end', $queryString);

        $params = $query->getParams();

        $this->assertArrayHasKey('age_start', $params, 'Missing binding for age_start');
        $this->assertArrayHasKey('age_end', $params, 'Missing binding for age_end');

        $this->assertEquals(18, $params['age_start']);
        $this->assertEquals(65, $params['age_end']);
    }

    #[Test]
    public function testWhereBetweenCustomParameterBinding()
    {
        $connection = $this->getMockConnection();
        $connection->setDriver(Connection::DRIVER_MYSQL);

        $query = Query::select('users', null, $connection)
            ->whereBetween('age', 20, 30, 'min_age', 'max_age');

        $queryString = $query->build();

        // Expected SQL part:
        // WHERE age BETWEEN :min_age AND :max_age

        $this->assertStringContainsString('WHERE age BETWEEN :min_age AND :max_age', $queryString);

        $params = $query->getParams();

        $this->assertArrayHasKey('min_age', $params, 'Missing binding for min_age');
        $this->assertArrayHasKey('max_age', $params, 'Missing binding for max_age');

        $this->assertEquals(20, $params['min_age']);
        $this->assertEquals(30, $params['max_age']);
    }

    #[Test]
    public function testWhereBetweenNonParameterized()
    {
        $connection = $this->getMockConnection();
        $connection->setDriver(Connection::DRIVER_MYSQL);

        $query = Query::select('users', null, $connection)
            ->whereBetween('age', 18, 65, null, null, false);

        $queryString = $query->build();

        // Expected SQL part for non-parameterized:
        // WHERE age BETWEEN 18 AND 65

        $this->assertStringContainsString('WHERE age BETWEEN 18 AND 65', $queryString);

        $params = $query->getParams();
        $this->assertEmpty($params, 'Parameters should be empty for non-parameterized query');
    }

    #[Test]
    public function testWhereBetweenWithStringsNonParameterized()
    {
        $connection = $this->getMockConnection();
        $connection->setDriver(Connection::DRIVER_MYSQL);

        $query = Query::select('events', null, $connection)
            ->whereBetween('date', '2023-01-01', '2023-12-31', null, null, false);

        $queryString = $query->build();

        // Expected SQL part for non-parameterized strings (quoted by mock connection):
        // WHERE date BETWEEN '2023-01-01' AND '2023-12-31'

        $this->assertStringContainsString("WHERE date BETWEEN '2023-01-01' AND '2023-12-31'", $queryString);
    }
}
