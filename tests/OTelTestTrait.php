<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Tests;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LoggerProviderBuilder;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

trait OTelTestTrait
{
    protected InMemoryExporter $exporter;
    protected InMemoryLogExporter $logExporter;

    protected function setUpOTel(): void
    {
        Globals::reset();
        $this->exporter = new InMemoryExporter();
        $this->logExporter = new InMemoryLogExporter();

        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $loggerProvider = (new LoggerProviderBuilder())
            ->addLogRecordProcessor(new SimpleLogRecordProcessor($this->logExporter))
            ->build();

        Globals::registerInitializer(fn (Configurator $configurator) => $configurator
            ->withTracerProvider($tracerProvider)
            ->withLoggerProvider($loggerProvider)
            ->withPropagator(TraceContextPropagator::getInstance()));
    }

    protected function tearDownOTel(): void
    {
        Globals::reset();
    }
}
