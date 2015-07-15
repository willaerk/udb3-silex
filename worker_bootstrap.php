<?php

require_once 'vendor/autoload.php';

Resque_Event::listen(
    'beforePerform',
    function (Resque_Job $job) {
        /** @var \Silex\Application $app */
        $app = require __DIR__ . '/bootstrap.php';

        $app->boot();

        $args = $job->getArguments();

        $context = unserialize(base64_decode($args['context']));
        $app['impersonator']->impersonate($context);

        // Allows to access the command bus in perform() of jobs that
        // come out of the queue.
        \CultuurNet\UDB3\CommandHandling\QueueJob::setCommandBus(
            $app['event_command_bus']
        );

        /** @var \Symfony\Component\Stopwatch\Stopwatch $stopwatch */
        $stopwatch = $app['stopwatch'];

        Resque_Event::listen(
            'afterPerform',
            function () use ($stopwatch) {
                foreach ($stopwatch->getSections() as $section) {
                    foreach ($section->getEvents() as $name => $event) {
                        print str_pad($name, 90, '.', STR_PAD_RIGHT) . ' ' . str_pad(number_format($event->getDuration(), 0), 8, ' ', STR_PAD_LEFT) . PHP_EOL;
                    }
                }
            }
        );
    }
);

