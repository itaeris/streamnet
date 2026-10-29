<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_screen_renders(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk();
    }
}
