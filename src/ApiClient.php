<?php

namespace Scriptburn\ApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Middleware;

class ApiClient
{
	private $baseUrl, $headers, $options = ['channel'];

	public function log($message, $level = "info")
	{
		if (!empty($this->options['log_channel']) && class_exists('Illuminate\Support\Facades\Log'))
		{
			$logger = \Illuminate\Support\Facades\Log::channel($this->options['log_channel']);
			call_user_func_array([$logger, $level], [$message]);
		}
	}
	public function __construct($baseUrl, array $headers = [], array $options = [])
	{
		$this->baseUrl = rtrim($baseUrl, "/");
		$this->headers = $headers;
		$this->options = array_merge(['log_channel' => env('LOG_CHANNEL')], $options);
	}
	private function buildRequestHeaders(array $clientConfigs = [])
	{
		if (empty($clientConfigs['headers']))
		{
			$clientConfigs['headers'] = [];
		}

		$clientConfigs['headers'] = array_merge_recursive($clientConfigs['headers'], $this->headers);

		return $clientConfigs;
	}
	public function retryDecider($maxRetries = 5)
	{
		return function (
			$retries,
			$request,
			$response = null,
			RequestException $exception = null
		) use ($maxRetries)
		{
			if ($retries)
			{
				$this->log(sprintf('retry %1$s of url %2$s', $retries, $request->getUri()));
			}
			// Limit the number of retries to 5
			if ($retries >= $maxRetries)
			{
				return false;
			}

			// Retry connection exceptions
			if ($exception instanceof ConnectException)
			{
				return true;
			}

			if ($response)
			{
				// Retry on server errors
				if ($response->getStatusCode() >= 500)
				{
					return true;
				}
			}

			return false;
		};
	}
	function retryDelay($retryDelay = 1000)
	{
		return function ($numberOfRetries) use ($retryDelay)
		{
			return $retryDelay * $numberOfRetries;
		};
	}
	private function buildClientConfig(array $clientConfigs = [], array $options = [])
	{
		//p_d($clientConfigs['headers']);
		$maxRetries = 5;
		$retryDelay = 1000;
		if (isset($options['with_retry']))
		{
			if (!empty($options['with_retry']['max_retries']))
			{
				$maxRetries = $options['with_retry']['max_retries'];
			}
			if (!empty($options['with_retry']['retry_delay']))
			{
				$retryDelay = $options['with_retry']['retry_delay'];
			}

			$handlerStack = HandlerStack::create(new CurlHandler());
			$handlerStack->push(Middleware::retry(self::retryDecider($maxRetries), self::retryDelay($retryDelay)));
			$clientConfigs['handler'] = $handlerStack;
		}
		$clientConfigs = $this->buildRequestHeaders($clientConfigs);

		return $clientConfigs;
	}

	public function getClient(array $clientConfigs = [], array $options = [])
	{
		$clientConfigs = $this->buildClientConfig($clientConfigs, $options);

		$client = new Client($clientConfigs);

		return $client;
	}
	public function makeRequest($api, $method = 'post', array $requestData = [], array $clientConfigs = [], array $options = [])
	{
		$options = array_merge($options, ['with_retry' => ['max_retries' => 5, 'retry_delay' => 1000]]);
		$client = $this->getClient($clientConfigs, $options);
		$requestpath = ($api ? "/".ltrim($api, "/") : "");
		$result = [
			'status' => 0,
			'http_code' => null,
			'body' => null,
			'content_type' => null,
			'error' => 'Some error occured',
		];
		try
		{
			$response = $client->request($method, $this->baseUrl.$requestpath, $requestData);

			$contentType = $response->hasHeader('Content-Type') ? $response->getHeader('Content-Type')[0] : 'text/html';
			$result = [
				'status' => 1,
				'http_code' => $response->getStatusCode(),
				'body' => $response->getBody()->getContents(),
				'content_type' => $contentType,
				'error' => null,
			];
			if (stripos($contentType, 'application/json') !== false)
			{
				$result['body'] = json_decode($result['body'], true);
			}
		}
		catch (ConnectException $e)
		{
			$result = array_merge($result, [
				'body' => $e->getMessage(),
				'error' => 'Unable to connect',
			]);
		}
		catch (RequestException $e)
		{
			if ($e->hasResponse())
			{
				$response = $e->getResponse();
				$contentType = $response->hasHeader('Content-Type') ? $response->getHeader('Content-Type')[0] : 'text/html';
				$result = array_merge($result, [
					'http_code' => $response->getStatusCode(),
					'body' => $response->getBody()->getContents(),
					'content_type' => $contentType,
					'error' => 'Api Request Error(1)',
				]);
				if (stripos($contentType, 'application/json') !== false)
				{
					$result['body'] = json_decode($result['body'], true);
				}
			}
			else
			{
				$result = array_merge($result, [
					'body' => $e->getMessage(),
					'error' => 'Api Request Error(2)',
				]);
			}
		}
		catch (\Exception $e)
		{
			$result = array_merge($result, [
				'error' => $e->getMessage(),
			]);
		}

		return $result;
	}

	public function makeApiCallJson($api, $method = 'post', array $requestData = [], array $clientConfigs = [], array $options = [])
	{
		if (empty($clientConfigs['headers']))
		{
			$clientConfigs['headers'] = [];
		}
		$clientConfigs['headers'] = array_merge($clientConfigs['headers'], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);

		$response = $this->makeRequest($api,
			$method,
			[\GuzzleHttp\RequestOptions::JSON => $requestData],
			$clientConfigs,
			$options
		);

		return $response;
	}
	public function makeApiCall($api, $method = 'post', array $requestData = [], array $clientConfigs = [], array $options = [])
	{
		$response = $this->makeRequest($api,
			$method,
			[\GuzzleHttp\RequestOptions::JSON => $requestData],
			$clientConfigs,
			$options
		);

		return $response;
	}
}
