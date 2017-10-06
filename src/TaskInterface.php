<?php

namespace Zikarsky\React\Gearman;

use Evenement\EventEmitterInterface;

/**
 * A representation of a Task on the client-side
 *
 * A task represents the work-package which is submitted to the
 * job-server. It is updated with information from the worker
 * with events.
 *
 * @event complete      TaskDataEvent $event, ClientInterface $client
 * @event status        TaskStatusEvent $event, ClientInterface $client
 * @event warning       TaskDataEvent $event, ClientInterface $client
 * @event failure       TaskEvent $event, ClientInterface $client
 * @event exception     TaskDataEvent $event, ClientInterface $client
 * @event data          TaskDataEvent $event ClientInterface $client
 */
interface TaskInterface extends EventEmitterInterface
{
    const PRIORITY_LOW      = "low";
    const PRIORITY_NORMAL   = "";
    const PRIORITY_HIGH     = "high";

    /**
     * Returns the function-name of the task
     *
     * @return string
     */
    public function getFunction();

    /**
     * Returns the job-handle of the task
     *
     * @return string
     */
    public function getHandle();

    /**
     * Returns the workload of this task
     *
     * @return string
     */
    public function getWorkload();

    /**
     * Returns the task's priority
     *
     * @return string
     */
    public function getPriority();

    /**
     * Returns the task's unique id
     *
     * @return string
     */
    public function getUniqueId();
}
