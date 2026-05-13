<?php

declare(strict_types=1);

namespace Traceway\OpenTelemetryBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\DBAL\Driver\Middleware as DoctrineMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\MeteredMiddleware as DoctrineMeteredMiddleware;
use Traceway\OpenTelemetryBundle\Doctrine\Middleware\TraceableMiddleware as DoctrineTraceableMiddleware;
use Traceway\OpenTelemetryBundle\EventSubscriber\ConsoleSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetryMetricsSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OpenTelemetrySubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\OtelLoggerFlushSubscriber;
use Traceway\OpenTelemetryBundle\EventSubscriber\SchedulerSubscriber;
use Traceway\OpenTelemetryBundle\Mailer\TraceableMailer;
use Traceway\OpenTelemetryBundle\Mailer\TraceableTransports;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMetricsMiddleware;
use Traceway\OpenTelemetryBundle\Messenger\OpenTelemetryMiddleware;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistry;
use Traceway\OpenTelemetryBundle\Metrics\MeterRegistryInterface;
use Traceway\OpenTelemetryBundle\Tracing;
use Traceway\OpenTelemetryBundle\Monolog\OtelLogHandler;
use Traceway\OpenTelemetryBundle\Monolog\TraceContextProcessor;
use Traceway\OpenTelemetryBundle\Twig\OpenTelemetryTwigExtension;

