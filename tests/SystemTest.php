<?php

use Zikarsky\React\Gearman\ClientInterface;
use Zikarsky\React\Gearman\DuplicateJobException;
use Zikarsky\React\Gearman\Event\TaskDataEvent;
use Zikarsky\React\Gearman\Event\TaskEvent;
use Zikarsky\React\Gearman\JobInterface;
use Zikarsky\React\Gearman\TaskInterface;
use Zikarsky\React\Gearman\WorkerInterface;

/**
 * Class SystemTest
 * @group system
 */
class SystemTest extends PHPUnit_Framework_TestCase
{

    protected function asyncTest(callable $coroutine)
    {
        \Amp\Loop::set((new \Amp\Loop\DriverFactory)->create());
        gc_collect_cycles(); // extensions using an event loop may otherwise leak the file descriptors to the loop
        $result = \Amp\Promise\wait(\Amp\call($coroutine));
        $info = \Amp\Loop::get()->getInfo();
        if ($info['enabled_watchers']['referenced'] > 0) {
            $this->fail("Loop has referenced watchers: " . json_encode($info));
        }
        return $result;
    }

    protected function getFactory()
    {
        $loop = \Amp\ReactAdapter\ReactAdapter::get();
        return new \Zikarsky\React\Gearman\Factory($loop);
    }

    protected function getWorkerAndClient()
    {
        $factory = $this->getFactory();

        /**
         * @var ClientInterface $client
         * @var WorkerInterface $worker
         */
        $client = yield $factory->createClient('127.0.0.1', 4730);
        $worker = yield $factory->createWorker('127.0.0.1', 4730);
        return [$client, $worker];
    }

    protected function getTaskPromise(TaskInterface $task, callable $onTask)
    {
        $deferred = new \Amp\Deferred();

        $watcher = \Amp\Loop::delay(200, function () use ($deferred, $task) {
            $deferred->fail(new Exception("Job timed out: {$task->getWorkload()}"));
        });

        $task->on('complete', function (TaskDataEvent $event, ClientInterface $client) use ($deferred, $onTask, $watcher) {
            \Amp\Loop::cancel($watcher);
            try {
                $onTask($event, $client);
                $deferred->resolve();
            } catch (Throwable $e) {
                $deferred->fail($e);
            }
        });

        $task->on('exception', function (TaskDataEvent $event, ClientInterface $client) use ($deferred, $onTask, $watcher) {
            \Amp\Loop::cancel($watcher);
            $deferred->fail(new Exception($event->getData()));
        });

        $task->on('failure', function (TaskEvent $event, ClientInterface $client) use ($deferred, $onTask, $watcher) {
            \Amp\Loop::cancel($watcher);
            $deferred->fail(new Exception("N/A"));
        });

        return $deferred->promise();
    }

    public function testSubmitAndWork()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            $workerCalled = false;
            $responseReceived = false;
            yield $worker->register($queueName, function (JobInterface $job) use (&$workerCalled) {
                $job->complete($job->getWorkload());
                $workerCalled = true;
            });

            /**
             * @var TaskInterface $task
             */
            $task = yield $client->submit($queueName, 'TestData');

