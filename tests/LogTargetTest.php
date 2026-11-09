<?php

declare(strict_types=1);

namespace pekopt\yii2sentry\tests;

use pekopt\yii2sentry\collectors\LogCollector\LogTarget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

use function Sentry\init;

#[CoversClass(LogTarget::class)]
final class LogTargetTest extends TestCase
{
    private HubInterface $previousHub;

    private RecordingTransport $transport;

    protected function setUp(): void
    {
        $this->previousHub = SentrySdk::getCurrentHub();
        $this->transport = new RecordingTransport();

        init([
            'dsn' => 'http://public@example.com/1',
            'default_integrations' => false,
            'transport' => $this->transport,
        ]);
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->previousHub);
    }

    public function testCaptureMessageWithNullLevelUsesInfoSeverity(): void
    {
        $this->createLogTarget()->captureMessageForTest(
            'Something happened',
            null,
            [
                'logger' => 'app.test',
                'extra' => ['foo' => 'bar'],
                'tags' => ['custom' => 'value'],
            ]
        );

        self::assertCount(1, $this->transport->events);

        $event = $this->transport->events[0];

        self::assertSame('Something happened', $event->getMessage());
        self::assertSame(Severity::INFO, (string) $event->getLevel());
        self::assertSame('app.test', $event->getTags()['logger']);
        self::assertSame('value', $event->getTags()['custom']);
        self::assertSame('bar', $event->getExtra()['foo']);
    }

    public function testCaptureMessageWithoutLevelArgumentUsesInfoSeverity(): void
    {
        $this->createLogTarget()->captureMessageForTest('Something happened');

        self::assertCount(1, $this->transport->events);
        self::assertSame(Severity::INFO, (string) $this->transport->events[0]->getLevel());
    }

    public function testCaptureMessageWithExplicitLevelKeepsThatLevel(): void
    {
        $this->createLogTarget()->captureMessageForTest('Something failed', Severity::error());

        self::assertCount(1, $this->transport->events);
        self::assertSame(Severity::ERROR, (string) $this->transport->events[0]->getLevel());
    }

    /**
     * PHP 8.4 deprecates implicitly nullable parameters such as "Severity $level = null".
     * The parameter must be declared as "?Severity $level = null" instead.
     */
    public function testCaptureMessageLevelParameterIsExplicitlyNullable(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/collectors/LogCollector/LogTarget.php');

        self::assertIsString($source);
        self::assertMatchesRegularExpression(
            '/function\s+captureMessage\s*\(\s*string\s+\$message\s*,\s*\?Severity\s+\$level\s*=\s*null/',
            $source,
            'captureMessage() must declare its $level parameter as "?Severity $level = null" to avoid PHP 8.4 deprecations.'
        );
    }

    private function createLogTarget(): LogTarget
    {
        return new class extends LogTarget {
            public function captureMessageForTest(string $message, ?Severity $level = null, array $options = []): void
            {
                $this->captureMessage($message, $level, $options);
            }
        };
    }
}

final class RecordingTransport implements TransportInterface
{
    /** @var Event[] */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
