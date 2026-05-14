<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\Metrics;

/**
 * Shared explicit bucket boundaries for OpenTelemetry duration histograms.
 *
 * Centralizing the boundaries here keeps every Metered* class in the bundle
 * reporting the same bucket layout, so cross-subsystem latency comparisons in
 * backends (Grafana, Tempo, etc.) stay coherent and aggregate well.
 *
 * Values match the boundaries recommended by the OTel HTTP and messaging
 * metric semantic conventions for second-based latency histograms.
 */
final class DurationBoundaries
{
    /**
     * Bucket boundaries (in seconds) applied to every second-based latency
     * histogram emitted by the bundle: messaging.process.duration,
     * messaging.client.operation.duration, http.server.request.duration,
     * http.client.request.duration, db.client.operation.duration.
     *
     * @var list<float|int>
     */
    public const SECONDS = [
        0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10,
    ];

    private function __construct()
    {
    }
}
