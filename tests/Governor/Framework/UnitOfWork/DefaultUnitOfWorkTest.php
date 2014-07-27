<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The software is based on the Axon Framework project which is
 * licensed under the Apache 2.0 license. For more information on the Axon Framework
 * see <http://www.axonframework.org/>.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.governor-framework.org/>.
 */

namespace Governor\Framework\UnitOfWork;

use Governor\Framework\Domain\GenericEventMessage;
use Governor\Framework\EventHandling\EventListenerInterface;
use Governor\Framework\Domain\AggregateRootInterface;
use Governor\Framework\EventHandling\EventBusInterface;

/**
 * Description of DefaultUnitOfWorkTest
 *
 * @author david
 */
class DefaultUnitOfWorkTest extends \PHPUnit_Framework_TestCase
{

    private $testSubject;
    private $mockEventBus;
    private $mockAggregateRoot;
    private $mockLogger;
    private $event1;
    private $event2;
    private $listener1;
    private $listener2;
    private $callback;

    public function setUp()
    {
        while (CurrentUnitOfWork::isStarted()) {
            CurrentUnitOfWork::get()->rollback();
        }

        $this->event1 = new GenericEventMessage(new TestMessage(1));
        $this->event2 = new GenericEventMessage(new TestMessage(1));

        $this->testSubject = new DefaultUnitOfWork();
        $this->mockEventBus = $this->getMock(EventBusInterface::class);
        $this->mockAggregateRoot = $this->getMock(AggregateRootInterface::class);
        $this->listener1 = $this->getMock(EventListenerInterface::class);
        $this->listener2 = $this->getMock(EventListenerInterface::class);
        $this->callback = $this->getMock(SaveAggregateCallbackInterface::class);
        /*

          callback = mock(SaveAggregateCallback.class);
          doAnswer(new PublishEvent(event1)).doAnswer(new PublishEvent(event2))
          .when(callback).save(mockAggregateRoot);
          doAnswer(new Answer() {
          @Override
          public Object answer(InvocationOnMock invocation) throws Throwable {
          listener1.handle((EventMessage) invocation.getArguments()[0]);
          listener2.handle((EventMessage) invocation.getArguments()[0]);
          return null;
          }
          }).when(mockEventBus).publish(isA(EventMessage.class)); */
    }

    public function tearDown()
    {
        $this->assertFalse(CurrentUnitOfWork::isStarted(),
                "A UnitOfWork was not properly cleared");
    }

    public function testTransactionBoundUnitOfWorkLifecycle()
    {
        $mockListener = \Phake::mock(UnitOfWorkListenerInterface::class);
        $mockTransactionManager = \Phake::mock(TransactionManagerInterface::class);

        \Phake::when($mockTransactionManager)->startTransaction()->thenReturn(new \stdClass());

        $uow = DefaultUnitOfWork::startAndGet($mockTransactionManager);
        $uow->registerListener($mockListener);

        \Phake::verify($mockTransactionManager)->startTransaction();
        \Phake::verifyNoInteraction($mockListener);

        $uow->commit();

        \Phake::inOrder(
                \Phake::verify($mockListener)->onPrepareCommit(\Phake::anyParameters()),
                \Phake::verify($mockListener)->onPrepareTransactionCommit(\Phake::equalTo($uow),
                        \Phake::anyParameters()),
                \Phake::verify($mockTransactionManager)->commitTransaction(\Phake::anyParameters()),
                \Phake::verify($mockListener)->afterCommit(\Phake::equalTo($uow)),
                \Phake::verify($mockListener)->onCleanup(\Phake::equalTo($uow))
        );
    }

    public function testTransactionBoundUnitOfWorkLifecycle_Rollback()
    {
        $mockListener = \Phake::mock(UnitOfWorkListenerInterface::class);
        $mockTransactionManager = \Phake::mock(TransactionManagerInterface::class);

        \Phake::when($mockTransactionManager)->startTransaction()->thenReturn(new \stdClass());

        $uow = DefaultUnitOfWork::startAndGet($mockTransactionManager);
        $uow->registerListener($mockListener);

        \Phake::verify($mockTransactionManager)->startTransaction();
        \Phake::verifyNoInteraction($mockListener);

        $uow->rollback();

        \Phake::inOrder(
                \Phake::verify($mockTransactionManager)->rollbackTransaction(\Phake::anyParameters()),
                \Phake::verify($mockListener)->onRollback(\Phake::anyParameters()),
                \Phake::verify($mockListener)->onCleanup(\Phake::equalTo($uow))
        );
    }

