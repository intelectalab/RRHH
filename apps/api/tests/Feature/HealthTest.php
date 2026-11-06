<?php

it('returns health ok', function () {
    $response = $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonStructure([
            'data' => [
                'status',
                'timestamp',
            ],
        ]);

    $this->assertMatchesOpenApi($response, 'get', '/platform/health', 200);
});
