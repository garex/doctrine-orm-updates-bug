<?php

namespace Garex\DoctrineOrmUpdatesBug\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class DuplicateUpdatesBugTest extends TestCase
{
    public function testDemonstrateDuplicates(): void
    {
        $params = [
            'memory' => true,
        ];
        $driver = new Driver();
        $conn = new Connection($params, $driver);
        $logger = new Logger('doctrine-sql');
        $logger->pushHandler(new StreamHandler('php://stderr'));

        if (interface_exists(SQLLogger::class)) {
            $sqlLogger = new class($logger) implements SQLLogger
            {
                /**
                 * @var Logger
                 */
                private $logger;

                public function __construct(Logger $logger)
                {
                    $this->logger = $logger;
                }

                public function startQuery($sql, ?array $params = null, ?array $types = null)
                {
                    $this->logger->debug($sql);
                }
                public function stopQuery() {}
            };
            $conn->getConfiguration()->setSQLLogger($sqlLogger);
        } else {
            $conn->getConfiguration()->setMiddlewares([new LoggingMiddleware($logger)]);
        }
        $logger->warning('Does logger works?');
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
        ], SimplifiedXmlDriver::DEFAULT_FILE_EXTENSION, false);
        $driverChain->addDriver($xmlDriver, 'Garex');

        $config->setMetadataDriverImpl($driverChain);
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('Proxy');

        $em = new EntityManager($conn, $config);

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

class Human
{
    public $id;

    public $name;
}

class HumanReadModel
{
    public $humanId;

    public $humanName;

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

        $em = $event->getObjectManager();
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

class Head
{
    public $humanId;

    public $radius;
}