    public function testUnitOfWorkRegistersListenerWithParent()
    {
        $parentUoW = $this->getMock(UnitOfWorkInterface::class);
        $parentUoW->expects($this->once())
                ->method('registerListener')
                ->with($this->anything());

        CurrentUnitOfWork::set($parentUoW);
        $innerUow = DefaultUnitOfWork::startAndGet($this->mockLogger);
        $innerUow->rollback();
        $parentUoW->rollback();
        CurrentUnitOfWork::clear($parentUoW);
    }

    public function testInnerUnitOfWorkRolledBackWithOuter()
    {
        $isRolledBack = false;
        $outer = DefaultUnitOfWork::startAndGet($this->mockLogger);
        $inner = DefaultUnitOfWork::startAndGet($this->mockLogger);

        $inner->registerListener(new RollbackTestAdapter(function (UnitOfWorkInterface $uow, \Exception $ex = null) use (&$isRolledBack) {
            $isRolledBack = true;
        }));


        $inner->commit();
        $outer->rollback();

        $this->assertTrue($isRolledBack,
                "The inner UoW wasn't properly rolled back");
        $this->assertFalse(CurrentUnitOfWork::isStarted(),
                "The UnitOfWork haven't been correctly cleared");
    }

    public function testInnerUnitOfWorkCommittedBackWithOuter()
    {
        $isCommitted = false;
        $outer = DefaultUnitOfWork::startAndGet($this->mockLogger);
        $inner = DefaultUnitOfWork::startAndGet($this->mockLogger);

        $inner->registerListener(new AfterCommitTestAdapter(function (UnitOfWorkInterface $uow) use (&$isCommitted) {
            $isCommitted = true;
        }));

        $inner->commit();
        $this->assertFalse($isCommitted,
                "The inner UoW was committed prematurely");
        $outer->commit();
        $this->assertTrue($isCommitted,
                "The inner UoW wasn't properly committed");
        $this->assertFalse(CurrentUnitOfWork::isStarted(),
                "The UnitOfWork haven't been correctly cleared");
    }

// !!! TODO 
    public function testSagaEventsDoNotOvertakeRegularEvents()
    {
        $this->testSubject->start();

        /*
          doAnswer(new Answer() {
          @Override
          public Object answer(InvocationOnMock invocation) throws Throwable {
          DefaultUnitOfWork uow = new DefaultUnitOfWork();
          uow.start();
          uow.registerAggregate(mockAggregateRoot, mockEventBus, callback);
          uow.commit();
          return null;
          }
          }).when(listener1).handle(event1); */

        $this->testSubject->registerAggregate($this->mockAggregateRoot,
                $this->mockEventBus, $this->callback);

        $this->testSubject->commit();

        /*
          InOrder inOrder = inOrder(listener1, listener2, callback);
          inOrder.verify(listener1, times(1)).handle(event1);
          inOrder.verify(listener2, times(1)).handle(event1);
          inOrder.verify(listener1, times(1)).handle(event2);
          inOrder.verify(listener2, times(1)).handle(event2); */
    }

    public function testUnitOfWorkRolledBackOnCommitFailure_ErrorOnPrepareCommit()
    {
        $mockListener = $this->getMock(UnitOfWorkListenerInterface::class);
        $mockListener->expects($this->once())
                ->method('onPrepareCommit')
                ->with($this->anything(), $this->anything(), $this->anything())
                ->will($this->throwException(new \RuntimeException('phpunit')));

        $mockListener->expects($this->once())
                ->method('onRollback')
                ->with($this->anything(),
                        $this->callback(function ($subject) {
                            return $subject instanceof \RuntimeException;
                        }));

        $mockListener->expects($this->never())
                ->method('afterCommit');

        $mockListener->expects($this->once())
                ->method('onCleanup')
                ->with($this->anything());

        $this->testSubject->registerListener($mockListener);
        $this->testSubject->start();

        try {
            $this->testSubject->commit();
            $this->fail("Expected exception");
        } catch (\Exception $ex) {
            $this->assertInstanceOf('\RuntimeException', $ex);
            $this->assertEquals('phpunit', $ex->getMessage());
        }
    }

