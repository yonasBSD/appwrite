<?php

namespace Tests\Unit\Messaging;

use Appwrite\Messaging\Adapter\Realtime;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;

class MessagingTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testUser(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            ID::unique(),
            [
                Role::user(ID::custom('123'))->toString(),
                Role::users()->toString(),
                Role::team(ID::custom('abc'))->toString(),
                Role::team(ID::custom('abc'), 'administrator')->toString(),
                Role::team(ID::custom('abc'), 'moderator')->toString(),
                Role::team(ID::custom('def'))->toString(),
                Role::team(ID::custom('def'), 'guest')->toString(),
            ],
            // Pass plain channel names, Realtime::subscribe will normalize them
            ['files', 'documents', 'documents.789', 'account.123']
        );

        $event = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => [
                    0 => 'account.123',
                ]
            ]
        ];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::users()->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::user(ID::custom('123'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'), 'administrator')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('abc'), 'moderator')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('def'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::team(ID::custom('def'), 'guest')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['roles'] = [Role::user(ID::custom('456'))->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::team(ID::custom('def'), 'member')->toString()];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['roles'] = [Role::any()->toString()];
        $event['data']['channels'] = ['documents.123'];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $event['data']['channels'] = ['documents.789'];

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertCount(1, $receivers);
        $this->assertEquals(1, $receivers[0]);

        $event['project'] = '2';

        $receivers = array_keys($realtime->getSubscribers($event));

        $this->assertEmpty($receivers);

        $realtime->unsubscribe(2);

        $this->assertCount(1, $realtime->connections);
        $this->assertCount(7, $realtime->subscriptions['1']);

        $realtime->unsubscribe(1);

        $this->assertEmpty($realtime->connections);
        $this->assertEmpty($realtime->subscriptions);
    }

    public function testSubscribeUnionsChannelsAndRoles(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::user(ID::custom('123'))->toString()],
            ['documents'],
        );

        $realtime->subscribe(
            '1',
            1,
            'sub-b',
            [Role::users()->toString()],
            ['files'],
        );

        $connection = $realtime->connections[1];

        $this->assertContains('documents', $connection['channels']);
        $this->assertContains('files', $connection['channels']);
        $this->assertContains(Role::user(ID::custom('123'))->toString(), $connection['roles']);
        $this->assertContains(Role::users()->toString(), $connection['roles']);
        $this->assertCount(2, $connection['channels']);
        $this->assertCount(2, $connection['roles']);
    }

    public function testUnsubscribeSubscriptionRemovesOnlyOneSubscription(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::user(ID::custom('123'))->toString()],
            ['documents'],
        );

        $realtime->subscribe(
            '1',
            1,
            'sub-b',
            [Role::users()->toString()],
            ['files'],
        );

        $removed = $realtime->unsubscribeSubscription(1, 'sub-a');

        $this->assertTrue($removed);
        $this->assertArrayHasKey(1, $realtime->connections);

        // sub-a is fully cleaned from the tree
        $this->assertArrayNotHasKey(
            Role::user(ID::custom('123'))->toString(),
            $realtime->subscriptions['1']
        );

        // sub-b still delivers
        $event = [
            'project' => '1',
            'roles' => [Role::users()->toString()],
            'data' => [
                'channels' => ['files'],
            ],
        ];
        $receivers = array_keys($realtime->getSubscribers($event));
        $this->assertEquals([1], $receivers);

        // Channels recomputed: sub-a's channel is gone
        $this->assertSame(['files'], $realtime->connections[1]['channels']);

        // Roles are connection-level auth context — union of both subscribe calls preserved
        $this->assertContains(Role::user(ID::custom('123'))->toString(), $realtime->connections[1]['roles']);
        $this->assertContains(Role::users()->toString(), $realtime->connections[1]['roles']);
    }

    public function testUnsubscribeSubscriptionIsIdempotent(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::users()->toString()],
            ['documents'],
        );

        $this->assertFalse($realtime->unsubscribeSubscription(1, 'does-not-exist'));
        $this->assertFalse($realtime->unsubscribeSubscription(99, 'sub-a'));

        // Original sub is untouched
        $event = [
            'project' => '1',
            'roles' => [Role::users()->toString()],
            'data' => [
                'channels' => ['documents'],
            ],
        ];
        $this->assertEquals([1], array_keys($realtime->getSubscribers($event)));
    }

    public function testUnsubscribeSubscriptionKeepsConnectionWhenLastSubRemoved(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::users()->toString()],
            ['documents'],
        );

        $this->assertTrue($realtime->unsubscribeSubscription(1, 'sub-a'));

        $this->assertArrayHasKey(1, $realtime->connections);
        $this->assertSame([], $realtime->connections[1]['channels']);
        // Roles preserved so a later resubscribe on the same connection still has auth context
        $this->assertSame([Role::users()->toString()], $realtime->connections[1]['roles']);
        $this->assertArrayNotHasKey('1', $realtime->subscriptions);
    }

    public function testResubscribeAfterUnsubscribingLastSubDelivers(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::users()->toString()],
            ['documents'],
        );

        $this->assertTrue($realtime->unsubscribeSubscription(1, 'sub-a'));

        // Simulate the message-based subscribe path reading stored roles
        $storedRoles = $realtime->connections[1]['roles'];
        $this->assertNotEmpty($storedRoles, 'connection roles must survive per-subscription removal');

        $realtime->subscribe('1', 1, 'sub-b', $storedRoles, ['files']);

        $event = [
            'project' => '1',
            'roles' => [Role::users()->toString()],
            'data' => [
                'channels' => ['files'],
            ],
        ];
        $this->assertEquals([1], array_keys($realtime->getSubscribers($event)));
    }

    public function testSubscribeAfterOnOpenEmptySentinelPreservesUnion(): void
    {
        $realtime = new Realtime();

        // Mirrors the onOpen empty-channels path: subscribe with '' id, empty channels
        $realtime->subscribe(
            '1',
            1,
            '',
            [Role::users()->toString()],
            [],
            [],
            'user-123',
        );

        // Now a real subscription comes in via the subscribe message type
        $realtime->subscribe(
            '1',
            1,
            'sub-a',
            [Role::user(ID::custom('user-123'))->toString()],
            ['documents'],
        );

        $this->assertSame('user-123', $realtime->connections[1]['userId']);
        $this->assertContains('documents', $realtime->connections[1]['channels']);
        $this->assertContains(Role::users()->toString(), $realtime->connections[1]['roles']);
        $this->assertContains(Role::user(ID::custom('user-123'))->toString(), $realtime->connections[1]['roles']);
    }

    public function testConvertChannelsGuest(): void
    {
        $user = new Document([
            '$id' => ''
        ]);

        $channels = [
            0 => 'files',
            1 => 'documents',
            2 => 'documents.789',
            3 => 'account',
            4 => 'account.456'
        ];

        $channels = Realtime::convertChannels($channels, $user->getId());
        $this->assertCount(4, $channels);
        $this->assertArrayHasKey('files', $channels);
        $this->assertArrayHasKey('documents', $channels);
        $this->assertArrayHasKey('documents.789', $channels);
        $this->assertArrayHasKey('account', $channels);
        $this->assertArrayNotHasKey('account.456', $channels);
    }

    public function testConvertChannelsUser(): void
    {
        $user  = new Document([
            '$id' => ID::custom('123'),
            'memberships' => [
                [
                    'teamId' => ID::custom('abc'),
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    'teamId' => ID::custom('def'),
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);
        $channels = [
            0 => 'files',
            1 => 'documents',
            2 => 'documents.789',
            3 => 'account',
            4 => 'account.456'
        ];

        $channels = Realtime::convertChannels($channels, $user->getId());

        $this->assertCount(5, $channels);
        $this->assertArrayHasKey('files', $channels);
        $this->assertArrayHasKey('documents', $channels);
        $this->assertArrayHasKey('documents.789', $channels);
        $this->assertArrayHasKey('account.123', $channels);
        $this->assertArrayHasKey('account', $channels);
        $this->assertArrayNotHasKey('account.456', $channels);
    }

    public function testFromPayloadPermissions(): void
    {
        /**
         * Test Collection Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'databases.database_id.collections.collection_id.documents.document_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
            ]),
            database: new Document([
                '$id' => ID::custom('database'),
            ]),
            collection: new Document([
                '$id' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertNotContains(Role::team('123abc')->toString(), $result['roles']);

        /**
         * Test Document Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'databases.database_id.collections.collection_id.documents.document_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
            database: new Document([
                '$id' => ID::custom('database'),
            ]),
            collection: new Document([
                '$id' => ID::custom('collection'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
                'documentSecurity' => true,
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertContains(Role::team('123abc')->toString(), $result['roles']);
    }

    public function testFromPayloadBucketLevelPermissions(): void
    {
        /**
         * Test Bucket Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'buckets.bucket_id.files.file_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
            ]),
            bucket: new Document([
                '$id' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertNotContains(Role::team('123abc')->toString(), $result['roles']);

        /**
         * Test File Level Permissions
         */
        $result = Realtime::fromPayload(
            event: 'buckets.bucket_id.files.file_id.create',
            payload: new Document([
                '$id' => ID::custom('test'),
                '$collection' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
            ]),
            bucket: new Document([
                '$id' => ID::custom('bucket'),
                '$permissions' => [
                    Permission::read(Role::team('123abc')),
                    Permission::update(Role::team('123abc')),
                    Permission::delete(Role::team('123abc')),
                ],
                'fileSecurity' => true
            ])
        );

        $this->assertContains(Role::any()->toString(), $result['roles']);
        $this->assertContains(Role::team('123abc')->toString(), $result['roles']);
    }

    public function testHasSubscriberIsActionChannelAware(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-create',
            [Role::any()->toString()],
            ['documents.create'],
        );

        // Plain base lookup hits the subscription.
        $this->assertTrue($realtime->hasSubscriber('1', Role::any()->toString(), 'documents'));

        // Action-channel lookup matches when the action is in the stored list.
        $this->assertTrue($realtime->hasSubscriber('1', Role::any()->toString(), 'documents.create'));

        // Action-channel lookup misses when the action is not stored — even though
        // the base channel exists.
        $this->assertFalse($realtime->hasSubscriber('1', Role::any()->toString(), 'documents.update'));

        // Unknown project / role still resolves to false.
        $this->assertFalse($realtime->hasSubscriber('nope', Role::any()->toString(), 'documents.create'));
        $this->assertFalse($realtime->hasSubscriber('1', 'role:other', 'documents.create'));

        // No-channel form still works.
        $this->assertTrue($realtime->hasSubscriber('1', Role::any()->toString()));
    }

    public function testHasSubscriberWildcardActionsSubsumeSpecific(): void
    {
        $realtime = new Realtime();

        // Subscribing to plain `documents` stores actions = ['*']. Any action-channel
        // lookup against the same base must succeed because '*' subsumes specific actions.
        $realtime->subscribe(
            '1',
            1,
            'sub-all',
            [Role::any()->toString()],
            ['documents'],
        );

        $this->assertTrue($realtime->hasSubscriber('1', Role::any()->toString(), 'documents'));
        $this->assertTrue($realtime->hasSubscriber('1', Role::any()->toString(), 'documents.create'));
        $this->assertTrue($realtime->hasSubscriber('1', Role::any()->toString(), 'documents.update'));
        $this->assertTrue($realtime->hasSubscriber('1', Role::any()->toString(), 'documents.delete'));
    }

    public function testParseActionChannel(): void
    {
        $this->assertSame(['documents', 'create'], Realtime::parseActionChannel('documents.create'));
        $this->assertSame(['documents', 'update'], Realtime::parseActionChannel('documents.update'));
        $this->assertSame(['documents', 'upsert'], Realtime::parseActionChannel('documents.upsert'));
        $this->assertSame(['documents', 'delete'], Realtime::parseActionChannel('documents.delete'));
        $this->assertSame(
            ['databases.X.collections.Y.documents.Z', 'create'],
            Realtime::parseActionChannel('databases.X.collections.Y.documents.Z.create')
        );
        $this->assertSame(
            ['databases.X.collections.Y.documents.Z', 'delete'],
            Realtime::parseActionChannel('databases.X.collections.Y.documents.Z.delete')
        );

        // No action suffix → unchanged with '*' default.
        $this->assertSame(['documents', '*'], Realtime::parseActionChannel('documents'));
        $this->assertSame(['documents.789', '*'], Realtime::parseActionChannel('documents.789'));

        // Unrecognised suffix → unchanged (treated as literal channel name).
        $this->assertSame(['documents.bogus', '*'], Realtime::parseActionChannel('documents.bogus'));
    }

    public function testActionChannelFiltersByEventAction(): void
    {
        $realtime = new Realtime();

        // Two subscriptions on the same connection: one filtered to creates only,
        // one filtered to updates only.
        $realtime->subscribe(
            '1',
            1,
            'sub-create',
            [Role::any()->toString()],
            ['documents.create'],
        );
        $realtime->subscribe(
            '1',
            1,
            'sub-update',
            [Role::any()->toString()],
            ['documents.update'],
        );

        $createEvent = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents'],
                'events' => [
                    'databases.db.collections.col.documents.doc.create',
                    'databases.*.collections.*.documents.*.create',
                ],
                'payload' => ['$id' => 'doc'],
            ],
        ];

        $updateEvent = $createEvent;
        $updateEvent['data']['events'] = [
            'databases.db.collections.col.documents.doc.update',
            'databases.*.collections.*.documents.*.update',
        ];

        // Create event should only deliver to sub-create.
        $receivers = $realtime->getSubscribers($createEvent);
        $this->assertCount(1, $receivers);
        $this->assertArrayHasKey(1, $receivers);
        $this->assertArrayHasKey('sub-create', $receivers[1]);
        $this->assertArrayNotHasKey('sub-update', $receivers[1]);

        // Update event should only deliver to sub-update.
        $receivers = $realtime->getSubscribers($updateEvent);
        $this->assertCount(1, $receivers);
        $this->assertArrayHasKey('sub-update', $receivers[1]);
        $this->assertArrayNotHasKey('sub-create', $receivers[1]);
    }

    public function testActionChannelDeleteFilter(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-delete',
            [Role::any()->toString()],
            ['documents.delete'],
        );

        $deleteEvent = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents'],
                'events' => [
                    'databases.db.collections.col.documents.doc.delete',
                    'databases.*.collections.*.documents.*.delete',
                ],
                'payload' => ['$id' => 'doc'],
            ],
        ];

        $receivers = $realtime->getSubscribers($deleteEvent);
        $this->assertArrayHasKey(1, $receivers);
        $this->assertArrayHasKey('sub-delete', $receivers[1]);

        // Other actions on the same base channel should not match the delete filter.
        $createEvent = $deleteEvent;
        $createEvent['data']['events'] = [
            'databases.db.collections.col.documents.doc.create',
            'databases.*.collections.*.documents.*.create',
        ];
        $this->assertEmpty($realtime->getSubscribers($createEvent));
    }

    public function testActionChannelHonorsResourceId(): void
    {
        $realtime = new Realtime();

        // Subscribe to creates on a specific document only.
        $realtime->subscribe(
            '1',
            1,
            'sub-doc-create',
            [Role::any()->toString()],
            ['documents.789.create'],
        );

        // The base channel for `documents.789.create` is `documents.789`.
        $event = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents.789'],
                'events' => [
                    'databases.db.collections.col.documents.789.create',
                    'databases.*.collections.*.documents.*.create',
                ],
                'payload' => ['$id' => '789'],
            ],
        ];

        $receivers = $realtime->getSubscribers($event);
        $this->assertCount(1, $receivers);
        $this->assertArrayHasKey('sub-doc-create', $receivers[1]);

        // Update on the same document should not match.
        $event['data']['events'] = [
            'databases.db.collections.col.documents.789.update',
            'databases.*.collections.*.documents.*.update',
        ];

        $this->assertEmpty($realtime->getSubscribers($event));

        // Create on a different document should not match (different base channel
        // entirely; subscription tree key won't even line up).
        $event['data']['channels'] = ['documents.999'];
        $event['data']['events'] = [
            'databases.db.collections.col.documents.999.create',
            'databases.*.collections.*.documents.*.create',
        ];

        $this->assertEmpty($realtime->getSubscribers($event));
    }

    public function testNonActionChannelStillReceivesAllEvents(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-all',
            [Role::any()->toString()],
            ['documents'],
        );

        $event = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents'],
                'events' => [
                    'databases.db.collections.col.documents.doc.create',
                ],
                'payload' => ['$id' => 'doc'],
            ],
        ];

        $this->assertArrayHasKey(1, $realtime->getSubscribers($event));

        $event['data']['events'] = ['databases.db.collections.col.documents.doc.update'];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($event));

        $event['data']['events'] = ['databases.db.collections.col.documents.doc.upsert'];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($event));
    }

    public function testMixedActionAndBaseChannelInSameSubscription(): void
    {
        $realtime = new Realtime();

        // Same sub-id covers `documents.create` (filtered) and `files` (unfiltered).
        // After parsing they live under different base-channel keys with their own
        // action metadata, so each gets its own filter behaviour.
        $realtime->subscribe(
            '1',
            1,
            'sub-mixed',
            [Role::any()->toString()],
            ['documents.create', 'files'],
        );

        // Create event on documents → matches.
        $createDoc = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents'],
                'events' => ['databases.db.collections.col.documents.doc.create'],
                'payload' => [],
            ],
        ];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($createDoc));

        // Update event on documents → blocked by the action filter on the documents key.
        $updateDoc = $createDoc;
        $updateDoc['data']['events'] = ['databases.db.collections.col.documents.doc.update'];
        $this->assertEmpty($realtime->getSubscribers($updateDoc));

        // Files channel has no action filter — any action delivers.
        $updateFile = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['files'],
                'events' => ['buckets.bucket.files.file.update'],
                'payload' => [],
            ],
        ];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($updateFile));
    }

    public function testActionChannelMetadataRoundTrips(): void
    {
        $realtime = new Realtime();

        $realtime->subscribe(
            '1',
            1,
            'sub-create',
            [Role::any()->toString()],
            ['documents.create', 'files'],
        );

        $meta = $realtime->getSubscriptionMetadata(1);

        $this->assertArrayHasKey('sub-create', $meta);
        $this->assertContains('documents.create', $meta['sub-create']['channels']);
        $this->assertContains('files', $meta['sub-create']['channels']);
        // Base form should NOT leak when an action was set.
        $this->assertNotContains('documents', $meta['sub-create']['channels']);
    }

    public function testSubscribeWithSameSubIdReplacesActionsNotMerges(): void
    {
        $realtime = new Realtime();
        $role = Role::any()->toString();

        // Initial subscribe: only `create` events on the documents base.
        $realtime->subscribe('1', 1, 'sub-x', [$role], ['documents.create']);

        $createEvent = [
            'project' => '1',
            'roles' => [$role],
            'data' => [
                'channels' => ['documents'],
                'events' => ['databases.db.collections.col.documents.doc.create'],
                'payload' => [],
            ],
        ];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($createEvent));

        // Re-subscribe with the SAME sub-id but a different action. Per the upsert
        // contract documented on Realtime::subscribe, this fully replaces the prior
        // state — actions are NOT unioned across calls (channels and queries already
        // followed replace-not-merge semantics; actions match that rule).
        $realtime->subscribe('1', 1, 'sub-x', [$role], ['documents.update']);

        // Create no longer matches: previous filter is gone.
        $this->assertEmpty($realtime->getSubscribers($createEvent));

        // Update now matches.
        $updateEvent = $createEvent;
        $updateEvent['data']['events'] = ['databases.db.collections.col.documents.doc.update'];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($updateEvent));

        // Metadata reflects only the new state.
        $meta = $realtime->getSubscriptionMetadata(1);
        $this->assertContains('documents.update', $meta['sub-x']['channels']);
        $this->assertNotContains('documents.create', $meta['sub-x']['channels']);
    }

    public function testActionAndBaseChannelTogetherRoundTripsLosslessly(): void
    {
        $realtime = new Realtime();

        // Subscribing with both a specific-action channel AND its plain base form must
        // preserve both names: '*' short-circuits delivery (so update events still
        // come through), but the metadata kept for re-auth/permissions-changed flows
        // would otherwise drop `documents.create` entirely on the next refresh.
        $realtime->subscribe(
            '1',
            1,
            'sub-mixed',
            [Role::any()->toString()],
            ['documents.create', 'documents'],
        );

        $meta = $realtime->getSubscriptionMetadata(1);
        $this->assertContains('documents.create', $meta['sub-mixed']['channels']);
        $this->assertContains('documents', $meta['sub-mixed']['channels']);

        // Update events still deliver because '*' is in the actions list.
        $updateEvent = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents'],
                'events' => ['databases.db.collections.col.documents.doc.update'],
                'payload' => [],
            ],
        ];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($updateEvent));

        // Round-trip: feed the metadata back through subscribe() and the original
        // pair of channel names must come out again.
        $realtime->unsubscribe(1);
        $realtime->subscribe(
            '1',
            1,
            'sub-mixed',
            [Role::any()->toString()],
            $meta['sub-mixed']['channels'],
        );

        $metaAgain = $realtime->getSubscriptionMetadata(1);
        $this->assertContains('documents.create', $metaAgain['sub-mixed']['channels']);
        $this->assertContains('documents', $metaAgain['sub-mixed']['channels']);
    }

    public function testMergingMultipleActionsOnSameBaseChannel(): void
    {
        $realtime = new Realtime();

        // Subscribing to multiple actions on the same base merges their action lists
        // onto a single tree entry.
        $realtime->subscribe(
            '1',
            1,
            'sub-multi',
            [Role::any()->toString()],
            ['documents.create', 'documents.update'],
        );

        $createEvent = [
            'project' => '1',
            'roles' => [Role::any()->toString()],
            'data' => [
                'channels' => ['documents'],
                'events' => ['databases.db.collections.col.documents.doc.create'],
                'payload' => [],
            ],
        ];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($createEvent));

        $updateEvent = $createEvent;
        $updateEvent['data']['events'] = ['databases.db.collections.col.documents.doc.update'];
        $this->assertArrayHasKey(1, $realtime->getSubscribers($updateEvent));

        // Upsert should not match — neither create nor update covers it.
        $upsertEvent = $createEvent;
        $upsertEvent['data']['events'] = ['databases.db.collections.col.documents.doc.upsert'];
        $this->assertEmpty($realtime->getSubscribers($upsertEvent));

        $meta = $realtime->getSubscriptionMetadata(1);
        $this->assertContains('documents.create', $meta['sub-multi']['channels']);
        $this->assertContains('documents.update', $meta['sub-multi']['channels']);
    }
}
