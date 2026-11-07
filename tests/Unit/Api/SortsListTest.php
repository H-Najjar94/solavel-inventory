<?php

namespace Tests\Unit\Api;

use App\Http\Controllers\Api\Concerns\SortsList;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SortsListTest extends TestCase
{
    private function probe(): object
    {
        return new class
        {
            use SortsList;

            public bool $columnsExist = true;

            protected function sortColumnExists(string $connection, string $table, string $column): bool
            {
                return $this->columnsExist;
            }

            public function apply($query, Request $r, array $map)
            {
                return $this->applyListSort($query, $r, $map);
            }

            public function resolve(Request $r, array $map): ?array
            {
                return $this->resolveListSort($r, $map);
            }
        };
    }

    #[Test]
    public function only_whitelisted_keys_resolve_and_direction_defaults_to_ascending(): void
    {
        $map = ['number' => 'po_number'];
        $p = $this->probe();
        $this->assertSame(['number', 'asc'], $p->resolve(Request::create('/x', 'GET', ['sort' => 'number']), $map));
        $this->assertSame(['number', 'desc'], $p->resolve(Request::create('/x', 'GET', ['sort' => 'number', 'dir' => 'DESC']), $map));
        $this->assertSame(['number', 'asc'], $p->resolve(Request::create('/x', 'GET', ['sort' => 'number', 'dir' => 'x; drop']), $map));
    }

    #[Test]
    public function unknown_or_malformed_keys_keep_the_default_order(): void
    {
        $map = ['number' => 'po_number'];
        $p = $this->probe();
        $this->assertNull($p->resolve(Request::create('/x', 'GET', ['sort' => 'password']), $map));
        $this->assertNull($p->resolve(Request::create('/x', 'GET', ['sort' => ['number']]), $map));
        $this->assertNull($p->resolve(Request::create('/x', 'GET', []), $map));
    }

    private function fakeQuery(): object
    {
        return new class
        {
            public array $calls = [];

            public function getQuery(): object
            {
                $q = $this;

                return new class($q)
                {
                    public function __construct(private object $q) {}

                    public function getConnection(): object
                    {
                        return new class
                        {
                            public function getName(): string
                            {
                                return 'tenant';
                            }
                        };
                    }

                    public function getGrammar(): object
                    {
                        return new class
                        {
                            public function wrap(string $v): string
                            {
                                return '`'.$v.'`';
                            }
                        };
                    }
                };
            }

            public function getModel(): object
            {
                return new class
                {
                    public function getTable(): string
                    {
                        return 'things';
                    }
                };
            }

            public function __call($name, $args)
            {
                $this->calls[] = $name;

                return $this;
            }
        };
    }

    #[Test]
    public function unverifiable_columns_fall_back_to_the_default_order_without_touching_the_query(): void
    {
        $p = $this->probe();
        $p->columnsExist = false;
        $q = $this->fakeQuery();
        $p->apply($q, Request::create('/x', 'GET', ['sort' => 'number']), ['number' => 'po_number']);
        $this->assertSame([], $q->calls);
    }

    #[Test]
    public function verified_columns_replace_the_default_order(): void
    {
        $p = $this->probe();
        $q = $this->fakeQuery();
        $p->apply($q, Request::create('/x', 'GET', ['sort' => 'number']), ['number' => 'po_number']);
        $this->assertSame('reorder', $q->calls[0]);
        $q2 = $this->fakeQuery();
        $p->apply($q2, Request::create('/x', 'GET', []), ['number' => 'po_number']);
        $this->assertSame([], $q2->calls);
    }
}
