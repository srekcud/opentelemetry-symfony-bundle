<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Monolog;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\Severity;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Monolog handler that exports log records via the OpenTelemetry Logs API.
 *
 * Each Monolog channel becomes its own OTel instrumentation scope, matching
 * how the official opentelemetry-logger-monolog contrib handler does it.
 * Trace context is correlated by the SDK from the active span on emit.
 */
final class OtelLogHandler extends AbstractProcessingHandler implements ResetInterface
{
    /** @var array<string, LoggerInterface> */
    private array $loggers = [];
    private readonly NormalizerFormatter $normalizer;

    /**
     * Guards against recursion when the OTel export path itself produces a log record
     * (e.g. the OTLP HTTP exporter logging on a failed send). Without this, SimpleLogRecordProcessor
     * turns such a log into an infinite loop, and BatchLogRecordProcessor's forceFlush loses records.
     */
    private bool $emitting = false;

    public function __construct(
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        $this->normalizer = new NormalizerFormatter();
    }

    protected function write(LogRecord $record): void
    {
        if ($this->emitting) {
            return;
        }

        $this->emitting = true;
        try {
            $builder = $this->getLogger($record->channel)
                ->logRecordBuilder()
                ->setTimestamp(((int) $record->datetime->format('Uu')) * 1000)
                ->setSeverityNumber(Severity::fromPsr3($record->level->toPsrLogLevel()))
                ->setSeverityText($record->level->getName())
                ->setBody($record->message)
                ->setAttribute('monolog.channel', $record->channel);

            foreach ($record->context as $key => $value) {
                if ('exception' === $key && $value instanceof \Throwable) {
                    $builder->setException($value);
                    continue;
                }
                $builder->setAttribute('monolog.context.' . $key, $this->toAttributeValue($value));
            }

            foreach ($record->extra as $key => $value) {
                // The SDK sets trace_id/span_id natively on the LogRecord from the active span.
                // TraceContextProcessor also writes them into extra for non-OTel handlers — skip the duplicate here.
                if ('trace_id' === $key || 'span_id' === $key) {
                    continue;
                }
                $builder->setAttribute('monolog.extra.' . $key, $this->toAttributeValue($value));
            }

            $builder->emit();
        } catch (\Throwable $e) {
            // A logging handler must never take down the thing being logged about.
            error_log(sprintf('OtelLogHandler: failed to export log record: %s', $e->getMessage()));
        } finally {
            $this->emitting = false;
        }
    }

    public function reset(): void
    {
        parent::reset();
        $this->loggers = [];
        $this->emitting = false;
    }

    private function getLogger(string $channel): LoggerInterface
    {
        return $this->loggers[$channel] ??= Globals::loggerProvider()->getLogger($channel);
    }

    /**
     * @return bool|int|float|string|array<int, bool|int|float|string|null>|null
     */
    private function toAttributeValue(mixed $value): bool|int|float|string|array|null
    {
        if (null === $value || \is_scalar($value)) {
            return $value;
        }

        $normalized = $this->normalizer->normalizeValue($value);

        if (null === $normalized || \is_scalar($normalized)) {
            return $normalized;
        }

        if (array_is_list($normalized)) {
            $scalarList = [];
            foreach ($normalized as $item) {
                if (null !== $item && !\is_scalar($item)) {
                    $scalarList = null;
                    break;
                }
                $scalarList[] = $item;
            }
            if (null !== $scalarList) {
                return $scalarList;
            }
        }

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[unserializable]';
    }
}
