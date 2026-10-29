<?php

namespace Tests\Feature;

use Tests\TestCase;

class NetworkApiTest extends TestCase
{
    public function test_returns_configured_lan_ip_when_opened_from_localhost(): void
    {
        config(['app.lan_ip' => '192.168.1.50']);

        $this->getJson('http://localhost/api/network')->assertOk()->assertExactJson(['ip' => '192.168.1.50']);
    }

    public function test_returns_the_lan_ip_used_to_open_the_app(): void
    {
        config(['app.lan_ip' => '192.168.1.50']);

        $this->getJson('http://192.168.1.77/api/network')->assertOk()->assertExactJson(['ip' => '192.168.1.77']);
    }

    public function test_unknown_without_config(): void
    {
        config(['app.lan_ip' => null]);

        $this->getJson('http://localhost/api/network')->assertOk()->assertExactJson(['ip' => null]);
    }
}
