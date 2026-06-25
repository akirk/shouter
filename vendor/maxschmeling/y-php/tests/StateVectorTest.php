<?php

declare(strict_types=1);

namespace Yjs\Tests;

use PHPUnit\Framework\TestCase;
use Yjs\StateVector;

final class StateVectorTest extends TestCase
{
    public function testStateVectorRoundTrips(): void
    {
        $stateVector = [
            1 => 11,
            42 => 128,
            7 => 0,
        ];

        self::assertEquals($stateVector, StateVector::decode(StateVector::encode($stateVector)));
    }

    public function testStateVectorSortsClientsDescendingLikeYjs(): void
    {
        $encoded = StateVector::encode([
            1 => 11,
            42 => 128,
            7 => 0,
        ]);

        self::assertSame([42 => 128, 7 => 0, 1 => 11], StateVector::decode($encoded));
    }
}
