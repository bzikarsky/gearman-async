<?php

namespace Zikarsky\React\Gearman;

use Ramsey\Uuid\Uuid;
use React\Promise\FulfilledPromise;
use React\Promise\PromiseInterface;
use Zikarsky\React\Gearman\Event\TaskDataEvent;
use Zikarsky\React\Gearman\Event\TaskEvent;
use Zikarsky\React\Gearman\Event\TaskStatusEvent;
use Zikarsky\React\Gearman\Protocol\Connection;
use Zikarsky\React\Gearman\Protocol\Participant;
use Zikarsky\React\Gearman\Command\Binary\CommandInterface;
use Zikarsky\React\Gearman\Command\Exception as ProtocolException;
use React\Promise\Promise;

/**
 * A client can submit work requests to a Gearman server
 *
 * All the methods interacting with the server will return promises which get resolved as soon the
 * request is done. The events returned by submit, will get their own events fired, as soon the client receives
 * them
 *
 * @author Benjamin Zikarsky <benjamin@zikarsky.de>
 */
class Client extends Participant implements ClientInterface
{
    /**
     * @var string[]
     */
    private static $workEvents = [
        "WORK_COMPLETE",
        "WORK_STATUS",
        "WORK_FAIL",
        "WORK_EXCEPTION",
        "WORK_DATA",
        "WORK_WARNING"
    ];

    /**
     * @var TaskInterface[]
     */
    protected $tasks = [];

    protected $uniqueTasks = [];

    /**
     * @var int
     */
    protected $pendingActions = 0;

    /**
     * @var \React\Promise\Deferred[]
     */
    protected $waitingPromises = [];

    /**
     * Creates the client on top of the given connection
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);

        foreach (self::$workEvents as $event) {
            $this->getConnection()->on($event, [$this, 'handleWorkEvent']);
        }

        $this->getConnection()->pause();
        $this->on("close", function () {
            foreach ($this->tasks as $task) {
                $task->emit('exception', [new TaskDataEvent($task, 'Lost connection'), $this]);
                $this->setTaskDone($task);
            }
        });
    }

    /**
     * @param $handle
     * @return null|TaskInterface
     */
    protected function getTaskByHandle($handle)
    {
        if (isset($this->tasks[$handle])) {
            return $this->tasks[$handle];
        }

        return null;
    }

    protected function assertUniqueId($uniqueId = null)
    {
        if (empty($uniqueId)) {
            $uniqueId = Uuid::uuid4()->toString();
        }

        return $uniqueId;
    }

    protected function checkUniqueTasks($function, $uniqueId)
    {
        if (isset($this->uniqueTasks[$function . ';' . $uniqueId])) {
            throw new DuplicateJobException("Job with unique id already submitted");
        }

        $this->uniqueTasks[$function . ';' . $uniqueId] = true;
    }

    protected function setTaskDone(TaskInterface $task)
    {
        unset($this->tasks[$task->getHandle()]);

        if ($task->getUniqueId() !== '') {
            unset($this->uniqueTasks[$task->getFunction() . ';' . $task->getUniqueId()]);
        }

        $this->taskEnd();
    }

    /**
     * Submits the given work-request (function, workload) at the given priority
     * The promise resolves with a representing TaskInterface instance as soon the server
     * confirms the queuing of the task
     *
     * @param string $function
     * @param string $workload
     * @param string $priority
     * @param string $uniqueId
     * @return Promise
     */
    public function submit($function, $workload = "", $priority = TaskInterface::PRIORITY_NORMAL, $uniqueId = "")
    {
        $uniqueId = $this->assertUniqueId($uniqueId);
        $this->checkUniqueTasks($function, $uniqueId);

        $type = "SUBMIT_JOB" . ($priority == "" ? "" : "_" . strtoupper($priority));
        $command = $this->getCommandFactory()->create($type, [
            'function_name' => $function,
            'id' => $uniqueId,
            CommandInterface::DATA => $workload
        ]);

        $promise = $this->blockingAction(
            $command,
            "JOB_CREATED",
            function (CommandInterface $submitCmd, CommandInterface $createdCmd) use ($workload, $priority, $uniqueId) {
                $handle = $createdCmd->get('job_handle');
                $task = new Task(
                    $submitCmd->get('function_name'),
                    $workload,
                    $createdCmd->get('job_handle'),
                    $priority,
                    $uniqueId
                );

                $this->taskStart();

                $this->tasks[$handle] = $task;

                $this->emit("task-submitted", [$task, $this]);

                return $task;
            }
        );

        return $promise;
    }

    /**
     * Submits the given work-request (function, workload) at the given priority
     * The promise resolves with a representing TaskInterface instance as soon the server
     * confirms the queuing of the task
     *
     * @param $function
     * @param string $workload
     * @param string $priority
     * @param string $uniqueId
     * @return Promise
     */
    public function submitBackground($function, $workload = "", $priority = TaskInterface::PRIORITY_NORMAL, $uniqueId = "")
    {
        $uniqueId = $this->assertUniqueId($uniqueId);
        $type = "SUBMIT_JOB" . ($priority == "" ? "" : "_" . strtoupper($priority)) . "_BG";
        $command = $this->getCommandFactory()->create($type, [
            'function_name' => $function,
            'id' => $uniqueId,
            CommandInterface::DATA => $workload
        ]);

        $promise = $this->blockingAction(
            $command,
            "JOB_CREATED",
            function (CommandInterface $submitCmd, CommandInterface $createdCmd) use ($workload, $priority, $uniqueId) {
                $task = new Task(
                    $submitCmd->get('function_name'),
                    $workload,
                    $createdCmd->get('job_handle'),
                    $priority,
                    $uniqueId
                );

                $this->emit("task-submitted", [$task, $this]);
                return $task;
            }
        );

        return $promise;
    }

