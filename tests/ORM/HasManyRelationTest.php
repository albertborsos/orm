<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\ORM\Tests;

use Doctrine\Common\Collections\Collection;
use Spiral\ORM\Heap;
use Spiral\ORM\Loader\RelationLoader;
use Spiral\ORM\Relation;
use Spiral\ORM\Schema;
use Spiral\ORM\Selector;
use Spiral\ORM\State;
use Spiral\ORM\Tests\Fixtures\Comment;
use Spiral\ORM\Tests\Fixtures\EntityMapper;
use Spiral\ORM\Tests\Fixtures\User;
use Spiral\ORM\Tests\Traits\TableTrait;
use Spiral\ORM\UnitOfWork;

abstract class HasManyRelationTest extends BaseTest
{
    use TableTrait;

    public function setUp()
    {
        parent::setUp();

        $this->makeTable('user', [
            'id'      => 'primary',
            'email'   => 'string',
            'balance' => 'float'
        ]);

        $this->getDatabase()->table('user')->insertMultiple(
            ['email', 'balance'],
            [
                ['hello@world.com', 100],
                ['another@world.com', 200],
            ]
        );

        $this->makeTable('comment', [
            'id'      => 'primary',
            'user_id' => 'integer',
            'message' => 'string'
        ]);

        $this->getDatabase()->table('comment')->insertMultiple(
            ['user_id', 'message'],
            [
                [1, 'msg 1'],
                [1, 'msg 2'],
                [1, 'msg 3'],
            ]
        );

        $this->orm = $this->orm->withSchema(new Schema([
            User::class    => [
                Schema::ALIAS       => 'user',
                Schema::MAPPER      => EntityMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'user',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'email', 'balance'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => [
                    'comments' => [
                        Relation::TYPE   => Relation::HAS_MANY,
                        Relation::TARGET => Comment::class,
                        Relation::SCHEMA => [
                            Relation::CASCADE   => true,
                            Relation::INNER_KEY => 'id',
                            Relation::OUTER_KEY => 'user_id',
                        ],
                    ]
                ]
            ],
            Comment::class => [
                Schema::ALIAS       => 'comment',
                Schema::MAPPER      => EntityMapper::class,
                Schema::DATABASE    => 'default',
                Schema::TABLE       => 'comment',
                Schema::PRIMARY_KEY => 'id',
                Schema::COLUMNS     => ['id', 'user_id', 'message'],
                Schema::SCHEMA      => [],
                Schema::RELATIONS   => []
            ]
        ]));
    }