    public function testUnitOfWorkRolledBackOnCommitFailure_ErrorOnCommitAggregate()
    {
        $mockListener = $this->getMock(UnitOfWorkListenerInterface::class);

        $this->callback->expects($this->once())
                ->method('save')
                ->with($this->anything())
                ->will($this->throwException(new \RuntimeException('phpunit')));

        $mockListener->expects($this->once())
                ->method('onPrepareCommit')
                ->with($this->anything(), $this->anything(), $this->anything());


        $mockListener->expects($this->once())
                ->method('onRollback')
                ->with($this->anything(),
                        $this->callback(function ($subject) {
                            return $subject instanceof \RuntimeException;
                        }));

        $mockListener->expects($this->never())
                ->method('afterCommit');

        $mockListener->expects($this->once())
                ->method('onCleanup')
                ->with($this->anything());

        $this->testSubject->registerListener($mockListener);
        $this->testSubject->registerAggregate($this->mockAggregateRoot,
                $this->mockEventBus, $this->callback);
        $this->testSubject->start();

        try {
            $this->testSubject->commit();
            $this->fail("Expected exception");
        } catch (\RuntimeException $ex) {
            $this->assertInstanceOf('\RuntimeException', $ex,
                    "Got an exception, but the wrong one");
            $this->assertEquals('phpunit', $ex->getMessage(),
                    "Got an exception, but the wrong one");
        }
    }

    public function testUnitOfWorkRolledBackOnCommitFailure_ErrorOnDispatchEvents()
    {
        $mockListener = $this->getMock(UnitOfWorkListenerInterface::class);

        $mockListener->expects($this->once())
                ->method('onEventRegistered')
                ->with($this->anything(), $this->anything())
                ->will($this->returnValue(new GenericEventMessage(new TestMessage(1))));

        $this->mockEventBus->expects($this->once())
                ->method('publish')
                ->with($this->anything())
                ->will($this->throwException(new \RuntimeException('phpunit')));

        $mockListener->expects($this->once())
                ->method('onPrepareCommit')
                ->with($this->anything(), $this->anything(), $this->anything());


        $mockListener->expects($this->once())
                ->method('onRollback')
                ->with($this->anything(),
                        $this->callback(function ($subject) {
                            return $subject instanceof \RuntimeException;
                        }));

        $mockListener->expects($this->never())
                ->method('afterCommit');

        $this->testSubject->start();
        $this->testSubject->registerListener($mockListener);
        $this->testSubject->publishEvent(new GenericEventMessage(new TestMessage(1)),
                $this->mockEventBus);

        try {
            $this->testSubject->commit();
            $this->fail("Expected exception");
        } catch (\RuntimeException $ex) {
            $this->assertInstanceOf('\RuntimeException', $ex,
                    "Got an exception, but the wrong one");
            $this->assertEquals('phpunit', $ex->getMessage(),
                    "Got an exception, but the wrong one");
        }
    }

    public function testUnitOfWorkCleanupDelayedUntilOuterUnitOfWorkIsCleanedUp_InnerCommit()
    {
        $outerListener = $this->getMock(UnitOfWorkListenerInterface::class);
        $innerListener = $this->getMock(UnitOfWorkListenerInterface::class);

        $outer = DefaultUnitOfWork::startAndGet($this->mockLogger);
        $inner = DefaultUnitOfWork::startAndGet($this->mockLogger);

        $outer->registerListener($outerListener);
        $inner->registerListener($innerListener);

        $innerListener->expects($this->once())
                ->method('afterCommit');

        $innerListener->expects($this->once())
                ->method('onCleanup');

        $inner->commit();

        //$innerListener->expects($this->at(1))

        $outer->commit();

        /*
          UnitOfWork outer = DefaultUnitOfWork.startAndGet();
          UnitOfWork inner = DefaultUnitOfWork.startAndGet();
          inner.registerListener(innerListener);
          outer.registerListener(outerListener);
          inner.commit();
          verify(innerListener, never()).afterCommit(isA(UnitOfWork.class));
          verify(innerListener, never()).onCleanup(isA(UnitOfWork.class));
          outer.commit();

          InOrder inOrder = inOrder(innerListener, outerListener);
          inOrder.verify(innerListener).afterCommit(isA(UnitOfWork.class));
          inOrder.verify(outerListener).afterCommit(isA(UnitOfWork.class));
          inOrder.verify(innerListener).onCleanup(isA(UnitOfWork.class));
          inOrder.verify(outerListener).onCleanup(isA(UnitOfWork.class)); */
    }

