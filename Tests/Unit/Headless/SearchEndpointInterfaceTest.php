<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ApacheSolrForTypo3\Solr\Tests\Unit\Headless;

use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSetService;
use ApacheSolrForTypo3\Solr\Headless\SearchEndpointInterface;
use ApacheSolrForTypo3\Solr\Tests\Unit\SetUpUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies that SearchEndpointInterface defines the expected contract and that
 * SearchResultSetService satisfies it.
 */
#[CoversClass(SearchEndpointInterface::class)]
class SearchEndpointInterfaceTest extends SetUpUnitTestCase
{
    #[Test]
    public function interfaceDeclaresSearchMethod(): void
    {
        self::assertTrue(
            method_exists(SearchEndpointInterface::class, 'search'),
            'SearchEndpointInterface must declare a search() method',
        );
    }

    #[Test]
    public function searchResultSetServiceImplementsSearchEndpointInterface(): void
    {
        self::assertTrue(
            is_a(SearchResultSetService::class, SearchEndpointInterface::class, true),
            'SearchResultSetService must implement SearchEndpointInterface',
        );
    }
}