    public function testFetchRelation()
    {
        $selector = new Selector($this->orm, User::class);
        $selector->load('comments')->orderBy('user.id');

        $this->assertEquals([
            [
                'id'       => 1,
                'email'    => 'hello@world.com',
                'balance'  => 100.0,
                'comments' => [
                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'message' => 'msg 1',
                    ],
                    [
                        'id'      => 2,
                        'user_id' => 1,
                        'message' => 'msg 2',
                    ],
                    [
                        'id'      => 3,
                        'user_id' => 1,
                        'message' => 'msg 3',
                    ],
                ],
            ],
            [
                'id'       => 2,
                'email'    => 'another@world.com',
                'balance'  => 200.0,
                'comments' => [],
            ],
        ], $selector->fetchData());
    }

    public function testFetchRelationInload()
    {
        $selector = new Selector($this->orm, User::class);
        $selector->load('comments', ['method' => RelationLoader::INLOAD])->orderBy('user.id');

        $this->assertEquals([
            [
                'id'       => 1,
                'email'    => 'hello@world.com',
                'balance'  => 100.0,
                'comments' => [
                    [
                        'id'      => 1,
                        'user_id' => 1,
                        'message' => 'msg 1',
                    ],
                    [
                        'id'      => 2,
                        'user_id' => 1,
                        'message' => 'msg 2',
                    ],
                    [
                        'id'      => 3,
                        'user_id' => 1,
                        'message' => 'msg 3',
                    ],
                ],
            ],
            [
                'id'       => 2,
                'email'    => 'another@world.com',
                'balance'  => 200.0,
                'comments' => [],
            ],
        ], $selector->fetchData());
    }

    public function testAccessRelated()
    {
        $selector = new Selector($this->orm, User::class);
        /**
         * @var User $a
         * @var User $b
         */
        list($a, $b) = $selector->load('comments')->orderBy('user.id')->fetchAll();

        $this->assertInstanceOf(Collection::class, $a->comments);
        $this->assertInstanceOf(Collection::class, $b->comments);

        $this->assertCount(3, $a->comments);
        $this->assertCount(0, $b->comments);

        $this->assertEquals('msg 1', $a->comments[0]->message);
        $this->assertEquals('msg 2', $a->comments[1]->message);
        $this->assertEquals('msg 3', $a->comments[2]->message);
    }

    public function testCreateWithRelations()
    {
        $e = new User();
        $e->email = 'test@email.com';
        $e->balance = 300;
        $e->comments->add(new Comment());
        $e->comments->add(new Comment());

        $e->comments[0]->message = 'msg A';
        $e->comments[1]->message = 'msg B';

        $tr = new UnitOfWork($this->orm);
        $tr->store($e);
        $tr->run();

        $this->assertEquals(3, $e->id);

        $this->assertTrue($this->orm->getHeap()->has($e));
        $this->assertSame(State::LOADED, $this->orm->getHeap()->get($e)->getState());

        $this->assertTrue($this->orm->getHeap()->has($e->comments[0]));
        $this->assertSame(State::LOADED, $this->orm->getHeap()->get($e->comments[0])->getState());
        $this->assertSame($e->id, $this->orm->getHeap()->get($e->comments[0])->getData()['user_id']);

        $this->assertTrue($this->orm->getHeap()->has($e->comments[1]));
        $this->assertSame(State::LOADED, $this->orm->getHeap()->get($e->comments[1])->getState());
        $this->assertSame($e->id, $this->orm->getHeap()->get($e->comments[1])->getData()['user_id']);

        $selector = new Selector($this->orm, User::class);
        $selector->load('comments');

        $this->assertEquals([
            [
                'id'       => 3,
                'email'    => 'test@email.com',
                'balance'  => 300.0,
                'comments' => [
                    [
                        'id'      => 4,
                        'user_id' => 3,
                        'message' => 'msg A',
                    ],
                    [
                        'id'      => 5,
                        'user_id' => 3,
                        'message' => 'msg B',
                    ],
                ],
            ],
        ], $selector->wherePK(3)->fetchData());
    }

    public function testRemoveChildren()
    {
        $selector = new Selector($this->orm, User::class);
        $selector->load('comments');

        /** @var User $e */
        $e = $selector->wherePK(1)->fetchOne();

        $e->comments->remove(1);

        $tr = new UnitOfWork($this->orm);
        $tr->store($e);
        $tr->run();

        $selector = new Selector($this->orm->withHeap(new Heap()), User::class);
        $selector->load('comments');

        /** @var User $e */
        $e = $selector->wherePK(1)->fetchOne();

        $this->assertCount(2, $e->comments);

        $this->assertSame('msg 1', $e->comments[0]->message);
        $this->assertSame('msg 3', $e->comments[1]->message);
    }

    public function testAddAndRemoveChildren()
    {
        $selector = new Selector($this->orm, User::class);
        $selector->load('comments');

        /** @var User $e */
        $e = $selector->wherePK(1)->fetchOne();

        $e->comments->remove(1);

        $c = new Comment();
        $c->message = "msg 4";
        $e->comments->add($c);

        $tr = new UnitOfWork($this->orm);
        $tr->store($e);
        $tr->run();

        $selector = new Selector($this->orm->withHeap(new Heap()), User::class);
        $selector->load('comments');

        /** @var User $e */
        $e = $selector->wherePK(1)->fetchOne();

        $this->assertCount(3, $e->comments);

        $this->assertSame('msg 1', $e->comments[0]->message);
        $this->assertSame('msg 3', $e->comments[1]->message);
        $this->assertSame('msg 4', $e->comments[2]->message);
    }

    public function testSliceAndSaveToAnotherParent()
    {
        $selector = new Selector($this->orm, User::class);
        /**
         * @var User $a
         * @var User $b
         */
        list($a, $b) = $selector->load('comments')->orderBy('user.id')->fetchAll();

        $this->assertCount(3, $a->comments);
        $this->assertCount(0, $b->comments);

        $b->comments = $a->comments->slice(0, 2);

        foreach ($b->comments as $c) {
            $a->comments->removeElement($c);
        }

        $b->comments[0]->message = "new b";

        $this->assertCount(1, $a->comments);
        $this->assertCount(2, $b->comments);

        $tr = new UnitOfWork($this->orm);
        $tr->store($a);
        $tr->store($b);
        $tr->run();

        $selector = new Selector($this->orm->withHeap(new Heap()), User::class);

        /**
         * @var User $a
         * @var User $b
         */
        list($a, $b) = $selector->load('comments', [
            'method' => RelationLoader::INLOAD,
            'alias'  => 'comment'
        ])->orderBy('user.id')->orderBy('comment.id')->fetchAll();

        $this->assertCount(1, $a->comments);
        $this->assertCount(2, $b->comments);

        $this->assertEquals(3, $a->comments[0]->id);
        $this->assertEquals(1, $b->comments[0]->id);
        $this->assertEquals(2, $b->comments[1]->id);

        $this->assertEquals('new b', $b->comments[0]->message);
    }
}