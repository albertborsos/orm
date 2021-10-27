<?php

declare(strict_types=1);

namespace Cycle\ORM\Tests\Functional\Driver\MySQL\Relation\RefersTo;

// phpcs:ignore
use Cycle\ORM\Tests\Functional\Driver\Common\Relation\RefersTo\RefersToRelationMiniRowsetTest as CommonTest;

/**
 * @group driver
 * @group driver-mysql
 */
class RefersToRelationMiniRowsetTest extends CommonTest
{
    public const DRIVER = 'mysql';
}