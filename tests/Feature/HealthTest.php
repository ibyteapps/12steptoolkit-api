<?php

it('answers the health check', function () {
    $this->getJson('/api/v2/health')
        ->assertOk()
        ->assertJsonPath('data.ok', true);
});
