# Doctrine ORM UnitOfWork::executeUpdates foreach duplicates bug under recursion

Doctrine ORM v2.16.0 internally starts to execute all updates by single foreach on single UoW property.

Events firing stays inside foreach.

In every iteration property entityUpdates is unsetted. But if we will have recursive call, that will return us to executeUpdates -- then unsetted in later recursive call update will be exceuted on 1st level call.

## Tests

See github actions as demonstration. We don't have duplicates on version 2.15.5 and have em on 2.16.0 till current 3.3.3.

## How to fix?

See PhpForeachRecursionTest as a demo.

Use `ArrayIterator` instead of direct iterating over array.

```php
            public function executeUpdates(): void
            {
                foreach ($it = new \ArrayIterator($this->entities) as $oid => $entity) {
                    $this->updatedEntities[] = "$entity#$oid";
                    unset($it[$oid]); // Unset here also needed
                    unset($this->entities[$oid]);
                    $this->onSomeEvent($oid, $entity);
                }
            }

```

## Why it happens?

```php
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
```