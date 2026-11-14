<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsTest extends TestCase
{
    public function test_preflight_from_zalo_origin_is_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/products', [], [], [], [
            'HTTP_ORIGIN' => 'https://h5.zdn.vn',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response->assertHeader('Access-Control-Allow-Origin', 'https://h5.zdn.vn');
    }

    public function test_unknown_origin_is_not_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/products', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNotSame(
            'https://evil.example',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
        $this->assertNotSame(
            '*',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }
}
