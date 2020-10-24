<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\Importer\Strategy;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Shlinkio\Shlink\Importer\Exception\BitlyApiException;
use Shlinkio\Shlink\Importer\Exception\ImportException;
use Shlinkio\Shlink\Importer\Model\BitlyApiProgressTracker;
use Shlinkio\Shlink\Importer\Model\ImportedShlinkUrl;
use Shlinkio\Shlink\Importer\Params\BitlyApiParams;
use Shlinkio\Shlink\Importer\Util\DateHelpersTrait;
use Throwable;

use function Functional\filter;
use function Functional\map;
use function json_decode;
use function ltrim;
use function parse_url;
use function sprintf;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

class BitlyApiImporter implements ImporterStrategyInterface
{
    use DateHelpersTrait;

    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;

    public function __construct(ClientInterface $httpClient, RequestFactoryInterface $requestFactory)
    {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
    }

    /**
     * @return ImportedShlinkUrl[]
     * @throws ImportException
     */
    public function import(array $rawParams): iterable
    {
        $params = BitlyApiParams::fromRawParams($rawParams);
        $progressTracker = BitlyApiProgressTracker::initFromParams($params);
        $initialGroup = $progressTracker->initialGroup();
        $initialGroupFound = $initialGroup === null;

        try {
            ['groups' => $groups] = $this->callToBitlyApi('/groups', $params, $progressTracker);

            foreach ($groups as ['guid' => $groupId]) {
                // Skip groups until the initial one is found
                $initialGroupFound = $initialGroupFound || $groupId === $initialGroup;
                if (!$initialGroupFound) {
                    continue;
                }

                yield from $this->loadUrlsForGroup($groupId, $params, $progressTracker);
            }
        } catch (ImportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ImportException::fromError($e);
        }
    }

    /**
     * @return ImportedShlinkUrl[]
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    private function loadUrlsForGroup(
        string $groupId,
        BitlyApiParams $params,
        BitlyApiProgressTracker $progressTracker
    ): iterable {
        $pagination = [];
        $archived = $params->ignoreArchived() ? 'off' : 'both';
        $createdBefore = $groupId === $progressTracker->initialGroup() ? $progressTracker->createdBefore() : '';

        do {
            $url = $pagination['next'] ?? sprintf(
                '/groups/%s/bitlinks?archived=%s&created_before=%s',
                $groupId,
                $archived,
                $createdBefore,
            );
            ['links' => $links, 'pagination' => $pagination] = $this->callToBitlyApi($url, $params, $progressTracker);
            $progressTracker->updateLastProcessedGroup($groupId);

            $filteredLinks = filter(
                $links,
                static fn (array $link): bool => isset($link['long_url']) && ! empty($link['long_url']),
            );

            yield from map($filteredLinks, function (array $link) use ($params, $progressTracker): ImportedShlinkUrl {
                $hasCreatedDate = isset($link['created_at']);
                if ($hasCreatedDate) {
                    $progressTracker->updateLastProcessedUrlDate($link['created_at']);
                }

                $date = $hasCreatedDate && $params->keepCreationDate()
                    ? $this->dateFromAtom($link['created_at'])
                    : clone $progressTracker->startDate();
                $parsedLink = parse_url($link['link'] ?? '');
                $host = $parsedLink['host'] ?? null;
                $domain = $host !== 'bit.ly' && $params->importCustomDomains() ? $host : null;
                $shortCode = ltrim($parsedLink['path'] ?? '', '/');
                $tags = $params->importTags() ? $link['tags'] ?? [] : [];

                return new ImportedShlinkUrl($link['long_url'], $tags, $date, $domain, $shortCode);
            });
        } while (! empty($pagination['next']));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    private function callToBitlyApi(
        string $url,
        BitlyApiParams $params,
        BitlyApiProgressTracker $progressTracker
    ): array {
        $url = str_starts_with($url, 'http') ? $url : sprintf('https://api-ssl.bitly.com/v4%s', $url);
        $request = $this->requestFactory->createRequest('GET', $url)->withHeader(
            'Authorization',
            sprintf('Bearer %s', $params->accessToken()),
        );
        $resp = $this->httpClient->sendRequest($request);
        $body = (string) $resp->getBody();
        $statusCode = $resp->getStatusCode();

        if ($statusCode >= 400) {
            throw BitlyApiException::fromInvalidRequest(
                $url,
                $statusCode,
                $body,
                $progressTracker->generateContinueToken() ?? $params->continueToken(),
            );
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }
}
