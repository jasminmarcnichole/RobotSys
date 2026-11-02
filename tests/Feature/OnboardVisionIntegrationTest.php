<?php

namespace Tests\Feature;

use Tests\TestCase;

class OnboardVisionIntegrationTest extends TestCase
{
    public function test_homepage_redirects_to_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('robot.dashboard'));
    }
}
