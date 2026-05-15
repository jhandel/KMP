<?php

declare(strict_types=1);

namespace Queue\Test\TestCase\Queue;

use App\Services\Tenant\TenantContext;
use App\Services\Tenant\TenantContextAccessor;
use Cake\Console\CommandInterface;
use Cake\Console\ConsoleIo;
use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Psr\Log\NullLogger;
use Queue\Model\Entity\QueuedJob;
use Queue\Model\QueueException;
use Queue\Model\Table\QueuedJobsTable;
use Queue\Console\Io;
use Queue\Queue\Processor;
use Queue\Queue\TaskInterface;
use Shim\TestSuite\ConsoleOutput;
use Shim\TestSuite\TestTrait;

class ProcessorTest extends TestCase
{

	use TestTrait;

	/**
	 * @var array<string>
	 */
	protected array $fixtures = [
		'plugin.Queue.QueuedJobs',
		'plugin.Queue.QueueProcesses',
	];

	/**
	 * @var \Queue\Queue\Processor
	 */
	protected $Processor;

	/**
	 * @return void
	 */
	public function setUp(): void
	{
		parent::setUp();
		TenantContextAccessor::set(null);
		TenantContext::clearCurrent();

		Configure::write('Queue', [
			'sleeptime' => 1,
			'defaultworkertimeout' => 3,
			'workermaxruntime' => 3,
			'cleanuptimeout' => 10,
			'exitwhennothingtodo' => false,
		]);
	}

	public function tearDown(): void
	{
		TenantContextAccessor::set(null);
		TenantContext::clearCurrent();
		parent::tearDown();
	}

	/**
	 * Set an inaccessible object property for processor unit tests.
	 *
	 * @param object $object Object to update
	 * @param string $property Property name
	 * @param mixed $value Value to assign
	 * @return void
	 */
	protected function setProperty(object $object, string $property, mixed $value): void
	{
		$reflection = new \ReflectionObject($object);
		$reflectionProperty = $reflection->getProperty($property);
		$reflectionProperty->setValue($object, $value);
	}

	/**
	 * @return void
	 */
	public function testStringToArray()
	{
		$this->Processor = new Processor(new Io(new ConsoleIo()), new NullLogger());

		$string = 'Foo,Bar,';
		$result = $this->invokeMethod($this->Processor, 'stringToArray', [$string]);

		$expected = [
			'Foo',
			'Bar',
		];
		$this->assertSame($expected, $result);
	}

	/**
	 * @return void
	 */
	public function testTimeNeeded()
	{
		$this->Processor = new Processor(new Io(new ConsoleIo()), new NullLogger());

		$result = $this->invokeMethod($this->Processor, 'timeNeeded');
		$this->assertMatchesRegularExpression('/\d+s/', $result);
	}

	/**
	 * @return void
	 */
	public function testMemoryUsage()
	{
		$this->Processor = new Processor(new Io(new ConsoleIo()), new NullLogger());

		$result = $this->invokeMethod($this->Processor, 'memoryUsage');
		$this->assertMatchesRegularExpression('/^\d+MB/', $result, 'Should be e.g. `17MB` or `17MB/1GB` etc.');
	}

	/**
	 * @return void
	 */
	public function testRun()
	{
		$this->_needsConnection();

		$out = new ConsoleOutput();
		$err = new ConsoleOutput();
		$this->Processor = new Processor(new Io(new ConsoleIo($out, $err)), new NullLogger());

		$config = [
			'verbose' => true,
		];
		$result = $this->Processor->run($config);

		$this->assertSame(CommandInterface::CODE_SUCCESS, $result);
	}

