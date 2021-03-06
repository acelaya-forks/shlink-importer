<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Importer\Http;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Shlinkio\Shlink\Importer\Http\InvalidRequestException;
use Shlinkio\Shlink\Importer\Http\RestApiConsumer;

class RestApiConsumerTest extends TestCase
{
    use ProphecyTrait;

    private RestApiConsumer $apiConsumer;
    private ObjectProphecy $httpClient;
    private ObjectProphecy $requestFactory;

    public function setUp(): void
    {
        $this->httpClient = $this->prophesize(ClientInterface::class);
        $this->requestFactory = $this->prophesize(RequestFactoryInterface::class);

        $this->apiConsumer = new RestApiConsumer($this->httpClient->reveal(), $this->requestFactory->reveal());
    }

    /**
     * @test
     * @dataProvider provideFailureStatuses
     */
    public function exceptionIsThrownWhenRequestReturnsNonSuccessfulResponse(int $status): void
    {
        $req = new Request('GET', '');
        $createRequest = $this->requestFactory->createRequest(Argument::cetera())->willReturn($req);
        $sendRequest = $this->httpClient->sendRequest($req)->willReturn(new Response($status));

        $this->expectException(InvalidRequestException::class);
        $this->expectDeprecationMessage('Request to /foo/bar failed with status code ' . $status);
        $createRequest->shouldBeCalledOnce();
        $sendRequest->shouldBeCalledOnce();

        $this->apiConsumer->callApi('/foo/bar');
    }

    public function provideFailureStatuses(): iterable
    {
        yield '400' => [400];
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
        yield '500' => [500];
        yield '503' => [503];
        yield '504' => [504];
    }

    /** @test */
    public function exceptionIsThrownIfResponseBodyIsNotValidJson(): void
    {
        $req = new Request('GET', '');
        $createRequest = $this->requestFactory->createRequest(Argument::cetera())->willReturn($req);
        $sendRequest = $this->httpClient->sendRequest($req)->willReturn(new Response(200, [], '{"foo'));

        $this->expectException(JsonException::class);
        $createRequest->shouldBeCalledOnce();
        $sendRequest->shouldBeCalledOnce();

        $this->apiConsumer->callApi('/foo/bar');
    }

    /** @test */
    public function responseBodyIsParsedWhenProperJsonIsReturned(): void
    {
        $req = new Request('GET', '');
        $createRequest = $this->requestFactory->createRequest(Argument::cetera())->willReturn($req);
        $sendRequest = $this->httpClient->sendRequest(Argument::that(function (RequestInterface $request): bool {
            $headers = $request->getHeaders();

            Assert::assertArrayHasKey('Authorization', $headers);
            Assert::assertEquals('Bearer foobar', $request->getHeaderLine('Authorization'));
            Assert::assertArrayHasKey('X-Api-Key', $headers);
            Assert::assertEquals('abc-123', $request->getHeaderLine('X-Api-Key'));

            return true;
        }))->willReturn(new Response(200, [], '{"foo": "bar"}'));

        $result = $this->apiConsumer->callApi('/foo/bar', [
            'Authorization' => 'Bearer foobar',
            'X-Api-Key' => 'abc-123',
        ]);

        self::assertEquals(['foo' => 'bar'], $result);
        $createRequest->shouldHaveBeenCalledOnce();
        $sendRequest->shouldHaveBeenCalledOnce();
    }
}
