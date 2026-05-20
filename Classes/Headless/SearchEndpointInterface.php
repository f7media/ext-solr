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

namespace ApacheSolrForTypo3\Solr\Headless;

use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSet;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequest;

/**
 * Contract for services that execute a Solr search and return a SearchResultSet.
 *
 * Custom frontend controllers in headless setups should type-hint against this
 * interface rather than the concrete SearchResultSetService, keeping them
 * decoupled from the underlying implementation.
 *
 * Because SearchResultSetService requires per-request runtime arguments
 * (TypoScriptConfiguration, Search), it cannot be wired automatically via the
 * DI container. Instantiate it with GeneralUtility::makeInstance() and then
 * type-hint the result as SearchEndpointInterface for clarity.
 *
 * @see \ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSetService
 */
interface SearchEndpointInterface
{
    /**
     * Execute a search for the given request and return the populated result set.
     */
    public function search(SearchRequest $request): SearchResultSet;
}
