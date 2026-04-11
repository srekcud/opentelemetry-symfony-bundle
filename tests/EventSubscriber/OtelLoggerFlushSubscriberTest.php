<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class OtelLoggerFlushSubscriberTest extends TestCase
{
    use OTelTestTrait;

    protected function setUp(): void
    {
        $this->setUpOTel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOTel();
    }

    public function testSubscribesToKernelAndConsoleTerminate(): void
    {
        $events = OtelLoggerFlushSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::TERMINATE, $events);
        self::assertArrayHasKey(ConsoleEvents::TERMINATE, $events);

        self::assertSame('flush', $events[KernelEvents::TERMINATE][0]);
        self::assertSame('flush', $events[ConsoleEvents::TERMINATE][0]);
    }

    public function testFlushDoesNotThrowOnKernelTerminate(): void
    {
        $subscriber = new OtelLoggerFlushSubscriber();

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new TerminateEvent($kernel, new Request(), new Response());

        $subscriber->flush($event);

        $this->expectNotToPerformAssertions();
    }
}
