<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests\Mailer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Traceway\OpenTelemetryBundle\Mailer\MeteredTransports;
use Traceway\OpenTelemetryBundle\Mailer\TraceableTransports;
use Traceway\OpenTelemetryBundle\Tests\OTelTestTrait;

final class MeteredTransportsTest extends TestCase
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

    public function testSendEmitsCounterAndDuration(): void
    {
        $sent = new SentMessage(
            $this->buildEmail(),
            new Envelope(new Address('sender@example.com'), [new Address('alice@example.com')]),
        );

        $transports = new MeteredTransports($this->fixedReturn($sent), 'test');
        $transports->send($this->buildEmail());

        $metrics = $this->collectMetrics();

        self::assertArrayHasKey('messaging.client.sent.messages', $metrics);
        $counter = $metrics['messaging.client.sent.messages'];
        self::assertSame('{message}', $counter->unit);

        $counterPoints = [...$counter->data->dataPoints];
        self::assertCount(1, $counterPoints);
        self::assertSame(1, $counterPoints[0]->value);

        $attr = $counterPoints[0]->attributes->toArray();
        self::assertSame('symfony_mailer', $attr['messaging.system']);
        self::assertSame('send', $attr['messaging.operation.name']);
        self::assertSame('send', $attr['messaging.operation.type']);
        self::assertArrayNotHasKey('error.type', $attr);
        self::assertArrayNotHasKey('messaging.destination.name', $attr);

        self::assertArrayHasKey('messaging.client.operation.duration', $metrics);
        $hist = $metrics['messaging.client.operation.duration'];
        self::assertSame('s', $hist->unit);

        $histPoints = [...$hist->data->dataPoints];
        self::assertCount(1, $histPoints);
        self::assertSame(1, $histPoints[0]->count);
        self::assertGreaterThanOrEqual(0.0, $histPoints[0]->sum);
    }

    public function testXTransportHeaderBecomesDestinationName(): void
    {
        $sent = new SentMessage(
            $this->buildEmail(),
            new Envelope(new Address('sender@example.com'), [new Address('alice@example.com')]),
        );

        $transports = new MeteredTransports($this->fixedReturn($sent), 'test');

        $email = $this->buildEmail();
        $email->getHeaders()->addTextHeader('X-Transport', 'alerts');
        $transports->send($email);

        $metrics = $this->collectMetrics();
        $counterAttr = [...$metrics['messaging.client.sent.messages']->data->dataPoints][0]->attributes->toArray();
        $histAttr = [...$metrics['messaging.client.operation.duration']->data->dataPoints][0]->attributes->toArray();

        self::assertSame('alerts', $counterAttr['messaging.destination.name']);
        self::assertSame('alerts', $histAttr['messaging.destination.name']);
    }

    public function testFailurePathRecordsErrorTypeAndRethrows(): void
    {
        $inner = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('connection refused');
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };

        $transports = new MeteredTransports($inner, 'test');

        try {
            $transports->send($this->buildEmail());
            self::fail('Expected TransportException');
        } catch (TransportException $e) {
            self::assertSame('connection refused', $e->getMessage());
        }

        $metrics = $this->collectMetrics();

        $counterAttr = [...$metrics['messaging.client.sent.messages']->data->dataPoints][0]->attributes->toArray();
        self::assertSame(TransportException::class, $counterAttr['error.type']);

        $histAttr = [...$metrics['messaging.client.operation.duration']->data->dataPoints][0]->attributes->toArray();
        self::assertSame(TransportException::class, $histAttr['error.type']);
    }

    public function testNullSentMessageStillRecords(): void
    {
        $transports = new MeteredTransports($this->fixedReturn(null), 'test');
        $transports->send($this->buildEmail());

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('messaging.client.sent.messages', $metrics);
        self::assertArrayHasKey('messaging.client.operation.duration', $metrics);
    }

    public function testToStringDelegates(): void
    {
        $inner = new class implements TransportInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return null;
            }

            public function __toString(): string
            {
                return 'smtp://relay.example.com:25';
            }
        };

        $transports = new MeteredTransports($inner, 'test');
        self::assertSame('smtp://relay.example.com:25', (string) $transports);
    }

    /**
     * Integration check that mirrors the runtime DI stack: TraceableTransports
     * (decoration priority 0, outer) wraps MeteredTransports (priority 8, inner).
     * Confirms both decorators fire on a single send and agree on the destination
     * attribute, which is the contract callers rely on for joining metrics to
     * traces in a backend.
     *
     * If a future refactor accidentally swaps the priorities, MeteredTransports
     * ends up outside the trace span scope and exemplar linkage silently breaks
     * — this test would still pass (both fire, attributes still agree). Catching
     * the priority direction itself would need an SDK-level exemplar assertion,
     * which is out of scope here. This test guards the weaker but still useful
     * invariant: both decorators are wired and observe a consistent view.
     */
    public function testWrappedByTraceableTransportsBothEmit(): void
    {
        $sent = new SentMessage(
            $this->buildEmail(),
            new Envelope(new Address('sender@example.com'), [new Address('alice@example.com')]),
        );
        $sent->setMessageId('integration-1@example.com');

        $inner = $this->fixedReturn($sent);
        $metered = new MeteredTransports($inner, 'test');
        $traceable = new TraceableTransports($metered, 'test');

        $email = $this->buildEmail();
        $email->getHeaders()->addTextHeader('X-Transport', 'primary');
        $traceable->send($email);

        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        $spanAttr = $spans[0]->getAttributes()->toArray();

        $metrics = $this->collectMetrics();
        self::assertArrayHasKey('messaging.client.operation.duration', $metrics);
        self::assertArrayHasKey('messaging.client.sent.messages', $metrics);

        $metricAttr = [...$metrics['messaging.client.sent.messages']->data->dataPoints][0]->attributes->toArray();

        self::assertSame($spanAttr['messaging.system'], $metricAttr['messaging.system']);
        self::assertSame($spanAttr['messaging.operation.name'], $metricAttr['messaging.operation.name']);
        self::assertSame($spanAttr['messaging.destination.name'], $metricAttr['messaging.destination.name']);
    }

    public function testResetClearsCachedInstruments(): void
    {
        $transports = new MeteredTransports($this->fixedReturn(null), 'test');
        $transports->send($this->buildEmail());

        // No exception expected; reset() should leave the decorator usable for a second cycle.
        $transports->reset();
        $transports->send($this->buildEmail());

        $metrics = $this->collectMetrics();
        $counterPoints = [...$metrics['messaging.client.sent.messages']->data->dataPoints];

        $total = 0;
        foreach ($counterPoints as $point) {
            $total += $point->value;
        }
        self::assertSame(2, $total);
    }

    private function buildEmail(): Email
    {
        return (new Email())
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Hi')
            ->text('Body');
    }

    private function fixedReturn(?SentMessage $sent): TransportInterface
    {
        return new class($sent) implements TransportInterface {
            public function __construct(private readonly ?SentMessage $sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return $this->sent;
            }

            public function __toString(): string
            {
                return 'fake://';
            }
        };
    }
}
