<?php

namespace Enqueue\Stomp\Tests;

use Enqueue\Stomp\BufferedStompClient;
use Enqueue\Stomp\ExtensionType;
use Enqueue\Stomp\StompConsumer;
use Enqueue\Stomp\StompDestination;
use Enqueue\Stomp\StompMessage;
use Enqueue\Test\ClassExtensionTrait;
use Enqueue\Test\ReadAttributeTrait;
use Interop\Queue\Consumer;
use Interop\Queue\Exception\InvalidMessageException;
use Interop\Queue\Message;
use Stomp\Protocol\Protocol;
use Stomp\Transport\Frame;

class StompConsumerTest extends \PHPUnit\Framework\TestCase
{
    use ClassExtensionTrait;
    use ReadAttributeTrait;

    public function testShouldImplementMessageConsumerInterface()
    {
        $this->assertClassImplements(Consumer::class, StompConsumer::class);
    }

    public function testCouldGetQueue()
    {
        $consumer = new StompConsumer($this->createStompClientMock(), $dest = $this->createDummyDestination());

        $this->assertSame($dest, $consumer->getQueue());
    }

    public function testShouldReturnDefaultAckMode()
    {
        $consumer = new StompConsumer($this->createStompClientMock(), $this->createDummyDestination());

        $this->assertSame(StompConsumer::ACK_CLIENT_INDIVIDUAL, $consumer->getAckMode());
    }

    public function testCouldSetGetAckMethod()
    {
        $consumer = new StompConsumer($this->createStompClientMock(), $this->createDummyDestination());
        $consumer->setAckMode(StompConsumer::ACK_CLIENT);

        $this->assertSame(StompConsumer::ACK_CLIENT, $consumer->getAckMode());
    }

    public function testShouldThrowLogicExceptionIfAckModeIsInvalid()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Ack mode is not valid: "invalid-ack-mode"');