            yield $this->getTaskPromise($task, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $responseReceived = true;
                $this->assertEquals('TestData', $event->getData());
            });

            $this->assertTrue($workerCalled);
            $this->assertTrue($responseReceived);
            $worker->disconnect();
        });
    }

    public function testSubmitBackgroundAndWork()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            $workerCalled = false;

            $task = yield $client->submitBackground($queueName, 'TestData');
            $this->assertInstanceOf(TaskInterface::class, $task);

            $deferred = new \Amp\Deferred();

            $watcher = \Amp\Loop::delay(100, function () use ($deferred) {
                $deferred->fail(new Exception("Job timed out"));
            });

            yield $worker->register($queueName, function (JobInterface $job) use (&$workerCalled, $deferred, $watcher) {
                $job->complete($job->getWorkload());
                $workerCalled = true;
                $this->assertEquals('TestData', $job->getWorkload());
                $deferred->resolve();
                \Amp\Loop::cancel($watcher);
            });

            yield $deferred->promise();
            $this->assertTrue($workerCalled);
            $worker->disconnect();
        });
    }

    public function testSubmitWithUniqueIds()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            /**
             * @var TaskInterface $task1
             * @var TaskInterface $task2
             * @var TaskInterface $task3
             */
            $task1 = yield $client->submit($queueName, 'TestData1', TaskInterface::PRIORITY_NORMAL, '1');
            try {
                yield $client->submit($queueName, 'TestData1a', TaskInterface::PRIORITY_NORMAL, '1');
                $this->fail("DuplicateJobException not thrown");
            } catch (DuplicateJobException $e) {
                // Expected
            }
            $task3 = yield $client->submit($queueName, 'TestData2', TaskInterface::PRIORITY_NORMAL, '2');

            $taskPromise1 = $this->getTaskPromise($task1, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData1', $event->getData());
            });
            $taskPromise3 = $this->getTaskPromise($task3, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData2', $event->getData());
            });

            $deferred = new \Amp\Deferred();

            $jobCalls = [];
            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls, $deferred) {
                $job->complete($job->getWorkload());
                $jobCalls[] = $job->getWorkload();
                if (count($jobCalls) == 2) {
                    $deferred->resolve();
                }
            });

            yield $taskPromise1;
            yield $taskPromise3;

            $this->assertEquals([
                'TestData1',
                'TestData2',
            ], $jobCalls);
            $worker->disconnect();
        });
    }

    public function testSubmitBackgroundWithUniqueIds()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            yield $client->submitBackground($queueName, 'TestData1', TaskInterface::PRIORITY_NORMAL, '1b');
            yield $client->submitBackground($queueName, 'TestData1a', TaskInterface::PRIORITY_NORMAL, '1b');
            yield $client->submitBackground($queueName, 'TestData2', TaskInterface::PRIORITY_NORMAL, '2b');

            $deferred = new \Amp\Deferred();
            $watcher = \Amp\Loop::delay(100, function () use ($deferred) {
                $deferred->fail(new Exception("Job timed out"));
            });

            $jobCalls = [];
            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls, $deferred, $watcher) {
                $job->complete($job->getWorkload());
                $jobCalls[] = $job->getWorkload();
                if (count($jobCalls) == 2) {
                    $deferred->resolve();
                    \Amp\Loop::cancel($watcher);
                }
            });

            yield $deferred->promise();

            sort($jobCalls);
            $this->assertEquals([
                'TestData1',
                'TestData2',
            ], $jobCalls);
            $worker->disconnect();
        });
    }

    public function testSubmitWithPriorities()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            $task1 = yield $client->submit($queueName, 'TestData3', TaskInterface::PRIORITY_LOW, '3');
            try {
                yield $client->submit($queueName, 'TestData3a', TaskInterface::PRIORITY_LOW, '3');
            } catch (Exception $e) {

            }
            $task3 = yield $client->submit($queueName, 'TestData4', TaskInterface::PRIORITY_HIGH, '4');

            $taskPromise1 = $this->getTaskPromise($task1, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData3', $event->getData());
            });
            $taskPromise3 = $this->getTaskPromise($task3, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData4', $event->getData());
            });

            $deferred = new \Amp\Deferred();

            $jobCalls = [];

            $watcher = \Amp\Loop::delay(100, function () use ($deferred) {
                $deferred->fail(new Exception("Job timed out"));
            });

            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls, $deferred, $watcher) {
                $job->complete($job->getWorkload());
                $jobCalls[] = $job->getWorkload();
                if (count($jobCalls) == 2) {
                    \Amp\Loop::cancel($watcher);
                    $deferred->resolve();
                }
            });

            yield $deferred->promise();

            yield $taskPromise1;
            yield $taskPromise3;

            $this->assertEquals([
                'TestData4',
                'TestData3',
            ], $jobCalls);
            $worker->disconnect();
        });
    }

    public function testSubmitBackgroundWithPriorities()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            yield $client->submitBackground($queueName, 'TestData3', TaskInterface::PRIORITY_LOW, '3b');
            yield $client->submitBackground($queueName, 'TestData3a', TaskInterface::PRIORITY_LOW, '3b');
            yield $client->submitBackground($queueName, 'TestData4', TaskInterface::PRIORITY_HIGH, '4b');

            $deferred = new \Amp\Deferred();

            $jobCalls = [];

            $watcher = \Amp\Loop::delay(100, function () use ($deferred) {
                $deferred->fail(new Exception("Job timed out"));
            });

            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls, $deferred, $watcher) {
                $job->complete($job->getWorkload());
                $jobCalls[] = $job->getWorkload();
                if (count($jobCalls) == 2) {
                    \Amp\Loop::cancel($watcher);
                    $deferred->resolve();
                }
            });

            yield $deferred->promise();
            $this->assertEquals([
                'TestData4',
                'TestData3',
            ], $jobCalls);
            $worker->disconnect();
        });
    }

    public function testProgress()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            /**
             * @var TaskInterface $task1
             */
            $task = yield $client->submit($queueName, 'TestData1', TaskInterface::PRIORITY_NORMAL, '1p');

            $dataReceived = [];
            $task->on('data', function (TaskDataEvent $event, ClientInterface $client) use (&$dataReceived) {
                $dataReceived[] = $event->getData();
            });

            $taskPromise1 = $this->getTaskPromise($task, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData1', $event->getData());
            });

            $deferred = new \Amp\Deferred();

            $jobCalls = [];
            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls, $deferred) {
                $job->sendData("Some data");
                $job->complete($job->getWorkload());
                $jobCalls[] = $job->getWorkload();
                $deferred->resolve();
            });

            yield $taskPromise1;

            $this->assertEquals([
                'TestData1'
            ], $jobCalls);
            $this->assertEquals([
                'Some data'
            ], $dataReceived);
            $worker->disconnect();
        });
    }

    public function testException()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            yield $client->setOption('exceptions');
            /**
             * @var TaskInterface $task1
             */
            $task = yield $client->submit($queueName, 'TestData1', TaskInterface::PRIORITY_NORMAL);

            $taskPromise1 = $this->getTaskPromise($task, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData1', $event->getData());
            });

            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls) {
                $job->fail("Reason");
            });

            try {
                yield $taskPromise1;
                $this->fail("Job did not return with error");
            } catch (Exception $e) {
                $this->assertEquals("Reason", $e->getMessage());
            }
            $worker->disconnect();
        });
    }

    public function testError()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            /**
             * @var TaskInterface $task1
             */
            $task = yield $client->submit($queueName, 'TestData1', TaskInterface::PRIORITY_NORMAL);

            $taskPromise1 = $this->getTaskPromise($task, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData1', $event->getData());
            });

            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls) {
                $job->fail();
            });

            try {
                yield $taskPromise1;
                $this->fail("Job did not return with error");
            } catch (Exception $e) {
                $this->assertEquals("N/A", $e->getMessage());
            }
            $worker->disconnect();
        });
    }

    public function testWarning()
    {
        $queueName = __METHOD__;
        $this->asyncTest(function () use ($queueName) {
            /**
             * @var ClientInterface $client
             * @var WorkerInterface $worker
             */
            list($client, $worker) = yield from $this->getWorkerAndClient();

            /**
             * @var TaskInterface $task1
             */
            $task = yield $client->submit($queueName, 'TestData1', TaskInterface::PRIORITY_NORMAL);

            $taskPromise1 = $this->getTaskPromise($task, function (TaskDataEvent $event, ClientInterface $client) use (&$responseReceived) {
                $this->assertEquals('TestData1', $event->getData());
            });

            $warningReceived = null;
            $task->on('warning', function (TaskDataEvent $event, ClientInterface $client) use (&$warningReceived) {
                $warningReceived = $event->getData();
            });

            yield $worker->register($queueName, function (JobInterface $job) use (&$jobCalls) {
                $job->sendWarning('A warning');
                $job->complete('TestData1');
            });

            yield $taskPromise1;
            $this->assertEquals("A warning", $warningReceived);
            $worker->disconnect();
        });
    }

}
