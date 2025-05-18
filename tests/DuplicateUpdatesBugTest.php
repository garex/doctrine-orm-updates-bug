<?php

namespace Garex\DoctrineOrmUpdatesBug\Tests;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\EntityListeners;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;

final class DuplicateUpdatesBugTest extends TestCase
{
    public function testDemonstrateDuplicates(): void
    {
        $params = [
            'memory' => true,
        ];
        $driver = new Driver();
        $conn = new Connection($params, $driver);
        if (interface_exists(SQLLogger::class)) {
            $logger = new class() implements SQLLogger
            {
                public function startQuery($sql, ?array $params = null, ?array $types = null)
                {
                    echo "\n$sql\n";
                }
                public function stopQuery() {}
            };
            $conn->getConfiguration()->setSQLLogger($logger);
        }
        if (class_exists(LoggingMiddleware::class)) {
            $logger = new Logger('doctrine-sql');
            $conn->getConfiguration()->setMiddlewares([new LoggingMiddleware($logger)]);
        }
        $conn->executeStatement(<<<SQL
        CREATE TABLE humans(
            id INTEGER PRIMARY KEY,
            name CHAR(255)
        )
        SQL);
        $conn->executeStatement(<<<SQL
        CREATE TABLE heads(
            human_id INTEGER,
            radius INTEGER
        )
        SQL);
        $conn->executeStatement(<<<SQL
        CREATE TABLE human_read_models(
            human_id INTEGER,
            human_name CHAR(255),
            head_radius INTEGER
        )
        SQL);
        $config = new Configuration();
        $driverChain = new MappingDriverChain();

        $xmlDriver = new SimplifiedXmlDriver([
            __DIR__ => 'Garex\\DoctrineOrmUpdatesBug\\Tests'
        ]);
        $driverChain->addDriver($xmlDriver, 'Garex');

        $config->setMetadataDriverImpl($driverChain);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('Proxy');

        $em = EntityManager::create($conn, $config);

        $listener = new HumanOrHeadListener($em);
        $config->getEntityListenerResolver()->register($listener);

        $human = new Human();
        $human->id = 1;
        $human->name = 'Qqq Www Eee';

        $em->persist($human);
        $em->flush();

        $head = new Head();
        $head->humanId = $human->id;
        $head->radius = 9;

        $em->persist($head);
        $em->flush();

        $human->name = 'Www Eee';
        $head->radius = 10;

        $em->flush();

        $this->assertEquals([
            'HumanOrHeadListener::postPersist Human',
            'HumanOrHeadListener::postPersist Head',
            'HumanOrHeadListener::postUpdate Head',
            'HumanOrHeadListener::postUpdate Human',
//             'HumanOrHeadListener::postUpdate - Human', // Should not happen!
        ], $listener->eventsLog);
    }
}

/**
 * @Entity()
 * @EntityListeners({"HumanOrHeadListener"})
 * @Table(name="humans")
 */
class Human
{
    /**
     * @Id()
     * @Column(type="integer")
     * @GeneratedValue()
     */
    public $id;

    /**
     * @Column(type="string")
     */
    public $name;
}

/**
 * @Entity()
 * @Table(name="human_read_models")
 */
class HumanReadModel
{
    /**
     * @Id()
     * @Column(type="integer", name="human_id")
     */
    public $humanId;

    /**
     * @Column(type="string", name="human_name")
     */
    public $humanName;

    /**
     * @Column(type="integer", name="head_radius")
     */
    public $headRadius;
}

class HumanOrHeadListener
{
    public $eventsLog = [];

    public function postPersist($entity, LifecycleEventArgs $event)
    {
        $this->denormalize($entity, $event, 'HumanOrHeadListener::'.__FUNCTION__);
    }

    public function postUpdate($entity, LifecycleEventArgs $event)
    {
        $this->denormalize($entity, $event, 'HumanOrHeadListener::'.__FUNCTION__);
    }

    private function denormalize($entity, LifecycleEventArgs $event, string $method): void
    {
        $reflection = new \ReflectionClass($entity);
        $shortClass = $reflection->getShortName();
        echo "$method - $shortClass\n";

        $this->eventsLog[] = "$method $shortClass";

        $em = $event->getEntityManager();
        if ($entity instanceof Human) {
            $humanId = $entity->id;
        }
        if ($entity instanceof Head) {
            $humanId = $entity->humanId;
        }
        $readModel = $em->find(HumanReadModel::class, $humanId);
        if (null === $readModel) {
            $readModel = new HumanReadModel();
            $readModel->humanId = $humanId;
            $em->persist($readModel);
        }
        if ($entity instanceof Human) {
            $readModel->humanName = $entity->name;
        }
        if ($entity instanceof Head) {
            $readModel->headRadius = $entity->radius;
        }

        $em->flush();
    }
}

/**
 * @Entity()
 * @EntityListeners({"HumanOrHeadListener"})
 * @Table(name="heads")
 */
class Head
{
    /**
     * @Id()
     * @Column(type="integer", name="human_id")
     */
    public $humanId;

    /**
     * @Column(type="integer")
     */
    public $radius;
}

