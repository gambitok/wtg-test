<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_api_route_with_an_unsupported_method_returns_json(): void
    {
        $response = $this->get('/api/imports');

        $response
            ->assertStatus(405)
            ->assertJson([
                'message' => 'The GET method is not supported for this route. Supported methods: POST.',
            ])
            ->assertJsonMissingPath('trace');
    }

    public function test_an_unknown_api_resource_returns_json_not_found(): void
    {
        $response = $this->get('/api/imports/999999');

        $response
            ->assertNotFound()
            ->assertJson([
                'message' => 'Resource not found.',
            ])
            ->assertJsonMissingPath('trace');
    }

    public function test_api_validation_errors_are_json_without_an_accept_header(): void
    {
        $response = $this->post('/api/imports', [
            'supplier' => 'missing-supplier',
            'external_import_id' => 'import-invalid-supplier',
            'sent_at' => '2026-09-08T10:00:00Z',
            'offers' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => ['supplier'],
            ])
            ->assertJsonMissingPath('trace');
    }
}