	public function testRestoreTenantContextSetsAmbientContextFromJobPayload(): void
	{
		$this->Processor = new Processor(new Io(new ConsoleIo()), new NullLogger());
		$data = [
			'job' => 'payload',
			'__tenant_context' => [
				'id' => 17,
				'slug' => 'tenant-a',
				'displayName' => 'Tenant A',
				'status' => 'active',
				'schemaVersion' => '2026.04',
				'primaryHost' => 'tenant-a.example.org',
				'resolvedHost' => 'tenant-a.example.org',
			],
		];
		$method = new \ReflectionMethod(Processor::class, 'restoreTenantContext');
		$method->setAccessible(true);
		$temporary = $method->invokeArgs($this->Processor, [&$data]);

		$this->assertTrue($temporary);
		$this->assertArrayNotHasKey('__tenant_context', $data);
		$this->assertSame(17, TenantContext::getCurrent()?->id);
		$this->assertSame('tenant-a', TenantContextAccessor::get()?->slug);
	}

	public function testRestoreTenantContextRejectsCrossTenantPayload(): void
	{
		TenantContext::setCurrent(new TenantContext(
			9,
			'tenant-worker',
			'Tenant Worker',
			'active',
			null,
			'tenant-worker.example.org',
			'tenant-worker.example.org',
		));
		$this->Processor = new Processor(new Io(new ConsoleIo()), new NullLogger());
		$data = [
			'__tenant_context' => [
				'id' => 10,
				'slug' => 'tenant-job',
				'displayName' => 'Tenant Job',
				'status' => 'active',
				'resolvedHost' => 'tenant-job.example.org',
			],
		];
		$method = new \ReflectionMethod(Processor::class, 'restoreTenantContext');
		$method->setAccessible(true);

		$this->expectException(QueueException::class);
		$method->invokeArgs($this->Processor, [&$data]);
	}

	public function testClearTemporaryTenantContextClearsAmbientContextWhenRequested(): void
	{
		$context = new TenantContext(
			11,
			'tenant-clear',
			'Tenant Clear',
			'active',
			null,
			'tenant-clear.example.org',
			'tenant-clear.example.org',
		);
		TenantContext::setCurrent($context);
		TenantContextAccessor::set($context);
		$this->Processor = new Processor(new Io(new ConsoleIo()), new NullLogger());

		$this->invokeMethod($this->Processor, 'clearTemporaryTenantContext', [true]);

		$this->assertNull(TenantContext::getCurrent());
		$this->assertNull(TenantContextAccessor::get());
	}

	public function testRunJobSetsTenantContextBeforeExecutionAndClearsAfterSuccess(): void
	{
		$seenContext = [];
		$task = new class ($seenContext) implements TaskInterface {
			/** @var array<string, mixed> */
			private array $seenContext;

			/**
			 * @param array<string, mixed> $seenContext
			 */
			public function __construct(array &$seenContext) {
				$this->seenContext = &$seenContext;
			}

			public function run(array $data, int $jobId): void {
				$this->seenContext = [
					'jobId' => $jobId,
					'tenantId' => TenantContext::getCurrent()?->id,
					'tenantSlug' => TenantContextAccessor::get()?->slug,
					'payloadHasTenantMeta' => array_key_exists('__tenant_context', $data),
				];
			}
		};

		$processor = $this->getMockBuilder(Processor::class)
			->setConstructorArgs([new Io(new ConsoleIo()), new NullLogger()])
			->onlyMethods(['loadTask'])
			->getMock();
		$processor->expects($this->once())
			->method('loadTask')
			->with('TenantAwareTask')
			->willReturn($task);

		$queuedJobs = $this->createMock(QueuedJobsTable::class);
		$queuedJobs->expects($this->once())
			->method('markJobDone')
			->willReturn(true);
		$this->setProperty($processor, 'QueuedJobs', $queuedJobs);

		$this->invokeMethod($processor, 'runJob', [
			$this->buildQueuedJob(201, 'TenantAwareTask', $this->tenantData(7, 'tenant-a')),
			'pid-success',
		]);

		$this->assertSame(201, $seenContext['jobId']);
		$this->assertSame(7, $seenContext['tenantId']);
		$this->assertSame('tenant-a', $seenContext['tenantSlug']);
		$this->assertFalse($seenContext['payloadHasTenantMeta']);
		$this->assertNull(TenantContext::getCurrent());
		$this->assertNull(TenantContextAccessor::get());
	}