final class OpenTelemetryExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($this->isMessengerAvailable()) {
            $middlewares = [];
            if ($config['messenger_enabled']) {
                $middlewares[] = OpenTelemetryMiddleware::class;
            }
            /** @var array{enabled?: bool, messenger?: array{enabled?: bool}} $metrics */
            $metrics = $config['metrics'] ?? [];
            if (($metrics['enabled'] ?? false) && ($metrics['messenger']['enabled'] ?? false)) {
                $middlewares[] = OpenTelemetryMetricsMiddleware::class;
            }
            if ([] !== $middlewares) {
                $container->prependExtensionConfig('framework', [
                    'messenger' => [
                        'buses' => [
                            'messenger.bus.default' => [
                                'middleware' => $middlewares,
                            ],
                        ],
                    ],
                ]);
            }
        }

        if ($config['log_export_enabled']) {
            if (!$container->hasExtension('monolog')) {
                throw new \LogicException(
                    'The "open_telemetry.log_export_enabled" option requires symfony/monolog-bundle to be installed and enabled. Run "composer require symfony/monolog-bundle" or set "log_export_enabled: false".'
                );
            }

            $container->prependExtensionConfig('monolog', [
                'handlers' => [
                    'opentelemetry' => [
                        'type' => 'service',
                        'id' => OtelLogHandler::class,
                    ],
                ],
            ]);

            $handlerDef = new Definition(OtelLogHandler::class);
            $handlerDef->setArgument('$level', $config['log_export_level']);
            $handlerDef->setArgument('$captureCodeAttributes', $config['log_export_capture_code_attributes']);
            $handlerDef->setArgument('$unprefixedAttributes', $config['log_export_unprefixed_attributes']);
            $handlerDef->setAutoconfigured(true);
            $container->setDefinition(OtelLogHandler::class, $handlerDef);

            $flushDef = new Definition(OtelLoggerFlushSubscriber::class);
            $flushDef->setAutoconfigured(true);
            $container->setDefinition(OtelLoggerFlushSubscriber::class, $flushDef);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 2) . '/config'));
        $loader->load('services.yaml');

        $tracerName = \is_string($config['tracer_name']) ? $config['tracer_name'] : 'opentelemetry-symfony';

        $container->getDefinition(Tracing::class)
            ->setArgument('$tracerName', $tracerName);

        $httpClientEnabled = $config['http_client_enabled'] && $this->isHttpClientAvailable();
        $container->setParameter('open_telemetry.http_client_enabled', $httpClientEnabled);
        $container->setParameter('open_telemetry.tracer_name', $tracerName);

        /** @var string[] $httpExcludedHosts */
        $httpExcludedHosts = $config['http_client_excluded_hosts'];
        $container->setParameter('open_telemetry.http_client_excluded_hosts', $httpExcludedHosts);

        if ($config['traces_enabled']) {
            $container->getDefinition(OpenTelemetrySubscriber::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedPaths', $config['excluded_paths'])
                ->setArgument('$recordClientIp', $config['record_client_ip'])
                ->setArgument('$errorStatusThreshold', $config['error_status_threshold']);
        } else {
            $container->removeDefinition(OpenTelemetrySubscriber::class);
        }

        if ($config['console_enabled'] && $this->isConsoleAvailable()) {
            $container->getDefinition(ConsoleSubscriber::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$excludedCommands', $config['console_excluded_commands']);
        } else {
            $container->removeDefinition(ConsoleSubscriber::class);
        }

        $schedulerEnabled = $config['scheduler_enabled'] && $this->isSchedulerAvailable();

        if ($config['messenger_enabled'] && $this->isMessengerAvailable()) {
            $container->getDefinition(OpenTelemetryMiddleware::class)
                ->setArgument('$tracerName', $tracerName)
                ->setArgument('$rootSpans', $config['messenger_root_spans'])
                ->setArgument('$excludeScheduledMessages', $schedulerEnabled);
        } else {
            $container->removeDefinition(OpenTelemetryMiddleware::class);
        }

        if ($schedulerEnabled) {
            $schedulerDef = new Definition(SchedulerSubscriber::class);
            $schedulerDef->setArgument('$tracerName', $tracerName);
            $schedulerDef->setAutoconfigured(true);
            $container->setDefinition(SchedulerSubscriber::class, $schedulerDef);
        }

        if ($config['doctrine_enabled'] && $this->isDoctrineAvailable()) {
            $definition = new Definition(DoctrineTraceableMiddleware::class);
            $definition->setArgument('$tracerName', $tracerName);
            $definition->setArgument('$recordStatements', $config['doctrine_record_statements']);
            $definition->addTag('doctrine.middleware');
            $container->setDefinition(DoctrineTraceableMiddleware::class, $definition);
        }

        $cacheEnabled = $config['cache_enabled'] && $this->isCacheAvailable();
        $container->setParameter('open_telemetry.cache_enabled', $cacheEnabled);
        /** @var string[] $cacheExcludedPools */
        $cacheExcludedPools = $config['cache_excluded_pools'];
        $container->setParameter('open_telemetry.cache_excluded_pools', $cacheExcludedPools);

        if ($config['twig_enabled'] && $this->isTwigAvailable()) {
            /** @var string[] $twigExcluded */
            $twigExcluded = $config['twig_excluded_templates'];
            $twigExtDef = new Definition(OpenTelemetryTwigExtension::class);
            $twigExtDef->setArgument('$tracerName', $tracerName);
            $twigExtDef->setArgument('$excludedTemplates', $twigExcluded);
            $twigExtDef->addTag('twig.extension');
            $container->setDefinition(OpenTelemetryTwigExtension::class, $twigExtDef);
        }

        if ($config['mailer_enabled'] && $this->isMailerAvailable()) {
            $mailerDef = new Definition(TraceableMailer::class);
            $mailerDef->setDecoratedService('mailer.mailer', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            $mailerDef->setArgument('$decorated', new Reference('.inner'));
            $mailerDef->setArgument('$tracerName', $tracerName);
            $mailerDef->setArgument('$recordSubject', $config['mailer_record_subject']);
            $container->setDefinition(TraceableMailer::class, $mailerDef);

            $transportsDef = new Definition(TraceableTransports::class);
            $transportsDef->setDecoratedService('mailer.transports', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
            $transportsDef->setArgument('$decorated', new Reference('.inner'));
            $transportsDef->setArgument('$tracerName', $tracerName);
            $container->setDefinition(TraceableTransports::class, $transportsDef);
        }

        if ($config['monolog_enabled'] && $this->isMonologAvailable()) {
            $monologDef = new Definition(TraceContextProcessor::class);
            $monologDef->addTag('monolog.processor');
            $container->setDefinition(TraceContextProcessor::class, $monologDef);
        }

        /** @var array{enabled: bool, meter_name: string, messenger: array{enabled: bool, excluded_queues: list<string>}, doctrine: array{enabled: bool}, http_server: array{enabled: bool, excluded_paths: list<string>}, http_client: array{enabled: bool, excluded_hosts: list<string>}} $metrics */
        $metrics = $config['metrics'];
        $meterName = $metrics['meter_name'];

        if ($metrics['enabled']) {
            $container->getDefinition(MeterRegistry::class)
                ->setArgument('$meterName', $meterName);
        } else {
            $container->removeDefinition(MeterRegistry::class);
            $container->removeAlias(MeterRegistryInterface::class);
        }

        if ($metrics['enabled'] && $metrics['messenger']['enabled'] && $this->isMessengerAvailable()) {
            $container->getDefinition(OpenTelemetryMetricsMiddleware::class)
                ->setArgument('$meterName', $meterName)
                ->setArgument('$excludedQueues', $metrics['messenger']['excluded_queues']);
        } else {
            $container->removeDefinition(OpenTelemetryMetricsMiddleware::class);
        }

        if ($metrics['enabled'] && $metrics['doctrine']['enabled'] && $this->isDoctrineAvailable()) {
            $definition = new Definition(DoctrineMeteredMiddleware::class);
            $definition->setArgument('$meterName', $meterName);
            $definition->addTag('doctrine.middleware');
            $container->setDefinition(DoctrineMeteredMiddleware::class, $definition);
        }

        if ($metrics['enabled'] && $metrics['http_server']['enabled']) {
            $container->getDefinition(OpenTelemetryMetricsSubscriber::class)
                ->setArgument('$meterName', $meterName)
                ->setArgument('$excludedPaths', $metrics['http_server']['excluded_paths']);
        } else {
            $container->removeDefinition(OpenTelemetryMetricsSubscriber::class);
        }

        $httpClientMetricsEnabled = $metrics['enabled'] && $metrics['http_client']['enabled'] && $this->isHttpClientAvailable();
        $container->setParameter('open_telemetry.http_client_metrics_enabled', $httpClientMetricsEnabled);
        $container->setParameter('open_telemetry.metrics_meter_name', $meterName);
        $container->setParameter('open_telemetry.http_client_metrics_excluded_hosts', $metrics['http_client']['excluded_hosts']);
    }

    private function isConsoleAvailable(): bool
    {
        return class_exists(\Symfony\Component\Console\ConsoleEvents::class);
    }

    private function isMessengerAvailable(): bool
    {
        return interface_exists(MiddlewareInterface::class);
    }

    private function isHttpClientAvailable(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }

    private function isDoctrineAvailable(): bool
    {
        return interface_exists(DoctrineMiddleware::class);
    }

    private function isCacheAvailable(): bool
    {
        return interface_exists(\Symfony\Contracts\Cache\CacheInterface::class);
    }

    private function isTwigAvailable(): bool
    {
        return class_exists(\Twig\Environment::class);
    }

    private function isMonologAvailable(): bool
    {
        return class_exists(\Monolog\Logger::class);
    }

    private function isSchedulerAvailable(): bool
    {
        return interface_exists(\Symfony\Component\Scheduler\ScheduleProviderInterface::class);
    }
    
    private function isMailerAvailable(): bool
    {
        return interface_exists(MailerInterface::class);
    }
}
