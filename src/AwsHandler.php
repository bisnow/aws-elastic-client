<?php

namespace RenokiCo\AwsElasticHandler;

use Aws\Credentials\Credentials;
use Aws\Signature\SignatureV4;
use Elasticsearch\ClientBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
use Psr\Http\Message\ResponseInterface;

class AwsHandler
{
    /**
     * The configuration for Elasticsearch.
     *
     * @var array
     */
    protected $config;

    /**
     * Initialize the handler.
     *
     * @param  array  $config
     * @return void
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Invoke the handler function.
     *
     * @param  array  $request
     * @return \GuzzleHttp\Ring\Future\CompletedFutureArray
     */
    public function __invoke(array $request)
    {
        if (! $this->config['enabled'] ?? false) {
            return $this->handleRequestWithDefaultHandler($request);
        }

        $clientOptions = [
          'verify' => $request['client']['verify'] ?? true
        ];

        $psr7Handler = $this->custom_http_handler($clientOptions);
        $signer = new SignatureV4('es', $this->config['aws_region']);

        $psr7Request = new Request(
            $request['http_method'],
            (new Uri($request['uri']))
                ->withScheme($request['scheme'])
                ->withHost($request['headers']['Host'][0])
                ->withPort($request['client']['curl'][3] ?? 9200),
            $request['headers'],
            $request['body']
        );

        // Sign the PSR-7 request with credentials from the environment.
        $signedRequest = $signer->signRequest(
            $psr7Request,
            new Credentials(
                $this->config['aws_access_key_id'],
                $this->config['aws_secret_access_key'],
                $this->config['aws_session_token'] ?? null
            )
        );

        // Send the signed request to Amazon ES.
        $response = $psr7Handler($signedRequest)->then(function (ResponseInterface $response) {
            return $response;
        }, function ($error) {
            return $error['response'];
        })->wait();

        if (! $response) {
            return $this->handleRequestWithDefaultHandler($request);
        }

        // Convert the PSR-7 response to a RingPHP response.
        return new CompletedFutureArray([
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => $response->getBody()->detach(),
            'transfer_stats' => ['total_time' => 0],
            'effective_url' => (string) $psr7Request->getUri(),
        ]);
    }

    /**
     * Handle the request with default handler.
     *
     * @param  array  $request
     * @return \GuzzleHttp\Ring\Future\CompletedFutureArray
     */
    protected function handleRequestWithDefaultHandler(array $request)
    {
        $defaultHandler = ClientBuilder::defaultHandler();

        return $defaultHandler($request);
    }

    /**
     * Creates an HTTP handler based on the available clients using the provided config.
     *
     * @return \Aws\Handler\GuzzleV5\GuzzleHandler|callable|\Aws\Handler\GuzzleV6\GuzzleHandler
     */
    protected function custom_http_handler(array $config)
    {
        $version = \Aws\guzzle_major_version();
        // If Guzzle 6 or 7 installed
        if ($version === 6 || $version === 7) {
            return new \Aws\Handler\GuzzleV6\GuzzleHandler(new Client($config));
        }

        // If Guzzle 5 installed
        if ($version === 5) {
            return new \Aws\Handler\GuzzleV5\GuzzleHandler(new Client($config));
        }

        throw new \RuntimeException('Unknown Guzzle version: ' . $version);
    }
}