    /*
      @SuppressWarnings({"unchecked", "ThrowableResultOfMethodCallIgnored", "NullableProblems"})
      @Test
      public void testUnitOfWorkCleanupDelayedUntilOuterUnitOfWorkIsCleanedUp_InnerRollback() {
      UnitOfWorkListener outerListener = mock(UnitOfWorkListener.class);
      UnitOfWorkListener innerListener = mock(UnitOfWorkListener.class);
      UnitOfWork outer = DefaultUnitOfWork.startAndGet();
      UnitOfWork inner = DefaultUnitOfWork.startAndGet();
      inner.registerListener(innerListener);
      outer.registerListener(outerListener);
      inner.rollback();
      verify(innerListener, never()).afterCommit(isA(UnitOfWork.class));
      verify(innerListener, never()).onCleanup(isA(UnitOfWork.class));
      outer.commit();

      InOrder inOrder = inOrder(innerListener, outerListener);
      inOrder.verify(innerListener).onRollback(isA(UnitOfWork.class), (Throwable) isNull());
      inOrder.verify(outerListener).afterCommit(isA(UnitOfWork.class));
      inOrder.verify(innerListener).onCleanup(isA(UnitOfWork.class));
      inOrder.verify(outerListener).onCleanup(isA(UnitOfWork.class));
      }

      @SuppressWarnings({"unchecked", "ThrowableResultOfMethodCallIgnored", "NullableProblems"})
      @Test
      public void testUnitOfWorkCleanupDelayedUntilOuterUnitOfWorkIsCleanedUp_InnerCommit_OuterRollback() {
      UnitOfWorkListener outerListener = mock(UnitOfWorkListener.class);
      UnitOfWorkListener innerListener = mock(UnitOfWorkListener.class);
      UnitOfWork outer = DefaultUnitOfWork.startAndGet();
      UnitOfWork inner = DefaultUnitOfWork.startAndGet();
      inner.registerListener(innerListener);
      outer.registerListener(outerListener);
      inner.commit();
      verify(innerListener, never()).afterCommit(isA(UnitOfWork.class));
      verify(innerListener, never()).onCleanup(isA(UnitOfWork.class));
      outer.rollback();
      verify(outerListener, never()).onPrepareCommit(isA(UnitOfWork.class),
      anySetOf(AggregateRoot.class),
      anyListOf(EventMessage.class));

      InOrder inOrder = inOrder(innerListener, outerListener);
      inOrder.verify(innerListener).onPrepareCommit(isA(UnitOfWork.class),
      anySetOf(AggregateRoot.class),
      anyListOf(EventMessage.class));

      inOrder.verify(innerListener).onRollback(isA(UnitOfWork.class), (Throwable) isNull());
      inOrder.verify(outerListener).onRollback(isA(UnitOfWork.class), (Throwable) isNull());
      inOrder.verify(innerListener).onCleanup(isA(UnitOfWork.class));
      inOrder.verify(outerListener).onCleanup(isA(UnitOfWork.class));
      }

      private static class ReturnParameterAnswer implements Answer<Object> {

      private final int parameterIndex;

      private ReturnParameterAnswer(int parameterIndex) {
      this.parameterIndex = parameterIndex;
      }

      @Override
      public Object answer(InvocationOnMock invocation) throws Throwable {
      return invocation.getArguments()[parameterIndex];
      }
      }

      private class PublishEvent implements Answer {

      private final EventMessage event;

      private PublishEvent(EventMessage event) {
      this.event = event;
      }

      @Override
      public Object answer(InvocationOnMock invocation) throws Throwable {
      CurrentUnitOfWork.get().publishEvent(event, mockEventBus);
      return null;
      }
      } */
}

class TestMessage
{

    private $text;

    function __construct($text)
    {
        $this->text = $text;
    }

    public function getText()
    {
        return $this->text;
    }

}

class RollbackTestAdapter extends UnitOfWorkListenerAdapter
{

    private $closure;

    public function __construct(\Closure $closure)
    {
        $this->closure = $closure;
    }

    public function onRollback(UnitOfWorkInterface $unitOfWork,
            \Exception $failureCause = null)
    {
        $cb = $this->closure;
        $cb($unitOfWork, $failureCause);
    }

}

class AfterCommitTestAdapter extends UnitOfWorkListenerAdapter
{

    private $closure;

    public function __construct(\Closure $closure)
    {
        $this->closure = $closure;
    }

    public function afterCommit(UnitOfWorkInterface $unitOfWork)
    {
        $cb = $this->closure;
        $cb($unitOfWork);
    }

}
