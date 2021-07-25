<?php

declare(strict_types=1);

namespace WyriHaximus\React\Mutex;

use React\Promise\PromiseInterface;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\React\Mutex\Contracts\LockInterface;
use WyriHaximus\React\Mutex\Contracts\MutexInterface;

use function bin2hex;
use function random_bytes;
use function React\Promise\all;
use function time;
use function WyriHaximus\React\timedPromise;

use const WyriHaximus\Constants\Numeric\ONE;
use const WyriHaximus\Constants\Numeric\ONE_FLOAT;
use const WyriHaximus\Constants\Numeric\TWO;
use const WyriHaximus\Constants\Numeric\TWO_FLOAT;

abstract class AbstractMutexTestCase extends AsyncTestCase
{
    abstract public function provideMutex(): MutexInterface;

    /**
     * @test
     */
    final public function thatYouCantRequiredTheSameLockTwice(): void
    {
        $key = $this->generateKey();

        $mutex = $this->provideMutex();

        $firstLock  = '';
        $secondLock = '';

        /**
         * @psalm-suppress TooManyTemplateParams
         */
        $firstMutexPromise = $mutex->acquire($key, TWO_FLOAT);
        /** @phpstan-ignore-next-line */
        $firstMutexPromise->then(static function (?LockInterface $lock) use (&$firstLock): void {
            $firstLock = $lock;
        });
        $secondtMutexPromise = timedPromise(ONE)->then(
        /**
         * @psalm-suppress TooManyTemplateParams
         */
            static fn (): PromiseInterface => $mutex->acquire($key, TWO_FLOAT)
        );
        /** @phpstan-ignore-next-line */
        $secondtMutexPromise->then(static function (?LockInterface $lock) use (&$secondLock): void {
            $secondLock = $lock;
        });

        $this->await(all([$firstMutexPromise, $secondtMutexPromise]));

        self::assertInstanceOf(LockInterface::class, $firstLock);
        self::assertNull($secondLock);
    }

    /**
     * @test
     */
    final public function cannotReleaseLockWithWrongRng(): void
    {
        $key = $this->generateKey();

        $mutex = $this->provideMutex();

        $fakeLock = new LockStub($key, 'rng');

        /**
         * @psalm-suppress TooManyTemplateParams
         */
        $mutex->acquire($key, ONE_FLOAT);

        /**
         * @psalm-suppress TooManyTemplateParams
         */
        $result = $this->await($mutex->release($fakeLock));
        self::assertFalse($result);
    }

    private function generateKey(): string
    {
        return 'key-' . time() . '-' . bin2hex(random_bytes(TWO));
    }
}
