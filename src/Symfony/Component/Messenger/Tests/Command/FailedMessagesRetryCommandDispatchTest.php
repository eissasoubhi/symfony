<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Messenger\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Command\FailedMessagesRetryCommand;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

class FailedMessagesRetryCommandDispatchTest extends TestCase
{
    public function testDispatchesFailedMessageWithoutTransportContext()
    {
        $message = new \stdClass();
        $redeliveryStamp = new RedeliveryStamp(2);
        $envelope = new Envelope($message, [
            new ReceivedStamp('async'),
            new ConsumedByWorkerStamp(),
            new SentToFailureTransportStamp('async'),
            new TransportMessageIdStamp('failed-id'),
            $redeliveryStamp,
        ]);

        $receiver = $this->createMock(ListableReceiverInterface::class);
        $receiver->expects($this->once())->method('find')->with('failed-id')->willReturn($envelope);
        $receiver->expects($this->once())->method('ack')->with($envelope);
        $receiver->expects($this->never())->method('reject');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(function (Envelope $dispatchedEnvelope) use ($message, $redeliveryStamp) {
                $this->assertSame($message, $dispatchedEnvelope->getMessage());
                $this->assertNull($dispatchedEnvelope->last(ReceivedStamp::class));
                $this->assertNull($dispatchedEnvelope->last(ConsumedByWorkerStamp::class));
                $this->assertNull($dispatchedEnvelope->last(SentToFailureTransportStamp::class));
                $this->assertNull($dispatchedEnvelope->last(TransportMessageIdStamp::class));
                $this->assertSame($redeliveryStamp, $dispatchedEnvelope->last(RedeliveryStamp::class));

                return true;
            }))
            ->willReturnCallback(static fn (Envelope $dispatchedEnvelope) => $dispatchedEnvelope);

        $failureTransportName = 'failure_receiver';
        $command = new FailedMessagesRetryCommand(
            $failureTransportName,
            new ServiceLocator([$failureTransportName => static fn () => $receiver]),
            $bus,
            new EventDispatcher(),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'id' => ['failed-id'],
            '--force' => true,
            '--dispatch' => true,
        ]);

        $this->assertStringContainsString('All done!', $tester->getDisplay());
    }
}
