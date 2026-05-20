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

use ApacheSolrForTypo3\Solr\ConnectionManager;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Facets\AbstractFacet;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResult;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSet;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSetService;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Sorting\Sorting;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequestBuilder;
use ApacheSolrForTypo3\Solr\NoSolrConnectionFoundException;
use ApacheSolrForTypo3\Solr\Search;
use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use ApacheSolrForTypo3\Solr\System\Logging\SolrLogManager;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PSR-15 middleware providing a lightweight JSON search API for headless setups.
 *
 * Only registered when plugin.tx_solr.features.headless = 1 (or the equivalent
 * TYPO3_CONF_VARS['EXTENSIONS']['solr']['headless'] = 1 key). Accepts POST
 * requests at the configured path, validates same-origin Origin, executes the
 * search via SearchResultSetService, and returns the result set as JSON.
 *
 * This middleware is intentionally thin: it maps the JSON body to a
 * SearchRequest, delegates all search logic to SearchResultSetService, and
 * serialises the result. Business logic stays in the domain layer.
 */
class SearchApiMiddleware implements MiddlewareInterface
{
    /**
     * Default path at which the headless search API listens.
     * Override via TypoScript: plugin.tx_solr.features.headless.apiPath
     */
    public const DEFAULT_API_PATH = '/api/solr/search';

    public function __construct(
        private readonly ConnectionManager $connectionManager,
        private readonly ?SolrLogManager $logger = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Cheapest guard: reject non-POST without loading TypoScript
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        // Only activate after TSFE/TypoScript is set up – skip everything else
        $frontendTyposcript = $request->getAttribute('frontend.typoscript');
        if (!$frontendTyposcript instanceof FrontendTypoScript) {
            return $handler->handle($request);
        }

        $pageId = $request->getAttribute('routing')?->getPageId() ?? 0;
        $typoScriptConfiguration = GeneralUtility::makeInstance(
            TypoScriptConfiguration::class,
            $frontendTyposcript->getSetupArray(),
            $pageId,
        );

        $apiPath = (string)$typoScriptConfiguration->getValueByPathOrDefaultValue(
            'plugin.tx_solr.features.headless.apiPath',
            self::DEFAULT_API_PATH,
        );

        if ($request->getUri()->getPath() !== $apiPath) {
            return $handler->handle($request);
        }

        if (!$this->validateOrigin($request)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $languageId = (int)($request->getAttribute('language')?->getLanguageId() ?? 0);

        // Validate and parse body before any Solr I/O so invalid-JSON returns 400
        // without needing a Solr connection (keeps 400/503 semantics correct).
        $body = [];
        try {
            $body = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        try {
            $solrConnection = $this->connectionManager->getConnectionByTypo3Site(
                $request->getAttribute('site'),
                $languageId,
            );

            /** @var SearchEndpointInterface $searchService */
            $searchService = $this->buildSearchService($typoScriptConfiguration, $solrConnection);

            $searchRequest = $this->buildSearchRequestBuilder($typoScriptConfiguration)
                ->buildForSearch($body, $pageId, $languageId);

            $resultSet = $searchService->search($searchRequest);

            return new JsonResponse($this->serializeResultSet($resultSet));
        } catch (NoSolrConnectionFoundException) {
            $this->logger?->error('Solr is not available for headless search request');
            return new JsonResponse(['error' => 'Solr is not available'], 503);
        }
    }

    /**
     * Build the SearchRequestBuilder for the given TypoScript context.
     *
     * Extracted as a protected method so tests can override it without
     * mocking GeneralUtility::makeInstance or setting up FrontendUserSession.
     */
    protected function buildSearchRequestBuilder(TypoScriptConfiguration $configuration): SearchRequestBuilder
    {
        return GeneralUtility::makeInstance(SearchRequestBuilder::class, $configuration);
    }

    /**
     * Build the search service for the given request context.
     *
     * Extracted as a protected method so tests can override it without
     * mocking GeneralUtility::makeInstance directly.
     */
    protected function buildSearchService(
        TypoScriptConfiguration $configuration,
        SolrConnection $solrConnection,
    ): SearchEndpointInterface {
        $search = GeneralUtility::makeInstance(Search::class, $solrConnection);
        return GeneralUtility::makeInstance(SearchResultSetService::class, $configuration, $search);
    }

    /**
     * Verify that the Origin header, when present, matches the request host.
     *
     * Requests without an Origin header (same-site navigations, curl, etc.)
     * are allowed through so integrators can test without CORS headers.
     */
    private function validateOrigin(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return true;
        }

        $parsed = parse_url($origin);
        if (!is_array($parsed)) {
            return false;
        }

        $requestUri = $request->getUri();

        $sameHost = ($parsed['host'] ?? '') === $requestUri->getHost();
        $sameScheme = ($parsed['scheme'] ?? '') === $requestUri->getScheme();
        $samePort = ($parsed['port'] ?? null) === ($requestUri->getPort() ?: null);

        return $sameHost && $sameScheme && $samePort;
    }

    /**
     * Serialize a SearchResultSet to a plain array suitable for JSON encoding.
     *
     * The structure is intentionally flat and stable across minor versions.
     * Integrators who need richer data (e.g. full facet option lists) should
     * extend this middleware or dispatch a PSR-14 event on the result set
     * before it reaches this method.
     */
    private function serializeResultSet(SearchResultSet $resultSet): array
    {
        return [
            'allResultCount' => $resultSet->getAllResultCount(),
            'hasSearched' => $resultSet->getHasSearched(),
            'usedPage' => $resultSet->getUsedPage(),
            'usedResultsPerPage' => $resultSet->getUsedResultsPerPage(),
            'usedQuery' => (string)($resultSet->getUsedQuery() ?? ''),
            'isAutoCorrected' => $resultSet->getIsAutoCorrected(),
            'initialQueryString' => $resultSet->getIsAutoCorrected() ? $resultSet->getInitialQueryString() : '',
            'correctedQueryString' => $resultSet->getIsAutoCorrected() ? $resultSet->getCorrectedQueryString() : '',
            'documents' => $this->serializeDocuments($resultSet),
            'facets' => $this->serializeFacets($resultSet),
            'sortings' => $this->serializeSortings($resultSet),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeDocuments(SearchResultSet $resultSet): array
    {
        $documents = [];
        foreach ($resultSet->getSearchResults() as $result) {
            /** @var SearchResult $result */
            $fields = [];
            foreach ($result->getFieldNames() as $fieldName) {
                $fields[$fieldName] = $result[$fieldName];
            }
            $documents[] = $fields;
        }
        return $documents;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeFacets(SearchResultSet $resultSet): array
    {
        $facets = [];
        foreach ($resultSet->getFacets() as $facet) {
            /** @var AbstractFacet $facet */
            $facets[] = [
                'name' => $facet->getName(),
                'field' => $facet->getField(),
                'label' => $facet->getLabel(),
                'isUsed' => $facet->getIsUsed(),
                'isAvailable' => $facet->getIsAvailable(),
            ];
        }
        return $facets;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeSortings(SearchResultSet $resultSet): array
    {
        $sortings = [];
        foreach ($resultSet->getSortings() as $sorting) {
            /** @var Sorting $sorting */
            $sortings[] = [
                'name' => $sorting->getName(),
                'field' => $sorting->getField(),
                'label' => $sorting->getLabel(),
                'isSelected' => $sorting->getSelected(),
                'direction' => $sorting->getDirection(),
            ];
        }
        return $sortings;
    }
}