	public function testRunJobClearsTenantContextAfterFailure(): void
	{
		$seenContext = [];
		$task = new class ($seenContext) implements TaskInterface {
			/** @var array<string, mixed> */
			private array $seenContext;

			/**
			 * @param array<string, mixed> $seenContext
			 */
			public function __construct(array &$seenContext) {
				$this->seenContext = &$seenContext;
			}

			public function run(array $data, int $jobId): void {
				$this->seenContext = [
					'jobId' => $jobId,
					'tenantId' => TenantContext::getCurrent()?->id,
					'tenantSlug' => TenantContextAccessor::get()?->slug,
				];
				throw new \RuntimeException('Fail on purpose');
			}
		};

		$processor = $this->getMockBuilder(Processor::class)
			->setConstructorArgs([new Io(new ConsoleIo()), new NullLogger()])
			->onlyMethods(['loadTask', 'getTaskConf'])
			->getMock();
		$processor->expects($this->once())
			->method('loadTask')
			->with('FailingTenantTask')
			->willReturn($task);
		$processor->expects($this->once())
			->method('getTaskConf')
			->willReturn([]);

		$queuedJobs = $this->createMock(QueuedJobsTable::class);
		$queuedJobs->expects($this->never())
			->method('markJobDone');
		$queuedJobs->expects($this->once())
			->method('markJobFailed')
			->willReturn(true);
		$queuedJobs->expects($this->once())
			->method('getFailedStatus')
			->willReturn('finished as failed');
		$this->setProperty($processor, 'QueuedJobs', $queuedJobs);

		$this->invokeMethod($processor, 'runJob', [
			$this->buildQueuedJob(202, 'FailingTenantTask', $this->tenantData(8, 'tenant-b')),
			'pid-failure',
		]);

		$this->assertSame(202, $seenContext['jobId']);
		$this->assertSame(8, $seenContext['tenantId']);
		$this->assertSame('tenant-b', $seenContext['tenantSlug']);
		$this->assertNull(TenantContext::getCurrent());
		$this->assertNull(TenantContextAccessor::get());
	}

	public function testRunJobClearsTenantContextWhenMarkJobDoneThrows(): void
	{
		$task = new class implements TaskInterface {
			public function run(array $data, int $jobId): void {
			}
		};
		$processor = $this->getMockBuilder(Processor::class)
			->setConstructorArgs([new Io(new ConsoleIo()), new NullLogger()])
			->onlyMethods(['loadTask'])
			->getMock();
		$processor->expects($this->once())
			->method('loadTask')
			->willReturn($task);

		$queuedJobs = $this->createMock(QueuedJobsTable::class);
		$queuedJobs->expects($this->once())
			->method('markJobDone')
			->willThrowException(new \RuntimeException('mark done failed'));
		$this->setProperty($processor, 'QueuedJobs', $queuedJobs);

		try {
			$this->invokeMethod($processor, 'runJob', [
				$this->buildQueuedJob(205, 'TenantAwareTask', $this->tenantData(19, 'tenant-three')),
				'pid-done-throw',
			]);
			$this->fail('Expected runtime exception from markJobDone');
		} catch (\RuntimeException $exception) {
			$this->assertSame('mark done failed', $exception->getMessage());
		}

		$this->assertNull(TenantContext::getCurrent());
		$this->assertNull(TenantContextAccessor::get());
	}

	public function testRunJobClearsTenantContextWhenMarkJobFailedThrows(): void
	{
		$task = new class implements TaskInterface {
			public function run(array $data, int $jobId): void {
				throw new \RuntimeException('task failed');
			}
		};
		$processor = $this->getMockBuilder(Processor::class)
			->setConstructorArgs([new Io(new ConsoleIo()), new NullLogger()])
			->onlyMethods(['loadTask'])
			->getMock();
		$processor->expects($this->once())
			->method('loadTask')
			->willReturn($task);

		$queuedJobs = $this->createMock(QueuedJobsTable::class);
		$queuedJobs->expects($this->once())
			->method('markJobFailed')
			->willThrowException(new \RuntimeException('mark failed failed'));
		$this->setProperty($processor, 'QueuedJobs', $queuedJobs);

		try {
			$this->invokeMethod($processor, 'runJob', [
				$this->buildQueuedJob(206, 'FailingTenantTask', $this->tenantData(20, 'tenant-four')),
				'pid-failed-throw',
			]);
			$this->fail('Expected runtime exception from markJobFailed');
		} catch (\RuntimeException $exception) {
			$this->assertSame('mark failed failed', $exception->getMessage());
		}

		$this->assertNull(TenantContext::getCurrent());
		$this->assertNull(TenantContextAccessor::get());
	}