        $consumer = new StompConsumer($this->createStompClientMock(), $this->createDummyDestination());
        $consumer->setAckMode('invalid-ack-mode');
    }

    public function testShouldReturnDefaultPrefetchCount()
    {
        $consumer = new StompConsumer($this->createStompClientMock(), $this->createDummyDestination());

        $this->assertSame(1, $consumer->getPrefetchCount());
    }

    public function testCouldSetGetPrefetchCount()
    {
        $consumer = new StompConsumer($this->createStompClientMock(), $this->createDummyDestination());
        $consumer->setPrefetchCount(123);

        $this->assertSame(123, $consumer->getPrefetchCount());
    }

    public function testAcknowledgeShouldThrowInvalidMessageExceptionIfMessageIsWrongType()
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('The message must be an instance of');

        $consumer = new StompConsumer($this->createStompClientMock(), $this->createDummyDestination());
        $consumer->acknowledge($this->createMock(Message::class));
    }

    public function testShouldAcknowledgeMessage()
    {
        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getAckFrame')
            ->willReturn(new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
            ->with($this->isInstanceOf(Frame::class))
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;

        $message = new StompMessage();
        $message->setFrame(new Frame());

        $consumer = new StompConsumer($client, $this->createDummyDestination());
        $consumer->acknowledge($message);
    }

    public function testRejectShouldThrowInvalidMessageExceptionIfMessageIsWrongType()
    {
        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('The message must be an instance of');

        $consumer = new StompConsumer($this->createStompClientMock(), $this->createDummyDestination());
        $consumer->reject($this->createMock(Message::class));
    }

    public function testShouldRejectMessage()
    {
        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getNackFrame')
            ->willReturn($frame = new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
            ->with($this->isInstanceOf(Frame::class))
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;

        $message = new StompMessage();
        $message->setFrame(new Frame());

        $consumer = new StompConsumer($client, $this->createDummyDestination());
        $consumer->reject($message);

        $this->assertSame(['requeue' => 'false'], $frame->getHeaders());
    }

    public function testShouldRejectAndRequeueMessage()
    {
        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getNackFrame')
            ->willReturn($frame = new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
            ->with($this->isInstanceOf(Frame::class))
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;

        $message = new StompMessage();
        $message->setFrame(new Frame());

        $consumer = new StompConsumer($client, $this->createDummyDestination());
        $consumer->reject($message, true);

        $this->assertSame(['requeue' => 'true'], $frame->getHeaders());
    }

    public function testShouldReceiveMessageNoWait()
    {
        $messageFrame = new Frame('MESSAGE');

        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getSubscribeFrame')
            ->willReturn(new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
            ->with($this->isInstanceOf(Frame::class))
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
            ->willReturn($messageFrame)
        ;

        $message = new StompMessage();
        $message->setFrame(new Frame());

        $destination = $this->createDummyDestination();
        $destination->setType(StompDestination::TYPE_QUEUE);
        $destination->setStompName('name');

        $consumer = new StompConsumer($client, $destination);
        $message = $consumer->receiveNoWait();

        $this->assertInstanceOf(StompMessage::class, $message);
    }

    public function testReceiveMessageNoWaitShouldSubscribeOnlyOnce()
    {
        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getSubscribeFrame')
            ->willReturn(new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;
        $client
            ->expects($this->exactly(2))
            ->method('readMessageFrame')
        ;

        $message = new StompMessage();
        $message->setFrame(new Frame());

        $destination = $this->createDummyDestination();
        $destination->setType(StompDestination::TYPE_QUEUE);
        $destination->setStompName('name');

        $consumer = new StompConsumer($client, $destination);
        $consumer->receiveNoWait();
        $consumer->receiveNoWait();
    }

    public function testShouldAddExtraHeadersOnSubscribe()
    {
        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getSubscribeFrame')
            ->willReturn($subscribeFrame = new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
        ;

        $destination = $this->createDummyDestination();
        $destination->setStompName('name');
        $destination->setType(StompDestination::TYPE_QUEUE);
        $destination->setDurable(true);
        $destination->setAutoDelete(true);
        $destination->setExclusive(true);

        $consumer = new StompConsumer($client, $destination);
        $consumer->setPrefetchCount(123);

        $consumer->receiveNoWait();

        $expectedExtraHeaders = [
            'durable' => 'true',
            '_type_durable' => 'b',
            'auto-delete' => 'true',
            '_type_auto-delete' => 'b',
            'exclusive' => 'true',
            '_type_exclusive' => 'b',
            'prefetch-count' => '123',
            '_type_prefetch-count' => 'i',
        ];

        $this->assertSame($expectedExtraHeaders, $subscribeFrame->getHeaders());
    }

    public function testShouldConvertStompMessageFrameToMessage()
    {
        $headers = [
            'hkey' => 'hvalue',
            '_property_key' => 'value',
            '_property__type_key' => 's',
            'redelivered' => 'true',
        ];

        $stompMessageFrame = new Frame('MESSAGE', $headers, 'body');

        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getSubscribeFrame')
            ->willReturn(new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
            ->willReturn($stompMessageFrame)
        ;

        $destination = $this->createDummyDestination();
        $destination->setStompName('name');
        $destination->setType(StompDestination::TYPE_QUEUE);

        $consumer = new StompConsumer($client, $destination);

        $message = $consumer->receiveNoWait();

        $this->assertEquals('body', $message->getBody());
        $this->assertEquals(['key' => 'value'], $message->getProperties());
        $this->assertEquals(['hkey' => 'hvalue'], $message->getHeaders());
        $this->assertTrue($message->isRedelivered());
    }

    public function testShouldThrowLogicExceptionIfFrameIsNotMessageFrame()
    {
        $stompMessageFrame = new Frame('NOT-MESSAGE-FRAME');

        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getSubscribeFrame')
            ->willReturn(new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
            ->willReturn($stompMessageFrame)
        ;

        $destination = $this->createDummyDestination();
        $destination->setStompName('name');
        $destination->setType(StompDestination::TYPE_QUEUE);

        $consumer = new StompConsumer($client, $destination);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Frame is not MESSAGE frame but: "NOT-MESSAGE-FRAME"');

        $consumer->receiveNoWait();
    }

    public function testShouldReceiveWithUnlimitedTimeout()
    {
        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getSubscribeFrame')
            ->willReturn(new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
            ->willReturn(new Frame('MESSAGE'))
        ;

        $destination = $this->createDummyDestination();
        $destination->setStompName('name');
        $destination->setType(StompDestination::TYPE_QUEUE);

        $consumer = new StompConsumer($client, $destination);

        $message = $consumer->receive(0);

        $this->assertInstanceOf(StompMessage::class, $message);
    }

    public function testShouldReceiveWithTimeout()
    {
        $protocol = $this->createStompProtocolMock();
        $protocol
            ->expects($this->once())
            ->method('getSubscribeFrame')
            ->willReturn(new Frame())
        ;

        $client = $this->createStompClientMock();
        $client
            ->expects($this->once())
            ->method('sendFrame')
        ;
        $client
            ->expects($this->once())
            ->method('getProtocol')
            ->willReturn($protocol)
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
            ->willReturn(new Frame('MESSAGE'))
        ;

        $destination = $this->createDummyDestination();
        $destination->setStompName('name');
        $destination->setType(StompDestination::TYPE_QUEUE);

        $consumer = new StompConsumer($client, $destination);

        $message = $consumer->receive(5);

        $this->assertInstanceOf(StompMessage::class, $message);
    }

    public function testShouldReceiveWithoutSubscribeIfTempQueue()
    {
        $client = $this->createStompClientMock();
        $client
            ->expects($this->never())
            ->method('getProtocol')
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
        ;

        $message = new StompMessage();
        $message->setFrame(new Frame());

        $destination = $this->createDummyDestination();
        $destination->setType(StompDestination::TYPE_TEMP_QUEUE);
        $destination->setStompName('name');

        $consumer = new StompConsumer($client, $destination);
        $consumer->receive(1);
    }

    public function testShouldReceiveNoWaitWithoutSubscribeIfTempQueue()
    {
        $client = $this->createStompClientMock();
        $client
            ->expects($this->never())
            ->method('getProtocol')
        ;
        $client
            ->expects($this->once())
            ->method('readMessageFrame')
        ;

        $message = new StompMessage();
        $message->setFrame(new Frame());

        $destination = $this->createDummyDestination();
        $destination->setType(StompDestination::TYPE_TEMP_QUEUE);
        $destination->setStompName('name');

        $consumer = new StompConsumer($client, $destination);
        $consumer->receiveNoWait();
    }

    public function testShouldGenerateUniqueSubscriptionIdPerConsumer()
    {
        $destination = $this->createDummyDestination();
        $destination->setType(StompDestination::TYPE_QUEUE);
        $destination->setStompName('name');

        $fooConsumer = new StompConsumer($this->createStompClientMock(), $destination);
        $barConsumer = new StompConsumer($this->createStompClientMock(), $destination);

        $this->assertNotEmpty($this->readAttribute($fooConsumer, 'subscriptionId'));
        $this->assertNotEmpty($this->readAttribute($barConsumer, 'subscriptionId'));

        $fooSubscriptionId = $this->readAttribute($fooConsumer, 'subscriptionId');
        $barSubscriptionId = $this->readAttribute($barConsumer, 'subscriptionId');
        $this->assertNotEquals($fooSubscriptionId, $barSubscriptionId);
    }

    public function testShouldUseTempQueueNameAsSubscriptionId()
    {
        $destination = $this->createDummyDestination();
        $destination->setType(StompDestination::TYPE_TEMP_QUEUE);
        $destination->setStompName('foo');

        $consumer = new StompConsumer($this->createStompClientMock(), $destination);

        $this->assertAttributeEquals('/temp-queue/foo', 'subscriptionId', $consumer);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|Protocol
     */
    private function createStompProtocolMock()
    {
        return $this->createMock(Protocol::class);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|BufferedStompClient
     */
    private function createStompClientMock()
    {
        return $this->createMock(BufferedStompClient::class);
    }

    private function createDummyDestination(): StompDestination
    {
        $destination = new StompDestination(ExtensionType::RABBITMQ);
        $destination->setStompName('aName');
        $destination->setType(StompDestination::TYPE_QUEUE);

        return $destination;
    }
}
