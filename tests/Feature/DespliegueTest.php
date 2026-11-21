<?php

test('el health check /up responde 200', function () {
    $this->get('/up')->assertOk();
});

test('CORS permite solo los orígenes configurados en CORS_ALLOWED_ORIGINS', function () {
    config(['cors.allowed_origins' => ['https://panel.example.test']]);

    $preflight = fn (string $origen) => $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
        'HTTP_ORIGIN' => $origen,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    $preflight('https://panel.example.test')
        ->assertHeader('Access-Control-Allow-Origin', 'https://panel.example.test');

    expect($preflight('https://otro.example.test')->headers->get('Access-Control-Allow-Origin'))
        ->not->toBe('https://otro.example.test');
});
