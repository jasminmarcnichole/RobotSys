<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_homepage_redirects_to_the_robot_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('robot.dashboard'));
    }
}