    public function setOption($option)
    {
        if ($option != self::OPTION_FORWARD_EXCEPTIONS) {
            throw new ProtocolException("Unsupported option $option");
        }

        $command = $this->getCommandFactory()->create('OPTION_REQ', [
            'option_name' => $option
        ]);

        return $this->blockingAction($command, 'OPTION_RES', function (CommandInterface $req, CommandInterface $res) {
            $option = $req->get('option_name');
            $success = $option == $res->get('option_name');

            if (!$success) {
                throw new ProtocolException("Failed to set option. Server responded with different option");
            }

            $this->emit("option", [$option, $this]);

            return $option;
        });
    }

    public function getStatus($task)
    {
        $handle = $task instanceof Task ? $task->getHandle() : $task;

        $command = $this->getCommandFactory()->create('GET_STATUS', [
            'job_handle' => $handle
        ]);

        return $this->blockingAction(
            $command,
            "STATUS_RES",
            function (CommandInterface $req, CommandInterface $res) use ($handle, $task) {
                if ($req->get('job_handle') != $res->get('job_handle')) {
                    throw new ProtocolException("Job handle of returned STATUS_RES does not match the requested one");
                }

                $emitter = $task instanceof Task ? $task : $this->getTaskByHandle($handle);

                $event = new TaskStatusEvent(
                    $emitter !== null ? $emitter : new UnknownTask($handle),
                    $res->get('status'),
                    $res->get('running_status'),
                    $res->get('complete_numerator'),
                    $res->get('complete_denominator')
                );

                $emitter = $task instanceof Task ? $task : $this->getTaskByHandle($handle);
                if ($emitter !== null) {
                    $emitter->emit("status", [$event, $this]);
                }

                $this->emit("status", [$event, $this]);

                return $event;
            }
        );
    }

    public function cancel(TaskInterface $task)
    {
        $task->removeAllListeners();
        $this->setTaskDone($task);
    }

    /**
     * Waits until all pending tasks + submits have finished
     *
     * @return PromiseInterface
     */
    public function wait()
    {
        if (!$this->hasPendingTasks()) {
            return new FulfilledPromise();
        }
        $deferred = new \React\Promise\Deferred();
        $this->waitingPromises[] = $deferred;
        return $deferred->promise();
    }

    protected function handleWorkEvent(CommandInterface $command)
    {
        $handle = $command->get('job_handle');
        $task = $this->getTaskByHandle($handle);

        if ($task === null) {
            $this->emit('task-unknown', [$handle, $command->getName()]);
            return;
        }

        switch ($command->getName()) {
            case "WORK_COMPLETE":
                // There can be multiple tasks for a single handle. This can happen if > 1 tasks are submitted with the same unique id from the same client
                // WORK_COMPLETE is triggered for every submitted task. So only process a single one
                $task->emit('complete', [
                    new TaskDataEvent(
                        $task,
                        $command->get(CommandInterface::DATA)
                    ),
                    $this
                ]);
                $this->setTaskDone($task);
                break;
            case "WORK_STATUS":
                $task->emit('status', [
                    new TaskStatusEvent(
                        $task,
                        true,   // known
                        true,   // running
                        $command->get('complete_numerator'),
                        $command->get('complete_denominator')
                    ),
                    $this
                ]);
                break;
            case "WORK_FAIL":
                $task->emit('failure', [new TaskEvent($task), $this]);
                $this->setTaskDone($task);
                break;
            case "WORK_EXCEPTION":
                $task->emit('exception', [new TaskDataEvent($task, $command->get(CommandInterface::DATA)), $this]);
                $this->setTaskDone($task);
                break;
            case "WORK_DATA":
                $task->emit('data', [new TaskDataEvent($task, $command->get(CommandInterface::DATA)), $this]);
                break;
            case "WORK_WARNING":
                $task->emit('warning', [new TaskDataEvent($task, $command->get(CommandInterface::DATA)), $this]);
                break;
            // @codeCoverageIgnoreStart
            // internal safe guard, cannot be tested
            default:
                throw new ProtocolException("Unknown work event $command");
            // @codeCoverageIgnoreEnd
        }
    }

    protected function blockingActionStart()
    {
        parent::blockingActionStart();
        $this->pendingActions++;
        $this->getConnection()->resume();
    }

    protected function blockingActionEnd()
    {
        parent::blockingActionEnd();
        $this->pendingActions--;
        $this->disableReadableIfNoTasks();
    }


    protected function taskStart()
    {
        if (count($this->tasks) == 0) {
            $this->getConnection()->resume();
        }
    }

    protected function taskEnd()
    {
        $this->disableReadableIfNoTasks();
    }

    protected function hasPendingTasks()
    {
        return (count($this->tasks) + $this->pendingActions) > 0;
    }

    protected function disableReadableIfNoTasks()
    {
        if (!$this->hasPendingTasks()) {
            $this->getConnection()->pause();
            foreach ($this->waitingPromises as $promise) {
                $promise->resolve();
            }
        }
    }
}
