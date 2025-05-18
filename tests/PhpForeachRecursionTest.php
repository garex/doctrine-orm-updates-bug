<?php

namespace Garex\DoctrineOrmUpdatesBug\Tests;

use PHPUnit\Framework\TestCase;

class PhpForeachRecursionTest extends TestCase
{

    public function testWtfEventCount(): void
    {
        $unitOfWork = new class()
        {
            private $entities = [
                0 => 'human',
                1 => 'head',
                2 => 'eye',
            ];

            public $updatedEntities = [];

            public function executeUpdates(): void
            {
                foreach ($this->entities as $oid => $entity) {
                    $this->updatedEntities[] = "$entity#$oid";
                    unset($this->entities[$oid]);
                    $this->onSomeEvent($oid, $entity);
                }
            }

            private function onSomeEvent($oid, $entity): void
            {
                if ('human' === $entity) {
                    $this->executeUpdates();
                }
            }
        };

        $unitOfWork->executeUpdates();
        $this->assertEquals([
            'human#0',
            'head#1',
            'eye#2',
            // These last two items not expected as we are unsetting
            'head#1',
            'eye#2',
        ], $unitOfWork->updatedEntities);
    }


    public function testFixedEventCount(): void
    {
        $unitOfWork = new class()
        {
            private $entities = [
                0 => 'human',
                1 => 'head',
                2 => 'eye',
            ];

            public $updatedEntities = [];

            public function executeUpdates(): void
            {
                foreach ($it = new \ArrayIterator($this->entities) as $oid => $entity) {
                    $this->updatedEntities[] = "$entity#$oid";
                    unset($it[$oid]);
                    unset($this->entities[$oid]);
                    $this->onSomeEvent($oid, $entity);
                }
            }

            private function onSomeEvent($oid, $entity): void
            {
                if ('human' === $entity) {
                    $this->executeUpdates();
                }
            }
        };

        $unitOfWork->executeUpdates();
        $this->assertEquals([
            'human#0',
            'head#1',
            'eye#2',
            // Now those last two items was gone
        ], $unitOfWork->updatedEntities);
    }
}