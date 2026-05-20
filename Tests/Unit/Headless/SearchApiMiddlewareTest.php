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

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Facets\FacetCollection;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResultCollection;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSet;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSetService;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Sorting\SortingCollection;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequest;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequestBuilder;
use ApacheSolrForTypo3\Solr\Headless\SearchApiMiddleware;
use ApacheSolrForTypo3\Solr\Headless\SearchEndpointInterface;
use ApacheSolrForTypo3\Solr\NoSolrConnectionFoundException;
use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use ApacheSolrForTypo3\Solr\Tests\Unit\SetUpUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;

/**
 * Unit tests for SearchApiMiddleware.
 *
 * Tests covering path-guard conditions do not require a real Solr connection.
 * The success and error paths override buildSearchService() via an anonymous
 * subclass to keep the test free of GeneralUtility::makeInstance() coupling.
 */
#[CoversClass(SearchApiMiddleware::class)]
class SearchApiMiddlewareTest extends SetUpUnitTestCase
{
    protected ConnectionManager|MockObject $connectionManagerMock;

    /** Captures the forwarded request for inspection */
    protected RequestHandlerInterface $passThroughHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionManagerMock = $this->getMockBuilder(ConnectionManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->passThroughHandler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new NullResponse();
            }
        };
    }

    // -------------------------------------------------------------------------
    // Pass-through cases (middleware must not intercept)
    // -------------------------------------------------------------------------

    #[Test]
    public function passesRequestThroughWhenNoFrontendTyposcriptAttribute(): void
    {
        $request = new ServerRequest('https://example.com/api/solr/search', 'POST');
        $middleware = new SearchApiMiddleware($this->connectionManagerMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertInstanceOf(NullResponse::class, $response);
    }

    #[Test]
    public function passesRequestThroughWhenMethodIsNotPost(): void
    {
        // Method guard fires before TypoScript is loaded – no frontend.typoscript needed
        $request = new ServerRequest('https://example.com/api/solr/search', 'GET');
        $middleware = new SearchApiMiddleware($this->connectionManagerMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertInstanceOf(NullResponse::class, $response);
    }

    #[Test]
    public function passesRequestThroughWhenPathDoesNotMatch(): void
    {
        // Path guard fires after TypoScript is loaded – needs frontend.typoscript attribute
        $request = $this->buildRequest('POST', 'https://example.com/some-other-path');
        $middleware = new SearchApiMiddleware($this->connectionManagerMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertInstanceOf(NullResponse::class, $response);
    }

    // -------------------------------------------------------------------------
    // Origin validation
    // -------------------------------------------------------------------------

    #[Test]
    public function returnsForbiddenResponseForCrossOriginRequest(): void
    {
        $request = $this->buildRequest('POST', 'https://example.com/api/solr/search')
            ->withHeader('Origin', 'https://evil.example.org');
        $middleware = new SearchApiMiddleware($this->connectionManagerMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Forbidden', (string)$response->getBody());
    }

    #[Test]
    public function allowsRequestWithMatchingOriginHeader(): void
    {
        $solrConnectionMock = $this->createMock(SolrConnection::class);
        $this->connectionManagerMock->method('getConnectionByTypo3Site')->willReturn($solrConnectionMock);

        $resultSetMock = $this->buildEmptyResultSetMock();

        $request = $this->buildRequest('POST', 'https://example.com/api/solr/search')
            ->withHeader('Origin', 'https://example.com')
            ->withBody($this->createBodyStream('{"q":"test"}'));

        $middleware = $this->buildMiddlewareWithSearchService($resultSetMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function allowsRequestWithoutOriginHeader(): void
    {
        $solrConnectionMock = $this->createMock(SolrConnection::class);
        $this->connectionManagerMock->method('getConnectionByTypo3Site')->willReturn($solrConnectionMock);

        $resultSetMock = $this->buildEmptyResultSetMock();

        $request = $this->buildRequest('POST', 'https://example.com/api/solr/search')
            ->withBody($this->createBodyStream('{}'));

        $middleware = $this->buildMiddlewareWithSearchService($resultSetMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Solr unavailable
    // -------------------------------------------------------------------------

    #[Test]
    public function returns503WhenSolrConnectionCannotBeEstablished(): void
    {
        $this->connectionManagerMock
            ->method('getConnectionByTypo3Site')
            ->willThrowException(new NoSolrConnectionFoundException());

        $request = $this->buildRequest('POST', 'https://example.com/api/solr/search')
            ->withBody($this->createBodyStream('{}'));

        $middleware = new SearchApiMiddleware($this->connectionManagerMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('not available', (string)$response->getBody());
    }

    // -------------------------------------------------------------------------
    // Success path
    // -------------------------------------------------------------------------

    #[Test]
    public function returnsJsonWithResultCountOnSuccessfulSearch(): void
    {
        $solrConnectionMock = $this->createMock(SolrConnection::class);
        $this->connectionManagerMock->method('getConnectionByTypo3Site')->willReturn($solrConnectionMock);

        $resultSetMock = $this->buildEmptyResultSetMock(42);

        $request = $this->buildRequest('POST', 'https://example.com/api/solr/search')
            ->withBody($this->createBodyStream('{"q":"solr"}'));

        $middleware = $this->buildMiddlewareWithSearchService($resultSetMock);

        $response = $middleware->process($request, $this->passThroughHandler);
        $body = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(42, $body['allResultCount']);
    }

    #[Test]
    public function returns400ForInvalidJsonBody(): void
    {
        $solrConnectionMock = $this->createMock(SolrConnection::class);
        $this->connectionManagerMock->method('getConnectionByTypo3Site')->willReturn($solrConnectionMock);

        $request = $this->buildRequest('POST', 'https://example.com/api/solr/search')
            ->withBody($this->createBodyStream('{invalid-json'));

        $middleware = new SearchApiMiddleware($this->connectionManagerMock);

        $response = $middleware->process($request, $this->passThroughHandler);

        self::assertSame(400, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a POST ServerRequest with a FrontendTypoScript attribute attached,
     * simulating a request that has passed prepare-tsfe-rendering.
     */
    private function buildRequest(string $method, string $uri): ServerRequest
    {
        $frontendTyposcriptMock = $this->getMockBuilder(FrontendTypoScript::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSetupArray'])
            ->getMock();
        $frontendTyposcriptMock->method('getSetupArray')->willReturn([]);

        $siteMock = $this->getMockBuilder(Site::class)
            ->disableOriginalConstructor()
            ->getMock();

        return (new ServerRequest($uri, $method))
            ->withAttribute('frontend.typoscript', $frontendTyposcriptMock)
            ->withAttribute('site', $siteMock);
    }

    private function createBodyStream(string $content): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $content);
        rewind($resource);
        return new Stream($resource);
    }

    /**
     * Build an empty SearchResultSet mock suitable for non-asserting response tests.
     */
    private function buildEmptyResultSetMock(int $allResultCount = 0): SearchResultSet
    {
        $resultSetMock = $this->getMockBuilder(SearchResultSet::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getAllResultCount',
                'getHasSearched',
                'getUsedPage',
                'getUsedResultsPerPage',
                'getUsedQuery',
                'getIsAutoCorrected',
                'getInitialQueryString',
                'getCorrectedQueryString',
                'getSearchResults',
                'getFacets',
                'getSortings',
            ])
            ->getMock();

        $resultSetMock->method('getAllResultCount')->willReturn($allResultCount);
        $resultSetMock->method('getHasSearched')->willReturn(true);
        $resultSetMock->method('getUsedPage')->willReturn(1);
        $resultSetMock->method('getUsedResultsPerPage')->willReturn(10);
        $resultSetMock->method('getUsedQuery')->willReturn(null);
        $resultSetMock->method('getIsAutoCorrected')->willReturn(false);
        $resultSetMock->method('getInitialQueryString')->willReturn('');
        $resultSetMock->method('getCorrectedQueryString')->willReturn('');
        $resultSetMock->method('getSearchResults')->willReturn(new SearchResultCollection());
        $resultSetMock->method('getFacets')->willReturn(new FacetCollection());
        $resultSetMock->method('getSortings')->willReturn(new SortingCollection());

        return $resultSetMock;
    }

    /**
     * Build a SearchApiMiddleware subclass that returns a pre-built SearchEndpointInterface
     * mock, bypassing GeneralUtility::makeInstance() in buildSearchService() and
     * buildSearchRequestBuilder() to avoid FrontendUserSession session bootstrap.
     */
    private function buildMiddlewareWithSearchService(SearchResultSet $resultSet): SearchApiMiddleware
    {
        $searchServiceMock = $this->getMockBuilder(SearchResultSetService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['search'])
            ->getMock();
        $searchServiceMock->method('search')->willReturn($resultSet);

        $searchRequest = $this->getMockBuilder(SearchRequest::class)
            ->disableOriginalConstructor()
            ->getMock();

        $searchRequestBuilderMock = $this->getMockBuilder(SearchRequestBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['buildForSearch'])
            ->getMock();
        $searchRequestBuilderMock->method('buildForSearch')->willReturn($searchRequest);

        $connectionManager = $this->connectionManagerMock;

        return new class ($connectionManager, $searchServiceMock, $searchRequestBuilderMock) extends SearchApiMiddleware {
            public function __construct(
                ConnectionManager $connectionManager,
                private readonly SearchEndpointInterface $injectedService,
                private readonly SearchRequestBuilder $injectedBuilder,
            ) {
                parent::__construct($connectionManager);
            }

            protected function buildSearchService(
                TypoScriptConfiguration $configuration,
                SolrConnection $solrConnection,
            ): SearchEndpointInterface {
                return $this->injectedService;
            }

            protected function buildSearchRequestBuilder(TypoScriptConfiguration $configuration): SearchRequestBuilder
            {
                return $this->injectedBuilder;
            }
        };
    }
}
