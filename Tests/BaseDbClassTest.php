<?php

	namespace Stoic\Tests\Utilities;

	use PHPUnit\Framework\TestCase;
	use Stoic\Chain\DispatchBase;
	use Stoic\Pdo\BaseDbClass;
	use Pseudo\Pdo;
	use Stoic\Log\AppenderBase;
	use Stoic\Log\Logger;
	use Stoic\Log\MessageDispatch;
	use Stoic\Utilities\ReturnHelper;

	class BasicBaseClass extends BaseDbClass {
		public $testing;


		public function tryBadQuery() {
			return $this->tryPdoExcept(function () {
				throw new \PDOException('This is a test error');
			}, 'Testing');
		}

		public function tryNoQuery() {
			return $this->tryPdoExcept(function () {
				return true;
			}, 'Testing');
		}

		public function tryRHLog() {
			$rh = new ReturnHelper();
			$rh->addMessage('Testing!');

			$this->logReturnHelperMessages($rh, "Default message");

			return;
		}

		public function tryRHLogBad() {
			$this->logReturnHelperMessages(new ReturnHelper(), "");

			return;
		}

		public function tryRHLogNoMessages() {
			$this->logReturnHelperMessages(new ReturnHelper(), "Default message");

			return;
		}
	}

	class TestLogAppender extends AppenderBase {
		public array $outputMessages = [];


		public function __construct() {
			$this->setKey('TestLogAppender');
			$this->setVersion('1.0');

			return;
		}

		public function process(mixed $sender, DispatchBase &$dispatch) : void {
			if (!($dispatch instanceof MessageDispatch)) {
				return;
			}

			if (count($dispatch->messages) > 0) {
				foreach ($dispatch->messages as $message) {
					$this->outputMessages[] = $message->__toString();
				}
			}

			return;
		}
	}

	class BaseDbClassTest extends TestCase {
		public function test_Init() {
			$cls = new BasicBaseClass(new Pdo(), new Logger());
			$cls->testing = 'testing';

			self::assertEquals('testing', $cls->testing);

			return;
		}

		public function test_TryPdoExcept() {
			$cls = new BasicBaseClass(new Pdo());

			self::assertTrue($cls->tryNoQuery());
			self::assertNull($cls->tryBadQuery());

			return;
		}

		public function test_UnrollHelperMessages() {
			$log = new Logger();
			$app = new TestLogAppender();
			$log->addAppender($app);

			$cls = new BasicBaseClass(new Pdo(), $log);
			$cls->tryRHLog();
			$log->output();

			self::assertCount(1, $app->outputMessages, print_r($app->outputMessages, true));

			try {
				$cls->tryRHLogBad();
				self::fail();
			} catch (\InvalidArgumentException) {
				self::assertTrue(true);
			}

			$log = new Logger();
			$app = new TestLogAppender();
			$log->addAppender($app);

			$cls = new BasicBaseClass(new Pdo(), $log);
			$cls->tryRHLogNoMessages();
			$log->output();

			self::assertCount(1, $app->outputMessages, print_r($app->outputMessages, true));

			return;
		}
	}