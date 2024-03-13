<?php

declare(strict_types=1);

namespace WyriHaximus\React\Mutex;

use React\Promise\PromiseInterface;
use WyriHaximus\AsyncTestUtilities\AsyncTestCase;
use WyriHaximus\React\Mutex\Contracts\LockInterface;
use WyriHaximus\React\Mutex\Contracts\MutexInterface;

use function bin2hex;
use function random_bytes;
use function React\Async\await;
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

    /** @test */
    final public function thatYouCantRequiredTheSameLockTwice(): void
    {
        $key = $this->generateKey();

        $mutex = $this->provideMutex();

        $firstLock  = '';
        $secondLock = '';

        $firstMutexPromise = $mutex->acquire($key, TWO_FLOAT);
        /** @phpstan-ignore-next-line */
        $firstMutexPromise->then(static function (LockInterface|null $lock) use (&$firstLock): void {
            $firstLock = $lock;
        });
        $secondtMutexPromise = timedPromise(ONE)->then(
            static fn (): PromiseInterface => $mutex->acquire($key, TWO_FLOAT),
        );
        /** @phpstan-ignore-next-line */
        $secondtMutexPromise->then(static function (LockInterface|null $lock) use (&$secondLock): void {
            $secondLock = $lock;
        });

        await(all([$firstMutexPromise, $secondtMutexPromise]));

        self::assertInstanceOf(LockInterface::class, $firstLock);
        self::assertNull($secondLock);
    }

    /** @test */
    final public function cannotReleaseLockWithWrongRng(): void
    {
        $key = $this->generateKey();

        $mutex = $this->provideMutex();

        $fakeLock = new LockStub($key, 'rng');

        $mutex->acquire($key, ONE_FLOAT);

        $result = await($mutex->release($fakeLock));
        self::assertFalse($result);
    }

    /** @test */
    final public function spinWillWaiUntil(): void
    {
        $spinAcquireReleaseTime = null;
        $lockReleaseTime        = null;

        $key   = $this->generateKey();
        $mutex = $this->provideMutex();

        $lock = await($mutex->acquire($key, ONE_FLOAT * 100));
        self::assertInstanceOf(LockInterface::class, $lock);

        /** @phpstan-ignore-next-line */
        $spinPromise = $mutex->spin($key, ONE_FLOAT, 13, 3)->then(static function (LockInterface $lock) use (&$spinAcquireReleaseTime): LockInterface {
            $spinAcquireReleaseTime = time();

            return $lock;
        });

        $releasePromise = timedPromise(0.1)->then(static function () use (&$lockReleaseTime, $mutex, $lock): PromiseInterface {
            $lockReleaseTime = time();

            return $mutex->release($lock);
        });

        $result   = await($releasePromise);
        $spinLock = await($spinPromise);

        self::assertTrue($result);
        /** @psalm-suppress PossiblyNullReference */
        self::assertSame($key, $spinLock->key());
        self::assertNotNull($spinAcquireReleaseTime, 'Spin');
        self::assertNotNull($lockReleaseTime, 'Aquire');
        self::assertGreaterThan($lockReleaseTime, $spinAcquireReleaseTime);
    }

    /** @test */
    final public function spinDoesNotLock(): void
    {
        $key   = $this->generateKey();
        $mutex = $this->provideMutex();

        $lock = await($mutex->acquire($key, ONE_FLOAT * 100));
        self::assertInstanceOf(LockInterface::class, $lock);

        $spinPromise = $mutex->spin($key, ONE_FLOAT, 3, 0.001);

        $releasePromise = timedPromise(0.1)->then(static function () use ($mutex, $lock): PromiseInterface {
            return $mutex->release($lock);
        });

        [$result, $spinLock] = await(all([$releasePromise, $spinPromise]));

        self::assertTrue($result);
        self::assertNull($spinLock);
    }

    private function generateKey(): string
    {
        return 'key-' . time() . '-' . bin2hex(random_bytes(TWO));
    }
}
