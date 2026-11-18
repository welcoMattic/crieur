<?php

declare(strict_types=1);

namespace App\Demo;

use App\Message\ResetDemoLibrary;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The schedule of the public demo: its library is emptied every six hours from midnight UTC. The
 * all-in-one image consumes it on APP_ENV=demo only (messenger:consume scheduler_demo).
 */
#[AsSchedule('demo')]
final readonly class DemoSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(RecurringMessage::trigger(PublicDemo::resetTrigger(), new ResetDemoLibrary()))
            // A reset time missed while the worker restarted is caught up, once.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
