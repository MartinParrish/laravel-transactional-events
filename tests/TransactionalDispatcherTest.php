<?php

use Mockery as m;
use PHPUnit\Framework\TestCase;
use Illuminate\Events\Dispatcher;
use Neves\TransactionalEvents\Events\TransactionalDispatcher;
use Illuminate\Database\ConnectionInterface;
use \Illuminate\Database\Eloquent\Model;

class TransactionalDispatcherTest extends TestCase
{
    protected $modelMock;

    protected $dispatcher;

    public function tearDown()
    {
        m::close();
    }

    public function setUp()
    {
        unset($_SERVER['__events.test']);
        unset($_SERVER['__events.test.bar']);
        unset($_SERVER['__events.test.zen']);

        $this->modelMock = m::mock(Model::class);
        $this->dispatcher = new TransactionalDispatcher(new Dispatcher());
        $this->dispatcher->setTransactionalEvents(['*']);
    }

    /** @test */
    public function it_immediately_dispatches_event_out_of_transactions()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });
        $this->setupTransactionLevel(0);

        $this->dispatcher->dispatch('foo', $this->modelMock);

        $this->assertFalse($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test']);
    }

    /** @test */
    public function it_enqueues_event_dispatched_in_transactions()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });
        $this->setupTransactionLevel(1);

        $this->dispatcher->dispatch('foo', $this->modelMock);

        $this->assertTrue($this->hasCommitListeners());
        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }

    /** @test */
    public function it_dispatches_events_on_commit()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });
        $this->setupTransactionLevel(1);
        $this->dispatcher->dispatch('foo', $this->modelMock);

        $this->dispatcher->commit($this->getConnection());

        $this->assertFalse($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test']);
    }

    /** @test */
    public function it_forgets_enqueued_events_on_rollback()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });
        $this->setupTransactionLevel(1);
        $this->dispatcher->dispatch('foo', $this->modelMock);

        $this->dispatcher->rollback($this->getConnection());

        $this->assertFalse($this->hasCommitListeners());
        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }

    /** @test */
    public function it_immediately_dispatches_events_present_in_exceptions_list()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->setExcludedEvents(['foo']);
        $this->dispatcher->dispatch('foo', $this->modelMock);

        $this->assertFalse($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test']);
    }

    /** @test */
    public function it_immediately_dispatches_events_not_present_in_enabled_list()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->setTransactionalEvents(['bar']);
        $this->dispatcher->dispatch('foo', $this->modelMock);

        $this->assertFalse($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test']);
    }

    /** @test */
    public function it_immediately_dispatches_events_that_do_not_match_a_pattern()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->setTransactionalEvents(['foo/*']);
        $this->dispatcher->dispatch('foo', $this->modelMock);

        $this->assertFalse($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test']);
    }

    /** @test */
    public function it_enqueues_events_that_do_match_a_pattern()
    {
        $this->dispatcher->listen('foo/bar', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->setTransactionalEvents(['foo/*']);
        $this->dispatcher->dispatch('foo/bar', $this->modelMock);

        $this->assertTrue($this->hasCommitListeners());
        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }

    /** @test */
    public function it_immediately_dispatches_specific_events_excluded_on_a_pattern()
    {
        $this->dispatcher->listen('foo/bar', function () {
            $_SERVER['__events.test.bar'] = 'bar';
        });

        $this->dispatcher->listen('foo/zen', function () {
            $_SERVER['__events.test.zen'] = 'zen';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->setTransactionalEvents(['foo/*']);
        $this->dispatcher->setExcludedEvents(['foo/bar']);
        $this->dispatcher->dispatch('foo/bar', $this->modelMock);
        $this->dispatcher->dispatch('foo/zen', $this->modelMock);

        $this->assertTrue($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test.bar']);
        $this->assertArrayNotHasKey('__env.test.zen', $_SERVER);
    }

    /** @test */
    public function it_enqueues_events_matching_a_namespace_patterns()
    {
        $event = m::mock('\\Neves\\TransactionalEvent');
        $this->dispatcher->listen('\\Neves\\TransactionalEvent', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->dispatch($event, $this->modelMock);

        $this->assertTrue($this->hasCommitListeners());
        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }

    /** @test */
    public function it_dispatches_events_matching_a_namespace_patterns()
    {
        $event = m::mock('overload:\\App\\Neves\\Event');
        $this->dispatcher->listen(get_class($event), function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->setTransactionalEvents(['App\*']);
        $this->dispatcher->dispatch($event, $this->modelMock);
        $this->dispatcher->commit($this->getConnection());

        $this->assertFalse($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test']);
    }

    /** @test */
    public function it_dispatches_events_on_commit_event()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->dispatch('foo', $this->modelMock);
        $this->dispatcher->dispatch(new \Illuminate\Database\Events\TransactionCommitted($this->getConnection()), $this->modelMock);

        $this->assertFalse($this->hasCommitListeners());
        $this->assertEquals('bar', $_SERVER['__events.test']);
    }

    /** @test */
    public function it_forgets_events_on_rollback_event()
    {
        $this->dispatcher->listen('foo', function () {
            $_SERVER['__events.test'] = 'bar';
        });

        $this->setupTransactionLevel(1);
        $this->dispatcher->dispatch('foo', $this->modelMock);
        $this->dispatcher->dispatch(new \Illuminate\Database\Events\TransactionRolledBack($this->getConnection()), $this->modelMock);

        $this->assertFalse($this->hasCommitListeners());
        $this->assertArrayNotHasKey('__events.test', $_SERVER);
    }

    private function hasCommitListeners()
    {
        $connectionId = spl_object_hash($this->modelMock->getConnection());
        return $this->dispatcher->hasListeners($connectionId.'_commit');
    }

    private function getConnection()
    {
        return $this->modelMock->getConnection();
    }

    private function setupTransactionLevel($level = 1)
    {
        $connection = m::mock(ConnectionInterface::class)
            ->shouldReceive('transactionLevel')
            ->andReturn($level)
            ->shouldReceive('getName')
            ->andReturn('dummy')
            ->mock();

        $this->modelMock = $this->modelMock->shouldReceive('getConnection')
        ->andReturn($connection)->mock();

    }
}
