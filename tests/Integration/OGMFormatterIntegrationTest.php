<?php

declare(strict_types=1);

/*
 * This file is part of the Laudis Neo4j package.
 *
 * (c) Laudis technologies <http://laudis.tech>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Laudis\Neo4j\Tests\Integration;

use DateInterval;
use Ds\Map;
use Ds\Vector;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Formatter\OGMFormatter;
use Laudis\Neo4j\Formatter\Specialised\BoltOGMTranslator;
use Laudis\Neo4j\Formatter\Specialised\HttpOGMArrayTranslator;
use Laudis\Neo4j\Formatter\Specialised\HttpOGMStringTranslator;
use Laudis\Neo4j\Formatter\Specialised\HttpOGMTranslator;
use Laudis\Neo4j\Types\CartesianPoint;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\Date;
use Laudis\Neo4j\Types\DateTime;
use Laudis\Neo4j\Types\Duration;
use Laudis\Neo4j\Types\LocalDateTime;
use Laudis\Neo4j\Types\Node;
use Laudis\Neo4j\Types\Relationship;
use Laudis\Neo4j\Types\Time;
use PHPUnit\Framework\TestCase;
use function range;
use function sprintf;

final class OGMFormatterIntegrationTest extends TestCase
{
    /** @var ClientInterface<Vector<Map<string, mixed>>> */
    private ClientInterface $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = ClientBuilder::create()
            ->withDriver('http', 'http://neo4j:test@neo4j')
            ->withDriver('bolt', 'bolt://neo4j:test@neo4j')
            ->withDriver('cluster', 'neo4j://neo4j:test@core1')
            ->withFormatter(new OGMFormatter(
                new BoltOGMTranslator(),
                new HttpOGMTranslator(
                    new HttpOGMArrayTranslator(),
                    new HttpOGMStringTranslator()
                )
            ))
            ->build();

        $this->client->run('MATCH (n) DETACH DELETE n');
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testNull(string $alias): void
    {
        $results = $this->client->run('RETURN null as x', [], $alias);

        self::assertNull($results->first()->get('x'));
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testList(string $alias): void
    {
        $results = $this->client->run('RETURN range(5, 15) as list, range(16, 35) as list2', [], $alias);

        $list = $results->first()->get('list');
        $list2 = $results->first()->get('list2');

        self::assertInstanceOf(CypherList::class, $list);
        self::assertInstanceOf(CypherList::class, $list2);
        self::assertEquals(range(5, 15), $list->toArray());
        self::assertEquals(range(16, 35), $list2->toArray());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testMap(string $alias): void
    {
        $results = $this->client->run('RETURN {a: "b", c: "d"} as map', [], $alias);

        self::assertEquals(['a' => 'b', 'c' => 'd'], $results->first()->get('map')->toArray());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testBoolean(string $alias): void
    {
        $results = $this->client->run('RETURN true as bool1, false as bool2', [], $alias);

        self::assertEquals(1, $results->count());
        self::assertIsBool($results->first()->get('bool1'));
        self::assertIsBool($results->first()->get('bool2'));
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testInteger(string $alias): void
    {
        $results = $this->client->run(<<<CYPHER
UNWIND [{num: 1}, {num: 2}, {num: 3}] AS x
RETURN x.num
ORDER BY x.num ASC
CYPHER, [], $alias);

        self::assertEquals(3, $results->count());
        self::assertEquals(1, $results[0]['x.num']);
        self::assertEquals(2, $results[1]['x.num']);
        self::assertEquals(3, $results[2]['x.num']);
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testFloat(string $alias): void
    {
        $results = $this->client->run('RETURN 0.1 AS float', [], $alias);

        self::assertIsFloat($results->first()->get('float'));
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testString(string $alias): void
    {
        $results = $this->client->run('RETURN "abc" AS string', [], $alias);

        self::assertIsString($results->first()->get('string'));
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testDate(string $alias): void
    {
        $query = $this->articlesQuery();
        $query .= 'RETURN article.datePublished as published_at';

        $results = $this->client->run($query, [], $alias);

        self::assertEquals(3, $results->count());

        self::assertInstanceOf(Date::class, $results[0]['published_at']);
        self::assertEquals(18048, $results[0]['published_at']->getDays());

        self::assertInstanceOf(Date::class, $results[1]['published_at']);
        self::assertEquals(18049, $results[1]['published_at']->getDays());

        self::assertInstanceOf(Date::class, $results[2]['published_at']);
        self::assertEquals(18742, $results[2]['published_at']->getDays());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testTime(string $alias): void
    {
        $results = $this->client->run('RETURN time("12:00:00.000000000") AS time', [], $alias);

        $time = $results->first()->get('time');
        self::assertInstanceOf(Time::class, $time);
        self::assertEquals((float) 12 * 60 * 60, $time->getSeconds());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testLocalTime(string $alias): void
    {
        $results = $this->client->run('RETURN time("12:00:00.000000000") AS time', [], $alias);

        $time = $results->first()->get('time');
        self::assertInstanceOf(Time::class, $time);
        self::assertEquals((float) 12 * 60 * 60, $time->getSeconds());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testDateTime(string $alias): void
    {
        $query = $this->articlesQuery();
        $query .= 'RETURN article.created as created_at';

        $results = $this->client->run($query, [], $alias);

        self::assertEquals(3, $results->count());

        self::assertInstanceOf(DateTime::class, $results[0]['created_at']);
        self::assertEquals(1559414432, $results[0]['created_at']->getSeconds());
        self::assertEquals(142000000, $results[0]['created_at']->getNanoseconds());
        self::assertEquals(3600, $results[0]['created_at']->getTimeZoneOffsetSeconds());

        self::assertInstanceOf(DateTime::class, $results[1]['created_at']);
        self::assertEquals(1559471012, $results[1]['created_at']->getSeconds());
        self::assertEquals(122000000, $results[1]['created_at']->getNanoseconds());
        self::assertEquals(3600, $results[1]['created_at']->getTimeZoneOffsetSeconds());

        self::assertInstanceOf(DateTime::class, $results[2]['created_at']);
        self::assertGreaterThan(0, $results[2]['created_at']->getSeconds());
        self::assertGreaterThan(0, $results[2]['created_at']->getNanoseconds());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testLocalDateTime(string $alias): void
    {
        $result = $this->client->run('RETURN localdatetime() as local', [], $alias)->first()->get('local');

        self::assertInstanceOf(LocalDateTime::class, $result);
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testDuration(string $alias): void
    {
        $results = $this->client->run(<<<CYPHER
UNWIND [
  duration({days: 14, hours:16, minutes: 12}),
  duration({months: 5, days: 1.5}),
  duration({months: 0.75}),
  duration({weeks: 2.5}),
  duration({minutes: 1.5, seconds: 1, milliseconds: 123, microseconds: 456, nanoseconds: 789}),
  duration({minutes: 1.5, seconds: 1, nanoseconds: 123456789})
] AS aDuration
RETURN aDuration
CYPHER, [], $alias);

        self::assertEquals(6, $results->count());
        self::assertEquals(new Duration(0, 14, 58320, 0), $results[0]['aDuration']);
        self::assertEquals(new Duration(5, 1, 43200, 0), $results[1]['aDuration']);
        self::assertEquals(new Duration(0, 22, 71509, 500000000), $results[2]['aDuration']);
        self::assertEquals(new Duration(0, 17, 43200, 0), $results[3]['aDuration']);
        self::assertEquals(new Duration(0, 0, 91, 123456789), $results[4]['aDuration']);
        self::assertEquals(new Duration(0, 0, 91, 123456789), $results[5]['aDuration']);

        self::assertEquals(5, $results[1]['aDuration']->getMonths());
        self::assertEquals(1, $results[1]['aDuration']->getDays());
        self::assertEquals(43200, $results[1]['aDuration']->getSeconds());
        self::assertEquals(0, $results[1]['aDuration']->getNanoseconds());
        $interval = new DateInterval(sprintf('P%dM%dDT%dS', 5, 1, 43200));
        self::assertEquals($interval, $results[1]['aDuration']->toDateInterval());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testPoint(string $alias): void
    {
        $point = $this->client->run('RETURN point({x: 3, y: 4}) AS point', [], $alias)->first()->get('point');

        self::assertInstanceOf(CartesianPoint::class, $point);
        self::assertEquals(3.0, $point->getX());
        self::assertEquals(4.0, $point->getY());
        self::assertEquals('cartesian', $point->getCrs());
        self::assertGreaterThan(0, $point->getSrid());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testNode(string $alias): void
    {
        $uuid = 'cc60fd69-a92b-47f3-9674-2f27f3437d66';
        $email = 'a@b.c';
        $type = 'pepperoni';

        $results = $this->client->run(
            'MERGE (u:User{email: $email})-[:LIKES]->(p:Food:Pizza {type: $type}) ON CREATE SET u.uuid=$uuid RETURN u, p',
            ['email' => $email, 'uuid' => $uuid, 'type' => $type], $alias
        );

        self::assertEquals(1, $results->count());

        /** @var Node $u */
        $u = $results[0]['u'];
        self::assertInstanceOf(Node::class, $u);
        self::assertEquals(['User'], $u->labels()->toArray());
        self::assertEquals($email, $u->properties()['email']);
        self::assertEquals($uuid, $u->properties()['uuid']);

        /** @var Node $p */
        $p = $results[0]['p'];
        self::assertInstanceOf(Node::class, $p);
        self::assertEquals(['Food', 'Pizza'], $p->labels()->toArray());
        self::assertEquals($type, $p->properties()['type']);
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testRelationship(string $alias): void
    {
        $this->client->run('MATCH (n) DETACH DELETE n');
        $result = $this->client->run(<<<CYPHER
MERGE (x:X {x: 1}) - [xy:XY {x: 1, y: 1}] -> (y:Y {y: 1})
RETURN xy
CYPHER, [], $alias)->first()->get('xy');

        self::assertInstanceOf(Relationship::class, $result);
        self::assertEquals('XY', $result->getType());
        self::assertEquals(['x' => 1, 'y' => 1], $result->getProperties()->toArray());
    }

    /**
     * @dataProvider transactionProvider
     */
    public function testPath(string $alias): void
    {
        $results = $this->client->run(<<<'CYPHER'
MERGE (b:Node {x:$x}) - [:HasNode {attribute: $xy}] -> (:Node {y:$y}) - [:HasNode {attribute: $yz}] -> (:Node {z:$z})
WITH b
MATCH (x:Node) - [y:HasNode*2] -> (z:Node)
RETURN x, y, z
CYPHER
            , ['x' => 'x', 'xy' => 'xy', 'y' => 'y', 'yz' => 'yz', 'z' => 'z'], $alias);

        self::assertEquals(1, $results->count());
    }

    private function articlesQuery(): string
    {
        return <<<CYPHER
UNWIND [
    { title: "Cypher Basics I",
      created: datetime("2019-06-01T18:40:32.142+0100"),
      datePublished: date("2019-06-01"),
      readingTime: {minutes: 2, seconds: 15} },
    { title: "Cypher Basics II",
      created: datetime("2019-06-02T10:23:32.122+0100"),
      datePublished: date("2019-06-02"),
      readingTime: {minutes: 2, seconds: 30} },
    { title: "Dates, Datetimes, and Durations in Neo4j",
      created: datetime(),
      datePublished: date("2021-04-25"),
      readingTime: {minutes: 3, seconds: 30} }
] AS articleProperties

CREATE (article:Article {title: articleProperties.title})
SET article.created = articleProperties.created,
    article.datePublished = articleProperties.datePublished,
    article.readingTime = duration(articleProperties.readingTime)
CYPHER;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function transactionProvider(): array
    {
        return [
            ['http'],
            ['bolt'],
            ['cluster'],
        ];
    }
}