	public function testRunJobDoesNotLeakTenantContextBetweenSequentialJobs(): void
	{
		$jobOneSeenTenant = null;
		$jobTwoSeenTenant = null;
		$firstTask = new class ($jobOneSeenTenant) implements TaskInterface {
			private ?int $jobSeenTenantId;

			public function __construct(?int &$jobSeenTenantId) {
				$this->jobSeenTenantId = &$jobSeenTenantId;
			}

			public function run(array $data, int $jobId): void {
				$this->jobSeenTenantId = TenantContext::getCurrent()?->id;
			}
		};
		$secondTask = new class ($jobTwoSeenTenant) implements TaskInterface {
			private ?int $jobSeenTenantId;

			public function __construct(?int &$jobSeenTenantId) {
				$this->jobSeenTenantId = &$jobSeenTenantId;
			}

			public function run(array $data, int $jobId): void {
				$this->jobSeenTenantId = TenantContext::getCurrent()?->id;
			}
		};

		$processor = $this->getMockBuilder(Processor::class)
			->setConstructorArgs([new Io(new ConsoleIo()), new NullLogger()])
			->onlyMethods(['loadTask'])
			->getMock();
		$processor->expects($this->exactly(2))
			->method('loadTask')
			->with('TenantAwareTask')
			->willReturnOnConsecutiveCalls($firstTask, $secondTask);

		$queuedJobs = $this->createMock(QueuedJobsTable::class);
		$queuedJobs->expects($this->exactly(2))
			->method('markJobDone')
			->willReturn(true);
		$this->setProperty($processor, 'QueuedJobs', $queuedJobs);

		$this->invokeMethod($processor, 'runJob', [
			$this->buildQueuedJob(203, 'TenantAwareTask', $this->tenantData(17, 'tenant-one')),
			'pid-1',
		]);
		$this->invokeMethod($processor, 'runJob', [
			$this->buildQueuedJob(204, 'TenantAwareTask', $this->tenantData(18, 'tenant-two')),
			'pid-2',
		]);

		$this->assertSame(17, $jobOneSeenTenant);
		$this->assertSame(18, $jobTwoSeenTenant);
		$this->assertNull(TenantContext::getCurrent());
		$this->assertNull(TenantContextAccessor::get());
	}

	/**
	 * @param array<string, mixed> $data
	 * @return \Queue\Model\Entity\QueuedJob
	 */
	protected function buildQueuedJob(int $id, string $task, array $data): QueuedJob
	{
		return new QueuedJob([
			'id' => $id,
			'job_task' => $task,
			'data' => $data,
			'attempts' => 1,
		], ['markNew' => false]);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function tenantData(int $id, string $slug): array
	{
		return [
			'job' => 'payload',
			'__tenant_context' => [
				'id' => $id,
				'slug' => $slug,
				'displayName' => ucfirst($slug),
				'status' => 'active',
				'schemaVersion' => '2026.04',
				'primaryHost' => $slug . '.example.org',
				'resolvedHost' => $slug . '.example.org',
			],
		];
	}

	/**
	 * Helper method for skipping tests that need a real connection.
	 *
	 * @return void
	 */
	protected function _needsConnection()
	{
		$config = ConnectionManager::getConfig('test');
		$skip = strpos($config['driver'], 'Mysql') === false && strpos($config['driver'], 'Postgres') === false;
		$this->skipIf($skip, 'Only Mysql/Postgres is working yet for this.');
	}
}
