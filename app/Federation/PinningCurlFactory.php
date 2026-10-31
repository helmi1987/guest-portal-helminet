<?php

namespace App\Federation;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;

/**
 * cURL handle factory that pins the server's public key (CURLOPT_PINNEDPUBLICKEY). Guzzle does not
 * accept this option in the "curl" request option (allow-list), so it is set on the created handle;
 * curl aborts the connection before sending anything when the key does not match.
 */
final class PinningCurlFactory implements CurlFactoryInterface
{
    private CurlFactory $inner;

    /**
     * @param  array<int, mixed>  $options  further curl options outside Guzzle's allow-list (e.g.
     *                                      CURLOPT_MAXFILESIZE for downloads)
     */
    public function __construct(private readonly string $pin, private readonly array $options = [])
    {
        $this->inner = new CurlFactory(3);
    }

    public function create(RequestInterface $request, array $options): EasyHandle
    {
        $easy = $this->inner->create($request, $options);
        curl_setopt($easy->handle, CURLOPT_PINNEDPUBLICKEY, $this->pin);
        if ($this->options !== []) {
            curl_setopt_array($easy->handle, $this->options);
        }

        return $easy;
    }

    public function release(EasyHandle $easy): void
    {
        $this->inner->release($easy);
    }
}
