<?php

it('returns health ok', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'timestamp']);
});
