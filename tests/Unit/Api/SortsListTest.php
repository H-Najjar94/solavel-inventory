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
}
